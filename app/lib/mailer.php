<?php

class EmailService {
    private $smtp_host = '';
    private $smtp_port = 465; // SSL
    private $username = '';
    private $password = '';
    private $from_email = '';
    private $from_name = 'Training Management System';

    /**
     * Send email using SMTP
     */
    public function sendEmail($to_email, $to_name, $subject, $body_html, $body_text = null) {
        if (!$body_text) {
            $body_text = strip_tags($body_html);
        }

        try {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ]);

            $conn = stream_socket_client(
                "ssl://{$this->smtp_host}:{$this->smtp_port}",
                $errno,
                $errstr,
                20,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$conn) {
                throw new Exception("Connect failed: $errno $errstr");
            }

            $this->expect($conn, '220', 'greeting');
            // Derive the EHLO hostname from the sender address (else the server
            // host) instead of the hardcoded copy-paste leftover 'trainingplatform
            // .com', which advertised a wrong/foreign identity to the SMTP server
            // (hurts deliverability/SPF alignment and leaks a stale brand name).
            $domain = '';
            if (!empty($this->from_email) && strpos($this->from_email, '@') !== false) {
                $domain = trim(substr(strrchr($this->from_email, '@'), 1));
            }
            if ($domain === '') {
                $domain = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? php_uname('n'));
            }
            if ($domain === '' || $domain === null) { $domain = 'localhost'; }
            $this->cmd($conn, "EHLO $domain", '250', 'EHLO');

            $this->cmd($conn, "AUTH LOGIN", '334', 'AUTH LOGIN begin');
            $this->cmd($conn, base64_encode($this->username), '334', 'AUTH username');
            $this->cmd($conn, base64_encode($this->password), '235', 'AUTH password');

            $this->cmd($conn, "MAIL FROM:<{$this->from_email}>", '250', 'MAIL FROM');
            $this->cmd($conn, "RCPT TO:<$to_email>", '250', 'RCPT TO');
            $this->cmd($conn, "DATA", '354', 'DATA');

            $messageId = sprintf("<%s@%s>", bin2hex(random_bytes(8)), $domain);
            $date = date('r');
            $boundary = 'boundary_' . bin2hex(random_bytes(8));
            
            $headers = [
                "From: {$this->from_name} <{$this->from_email}>",
                "To: $to_name <$to_email>",
                "Subject: $subject",
                "Date: $date",
                "Message-ID: $messageId",
                "MIME-Version: 1.0",
                "Content-Type: multipart/alternative; boundary=\"$boundary\""
            ];

            $data = implode("\r\n", $headers) . "\r\n\r\n";
            
            // Text part
            $data .= "--$boundary\r\n";
            $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $data .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $data .= $body_text . "\r\n\r\n";
            
            // HTML part
            $data .= "--$boundary\r\n";
            $data .= "Content-Type: text/html; charset=UTF-8\r\n";
            $data .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $data .= $body_html . "\r\n\r\n";
            
            $data .= "--$boundary--\r\n";
            $data .= ".\r\n";

            fwrite($conn, $data);
            $this->expect($conn, '250', 'DATA end');

            $this->cmd($conn, "QUIT", '221', 'QUIT');
            fclose($conn);
            
            return true;

        } catch (Exception $e) {
            if (isset($conn) && is_resource($conn)) {
                @fwrite($conn, "QUIT\r\n");
                @fclose($conn);
            }
            error_log("Email send failed: " . $e->getMessage());
            return false;
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
        if (strpos($response, (string)$codePrefix) !== 0) {
            throw new Exception("[$step] Unexpected SMTP response. Wanted $codePrefix*, got: $response");
        }
        return $response;
    }

    private function cmd($conn, $command, $expectCode, $step) {
        fwrite($conn, $command . "\r\n");
        return $this->expect($conn, $expectCode, $step);
    }

    /**
     * Send email verification email
     */
    public function sendVerificationEmail($to_email, $to_name, $verification_token) {
        $verification_link = rtrim(root, '/') . "/verify-email?token=" . $verification_token;
        
        $subject = "Verify Your Email Address - Training Platform";
        
        $html_body = $this->getEmailTemplate([
            'title' => 'Verify Your Email Address',
            'greeting' => "Hello $to_name,",
            'message' => 'Welcome to our Training Platform! Please verify your email address to activate your account.',
            'button_text' => 'Verify Email Address',
            'button_link' => $verification_link,
            'additional_info' => 'This link will expire in 24 hours. If you didn\'t create an account, please ignore this email.',
            'footer' => 'Thank you for joining our Training Platform!'
        ]);

        return $this->sendEmail($to_email, $to_name, $subject, $html_body);
    }

    /**
     * Send forgot password email
     */
    public function sendForgotPasswordEmail($to_email, $to_name, $temporary_password) {
        $subject = "Your Temporary Password - Training Platform";
        
        $html_body = $this->getEmailTemplate([
            'title' => 'Temporary Password',
            'greeting' => "Hello $to_name,",
            'message' => 'You requested a password reset. Here is your temporary password:',
            'temp_password' => $temporary_password,
            'additional_info' => 'Please login with this temporary password and change it from your profile settings. This temporary password will expire in 24 hours.',
            'footer' => 'If you didn\'t request this, please contact our support team immediately.'
        ]);

        return $this->sendEmail($to_email, $to_name, $subject, $html_body);
    }

    /**
     * Email template generator
     */
    private function getEmailTemplate($data) {
        $temp_password_section = '';
        if (isset($data['temp_password'])) {
            $temp_password_section = '
            <div style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center;">
                <h3 style="color: #495057; margin: 0 0 10px 0;">Your Temporary Password</h3>
                <div style="font-family: monospace; font-size: 18px; font-weight: bold; color: #dc3545; background: white; padding: 10px; border-radius: 4px; display: inline-block;">
                    ' . htmlspecialchars($data['temp_password']) . '
                </div>
            </div>';
        }

        $button_section = '';
        if (isset($data['button_text']) && isset($data['button_link'])) {
            $button_section = '
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $data['button_link'] . '" style="background: #007bff; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block; font-size: 16px;">
                    ' . $data['button_text'] . '
                </a>
            </div>';
        }

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . $data['title'] . '</title>
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4;">
            <div style="max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.1);">
                <!-- Header -->
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center;">
                    <h1 style="margin: 0; font-size: 28px;">' . $data['title'] . '</h1>
                </div>
                
                <!-- Content -->
                <div style="padding: 40px;">
                    <p style="font-size: 18px; margin-bottom: 20px;">' . $data['greeting'] . '</p>
                    
                    <p style="font-size: 16px; margin-bottom: 20px;">' . $data['message'] . '</p>
                    
                    ' . $temp_password_section . '
                    ' . $button_section . '
                    
                    <p style="font-size: 14px; color: #666; margin-top: 30px;">' . $data['additional_info'] . '</p>
                    
                    <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
                    
                    <p style="font-size: 14px; color: #888;">' . $data['footer'] . '</p>
                </div>
                
                <!-- Footer -->
                <div style="background: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #eee;">
                    <p style="margin: 0; font-size: 12px; color: #666;">
                        © ' . date('Y') . ' Training Platform. All rights reserved.
                    </p>
                </div>
            </div>
        </body>
        </html>';
    }
}

// Legacy function for backward compatibility
function mailer() {
    $emailService = new EmailService();
    return $emailService->sendEmail(
        'compoxition@gmail.com',
        'Test Recipient',
        'SMTP Core Test',
        '<h1>Test Email</h1><p>This is a test email sent using the new EmailService class.</p>',
        'This is a test email sent using the new EmailService class.'
    );
}