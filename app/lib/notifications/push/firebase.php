<?php
$requirements = [
    'name' => 'Firebase FCM',
    'fields' => ['server_key', 'sender_id', 'project_id'],
    'icon' => 'notifications_active'
];

class FirebaseProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://fcm.googleapis.com/fcm/send';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($device_token, $to_name, $title, $message, $data = null, $badge = null, $sound = 'default') {
        try {
            if (empty($this->config['server_key'])) {
                throw new Exception('Firebase configuration incomplete. Server Key is required.');
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
            error_log("Firebase FCM Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendPushNotification($deviceToken, $title, $message, $data = null, $badge = null, $sound = 'default') {
        $serverKey = $this->config['server_key'];

        $payload = [
            'to' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $message,
                'sound' => $sound
            ],
            'priority' => 'high'
        ];

        if ($badge !== null) {
            $payload['notification']['badge'] = $badge;
        }

        if ($data !== null && is_array($data)) {
            $payload['data'] = $data;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: key=' . $serverKey
            ],
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
            if ($responseData['success'] == 1) {
                return [
                    'success' => true,
                    'message' => 'Push notification sent successfully via Firebase FCM',
                    'message_id' => $responseData['results'][0]['message_id'] ?? ''
                ];
            } else {
                $errorMsg = $responseData['results'][0]['error'] ?? 'Unknown error';
                return [
                    'success' => false,
                    'message' => 'Firebase FCM error: ' . $errorMsg
                ];
            }
        } else {
            $errorMessage = $this->getFirebaseErrorMessage($httpCode, $responseData);
            return [
                'success' => false,
                'message' => "Firebase FCM Error {$httpCode}: " . $errorMessage
            ];
        }
    }

    private function getFirebaseErrorMessage($httpCode, $responseData) {
        $commonErrors = [
            400 => 'Bad Request - Invalid JSON or invalid fields',
            401 => 'Unauthorized - Invalid Server Key',
            403 => 'Forbidden - Project not found or disabled',
            500 => 'Firebase Server Error',
            503 => 'Service Unavailable'
        ];

        if (isset($commonErrors[$httpCode])) {
            return $commonErrors[$httpCode];
        }

        if (isset($responseData['error'])) {
            return $responseData['error']['message'] ?? $responseData['error'];
        }

        return 'Unknown Firebase FCM error occurred';
    }

    public function testConnection() {
        try {
            if (empty($this->config['server_key'])) {
                throw new Exception('Server Key is required for testing connection');
            }

            $serverKey = $this->config['server_key'];
            
            // Test authentication by making a simple request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->baseUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['to' => 'test', 'notification' => ['title' => 'Test', 'body' => 'Test']]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: key=' . $serverKey
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                throw new Exception('CURL Error: ' . $curlError);
            }

            if ($httpCode === 200 || $httpCode === 400) {
                // 400 is expected for invalid token, but it means authentication worked
                return [
                    'success' => true,
                    'message' => 'Firebase FCM connection successful. Server key is valid.'
                ];
            } else if ($httpCode === 401) {
                throw new Exception('Invalid server key');
            } else {
                throw new Exception('Firebase FCM API returned HTTP ' . $httpCode);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Firebase FCM connection test failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'FirebaseProvider';
?>