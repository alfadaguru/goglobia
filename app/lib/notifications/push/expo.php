<?php
$requirements = [
    'name' => 'Expo Push',
    'fields' => ['access_token', 'experience_id'],
    'icon' => 'notifications_active'
];

class ExpoProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://exp.host/--/api/v2/push/send';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($device_token, $to_name, $title, $message, $data = null, $badge = null, $sound = 'default') {
        try {
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
            error_log("Expo Push Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendPushNotification($deviceToken, $title, $message, $data = null, $badge = null, $sound = 'default') {
        $payload = [
            'to' => $deviceToken,
            'title' => $title,
            'body' => $message,
            'sound' => $sound
        ];

        if ($badge !== null) {
            $payload['badge'] = $badge;
        }

        if ($data !== null && is_array($data)) {
            $payload['data'] = $data;
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate'
        ];

        if (!empty($this->config['access_token'])) {
            $headers[] = 'Authorization: Bearer ' . $this->config['access_token'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([$payload]),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
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

        $responseData = json_decode($response, true);

        if ($httpCode === 200) {
            if (isset($responseData['data'][0]['status']) && $responseData['data'][0]['status'] === 'ok') {
                return [
                    'success' => true,
                    'message' => 'Push notification sent successfully via Expo',
                    'receipt_id' => $responseData['data'][0]['id'] ?? ''
                ];
            } else {
                $errorMsg = $responseData['data'][0]['message'] ?? 'Unknown error';
                return [
                    'success' => false,
                    'message' => 'Expo API error: ' . $errorMsg
                ];
            }
        } else {
            $errorMessage = $this->getExpoErrorMessage($httpCode, $responseData);
            return [
                'success' => false,
                'message' => "Expo API Error {$httpCode}: " . $errorMessage
            ];
        }
    }

    private function getExpoErrorMessage($httpCode, $responseData) {
        $commonErrors = [
            400 => 'Bad Request - Invalid push notification message',
            401 => 'Unauthorized - Invalid access token',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'Expo Server Error'
        ];

        if (isset($commonErrors[$httpCode])) {
            return $commonErrors[$httpCode];
        }

        if (isset($responseData['errors'][0]['code'])) {
            return $responseData['errors'][0]['code'] . ': ' . ($responseData['errors'][0]['message'] ?? '');
        }

        return 'Unknown Expo API error occurred';
    }

    public function testConnection() {
        try {
            // Expo doesn't require authentication for basic usage, but we can test with a dummy token
            $testToken = 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]';
            
            // Just test if we can make a request to the API
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json'
            ];

            if (!empty($this->config['access_token'])) {
                $headers[] = 'Authorization: Bearer ' . $this->config['access_token'];
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->baseUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([['to' => $testToken, 'title' => 'Test', 'body' => 'Test']]),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                throw new Exception('CURL Error: ' . $curlError);
            }

            if ($httpCode === 200) {
                return [
                    'success' => true,
                    'message' => 'Expo connection successful. API is reachable.'
                ];
            } else {
                throw new Exception('Expo API returned HTTP ' . $httpCode);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Expo connection test failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'ExpoProvider';
?>