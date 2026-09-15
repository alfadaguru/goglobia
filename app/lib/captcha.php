<?php
/**
 * Custom CAPTCHA System
 * Prevents bot signups with mathematical challenges and honeypot fields
 */
class Captcha {
    
    /**
     * Generate a mathematical CAPTCHA challenge
     * @return array ['question' => string, 'answer' => int, 'hash' => string]
     */
    public static function generate() {
        $operations = [
            ['type' => 'add', 'symbol' => '+', 'word' => 'plus'],
            ['type' => 'subtract', 'symbol' => '-', 'word' => 'minus'],
            ['type' => 'multiply', 'symbol' => '×', 'word' => 'times']
        ];
        
        $operation = $operations[array_rand($operations)];
        
        // Generate numbers based on operation type
        if ($operation['type'] === 'add') {
            $num1 = rand(1, 20);
            $num2 = rand(1, 20);
            $answer = $num1 + $num2;
        } elseif ($operation['type'] === 'subtract') {
            $num1 = rand(10, 30);
            $num2 = rand(1, $num1 - 1);
            $answer = $num1 - $num2;
        } else { // multiply
            $num1 = rand(2, 9);
            $num2 = rand(2, 9);
            $answer = $num1 * $num2;
        }
        
        // Create question in both numeric and word format (randomly chosen)
        $useWords = rand(0, 1);
        if ($useWords) {
            $question = self::numberToWord($num1) . ' ' . $operation['word'] . ' ' . self::numberToWord($num2);
        } else {
            $question = $num1 . ' ' . $operation['symbol'] . ' ' . $num2;
        }
        
        // Create secure hash of the answer with timestamp
        $timestamp = time();
        $hash = self::createHash($answer, $timestamp);
        
        return [
            'question' => $question,
            'answer' => $answer,
            'hash' => $hash,
            'timestamp' => $timestamp
        ];
    }
    
    /**
     * Validate CAPTCHA answer
     * @param string $userAnswer User's submitted answer
     * @param string $hash Stored hash from session
     * @param int $timestamp Timestamp when captcha was generated
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validate($userAnswer, $hash, $timestamp) {
        // Check if captcha exists
        if (empty($hash) || empty($timestamp)) {
            return ['valid' => false, 'error' => 'captcha_missing'];
        }
        
        // Check if captcha expired (10 minutes)
        if (time() - $timestamp > 600) {
            return ['valid' => false, 'error' => 'captcha_expired'];
        }
        
        // Validate answer format
        if (!is_numeric($userAnswer)) {
            return ['valid' => false, 'error' => 'captcha_invalid'];
        }
        
        $userAnswer = (int)$userAnswer;
        
        // Verify hash matches
        $expectedHash = self::createHash($userAnswer, $timestamp);
        if (!hash_equals($hash, $expectedHash)) {
            return ['valid' => false, 'error' => 'captcha_wrong'];
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Create secure hash of answer with timestamp
     * @param int $answer The correct answer
     * @param int $timestamp Unix timestamp
     * @return string Hash
     */
    private static function createHash($answer, $timestamp) {
        return hash_hmac('sha256', $answer . '|' . $timestamp, self::secret());
    }

    /**
     * Resolve the captcha HMAC secret.
     *
     * The old hardcoded 'phptravels_captcha_secret_v10_2025' shipped in source,
     * so anyone could forge a valid captcha token (answer+timestamp HMAC) and
     * bypass the anti-bot check on signup/deposit entirely. Resolve a real secret
     * instead, mirroring the JWT approach:
     *   1. CAPTCHA_SECRET from .env (preferred — operator sets a long random value)
     *   2. per-install derived secret from private .env material (DB creds +
     *      license + path) — unique per deployment, never in source, stable across
     *      requests (so tokens verify within the same install).
     *   3. fail-safe per-process value if .env is unreadable.
     */
    private static function secret() {
        static $cache = null;
        if ($cache !== null) { return $cache; }

        $secret = '';
        $env = getenv('CAPTCHA_SECRET');
        if ($env !== false && trim((string) $env) !== '') {
            $secret = trim((string) $env);
        }

        $envFile = __DIR__ . '/../../.env';
        if ($secret === '' && is_file($envFile)) {
            $vars = @parse_ini_file($envFile);
            if (is_array($vars)) {
                if (!empty($vars['CAPTCHA_SECRET']) && trim((string) $vars['CAPTCHA_SECRET']) !== '') {
                    $secret = trim((string) $vars['CAPTCHA_SECRET']);
                } else {
                    $material = ($vars['DB_PASSWORD'] ?? '')
                        . '|' . ($vars['DB_DATABASE'] ?? '')
                        . '|' . ($vars['DB_USERNAME'] ?? '')
                        . '|' . ($vars['LICENSE_KEY'] ?? '')
                        . '|' . __DIR__;
                    if (trim($material, '|') !== '') {
                        $secret = hash('sha256', 'captcha-v10|' . $material);
                    }
                }
            }
        }

        if ($secret === '') {
            $secret = hash('sha256', 'captcha-v10|' . __DIR__ . '|' . (getenv('HOSTNAME') ?: php_uname('n')));
        }

        $cache = $secret;
        return $cache;
    }
    
    /**
     * Convert number to word (1-30)
     * @param int $num Number to convert
     * @return string Word representation
     */
    private static function numberToWord($num) {
        $words = [
            1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
            6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
            11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen',
            16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen', 20 => 'twenty',
            21 => 'twenty-one', 22 => 'twenty-two', 23 => 'twenty-three', 24 => 'twenty-four',
            25 => 'twenty-five', 26 => 'twenty-six', 27 => 'twenty-seven', 28 => 'twenty-eight',
            29 => 'twenty-nine', 30 => 'thirty'
        ];
        
        return $words[$num] ?? (string)$num;
    }
    
    /**
     * Generate honeypot field name (changes per session)
     * @return string Field name
     */
    public static function getHoneypotField() {
        if (!isset($_SESSION['honeypot_field'])) {
            // Generate random field name that looks legitimate
            $fields = ['company', 'website', 'fax', 'address2', 'middle_name', 'suffix'];
            $_SESSION['honeypot_field'] = $fields[array_rand($fields)] . '_' . bin2hex(random_bytes(4));
        }
        return $_SESSION['honeypot_field'];
    }
    
    /**
     * Check if honeypot was triggered (bot detected)
     * @return bool True if bot detected
     */
    public static function isHoneypotTriggered() {
        $honeypotField = self::getHoneypotField();
        return !empty($_POST[$honeypotField]);
    }
    
    /**
     * Check submission timing (too fast = bot)
     * @param int $minSeconds Minimum seconds required (default 3)
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function checkTimestamp($minSeconds = 3) {
        $formLoadTime = $_POST['form_load_time'] ?? 0;
        
        if (empty($formLoadTime)) {
            return ['valid' => false, 'error' => 'timing_missing'];
        }
        
        $timeTaken = time() - $formLoadTime;
        
        // Too fast (bot)
        if ($timeTaken < $minSeconds) {
            return ['valid' => false, 'error' => 'timing_too_fast'];
        }
        
        // Too slow (expired, over 30 minutes)
        if ($timeTaken > 1800) {
            return ['valid' => false, 'error' => 'timing_expired'];
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Comprehensive bot detection check
     * @return array ['is_bot' => bool, 'reason' => string|null]
     */
    public static function detectBot() {
        // Check honeypot
        if (self::isHoneypotTriggered()) {
            return ['is_bot' => true, 'reason' => 'honeypot_triggered'];
        }
        
        // Check timing
        $timingCheck = self::checkTimestamp();
        if (!$timingCheck['valid']) {
            return ['is_bot' => true, 'reason' => $timingCheck['error']];
        }
        
        // Check user agent
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (empty($userAgent) || strlen($userAgent) < 10) {
            return ['is_bot' => true, 'reason' => 'no_user_agent'];
        }
        
        // Check for common bot signatures
        $botSignatures = ['bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python-requests'];
        foreach ($botSignatures as $signature) {
            if (stripos($userAgent, $signature) !== false) {
                return ['is_bot' => true, 'reason' => 'bot_user_agent'];
            }
        }
        
        // Check if JavaScript was enabled (hidden field set by JS)
        $jsEnabled = $_POST['js_enabled'] ?? '0';
        if ($jsEnabled !== '1') {
            return ['is_bot' => true, 'reason' => 'no_javascript'];
        }
        
        return ['is_bot' => false, 'reason' => null];
    }
    
    /**
     * Generate CAPTCHA field HTML
     * @param array $captchaData CAPTCHA data from generate()
     * @return string HTML
     */
    public static function renderField($captchaData) {
        $honeypot = self::getHoneypotField();
        $formLoadTime = time();
        
        $html = '
        <!-- CAPTCHA -->
        <div class="form-control">
            <label for="captcha_answer" class="block text-sm font-medium text-gray-700 mb-1">
                Security Check: What is ' . htmlspecialchars($captchaData['question']) . '? *
            </label>
            <div class="input-group">
                <span class="input-icon-left material-symbols-outlined">calculate</span>
                <input type="number" 
                       id="captcha_answer" 
                       name="captcha_answer" 
                       required 
                       class="input-with-icon"
                       placeholder="Enter the answer"
                       autocomplete="off">
            </div>
            <input type="hidden" name="captcha_hash" value="' . htmlspecialchars($captchaData['hash']) . '">
            <input type="hidden" name="captcha_timestamp" value="' . htmlspecialchars($captchaData['timestamp']) . '">
        </div>
        
        <!-- Anti-bot measures (hidden fields) -->
        <input type="hidden" name="form_load_time" value="' . $formLoadTime . '">
        <input type="hidden" name="js_enabled" id="js_enabled" value="0">
        <input type="text" name="' . htmlspecialchars($honeypot) . '" value="" style="position:absolute;left:-9999px;width:1px;height:1px;" tabindex="-1" autocomplete="off" aria-hidden="true">
        
        <script>
        // Mark that JavaScript is enabled
        document.getElementById("js_enabled").value = "1";
        
        // Focus trap for honeypot (prevent accidental fills)
        document.addEventListener("DOMContentLoaded", function() {
            const honeypot = document.querySelector(\'[name="' . htmlspecialchars($honeypot) . '"]\');
            if (honeypot) {
                honeypot.addEventListener("focus", function() {
                    document.getElementById("captcha_answer").focus();
                });
            }
        });
        </script>
        ';
        
        return $html;
    }
}
