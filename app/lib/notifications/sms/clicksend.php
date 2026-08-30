<?php
// $requirements = [
//     'name' => 'ClickSend',
//     'fields' => ['username', 'api_key', 'from_number'],
//     'icon' => 'sms'
// ];

class ClicksendProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://rest.clicksend.com/v3';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($to, $to_name, $message, $subject = null, $from_name = null) {
        try {
            if (empty($this->config['username']) || empty($this->config['api_key']) || empty($this->config['from_number'])) {
                throw new Exception('ClickSend configuration incomplete. Username, API Key, and From Number are required.');
            }

            $phone = $this->cleanPhoneNumber($to);
            $result = $this->sendSMS($phone, $message);

            if ($result['success']) {
                return true;
            } else {
                throw new Exception($result['message']);
            }

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("ClickSend SMS Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendSMS($to, $message) {
        $username = $this->config['username'];
        $apiKey = $this->config['api_key'];
        $from = $this->config['from_number'];
        
        $url = $this->baseUrl . '/sms/send';

        $postData = [
            'messages' => [
                [
                    'source' => 'php',
                    'from' => $from,
                    'body' => $message,
                    'to' => $to
                ]
            ]
        ];

        $auth = base64_encode($username . ':' . $apiKey);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . $auth
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
            if (isset($responseData['data']['messages'][0]['status']) && 
                $responseData['data']['messages'][0]['status'] === 'SUCCESS') {
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully via ClickSend',
                    'message_id' => $responseData['data']['messages'][0]['message_id'] ?? ''
                ];
            } else {
                $errorMsg = $responseData['data']['messages'][0]['status'] ?? 'Unknown error';
                return [
                    'success' => false,
                    'message' => 'ClickSend API error: ' . $errorMsg
                ];
            }
        } else {
            $errorMessage = $this->getClickSendErrorMessage($httpCode, $responseData);
            return [
                'success' => false,
                'message' => "ClickSend API Error {$httpCode}: " . $errorMessage
            ];
        }
    }

    private function cleanPhoneNumber($phone) {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        return $cleaned;
    }

    private function getClickSendErrorMessage($httpCode, $responseData) {
        $commonErrors = [
            400 => 'Bad Request - Invalid parameters',
            401 => 'Unauthorized - Invalid credentials',
            403 => 'Forbidden - Insufficient credits or access denied',
            404 => 'Not Found - Resource not found',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'ClickSend Server Error'
        ];

        if (isset($commonErrors[$httpCode])) {
            return $commonErrors[$httpCode];
        }

        if (isset($responseData['response_msg'])) {
            return $responseData['response_msg'];
        }

        return 'Unknown ClickSend API error occurred';
    }

    public function testConnection() {
        try {
            if (empty($this->config['username']) || empty($this->config['api_key'])) {
                throw new Exception('Username and API Key are required for testing connection');
            }

            $username = $this->config['username'];
            $apiKey = $this->config['api_key'];
            
            $url = $this->baseUrl . '/account';

            $auth = base64_encode($username . ':' . $apiKey);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . $auth
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
                $credits = $responseData['data']['credit']['sms'] ?? 0;
                
                return [
                    'success' => true,
                    'message' => 'ClickSend connection successful. SMS credits: ' . $credits
                ];
            } else {
                $errorMessage = $this->getClickSendErrorMessage($httpCode, json_decode($response, true));
                throw new Exception("HTTP {$httpCode}: " . $errorMessage);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'ClickSend connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'ClicksendProvider';
?>