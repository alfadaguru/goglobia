<?php
$requirements = [
    'name' => 'Apple Push (APNS)',
    'fields' => ['key_id', 'team_id', 'bundle_id', 'private_key'],
    'icon' => 'notifications_active'
];

class ApnsProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://api.push.apple.com';
    private $developmentUrl = 'https://api.sandbox.push.apple.com';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($device_token, $to_name, $title, $message, $data = null, $badge = null, $sound = 'default') {
        try {
            if (empty($this->config['key_id']) || empty($this->config['team_id']) || empty($this->config['bundle_id']) || empty($this->config['private_key'])) {
                throw new Exception('APNS configuration incomplete. Key ID, Team ID, Bundle ID, and Private Key are required.');
            }

            if (empty($device_token)) {
                throw new Exception('Device token is required.');
            }

            $result = $this->sendPushNotification($device_token, $title, $message, $data, $badge, $sound);

            if ($result['success']) {
                return true;
            } else {
                throw new Exception($result['message']);
            }

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("APNS Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendPushNotification($deviceToken, $title, $message, $data = null, $badge = null, $sound = 'default') {
        $keyId = $this->config['key_id'];
        $teamId = $this->config['team_id'];
        $bundleId = $this->config['bundle_id'];
        $privateKey = $this->config['private_key'];

        // Prepare payload
        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $message
                ],
                'sound' => $sound
            ]
        ];

        if ($badge !== null) {
            $payload['aps']['badge'] = $badge;
        }

        if ($data !== null && is_array($data)) {
            $payload = array_merge($payload, $data);
        }

        $payloadJson = json_encode($payload);

        // Generate JWT token
        $jwt = $this->generateJWT($keyId, $teamId, $privateKey);

        // Use production URL for now (you might want to make this configurable)
        $url = $this->baseUrl . "/3/device/{$deviceToken}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: bearer ' . $jwt,
                'apns-topic: ' . $bundleId
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return [
                'success' => false,
                'message' => 'CURL Error: ' . $curlError
            ];
        }

        if ($httpCode === 200) {
            return [
                'success' => true,
                'message' => 'Push notification sent successfully via APNS'
            ];
        } else {
            $errorMessage = $this->getAPNSErrorMessage($httpCode, $response);
            return [
                'success' => false,
                'message' => "APNS Error {$httpCode}: " . $errorMessage
            ];
        }
    }

    private function generateJWT($keyId, $teamId, $privateKey) {
        $header = [
            'alg' => 'ES256',
            'kid' => $keyId
        ];

        $payload = [
            'iss' => $teamId,
            'iat' => time()
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $dataToSign = $headerEncoded . '.' . $payloadEncoded;

        // Create private key resource
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        if (!$privateKeyResource) {
            throw new Exception('Invalid private key format');
        }

        // Sign the data
        $signature = '';
        $success = openssl_sign($dataToSign, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKeyResource);

        if (!$success) {
            throw new Exception('Failed to sign JWT');
        }

        // Extract ASN.1 signature and convert to raw format
        $asn1 = $signature;
        $rawSignature = '';
        $position = 2; // Skip the SEQUENCE tag and length
        
        // Read R
        if (ord($asn1[$position]) === 0x02) {
            $rLength = ord($asn1[$position + 1]);
            $r = substr($asn1, $position + 2, $rLength);
            $position += 2 + $rLength;
            
            // Read S
            if (ord($asn1[$position]) === 0x02) {
                $sLength = ord($asn1[$position + 1]);
                $s = substr($asn1, $position + 2, $sLength);
                
                // Ensure both R and S are 32 bytes
                $r = str_pad($r, 32, "\0", STR_PAD_LEFT);
                $s = str_pad($s, 32, "\0", STR_PAD_LEFT);
                
                $rawSignature = $r . $s;
            }
        }

        $signatureEncoded = $this->base64UrlEncode($rawSignature);

        return $dataToSign . '.' . $signatureEncoded;
    }

    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function getAPNSErrorMessage($httpCode, $response) {
        $commonErrors = [
            400 => 'Bad request',
            403 => 'There was an error with the certificate or with the provider authentication token',
            405 => 'The request used a bad :method value. Only POST requests are supported',
            410 => 'The device token is no longer active for the topic',
            413 => 'The notification payload was too large',
            429 => 'The server received too many requests for the same device token',
            500 => 'Internal server error',
            503 => 'The server is shutting down and unavailable'
        ];

        if (isset($commonErrors[$httpCode])) {
            return $commonErrors[$httpCode];
        }

        $responseData = json_decode($response, true);
        if (isset($responseData['reason'])) {
            return $responseData['reason'];
        }

        return 'Unknown APNS error occurred';
    }

    public function testConnection() {
        try {
            if (empty($this->config['key_id']) || empty($this->config['team_id']) || empty($this->config['private_key'])) {
                throw new Exception('Key ID, Team ID, and Private Key are required for testing connection');
            }

            // Test JWT generation
            $jwt = $this->generateJWT(
                $this->config['key_id'],
                $this->config['team_id'],
                $this->config['private_key']
            );

            if ($jwt) {
                return [
                    'success' => true,
                    'message' => 'APNS configuration is valid. JWT token generated successfully.'
                ];
            } else {
                throw new Exception('Failed to generate JWT token');
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'APNS connection test failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'ApnsProvider';
?>