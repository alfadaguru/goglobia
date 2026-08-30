<?php
$requirements = [
    'name' => 'Mailchimp',
    'fields' => ['api_key', 'datacenter']
];

class MailchimpProvider {
    private $config;
    private $lastError;

    public function __construct($config) {
        $this->config = $config;
    }

    // Cheap, local completeness check (no network call) — used to warn admins
    // when they save an obviously incomplete configuration.
    public function isConfigured() {
        return !empty($this->config['api_key']) && !empty($this->config['datacenter']);
    }

    public function send($to_email, $to_name, $subject, $body_html, $body_text = null, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['api_key']) || empty($this->config['datacenter'])) {
                throw new Exception('Mailchimp configuration incomplete. Please check API key and datacenter.');
            }

            $body_text = $body_text ?? strip_tags($body_html);
            $from_email = $from_email ?? $this->config['from_email'] ?? 'noreply@example.com';
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';

            $result = $this->sendViaMailchimp($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name);
            
            return $result['success'];

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Mailchimp Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendViaMailchimp($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name) {
        $apiKey = $this->config['api_key'];
        $datacenter = $this->config['datacenter'];
        
        $endpoint = "https://{$datacenter}.api.mailchimp.com/3.0/messages/send";
        
        $messageData = [
            'key' => $apiKey,
            'message' => [
                'html' => $body_html,
                'text' => $body_text,
                'subject' => $subject,
                'from_email' => $from_email,
                'from_name' => $from_name,
                'to' => [
                    [
                        'email' => $to_email,
                        'name' => $to_name,
                        'type' => 'to'
                    ]
                ],
                'important' => false,
                'track_opens' => true,
                'track_clicks' => true,
                'auto_text' => true,
                'auto_html' => false,
                'inline_css' => true,
                'url_strip_qs' => true,
                'preserve_recipients' => false,
                'view_content_link' => false
            ],
            'async' => false,
            'ip_pool' => 'Main Pool',
            'send_at' => date('c')
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messageData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            throw new Exception("cURL Error: " . $curlError);
        }

        $responseData = json_decode($response, true);

        if ($httpCode === 200 && isset($responseData[0]['_id'])) {
            return [
                'success' => true,
                'message' => 'Email sent successfully via Mailchimp',
                'message_id' => $responseData[0]['_id'],
                'status' => $responseData[0]['status'] ?? 'sent'
            ];
        } else {
            $errorMsg = $responseData['message'] ?? $responseData['error'] ?? 'Unknown error occurred';
            throw new Exception("Mailchimp API Error ($httpCode): " . $errorMsg);
        }
    }

    private function sendViaMailchimpMarketing($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name) {
        $apiKey = $this->config['api_key'];
        $datacenter = $this->config['datacenter'];
        $listId = $this->config['list_id'] ?? '';
        
        if (empty($listId)) {
            throw new Exception('List ID is required for Mailchimp Marketing API');
        }

        $endpoint = "https://{$datacenter}.api.mailchimp.com/3.0/campaigns";
        
        $campaignData = [
            'type' => 'regular',
            'recipients' => [
                'list_id' => $listId
            ],
            'settings' => [
                'subject_line' => $subject,
                'from_name' => $from_name,
                'reply_to' => $from_email
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($campaignData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: apikey ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $campaignResponse = json_decode($response, true);

        if ($httpCode !== 200 || !isset($campaignResponse['id'])) {
            $errorMsg = $campaignResponse['detail'] ?? 'Failed to create campaign';
            throw new Exception("Campaign creation failed: " . $errorMsg);
        }

        $campaignId = $campaignResponse['id'];

        $contentEndpoint = "https://{$datacenter}.api.mailchimp.com/3.0/campaigns/{$campaignId}/content";
        $contentData = [
            'html' => $body_html
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $contentEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($contentData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: apikey ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentResponse = json_decode($response, true);

        if ($httpCode !== 200) {
            throw new Exception("Failed to set campaign content");
        }

        $sendEndpoint = "https://{$datacenter}.api.mailchimp.com/3.0/campaigns/{$campaignId}/actions/test";
        $sendData = [
            'test_emails' => [$to_email],
            'send_type' => 'html'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $sendEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sendData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: apikey ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $sendResponse = json_decode($response, true);

        if ($httpCode === 204) {
            return [
                'success' => true,
                'message' => 'Test email sent successfully via Mailchimp Marketing API',
                'campaign_id' => $campaignId
            ];
        } else {
            $errorMsg = $sendResponse['detail'] ?? 'Failed to send test email';
            throw new Exception("Send test failed: " . $errorMsg);
        }
    }

    public function testConnection() {
        try {
            if (empty($this->config['api_key']) || empty($this->config['datacenter'])) {
                throw new Exception('API key and datacenter are required');
            }

            $apiKey = $this->config['api_key'];
            $datacenter = $this->config['datacenter'];
            
            $endpoint = "https://{$datacenter}.api.mailchimp.com/3.0/users/ping";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['key' => $apiKey]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                throw new Exception("Connection failed: " . $curlError);
            }

            $responseData = json_decode($response, true);

            if ($httpCode === 200 && $responseData === 'PONG!') {
                return [
                    'success' => true,
                    'message' => 'Mailchimp Transactional API connection successful'
                ];
            } else {
                return $this->testMarketingAPI();
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Mailchimp connection failed: ' . $e->getMessage()
            ];
        }
    }

    private function testMarketingAPI() {
        try {
            $apiKey = $this->config['api_key'];
            $datacenter = $this->config['datacenter'];
            
            $endpoint = "https://{$datacenter}.api.mailchimp.com/3.0/ping";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: apikey ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                throw new Exception("Marketing API connection failed: " . $curlError);
            }

            $responseData = json_decode($response, true);

            if ($httpCode === 200 && isset($responseData['health_status'])) {
                return [
                    'success' => true,
                    'message' => 'Mailchimp Marketing API connection successful - Health: ' . $responseData['health_status']
                ];
            } else {
                $errorMsg = $responseData['detail'] ?? 'Authentication failed';
                throw new Exception("Marketing API Error ($httpCode): " . $errorMsg);
            }

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getAccountInfo() {
        try {
            $apiKey = $this->config['api_key'];
            $datacenter = $this->config['datacenter'];
            
            $endpoint = "https://{$datacenter}.api.mailchimp.com/3.0/";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: apikey ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $accountInfo = json_decode($response, true);

            if ($httpCode === 200) {
                return $accountInfo;
            } else {
                throw new Exception("Failed to get account info");
            }

        } catch (Exception $e) {
            return null;
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'MailchimpProvider';
?>