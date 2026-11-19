<?php
// Firebase Cloud Messaging — HTTP v1 API.
// Migrated from the DECOMMISSIONED legacy /fcm/send + "Authorization: key=<server_key>"
// (Google shut that down June 2024) to the current v1 API:
//   POST https://fcm.googleapis.com/v1/projects/{project_id}/messages:send
//   Authorization: Bearer <oauth2 access token>
// The access token is minted from a Google service-account JSON (sign a JWT with the
// service account's private key, exchange it at the OAuth2 token endpoint). Config
// field: `service_account_json` = the full service-account JSON (string or array).
$requirements = [
    'name'   => 'Firebase FCM',
    'fields' => ['service_account_json'],
    'icon'   => 'notifications_active',
    'help'   => 'Paste the full Firebase service-account JSON (Project settings → Service accounts → Generate new private key).',
];

class FirebaseProvider {
    private $config;
    private $lastError;

    public function __construct($config) {
        $this->config = is_array($config) ? $config : [];
    }

    /**
     * Send one push to one device token. Signature kept identical to the legacy
     * provider so SENDPUSH / the admin test button don't change.
     * @return bool
     */
    public function send($device_token, $to_name, $title, $message, $data = null, $badge = null, $sound = 'default') {
        try {
            $sa = $this->serviceAccount();
            if (!$sa) {
                throw new Exception('Firebase configuration incomplete: paste the service-account JSON.');
            }
            if (empty($device_token)) {
                throw new Exception('Device token is required.');
            }
            $result = $this->sendPushNotification($device_token, $title, $message, $data, $badge, $sound);
            if (!empty($result['success'])) {
                return true;
            }
            // Surface a token-invalid signal so the caller (SENDPUSH) can prune it.
            $this->lastError = $result['message'] ?? 'Firebase send failed';
            if (!empty($result['invalid_token'])) { $this->lastError = 'INVALID_TOKEN: ' . $this->lastError; }
            error_log('Firebase FCM Error: ' . $this->lastError);
            return false;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log('Firebase FCM Error: ' . $e->getMessage());
            return false;
        }
    }

    /** Parse the service-account JSON from config (string or already-decoded array). */
    private function serviceAccount() {
        $raw = $this->config['service_account_json'] ?? null;
        if (is_array($raw)) { $sa = $raw; }
        elseif (is_string($raw) && trim($raw) !== '') { $sa = json_decode($raw, true); }
        else { return null; }
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key']) || empty($sa['project_id'])) {
            return null;
        }
        return $sa;
    }

    /**
     * Mint a short-lived OAuth2 access token for FCM via the service-account JWT grant.
     * Cached in-process for the token's lifetime (minus a safety margin).
     */
    private function accessToken(array $sa) {
        static $cache = []; // keyed by client_email
        $key = $sa['client_email'];
        if (isset($cache[$key]) && $cache[$key]['exp'] > time() + 60) {
            return $cache[$key]['token'];
        }

        // SET-3 (SSRF): token_uri comes from the admin-pasted service-account JSON and
        // was used verbatim as the OAuth cURL URL — an attacker-chosen host would
        // receive the signed JWT (and be an outbound-request primitive). Pin it to
        // Google's token endpoint; only accept an override on a *.googleapis.com host.
        $tokenUri = 'https://oauth2.googleapis.com/token';
        $claimed = trim((string) ($sa['token_uri'] ?? ''));
        if ($claimed !== '') {
            $h = strtolower((string) parse_url($claimed, PHP_URL_HOST));
            if (stripos($claimed, 'https://') === 0 && ($h === 'oauth2.googleapis.com' || substr($h, -15) === '.googleapis.com')) {
                $tokenUri = $claimed;
            }
            // else: silently fall back to the canonical endpoint (do NOT fetch the override).
        }

        $now = time();
        $claim = [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $tokenUri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $b64 = static function ($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); };
        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode($claim));
        $signingInput = $header . '.' . $payload;

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $sa['private_key'], 'SHA256')) {
            throw new Exception('Could not sign the service-account JWT (check private_key).');
        }
        $jwt = $signingInput . '.' . $b64($signature);

        $ch = curl_init($tokenUri);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) { throw new Exception('OAuth token request failed: ' . $err); }
        $tok = json_decode((string) $resp, true);
        if ($code !== 200 || empty($tok['access_token'])) {
            throw new Exception('OAuth token error (' . $code . '): ' . ($tok['error_description'] ?? $tok['error'] ?? 'unknown'));
        }
        $cache[$key] = ['token' => $tok['access_token'], 'exp' => $now + (int) ($tok['expires_in'] ?? 3600)];
        return $tok['access_token'];
    }

    private function sendPushNotification($deviceToken, $title, $message, $data = null, $badge = null, $sound = 'default') {
        $sa = $this->serviceAccount();
        $token = $this->accessToken($sa);
        $projectId = $sa['project_id'];
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';

        // FCM v1 message. data[] values MUST be strings.
        $msg = [
            'token'        => $deviceToken,
            'notification' => ['title' => (string) $title, 'body' => (string) $message],
            'android'      => ['priority' => 'HIGH', 'notification' => ['sound' => (string) ($sound ?: 'default')]],
            'apns'         => ['payload' => ['aps' => ['sound' => (string) ($sound ?: 'default')]]],
        ];
        if ($badge !== null) { $msg['apns']['payload']['aps']['badge'] = (int) $badge; }
        if (is_array($data) && $data) {
            $msg['data'] = [];
            foreach ($data as $k => $v) { $msg['data'][(string) $k] = is_scalar($v) ? (string) $v : json_encode($v); }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => json_encode(['message' => $msg]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) { return ['success' => false, 'message' => 'CURL Error: ' . $curlError]; }

        $rd = json_decode((string) $response, true);
        if ($httpCode === 200 && !empty($rd['name'])) {
            return ['success' => true, 'message' => 'Sent via FCM v1', 'message_id' => $rd['name']];
        }
        // v1 returns a stable error code; UNREGISTERED / INVALID_ARGUMENT (bad token)
        // → mark invalid so SENDPUSH can prune the dead device row.
        $status = $rd['error']['status'] ?? '';
        $emsg   = $rd['error']['message'] ?? ('HTTP ' . $httpCode);
        $invalid = in_array($status, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true);
        return ['success' => false, 'message' => "FCM v1 ($httpCode/$status): $emsg", 'invalid_token' => $invalid];
    }

    public function testConnection() {
        try {
            $sa = $this->serviceAccount();
            if (!$sa) { throw new Exception('Paste the service-account JSON first.'); }
            // Minting an access token proves the credentials are valid without sending.
            $this->accessToken($sa);
            return ['success' => true, 'message' => 'Firebase FCM v1 credentials valid (OAuth token minted for project ' . $sa['project_id'] . ').'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Firebase FCM test failed: ' . $e->getMessage()];
        }
    }

    public function getLastError() { return $this->lastError; }
}

return 'FirebaseProvider';
