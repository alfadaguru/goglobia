<?php
$requirements = [
    'name' => 'SMTP',
    'fields' => ['host', 'port', 'username', 'password', 'security']
];

class SmtpProvider {
    private $config;
    private $lastError;

    public function __construct($config) {
        $this->config = $config;
    }

    // Cheap, local completeness check (no network call) — used to warn admins
    // when they save an obviously incomplete configuration.
    public function isConfigured() {
        return !empty($this->config['host']) && !empty($this->config['port'])
            && !empty($this->config['username']) && !empty($this->config['password']);
    }

    public function send($to_email, $to_name, $subject, $body_html, $body_text = null, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['host']) || empty($this->config['port']) || 
                empty($this->config['username']) || empty($this->config['password'])) {
                $this->lastError = 'SMTP configuration incomplete. Please check host, port, username, and password settings.';
                error_log("SMTP Error: " . $this->lastError);
                return false;
            }

            $body_text = $body_text ?? strip_tags($body_html);
            $from_email = $from_email ?? $this->config['from_email'] ?? $this->config['username'];
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';

            $result = $this->sendEmailDirect($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name);
            
            // Capture error message from result if send failed
            if (!$result['success']) {
                $this->lastError = $result['message'] ?? 'Unknown SMTP error occurred';
                error_log("SMTP Error: " . $this->lastError);
                return false;
            }
            
            return true;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("SMTP Error: " . $e->getMessage());
            return false;
        }
    }

    public function sendWithAttachment($to_email, $to_name, $subject, $body_html, $body_text = null, $attachment_path = null, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['host']) || empty($this->config['port']) || 
                empty($this->config['username']) || empty($this->config['password'])) {
                $this->lastError = 'SMTP configuration incomplete. Please check host, port, username, and password settings.';
                error_log("SMTP Error: " . $this->lastError);
                return false;
            }

            $body_text = $body_text ?? strip_tags($body_html);
            $from_email = $from_email ?? $this->config['from_email'] ?? $this->config['username'];
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';

            $result = $this->sendEmailDirect($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name, $attachment_path);
            
            // Capture error message from result if send failed
            if (!$result['success']) {
                $this->lastError = $result['message'] ?? 'Unknown SMTP error occurred';
                error_log("SMTP Error: " . $this->lastError);
                return false;
            }
            
            return true;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("SMTP Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendEmailDirect($to_email, $to_name, $subject, $body_html, $body_text = null, $from_email = null, $from_name = null, $attachment_path = null) {
        if (!$body_text) {
            $body_text = strip_tags($body_html);
        }

        $smtp_host = preg_replace('/^https?:\/\//i', '', $this->config['host']);
        $smtp_host = rtrim($smtp_host, '/');
        $smtp_port = $this->config['port'];
        $username = $this->config['username'];
        $password = $this->config['password'];
        
        $from_email = $from_email ?? $this->config['from_email'] ?? $username;
        $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';

        if (empty($from_email) || !filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid from email address: " . $from_email);
        }

        $security = strtolower($this->config['security'] ?? 'tls');
        
        // Port 587 uses plain connection + STARTTLS
        // Port 465 uses SSL from the start
        if ($smtp_port == 587) {
            $protocol = '';  // Plain connection, will upgrade with STARTTLS
            $use_starttls = true;
        } elseif ($smtp_port == 465) {
            $protocol = 'ssl://';
            $use_starttls = false;
        } else {
            // For other ports, respect the security setting
            $protocol = ($security === 'ssl') ? 'ssl://' : '';
            $use_starttls = ($security === 'tls');
        }

        try {
            // DEBUG LOG
            if ($attachment_path) {
            }

            // LOG: Connection attempt

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ]);

            $conn = @stream_socket_client(
                $protocol . $smtp_host . ":" . $smtp_port,
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$conn) {
                error_log("SMTP: Connection FAILED - Error Code: $errno, Error: $errstr");
                error_log("SMTP: Host: $smtp_host, Port: $smtp_port, Protocol: $protocol");
                throw new Exception("Connect failed: $errno $errstr");
            }
            

            stream_set_timeout($conn, 10);

            $this->expect($conn, '220', 'greeting');
            $domain = $this->extractDomain($from_email);
            $this->cmd($conn, "EHLO $domain", '250', 'EHLO');

            if ($use_starttls) {
                error_log("SMTP: Initiating STARTTLS...");
                $this->cmd($conn, "STARTTLS", '220', 'STARTTLS');
                
                $crypto_result = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$crypto_result) {
                    throw new Exception("STARTTLS failed: Could not enable TLS encryption");
                }
                
                $this->cmd($conn, "EHLO $domain", '250', 'EHLO after TLS');
            }

            $this->cmd($conn, "AUTH LOGIN", '334', 'AUTH LOGIN begin');
            $this->cmd($conn, base64_encode($username), '334', 'AUTH username');
            $this->cmd($conn, base64_encode($password), '235', 'AUTH password');

            $this->cmd($conn, "MAIL FROM:<{$from_email}>", '250', 'MAIL FROM');
            $this->cmd($conn, "RCPT TO:<$to_email>", '250', 'RCPT TO');
            $this->cmd($conn, "DATA", '354', 'DATA');

            $messageId = sprintf("<%s@%s>", bin2hex(random_bytes(8)), $domain);
            $date = date('r');
            
            // Mixed boundary for attachment
            $boundary_mixed = 'mixed_' . bin2hex(random_bytes(8));
            $boundary_alt = 'alt_' . bin2hex(random_bytes(8));
            
            $headers = [
                "From: {$from_name} <{$from_email}>",
                "To: $to_name <$to_email>",
                "Subject: $subject",
                "Date: $date",
                "Message-ID: $messageId",
                "MIME-Version: 1.0"
            ];

            if ($attachment_path && file_exists($attachment_path)) {
                $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary_mixed\"";
            } else {
                $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary_alt\"";
            }

            $data = implode("\r\n", $headers) . "\r\n\r\n";
            
            if ($attachment_path && file_exists($attachment_path)) {
                // Main body part (alternative)
                $data .= "--$boundary_mixed\r\n";
                $data .= "Content-Type: multipart/alternative; boundary=\"$boundary_alt\"\r\n\r\n";
            }
            
            $data .= "--$boundary_alt\r\n";
            $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $data .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $data .= $body_text . "\r\n\r\n";
            
            $data .= "--$boundary_alt\r\n";
            $data .= "Content-Type: text/html; charset=UTF-8\r\n";
            $data .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $data .= $body_html . "\r\n\r\n";
            
            $data .= "--$boundary_alt--\r\n\r\n";
            
            // Attachment part
            if ($attachment_path && file_exists($attachment_path)) {
                $filename = basename($attachment_path);
                $file_content = file_get_contents($attachment_path);
                $file_encoded = chunk_split(base64_encode($file_content));
                
                $data .= "--$boundary_mixed\r\n";
                $data .= "Content-Type: application/pdf; name=\"$filename\"\r\n";
                $data .= "Content-Transfer-Encoding: base64\r\n";
                $data .= "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n";
                $data .= $file_encoded . "\r\n";
                $data .= "--$boundary_mixed--\r\n";
            }
            
            $data .= ".\r\n";

            // Ensure all data is written to the socket
            $length = strlen($data);
            $written = 0;
            while ($written < $length) {
                $count = fwrite($conn, substr($data, $written));
                if ($count === false) {
                    throw new Exception("Failed to write to SMTP socket");
                }
                $written += $count;
            }
            
            $this->expect($conn, '250', 'DATA end');

            $this->cmd($conn, "QUIT", '221', 'QUIT');
            fclose($conn);
            
            return [
                'success' => true,
                'message' => 'Email sent successfully via SMTP'
            ];

        } catch (Exception $e) {
            if (isset($conn) && is_resource($conn)) {
                @fwrite($conn, "QUIT\r\n");
                @fclose($conn);
            }
            error_log("SMTP ERROR: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'SMTP Error: ' . $e->getMessage()
            ];
        }
    }

    private function expect($conn, $codePrefix, $step) {
        $response = '';
        while (!feof($conn)) {
            $line = fgets($conn, 515);
            if ($line === false) break;
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        
        // LOG: SMTP Response
        
        if (strpos($response, (string)$codePrefix) !== 0) {
            error_log("SMTP [$step] ERROR: Expected $codePrefix*, got: " . trim($response));
            throw new Exception("[$step] Unexpected SMTP response. Wanted $codePrefix*, got: $response");
        }
        return $response;
    }

    private function cmd($conn, $command, $expectCode, $step) {
        // LOG: SMTP Command (hide password)
        $logCommand = (strpos($step, 'password') !== false) ? '[PASSWORD HIDDEN]' : $command;
        
        fwrite($conn, $command . "\r\n");
        return $this->expect($conn, $expectCode, $step);
    }

    private function extractDomain($email) {
        $parts = explode('@', $email);
        return count($parts) === 2 ? $parts[1] : 'localhost';
    }

    public function testConnection() {
        try {
            $smtp_host = preg_replace('/^https?:\/\//i', '', $this->config['host']);
            $smtp_host = rtrim($smtp_host, '/');
            $smtp_port = $this->config['port'];
            $security = strtolower($this->config['security'] ?? 'tls');
            
            // Port 587 uses plain connection + STARTTLS
            // Port 465 uses SSL from the start
            if ($smtp_port == 587) {
                $protocol = '';  // Plain connection, will upgrade with STARTTLS
                $use_starttls = true;
            } elseif ($smtp_port == 465) {
                $protocol = 'ssl://';
                $use_starttls = false;
            } else {
                // For other ports, respect the security setting
                $protocol = ($security === 'ssl') ? 'ssl://' : '';
                $use_starttls = ($security === 'tls');
            }

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ]);

            $conn = stream_socket_client(
                $protocol . $smtp_host . ":" . $smtp_port,
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$conn) {
                throw new Exception("Connection failed: $errno $errstr");
            }

            $this->expect($conn, '220', 'greeting');
            
            $domain = $this->extractDomain($this->config['username'] ?? 'localhost');
            $this->cmd($conn, "EHLO $domain", '250', 'EHLO');

            if ($use_starttls) {
                $this->cmd($conn, "STARTTLS", '220', 'STARTTLS');
                
                $crypto_result = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$crypto_result) {
                    throw new Exception("STARTTLS failed: Could not enable TLS encryption");
                }
                
                $this->cmd($conn, "EHLO $domain", '250', 'EHLO after TLS');
            }

            $this->cmd($conn, "AUTH LOGIN", '334', 'AUTH LOGIN begin');
            $this->cmd($conn, base64_encode($this->config['username']), '334', 'AUTH username');
            $this->cmd($conn, base64_encode($this->config['password']), '235', 'AUTH password');

            $this->cmd($conn, "QUIT", '221', 'QUIT');
            fclose($conn);

            return [
                'success' => true,
                'message' => 'SMTP connection successful'
            ];

        } catch (Exception $e) {
            if (isset($conn) && is_resource($conn)) {
                @fwrite($conn, "QUIT\r\n");
                @fclose($conn);
            }
            return [
                'success' => false,
                'message' => 'SMTP connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'SmtpProvider';
?>