<?php
// $requirements = [
//     'name' => 'MessageBird',
//     'fields' => ['access_key', 'originator'],
//     'icon' => 'sms'
// ];

class MessagebirdProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://rest.messagebird.com';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($to, $to_name, $message, $subject = null, $from_name = null) {
        try {
            if (empty($this->config['access_key']) || empty($this->config['originator'])) {
                throw new Exception('MessageBird configuration incomplete. Access Key and Originator are required.');
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
            error_log("MessageBird SMS Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendSMS($to, $message) {
        $accessKey = $this->config['access_key'];
        $originator = $this->config['originator'];
        
        $url = $this->baseUrl . '/messages';

        $postData = [
            'recipients' => $to,
            'originator' => $originator,
            'body' => $message
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: AccessKey ' . $accessKey
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

        if ($httpCode === 201) {
            if (isset($responseData['id'])) {
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully via MessageBird',
                    'message_id' => $responseData['id']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Unexpected response format from MessageBird'
                ];
            }
        } else {
            $errorMessage = $this->getMessageBirdErrorMessage($httpCode, $responseData);
            return [
                'success' => false,
                'message' => "MessageBird API Error {$httpCode}: " . $errorMessage
            ];
        }
    }

    private function cleanPhoneNumber($phone) {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        return $cleaned;
    }

    private function getMessageBirdErrorMessage($httpCode, $responseData) {
        $commonErrors = [
            400 => 'Bad Request - Invalid parameters',
            401 => 'Unauthorized - Invalid Access Key',
            402 => 'Payment Required - Insufficient balance',
            403 => 'Forbidden - Access denied',
            404 => 'Not Found - Resource not found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity - Validation failed',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'MessageBird Server Error'
        ];

        if (isset($commonErrors[$httpCode])) {
            return $commonErrors[$httpCode];
        }

        if (isset($responseData['errors'])) {
            $errors = [];
            foreach ($responseData['errors'] as $error) {
                $errors[] = $error['description'] ?? $error['code'];
            }
            return implode(', ', $errors);
        }

        return 'Unknown MessageBird API error occurred';
    }

    public function testConnection() {
        try {
            if (empty($this->config['access_key'])) {
                throw new Exception('Access Key is required for testing connection');
            }

            $accessKey = $this->config['access_key'];
            
            $url = $this->baseUrl . '/balance';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: AccessKey ' . $accessKey
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
                $balance = $responseData['amount'] ?? 0;
                $currency = $responseData['currency'] ?? 'EUR';
                
                return [
                    'success' => true,
                    'message' => 'MessageBird connection successful. Balance: ' . $balance . ' ' . $currency
                ];
            } else {
                $errorMessage = $this->getMessageBirdErrorMessage($httpCode, json_decode($response, true));
                throw new Exception("HTTP {$httpCode}: " . $errorMessage);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'MessageBird connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'MessagebirdProvider';
?>