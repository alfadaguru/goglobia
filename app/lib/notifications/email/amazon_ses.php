<?php
$requirements = [
    'name' => 'Amazon SES',
    'fields' => ['access_key', 'secret_key', 'region']
];

class Amazon_SESProvider {
    private $config;
    private $lastError;

    public function __construct($config) {
        $this->config = $config;
    }

    // Cheap, local completeness check (no network call) — used to warn admins
    // when they save an obviously incomplete configuration.
    public function isConfigured() {
        return !empty($this->config['access_key']) && !empty($this->config['secret_key']) && !empty($this->config['region']);
    }

    public function send($to_email, $to_name, $subject, $body_html, $body_text = null, $from_email = null, $from_name = null) {
        try {
            if (empty($this->config['access_key']) || empty($this->config['secret_key']) || empty($this->config['region'])) {
                throw new Exception('Amazon SES configuration incomplete. Please check access key, secret key and region.');
            }

            $body_text = $body_text ?? strip_tags($body_html);
            $from_email = $from_email ?? $this->config['from_email'] ?? 'noreply@example.com';
            $from_name = $from_name ?? $this->config['from_name'] ?? 'PHPTRAVELS';

            $result = $this->sendViaSES($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name);

            return $result['success'];

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Amazon SES Error: " . $e->getMessage());
            return false;
        }
    }

    private function sendViaSES($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name) {
        $accessKey = $this->config['access_key'];
        $secretKey = $this->config['secret_key'];
        $region = $this->config['region'];

        $host = "email.$region.amazonaws.com";
        $service = 'ses';
        $terminationString = 'aws4_request';
        $algorithm = 'AWS4-HMAC-SHA256';
        $canonicalURI = '/';

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $payload = [
            'Action' => 'SendEmail',
            'Destination.ToAddresses.member.1' => "$to_name <$to_email>",
            'Message.Body.Html.Data' => $body_html,
            'Message.Body.Text.Data' => $body_text,
            'Message.Subject.Data' => $subject,
            'Source' => "$from_name <$from_email>",
            'Version' => '2010-12-01'
        ];

        $canonicalQueryString = http_build_query($payload);

        $canonicalHeaders = "host:$host\nx-amz-date:$amzDate\n";
        $signedHeaders = 'host;x-amz-date';
        $payloadHash = hash('sha256', $canonicalQueryString);

        $canonicalRequest = "POST\n$canonicalURI\n$canonicalQueryString\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

        $credentialScope = "$dateStamp/$region/$service/$terminationString";
        $stringToSign = "$algorithm\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

        $signature = $this->calculateAWS4Signature($stringToSign, $secretKey, $dateStamp, $region, $service);

        $authorizationHeader = "$algorithm Credential=$accessKey/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

        $headers = [
            "Authorization: $authorizationHeader",
            "x-amz-date: $amzDate",
            "Content-Type: application/x-www-form-urlencoded"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://$host/");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $canonicalQueryString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            throw new Exception("cURL Error: " . $curlError);
        }

        $xml = simplexml_load_string($response);

        if ($httpCode === 200 && isset($xml->SendEmailResult->MessageId)) {
            return [
                'success' => true,
                'message' => 'Email sent successfully via Amazon SES',
                'message_id' => (string)$xml->SendEmailResult->MessageId
            ];
        } else {
            $errorMsg = isset($xml->Error->Message) ? (string)$xml->Error->Message : 'Unknown error occurred';
            throw new Exception("Amazon SES Error ($httpCode): " . $errorMsg);
        }
    }

    private function calculateAWS4Signature($stringToSign, $secretKey, $dateStamp, $region, $service) {
        $kSecret = 'AWS4' . $secretKey;
        $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    public function testConnection() {
        try {
            if (empty($this->config['access_key']) || empty($this->config['secret_key']) || empty($this->config['region'])) {
                throw new Exception('Access key, secret key and region are required');
            }

            $accessKey = $this->config['access_key'];
            $secretKey = $this->config['secret_key'];
            $region = $this->config['region'];

            $host = "email.$region.amazonaws.com";
            $service = 'ses';
            $terminationString = 'aws4_request';
            $algorithm = 'AWS4-HMAC-SHA256';
            $canonicalURI = '/';

            $amzDate = gmdate('Ymd\THis\Z');
            $dateStamp = gmdate('Ymd');

            $payload = [
                'Action' => 'GetSendQuota',
                'Version' => '2010-12-01'
            ];

            $canonicalQueryString = http_build_query($payload);

            $canonicalHeaders = "host:$host\nx-amz-date:$amzDate\n";
            $signedHeaders = 'host;x-amz-date';
            $payloadHash = hash('sha256', $canonicalQueryString);

            $canonicalRequest = "POST\n$canonicalURI\n$canonicalQueryString\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

            $credentialScope = "$dateStamp/$region/$service/$terminationString";
            $stringToSign = "$algorithm\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

            $signature = $this->calculateAWS4Signature($stringToSign, $secretKey, $dateStamp, $region, $service);

            $authorizationHeader = "$algorithm Credential=$accessKey/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

            $headers = [
                "Authorization: $authorizationHeader",
                "x-amz-date: $amzDate",
                "Content-Type: application/x-www-form-urlencoded"
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://$host/");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $canonicalQueryString);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                throw new Exception("Connection failed: " . $curlError);
            }

            $xml = simplexml_load_string($response);

            if ($httpCode === 200 && isset($xml->GetSendQuotaResult)) {
                return [
                    'success' => true,
                    'message' => 'Amazon SES connection successful - API access verified'
                ];
            } else {
                $errorMsg = isset($xml->Error->Message) ? (string)$xml->Error->Message : 'Authentication failed';
                throw new Exception("API Error ($httpCode): " . $errorMsg);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Amazon SES connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}
