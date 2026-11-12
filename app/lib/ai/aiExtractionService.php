<?php

namespace App\lib\ai;

class aiExtractionService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @return array{status:bool,message:string,confidence?:float|null,data?:array,warnings?:array,error_code?:string}
     */
    public function extractFromUpload(array $file, string $bookingHash, string $passengerKey): array
    {
        if (!passportAiIsEnabled($this->db)) {
            return $this->failAndLog('AI_DISABLED', 'Passport scanning is currently disabled.');
        }

        // Ordered key chain (primary + backups). On a key-fixable failure we advance
        // to the next entry (see extractWithChain). A single-key install → 1 entry.
        $chain = function_exists('passportAiProviderConfigChain')
            ? passportAiProviderConfigChain($this->db) : [];
        if ($chain === []) {
            $single = passportAiActiveProviderConfig($this->db);
            if (is_array($single)) { $chain = [$single]; }
        }
        if ($chain === []) {
            return $this->failAndLog('AI_NOT_CONFIGURED', 'Passport AI is not configured. Add a valid API key under Admin → Settings → AI.');
        }
        $config = $chain[0]; // primary — used for validation (size/type limits are shared)

        if (!$this->isValidPassengerKey($passengerKey)) {
            return $this->fail('AI_INVALID_PASSENGER', 'Invalid passenger reference.');
        }

        if ($bookingHash === '' || !preg_match('/^[a-f0-9]{16}$/', $bookingHash)) {
            return $this->fail('AI_INVALID_BOOKING', 'Invalid booking session.');
        }

        $draft = $this->db->get('logs_bookings', ['hash'], ['hash' => $bookingHash]);
        if (!$draft) {
            return $this->fail('AI_INVALID_BOOKING', 'Booking session not found.');
        }

        if (!$this->checkRateLimit($bookingHash)) {
            return $this->failAndLog('AI_RATE_LIMITED', 'Too many passport scans. Please wait a few minutes and try again.', $config);
        }

        $validation = $this->validateUploadedFile($file, $config);
        if ($validation['status'] !== true) {
            return $validation;
        }

        $tmpPath = $validation['tmp_path'];
        $mimeType = $validation['mime'];

        try {
            $binary = file_get_contents($tmpPath);
            if ($binary === false || $binary === '') {
                return $this->fail('AI_CORRUPT_IMAGE', 'The uploaded file could not be read. Please try another image.');
            }

            // Walk the key chain: try the primary, fail over to each backup on a
            // key-fixable error (bad key / quota / provider down). A content failure
            // (bad image, low confidence) or a same-provider model error is NOT
            // retried — a different key wouldn't change it.
            $result = null;
            for ($ci = 0; $ci < count($chain); $ci++) {
                $cfg = $chain[$ci];
                $this->logEvent('request_initiated', [
                    'provider' => $cfg['key'],
                    'passenger' => $passengerKey,
                    'booking' => substr($bookingHash, 0, 6) . '…',
                ]);

                $provider = $this->makeProvider($cfg['key']);
                if ($provider === null) {
                    // Unsupported provider for this entry — treat like a failoverable
                    // error so a later usable key can still run.
                    $result = ['status' => false, 'error_code' => 'AI_PROVIDER_UNSUPPORTED', 'message' => 'The selected AI provider is not available yet.'];
                } else {
                    $result = $provider->extract($binary, $mimeType, $cfg);
                    unset($result['usage']);
                }

                if (!empty($result['status'])) {
                    $this->logEvent('processing_completed', [
                        'provider' => $cfg['key'],
                        'passenger' => $passengerKey,
                    ]);
                    break;
                }

                $errCode = (string) ($result['error_code'] ?? 'UNKNOWN');
                $this->logEvent('processing_failed', [
                    'provider' => $cfg['key'],
                    'error_code' => $errCode,
                ]);

                $hasNext = ($ci + 1) < count($chain);
                $nextProvider = $hasNext ? strtolower(trim((string) ($chain[$ci + 1]['key'] ?? ''))) : '';
                if ($hasNext && $this->ocrErrorIsFailoverable($errCode, (string) ($cfg['key'] ?? ''), $nextProvider)) {
                    error_log('[ai] passport OCR key ' . (string) ($cfg['fallback_label'] ?? ('rank' . $ci))
                        . ' failed (' . $errCode . ') — trying next backup key');
                    continue;
                }
                break; // success handled above; here = not failoverable or no more keys
            }

            // Never leak provider raw payloads / keys to browser.
            return [
                'status' => (bool) ($result['status'] ?? false),
                'message' => (string) ($result['message'] ?? 'Unknown error'),
                'confidence' => $result['confidence'] ?? null,
                'data' => $result['data'] ?? null,
                'warnings' => $result['warnings'] ?? [],
                'error_code' => $result['error_code'] ?? null,
            ];
        } catch (\Throwable $e) {
            error_log('[ai] extract error: ' . $e->getMessage());
            return $this->failAndLog('AI_INTERNAL_ERROR', 'AI processing failed. Please enter details manually.', $config ?? null, $passengerKey);
        }
    }

    private function makeProvider(string $key): ?aiProviderInterface
    {
        return match ($key) {
            'openai' => new openAiProvider(),
            'claude' => new claudeProvider(),
            'gemini' => new geminiProvider(),
            default => null,
        };
    }

    /**
     * Should passport OCR fall through to the next key for this provider error_code?
     * True for key/quota/outage/unsupported — a different key (or provider) can fix
     * those. An invalid-MODEL error only fails over when the NEXT key is a DIFFERENT
     * provider (same provider would hit the same bad model). Content failures (bad
     * image, low confidence, invalid response) are NOT retried — a different key
     * reads the same photo the same way.
     *
     * @param string $curProvider  provider key of the entry that just failed
     * @param string $nextProvider provider key of the next entry (for the model case)
     */
    private function ocrErrorIsFailoverable(string $errorCode, string $curProvider, string $nextProvider): bool
    {
        $code = strtoupper(trim($errorCode));
        $failover = [
            'AI_INVALID_API_KEY',        // bad/expired key
            'AI_QUOTA_EXCEEDED',         // billing / rate / quota
            'AI_PROVIDER_UNAVAILABLE',   // transport / 5xx
            'AI_PROVIDER_UNSUPPORTED',   // this entry's provider can't run here
            'AI_RATE_LIMITED',
        ];
        if (in_array($code, $failover, true)) {
            return true;
        }
        // A bad model only resolves on a DIFFERENT provider's key.
        if ($code === 'AI_INVALID_MODEL') {
            return $nextProvider !== '' && $nextProvider !== strtolower(trim($curProvider));
        }
        return false;
    }

    private function validateUploadedFile(array $file, array $config): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fail('AI_UPLOAD_FAILED', 'No passport image was uploaded.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return $this->fail('AI_UPLOAD_FAILED', 'Invalid upload.');
        }

        $maxSize = (int) ($config['max_file_size'] ?? 5242880);
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxSize) {
            $mb = max(1, (int) round($maxSize / 1048576));
            return $this->fail('AI_FILE_TOO_LARGE', "File is too large. Maximum size is {$mb} MB.");
        }

        $allowed = $config['allowed_file_types'] ?? ['jpg', 'jpeg', 'png', 'webp'];
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            return $this->fail('AI_INVALID_FILE_TYPE', 'Unsupported file type. Please upload JPG, PNG, or WEBP.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $mimeMap = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
        ];
        $allowedMimes = $mimeMap[$extension] ?? [];
        if ($mime === '' || !in_array($mime, $allowedMimes, true)) {
            return $this->fail('AI_INVALID_FILE_TYPE', 'The file content does not match a supported image type.');
        }

        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($tmpPath);
            if ($info === false) {
                return $this->fail('AI_CORRUPT_IMAGE', 'The image appears corrupted. Please try another file.');
            }
            $width = (int) ($info[0] ?? 0);
            $height = (int) ($info[1] ?? 0);
            if ($width < 200 || $height < 200) {
                return $this->fail(
                    'AI_LOW_RESOLUTION',
                    'We could not clearly read this passport. Please take another photo with better lighting and make sure the complete passport page is visible.'
                );
            }
        }

        return [
            'status' => true,
            'tmp_path' => $tmpPath,
            'mime' => $mime,
            'extension' => $extension,
        ];
    }

    private function isValidPassengerKey(string $key): bool
    {
        return (bool) preg_match('/^(adult|child|infant)_\d+$/', $key);
    }

    private function checkRateLimit(string $bookingHash): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $now = time();
        $window = 900; // 15 minutes
        $max = 10;

        if (!isset($_SESSION['ai_rate']) || !is_array($_SESSION['ai_rate'])) {
            $_SESSION['ai_rate'] = [];
        }

        $entry = $_SESSION['ai_rate'][$bookingHash] ?? ['start' => $now, 'count' => 0];
        if (($now - (int) $entry['start']) > $window) {
            $entry = ['start' => $now, 'count' => 0];
        }
        if ((int) $entry['count'] >= $max) {
            $_SESSION['ai_rate'][$bookingHash] = $entry;
            return false;
        }
        $entry['count'] = (int) $entry['count'] + 1;
        $_SESSION['ai_rate'][$bookingHash] = $entry;
        return true;
    }

    private function logEvent(string $event, array $context = []): void
    {
        // Safe technical log — no document numbers, names, or keys.
        error_log('[ai] ' . $event . ' ' . json_encode($context));
    }

    private function fail(string $code, string $message): array
    {
        return [
            'status' => false,
            'message' => $message,
            'error_code' => $code,
        ];
    }

    /**
     * @param array<string,mixed>|null $config
     */
    private function failAndLog(string $code, string $message, ?array $config = null, string $passengerKey = ''): array
    {
        return $this->fail($code, $message);
    }
}
