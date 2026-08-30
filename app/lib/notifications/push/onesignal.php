<?php
$requirements = [
    'name' => 'OneSignal',
    'fields' => ['app_id', 'api_key', 'user_auth_key'],
    'icon' => 'notifications_active'
];

class OnesignalProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://onesignal.com/api/v1';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($device_token, $to_name, $title, $message, $data = null, $badge = null, $sound = 'default') {
        try {
            if (empty($this->config['app_id']) || empty($this->config['api_key'])) {
                throw new Exception('OneSignal configuration incomplete. App ID and API Key are required.');
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
            error_log("OneSignal Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendPushNotification($deviceToken, $title, $message, $data = null, $badge = null, $sound = 'default') {
        $appId = $this->config['app_id'];
        $apiKey = $this->config['api_key'];
        
        $url = $this->baseUrl . '/notifications';

        $payload = [
            'app_id' => $appId,
            'include_player_ids' => [$deviceToken],
            'headings' => ['en' => $title],
            'contents' => ['en' => $message],
            'sound' => $sound
        ];

        if ($badge !== null) {
            $payload['ios_badgeType'] = 'SetTo';
            $payload['ios_badgeCount'] = $badge;
        }

        if ($data !== null && is_array($data)) {
            $payload['data'] = $data;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Basic ' . $apiKey
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
            if (isset($responseData['id'])) {
                return [
                    'success' => true,
                    'message' => 'Push notification sent successfully via OneSignal',
                    'notification_id' => $responseData['id'],
                    'recipients' => $responseData['recipients'] ?? 1
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Unexpected response format from OneSignal'
                ];
            }
        } else {
            $errorMessage = $this->getOneSignalErrorMessage($httpCode, $responseData);
            return [
                'success' => false,
                'message' => "OneSignal API Error {$httpCode}: " . $errorMessage
            ];
        }
    }

    private function getOneSignalErrorMessage($httpCode, $responseData) {
        $commonErrors = [
            400 => 'Bad Request - Invalid parameters',
            401 => 'Unauthorized - Invalid API Key',
            403 => 'Forbidden - Access denied',
            404 => 'Not Found - App not found',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'OneSignal Server Error'
        ];

        if (isset($commonErrors[$httpCode])) {
            return $commonErrors[$httpCode];
        }

        if (isset($responseData['errors'])) {
            if (is_array($responseData['errors'])) {
                return implode(', ', $responseData['errors']);
            } else {
                return $responseData['errors'];
            }
        }

        return 'Unknown OneSignal API error occurred';
    }

    public function testConnection() {
        try {
            if (empty($this->config['app_id']) || empty($this->config['api_key'])) {
                throw new Exception('App ID and API Key are required for testing connection');
            }

            $appId = $this->config['app_id'];
            $apiKey = $this->config['api_key'];
            
            $url = $this->baseUrl . "/apps/{$appId}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json; charset=utf-8',
                    'Authorization: Basic ' . $apiKey
                ],
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                throw new Exception('CURL Error: ' . $curlError);
            }

            if ($httpCode === 200) {
                $responseData = json_decode($response, true);
                $appName = $responseData['name'] ?? 'Unknown';
                
                return [
                    'success' => true,
                    'message' => 'OneSignal connection successful. App: ' . $appName
                ];
            } else {
                $errorMessage = $this->getOneSignalErrorMessage($httpCode, json_decode($response, true));
                throw new Exception("HTTP {$httpCode}: " . $errorMessage);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'OneSignal connection test failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'OnesignalProvider';
?>