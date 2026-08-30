<?php
// $requirements = [
//     'name' => 'Twilio WhatsApp',
//     'fields' => ['account_sid', 'auth_token', 'from_number'],
//     'icon' => 'call'
// ];

class TwillioProvider {
    private $config;
    private $lastError;
    private $baseUrl = 'https://api.twilio.com/2010-04-01';

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($to, $to_name, $message, $subject = null, $from_name = null) {
        try {
            if (empty($this->config['account_sid']) || empty($this->config['auth_token']) || empty($this->config['from_number'])) {
                throw new Exception('Twilio configuration incomplete. Account SID, Auth Token, and From number are required.');
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
            error_log("Twilio WhatsApp Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendWhatsAppMessage($to, $message) {
        $accountSid = $this->config['account_sid'];
        $authToken = $this->config['auth_token'];
        $from = $this->config['from_number'];
        
        if (!str_starts_with($from, 'whatsapp:')) {
            $from = 'whatsapp:' . $from;
        }
        
        if (!str_starts_with($to, 'whatsapp:')) {
            $to = 'whatsapp:' . $to;
        }

        $url = $this->baseUrl . "/Accounts/{$accountSid}/Messages.json";

        $postData = [
            'From' => $from,
            'To' => $to,
            'Body' => $message
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_USERPWD => "{$accountSid}:{$authToken}",
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

        if ($httpCode === 200 || $httpCode === 201) {
            if (isset($responseData['sid'])) {
                return [
                    'success' => true,
                    'message' => 'WhatsApp message sent successfully via Twilio',
                    'message_id' => $responseData['sid']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Unexpected response format from Twilio: ' . $response
                ];
            }
        } else {
            $errorMessage = $this->getTwilioErrorMessage($httpCode, $responseData);
            return [
                'success' => false,
                'message' => "Twilio API Error {$httpCode}: " . $errorMessage
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

    private function getTwilioErrorMessage($httpCode, $responseData) {
        $commonErrors = [
            400 => 'Bad Request - Invalid parameters',
            401 => 'Unauthorized - Invalid Account SID or Auth Token',
            403 => 'Forbidden - Your account lacks permissions to send WhatsApp messages',
            404 => 'Not Found - Resource not found',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'Twilio Server Error',
            503 => 'Service Unavailable'
        ];

        if (isset($commonErrors[$httpCode])) {
            return $commonErrors[$httpCode];
        }

        if (isset($responseData['message'])) {
            return $responseData['message'];
        }

        if (isset($responseData['code'])) {
            $twilioErrorCodes = [
                21211 => 'Invalid "To" phone number',
                21408 => 'Permission to send an SMS has not been enabled for the region',
                21610 => 'Cannot route to this number',
                30007 => 'Delivery failed - blocked by carrier',
            ];

            if (isset($twilioErrorCodes[$responseData['code']])) {
                return $twilioErrorCodes[$responseData['code']];
            }

            return 'Twilio Error ' . $responseData['code'];
        }

        return 'Unknown Twilio API error occurred';
    }

    public function testConnection() {
        try {
            if (empty($this->config['account_sid']) || empty($this->config['auth_token'])) {
                throw new Exception('Account SID and Auth Token are required for testing connection');
            }

            $accountSid = $this->config['account_sid'];
            $authToken = $this->config['auth_token'];
            
            $url = $this->baseUrl . "/Accounts/{$accountSid}.json";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERPWD => "{$accountSid}:{$authToken}",
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
                $accountStatus = $responseData['status'] ?? 'active';
                
                return [
                    'success' => true,
                    'message' => 'Twilio connection successful. Account status: ' . $accountStatus
                ];
            } else {
                $errorMessage = $this->getTwilioErrorMessage($httpCode, json_decode($response, true));
                throw new Exception("HTTP {$httpCode}: " . $errorMessage);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Twilio connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'TwillioProvider';
?>