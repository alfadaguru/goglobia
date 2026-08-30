<?php
$requirements = [
    'name' => 'Postmark',
    'fields' => ['server_token', 'message_stream']
];

class PostmarkProvider {
    private $config;
    private $lastError;

    public function __construct($config) {
        $this->config = $config;
    }

    // Cheap, local completeness check (no network call) — used to warn admins
    // when they save an obviously incomplete configuration.
    public function isConfigured() {
        return !empty($this->config['server_token']);
    }

    public function send($to_email, $to_name, $subject, $body_html, $body_text = null, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['server_token'])) {
                throw new Exception('Postmark configuration incomplete. Please check server token.');
            }

            $body_text = $body_text ?? strip_tags($body_html);
            $from_email = $from_email ?? $this->config['from_email'] ?? 'noreply@example.com';
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';

            $result = $this->sendViaPostmark($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name);
            
            return $result['success'];

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Postmark Error: " . $e->getMessage());
            return false;
        }
    }

    public function sendWithAttachment($to_email, $to_name, $subject, $body_html, $body_text = null, $attachment_path = null, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['server_token'])) {
                throw new Exception('Postmark configuration incomplete. Please check server token.');
            }

            $body_text = $body_text ?? strip_tags($body_html);
            $from_email = $from_email ?? $this->config['from_email'] ?? 'noreply@example.com';
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';

            $result = $this->sendViaPostmarkWithAttachment($to_email, $to_name, $subject, $body_html, $body_text, $attachment_path, $from_email, $from_name);
            
            return $result['success'];

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Postmark Error with Attachment: " . $e->getMessage());
            return false;
        }
    }

    private function sendViaPostmark($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name) {
        $serverToken = $this->config['server_token'];
        $messageStream = $this->config['message_stream'] ?? 'outbound';
        
        $endpoint = "https://api.postmarkapp.com/email";
        
        $emailData = [
            'From' => "$from_name <$from_email>",
            'To' => "$to_name <$to_email>",
            'Subject' => $subject,
            'HtmlBody' => $body_html,
            'TextBody' => $body_text,
            'MessageStream' => $messageStream,
            'TrackOpens' => true,
            'TrackLinks' => 'HtmlAndText'
        ];

        $emailData['Headers'] = [
            [
                'Name' => 'X-PHP-Travels',
                'Value' => '1.0'
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Postmark-Server-Token: ' . $serverToken
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

        if ($httpCode === 200) {
            return [
                'success' => true,
                'message' => 'Email sent successfully via Postmark',
                'message_id' => $responseData['MessageID'],
                'submitted_at' => $responseData['SubmittedAt'],
                'to' => $responseData['To']
            ];
        } else {
            $errorCode = $responseData['ErrorCode'] ?? 'Unknown';
            $errorMsg = $responseData['Message'] ?? 'Unknown error occurred';
            throw new Exception("Postmark API Error ($httpCode - $errorCode): " . $errorMsg);
        }
    }

    private function sendViaPostmarkWithAttachment($to_email, $to_name, $subject, $body_html, $body_text, $attachment_path, $from_email, $from_name) {
        $serverToken = $this->config['server_token'];
        $messageStream = $this->config['message_stream'] ?? 'outbound';
        
        $endpoint = "https://api.postmarkapp.com/email";
        
        $emailData = [
            'From' => "$from_name <$from_email>",
            'To' => "$to_name <$to_email>",
            'Subject' => $subject,
            'HtmlBody' => $body_html,
            'TextBody' => $body_text,
            'MessageStream' => $messageStream,
            'TrackOpens' => true,
            'TrackLinks' => 'HtmlAndText'
        ];

        // Add attachment if file exists
        if ($attachment_path && file_exists($attachment_path)) {
            $fileContent = file_get_contents($attachment_path);
            $base64Content = base64_encode($fileContent);
            $fileName = basename($attachment_path);
            
            // Determine content type
            $contentType = 'application/pdf';
            if (pathinfo($attachment_path, PATHINFO_EXTENSION) === 'pdf') {
                $contentType = 'application/pdf';
            }
            
            $emailData['Attachments'] = [
                [
                    'Name' => $fileName,
                    'Content' => $base64Content,
                    'ContentType' => $contentType
                ]
            ];
        }

        $emailData['Headers'] = [
            [
                'Name' => 'X-PHP-Travels',
                'Value' => '1.0'
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Postmark-Server-Token: ' . $serverToken
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            throw new Exception("cURL Error: " . $curlError);
        }

        $responseData = json_decode($response, true);

        if ($httpCode === 200) {
            return [
                'success' => true,
                'message' => 'Email with attachment sent successfully via Postmark',
                'message_id' => $responseData['MessageID'],
                'submitted_at' => $responseData['SubmittedAt'],
                'to' => $responseData['To']
            ];
        } else {
            $errorCode = $responseData['ErrorCode'] ?? 'Unknown';
            $errorMsg = $responseData['Message'] ?? 'Unknown error occurred';
            throw new Exception("Postmark API Error ($httpCode - $errorCode): " . $errorMsg);
        }
    }

    private function sendBatchViaPostmark($emails) {
        $serverToken = $this->config['server_token'];
        $messageStream = $this->config['message_stream'] ?? 'outbound';
        
        $endpoint = "https://api.postmarkapp.com/email/batch";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emails));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Postmark-Server-Token: ' . $serverToken
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseData = json_decode($response, true);

        return [
            'success' => $httpCode === 200,
            'data' => $responseData,
            'http_code' => $httpCode
        ];
    }

    public function sendWithTemplate($to_email, $to_name, $templateId, $templateModel, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['server_token'])) {
                throw new Exception('Server token is required');
            }

            $serverToken = $this->config['server_token'];
            $messageStream = $this->config['message_stream'] ?? 'outbound';
            $from_email = $from_email ?? $this->config['from_email'] ?? 'noreply@example.com';
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';
            
            $endpoint = "https://api.postmarkapp.com/email/withTemplate";
            
            $emailData = [
                'From' => "$from_name <$from_email>",
                'To' => "$to_name <$to_email>",
                'TemplateId' => $templateId,
                'TemplateModel' => $templateModel,
                'MessageStream' => $messageStream,
                'TrackOpens' => true
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Postmark-Server-Token: ' . $serverToken
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseData = json_decode($response, true);

            if ($httpCode === 200) {
                return [
                    'success' => true,
                    'message' => 'Template email sent successfully via Postmark',
                    'message_id' => $responseData['MessageID']
                ];
            } else {
                $errorCode = $responseData['ErrorCode'] ?? 'Unknown';
                $errorMsg = $responseData['Message'] ?? 'Unknown error occurred';
                throw new Exception("Postmark Template Error ($httpCode - $errorCode): " . $errorMsg);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function testConnection() {
        try {
            if (empty($this->config['server_token'])) {
                throw new Exception('Server token is required');
            }

            $serverToken = $this->config['server_token'];
            
            $endpoint = "https://api.postmarkapp.com/server";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Postmark-Server-Token: ' . $serverToken
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

            if ($httpCode === 200 && isset($responseData['ID'])) {
                return [
                    'success' => true,
                    'message' => 'Postmark connection successful - Server: ' . $responseData['Name'],
                    'server_info' => [
                        'name' => $responseData['Name'],
                        'id' => $responseData['ID'],
                        'api_tokens' => $responseData['ApiTokens'] ?? [],
                        'color' => $responseData['Color'] ?? 'default'
                    ]
                ];
            } else {
                $errorCode = $responseData['ErrorCode'] ?? 'Unknown';
                $errorMsg = $responseData['Message'] ?? 'Authentication failed';
                throw new Exception("API Error ($httpCode - $errorCode): " . $errorMsg);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Postmark connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getDeliveryStats() {
        try {
            $serverToken = $this->config['server_token'];
            
            $endpoint = "https://api.postmarkapp.com/deliverystats";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Postmark-Server-Token: ' . $serverToken
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $statsData = json_decode($response, true);

            if ($httpCode === 200) {
                return $statsData;
            } else {
                throw new Exception("Failed to get delivery stats");
            }

        } catch (Exception $e) {
            return null;
        }
    }

    public function getBounces($count = 100) {
        try {
            $serverToken = $this->config['server_token'];
            
            $endpoint = "https://api.postmarkapp.com/bounces?count=" . $count;
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Postmark-Server-Token: ' . $serverToken
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $bouncesData = json_decode($response, true);

            if ($httpCode === 200) {
                return $bouncesData;
            } else {
                throw new Exception("Failed to get bounces");
            }

        } catch (Exception $e) {
            return null;
        }
    }

    public function activateBounce($bounceId) {
        try {
            $serverToken = $this->config['server_token'];
            
            $endpoint = "https://api.postmarkapp.com/bounces/{$bounceId}/activate";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_PUT, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Postmark-Server-Token: ' . $serverToken
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseData = json_decode($response, true);

            return [
                'success' => $httpCode === 200,
                'data' => $responseData
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'PostmarkProvider';
?>