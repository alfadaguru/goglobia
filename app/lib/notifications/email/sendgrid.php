<?php
$requirements = [
    'name' => 'SendGrid',
    'fields' => ['api_key', 'from_email', 'from_name']
];

class SendGridProvider {
    private $config;
    private $lastError;

    public function __construct($config) {
        $this->config = $config;
    }

    // Cheap, local completeness check (no network call) — used to warn admins
    // when they save an obviously incomplete configuration.
    public function isConfigured() {
        return !empty($this->config['api_key']);
    }

    public function send($to_email, $to_name, $subject, $body_html, $body_text = null, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['api_key'])) {
                throw new Exception('SendGrid configuration incomplete. Please check API key.');
            }

            $body_text = $body_text ?? strip_tags($body_html);
            $from_email = $from_email ?? $this->config['from_email'] ?? 'noreply@example.com';
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';

            $result = $this->sendViaSendGrid($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name);
            
            return $result['success'];

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("SendGrid Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendViaSendGrid($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name) {
        $apiKey = $this->config['api_key'];
        
        $endpoint = "https://api.sendgrid.com/v3/mail/send";
        
        $emailData = [
            'personalizations' => [
                [
                    'to' => [
                        [
                            'email' => $to_email,
                            'name' => $to_name
                        ]
                    ],
                    'subject' => $subject
                ]
            ],
            'from' => [
                'email' => $from_email,
                'name' => $from_name
            ],
            'content' => [
                [
                    'type' => 'text/html',
                    'value' => $body_html
                ],
                [
                    'type' => 'text/plain',
                    'value' => $body_text
                ]
            ],
            'tracking_settings' => [
                'click_tracking' => [
                    'enable' => true,
                    'enable_text' => true
                ],
                'open_tracking' => [
                    'enable' => true
                ]
            ],
            'mail_settings' => [
                'sandbox_mode' => [
                    'enable' => false
                ]
            ]
        ];

        if (isset($this->config['categories'])) {
            $emailData['categories'] = is_array($this->config['categories']) 
                ? $this->config['categories'] 
                : [$this->config['categories']];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
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

        if ($httpCode === 202) {
            return [
                'success' => true,
                'message' => 'Email sent successfully via SendGrid',
                'http_code' => $httpCode
            ];
        } else {
            $responseData = json_decode($response, true);
            $errorMsg = $this->parseSendGridError($responseData);
            throw new Exception("SendGrid API Error ($httpCode): " . $errorMsg);
        }
    }

    private function parseSendGridError($responseData) {
        if (isset($responseData['errors']) && is_array($responseData['errors'])) {
            $errors = [];
            foreach ($responseData['errors'] as $error) {
                $errors[] = $error['message'] ?? 'Unknown error';
            }
            return implode(', ', $errors);
        }
        return $responseData['message'] ?? 'Unknown error occurred';
    }

    public function sendWithTemplate($to_email, $to_name, $templateId, $templateData, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['api_key'])) {
                throw new Exception('API key is required');
            }

            $apiKey = $this->config['api_key'];
            $from_email = $from_email ?? $this->config['from_email'] ?? 'noreply@example.com';
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';
            
            $endpoint = "https://api.sendgrid.com/v3/mail/send";
            
            $emailData = [
                'personalizations' => [
                    [
                        'to' => [
                            [
                                'email' => $to_email,
                                'name' => $to_name
                            ]
                        ],
                        'dynamic_template_data' => $templateData
                    ]
                ],
                'from' => [
                    'email' => $from_email,
                    'name' => $from_name
                ],
                'template_id' => $templateId,
                'tracking_settings' => [
                    'click_tracking' => ['enable' => true],
                    'open_tracking' => ['enable' => true]
                ]
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                throw new Exception("cURL Error: " . $curlError);
            }

            if ($httpCode === 202) {
                return [
                    'success' => true,
                    'message' => 'Template email sent successfully via SendGrid'
                ];
            } else {
                $responseData = json_decode($response, true);
                $errorMsg = $this->parseSendGridError($responseData);
                throw new Exception("SendGrid Template Error ($httpCode): " . $errorMsg);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function sendBatch($emails) {
        try {
            $apiKey = $this->config['api_key'];
            
            $endpoint = "https://api.sendgrid.com/v3/mail/send";
            
            $batchData = [
                'personalizations' => [],
                'from' => [
                    'email' => $this->config['from_email'] ?? 'noreply@example.com',
                    'name' => $this->config['from_name'] ?? 'PHPTRAVELS'
                ],
                'content' => [
                    [
                        'type' => 'text/html',
                        'value' => ''
                    ]
                ]
            ];

            foreach ($emails as $email) {
                $personalization = [
                    'to' => [
                        [
                            'email' => $email['to_email'],
                            'name' => $email['to_name'] ?? $email['to_email']
                        ]
                    ],
                    'subject' => $email['subject']
                ];

                if (isset($email['body_html'])) {
                    $personalization['content'] = [
                        [
                            'type' => 'text/html',
                            'value' => $email['body_html']
                        ]
                    ];
                }

                $batchData['personalizations'][] = $personalization;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($batchData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseData = json_decode($response, true);

            if ($httpCode === 202) {
                return [
                    'success' => true,
                    'message' => 'Batch emails sent successfully via SendGrid'
                ];
            } else {
                $errorMsg = $this->parseSendGridError($responseData);
                throw new Exception("SendGrid Batch Error ($httpCode): " . $errorMsg);
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
            if (empty($this->config['api_key'])) {
                throw new Exception('API key is required');
            }

            $apiKey = $this->config['api_key'];
            
            $endpoint = "https://api.sendgrid.com/v3/user/account";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
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

            if ($httpCode === 200 && isset($responseData['type']) && $responseData['type'] === 'user') {
                return [
                    'success' => true,
                    'message' => 'SendGrid connection successful - Account: ' . $responseData['email'],
                    'account_info' => [
                        'email' => $responseData['email'],
                        'first_name' => $responseData['first_name'] ?? '',
                        'last_name' => $responseData['last_name'] ?? '',
                        'website' => $responseData['website'] ?? '',
                        'state' => $responseData['state'] ?? ''
                    ]
                ];
            } else {
                $errorMsg = $this->parseSendGridError($responseData);
                throw new Exception("API Error ($httpCode): " . $errorMsg);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'SendGrid connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getStats($startDate = null, $endDate = null) {
        try {
            $apiKey = $this->config['api_key'];
            
            $startDate = $startDate ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $endDate ?? date('Y-m-d');
            
            $endpoint = "https://api.sendgrid.com/v3/stats?start_date={$startDate}&end_date={$endDate}";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $statsData = json_decode($response, true);

            if ($httpCode === 200) {
                return $statsData;
            } else {
                throw new Exception("Failed to get statistics");
            }

        } catch (Exception $e) {
            return null;
        }
    }

    public function getTemplates() {
        try {
            $apiKey = $this->config['api_key'];
            
            $endpoint = "https://api.sendgrid.com/v3/templates?generations=dynamic";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $templatesData = json_decode($response, true);

            if ($httpCode === 200) {
                return $templatesData['result'] ?? [];
            } else {
                throw new Exception("Failed to get templates");
            }

        } catch (Exception $e) {
            return [];
        }
    }

    public function validateEmail($email) {
        try {
            $apiKey = $this->config['api_key'];
            
            $endpoint = "https://api.sendgrid.com/v3/validations/email";
            
            $validationData = [
                'email' => $email,
                'source' => 'php-travels'
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($validationData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $validationResult = json_decode($response, true);

            if ($httpCode === 200) {
                return [
                    'valid' => $validationResult['result']['verdict'] === 'Valid',
                    'score' => $validationResult['result']['score'] ?? 0,
                    'details' => $validationResult['result']
                ];
            } else {
                throw new Exception("Email validation failed");
            }

        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'SendGridProvider';
?>