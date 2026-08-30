<?php
// $requirements = [
//     'name' => 'WhatsApp Business API',
//     'fields' => ['phone_number_id', 'access_token', 'verify_token', 'app_secret'],
//     'icon' => 'call'
// ];

class OfficialProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://graph.facebook.com/v17.0';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($to, $to_name, $message, $subject = null, $from_name = null) {
        try {
            if (empty($this->config['phone_number_id']) || empty($this->config['access_token'])) {
                throw new Exception('WhatsApp Business API configuration incomplete. Phone Number ID and Access Token are required.');
            }

            $phone = $this->cleanPhoneNumber($to);
            $result = $this->sendWhatsAppMessage($phone, $message);

            if ($result['success']) {
                return true;
            } else {
                throw new Exception($result['message']);
            }

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("WhatsApp Business API Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendWhatsAppMessage($to, $message) {
        $phoneNumberId = $this->config['phone_number_id'];
        $accessToken = $this->config['access_token'];
        
        $url = $this->baseUrl . "/{$phoneNumberId}/messages";

        $postData = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'text' => ['body' => $message]
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
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
            if (isset($responseData['messages'][0]['id'])) {
                return [
                    'success' => true,
                    'message' => 'WhatsApp message sent successfully via Business API',
                    'message_id' => $responseData['messages'][0]['id']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Unexpected response format from WhatsApp Business API'
                ];
            }
        } else {
            $errorMessage = $this->getBusinessApiErrorMessage($httpCode, $responseData);
            return [
                'success' => false,
                'message' => "WhatsApp Business API Error {$httpCode}: " . $errorMessage
            ];
        }
    }

    private function cleanPhoneNumber($phone) {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        if (strpos($cleaned, '+') !== 0) {
            $cleaned = '+' . $cleaned;
        }
        return $cleaned;
    }

    private function getBusinessApiErrorMessage($httpCode, $responseData) {
        $commonErrors = [
            400 => 'Bad Request - Invalid parameters',
            401 => 'Unauthorized - Invalid Access Token',
            403 => 'Forbidden - Permission denied',
            404 => 'Not Found - Phone number ID not found',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'Facebook Server Error'
        ];

        if (isset($commonErrors[$httpCode])) {
            return $commonErrors[$httpCode];
        }

        if (isset($responseData['error']['message'])) {
            return $responseData['error']['message'];
        }

        if (isset($responseData['error']['error_user_msg'])) {
            return $responseData['error']['error_user_msg'];
        }

        return 'Unknown WhatsApp Business API error occurred';
    }

    public function testConnection() {
        try {
            if (empty($this->config['phone_number_id']) || empty($this->config['access_token'])) {
                throw new Exception('Phone Number ID and Access Token are required for testing connection');
            }

            $phoneNumberId = $this->config['phone_number_id'];
            $accessToken = $this->config['access_token'];
            
            $url = $this->baseUrl . "/{$phoneNumberId}?fields=verified_name,quality_rating";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken
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
                $verifiedName = $responseData['verified_name'] ?? 'Unknown';
                
                return [
                    'success' => true,
                    'message' => 'WhatsApp Business API connection successful. Business: ' . $verifiedName
                ];
            } else {
                $errorMessage = $this->getBusinessApiErrorMessage($httpCode, json_decode($response, true));
                throw new Exception("HTTP {$httpCode}: " . $errorMessage);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'WhatsApp Business API connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'OfficialProvider';
?>