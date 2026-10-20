<?php
// FILE: app/lib/notifications/email/resend.php
// Resend (resend.com) transactional email provider. Matches the provider contract used
// by the other email providers (postmark/sendgrid/smtp): a top-level $requirements array
// for admin auto-discovery + a {Provider}Provider class with isConfigured() / send() /
// sendWithAttachment() returning bool, plus getLastError()/getLastResponse() for the
// admin "test email" tool. Raw cURL (no SDK).
//
// API facts (verified against resend.com/docs, 2026-10-12):
//   POST https://api.resend.com/emails
//   Headers: Authorization: Bearer re_...  +  Content-Type: application/json
//   Body: from, to, subject, html, text, reply_to, cc, bcc, attachments[].
//   from MUST use a VERIFIED domain (or the onboarding@resend.dev test sender, which can
//   only email the account owner until a domain is verified).
//   Success: HTTP 2xx, body {"id": "..."}.  Error: {"name","message","statusCode"}.
//   Attachments: array of {content: base64, filename, content_type?}.

$requirements = [
    'name'   => 'Resend',
    // api_key       — the Bearer send key (re_...), rendered as a password input.
    // webhook_secret — the Svix signing secret (whsec_...) from the Resend dashboard
    //                  Webhooks page; used by /webhooks/resend/events to verify delivery
    //                  events for campaign analytics. Optional; also a password input
    //                  (field name contains "secret"). Both come from the admin UI.
    // from_email / from_name come from the global email sender settings, like the other
    // providers (injected by SENDEMAIL / sendEmailNotification).
    'fields' => ['api_key', 'webhook_secret'],
];

class ResendProvider
{
    private $config;
    private $lastError;
    private $lastResponse;

    public function __construct($config)
    {
        $this->config = is_array($config) ? $config : [];
    }

    /** Cheap local completeness check (no network) — warns admins on incomplete config. */
    public function isConfigured()
    {
        $key = trim((string) ($this->config['api_key'] ?? ''));
        return $key !== '' && strpos($key, 're_') === 0;
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    /**
     * Send an HTML email. Returns true on success, false on failure (error in
     * getLastError()). Signature matches the other providers exactly.
     */
    public function send($to_email, $to_name, $subject, $body_html, $body_text = null, $from_email = null, $from_name = null)
    {
        try {
            if (!$this->hasKey()) {
                throw new Exception('Resend configuration incomplete. Please enter your API key (starts with "re_").');
            }
            $payload = $this->basePayload($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name);
            return $this->dispatch($payload);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log('Resend Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send with a single file attachment (base64 inline per the Resend API).
     */
    public function sendWithAttachment($to_email, $to_name, $subject, $body_html, $body_text = null, $attachment_path = null, $from_email = null, $from_name = null)
    {
        try {
            if (!$this->hasKey()) {
                throw new Exception('Resend configuration incomplete. Please enter your API key (starts with "re_").');
            }
            $payload = $this->basePayload($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name);

            if ($attachment_path && is_file($attachment_path) && is_readable($attachment_path)) {
                $raw = @file_get_contents($attachment_path);
                if ($raw !== false) {
                    $payload['attachments'] = [[
                        'content'  => base64_encode($raw),
                        'filename' => basename($attachment_path),
                    ]];
                }
            }
            return $this->dispatch($payload);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log('Resend Error with Attachment: ' . $e->getMessage());
            return false;
        }
    }

    // ---- internals ---------------------------------------------------------

    private function hasKey()
    {
        return trim((string) ($this->config['api_key'] ?? '')) !== '';
    }

    /** Build the Resend JSON body from the standard send args + sender settings. */
    private function basePayload($to_email, $to_name, $subject, $body_html, $body_text, $from_email, $from_name)
    {
        $from_email = $from_email ?: ($this->config['from_email'] ?? 'onboarding@resend.dev');
        $from_name  = $from_name  ?: ($this->config['from_name']  ?? 'PHPTRAVELS');
        $body_text  = $body_text  ?: trim(strip_tags($body_html));

        // Resend accepts "Name <email>" or a bare address. Only wrap in "Name <...>" when
        // we have a name AND a plausible email, else send the bare from_email.
        $fromHeader = ($from_name !== '' && strpos((string) $from_email, '@') !== false)
            ? sprintf('%s <%s>', $from_name, $from_email)
            : (string) $from_email;

        // "To" similarly: Resend's `to` is an address (or array). A display name must be
        // embedded as "Name <email>".
        $toHeader = ($to_name !== '' && strpos((string) $to_email, '@') !== false)
            ? sprintf('%s <%s>', $to_name, $to_email)
            : (string) $to_email;

        $payload = [
            'from'    => $fromHeader,
            'to'      => [$toHeader],
            'subject' => (string) $subject,
            'html'    => (string) $body_html,
        ];
        if ($body_text !== '') { $payload['text'] = $body_text; }

        // Optional reply-to from config (note the underscore spelling the REST API wants).
        $replyTo = trim((string) ($this->config['reply_to'] ?? ''));
        if ($replyTo !== '') { $payload['reply_to'] = $replyTo; }

        return $payload;
    }

    /** POST the payload to Resend; set lastResponse/lastError; return bool. */
    private function dispatch(array $payload)
    {
        $apiKey   = trim((string) $this->config['api_key']);
        $endpoint = 'https://api.resend.com/emails';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        // Verify TLS — the other providers disable it, but there's no reason to here and
        // it's the safer default for a brand-new provider talking to a known CA host.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception('cURL Error: ' . $curlErr);
        }

        $data = json_decode((string) $response, true);

        // Any 2xx is success (docs show 200; don't hard-code a single code).
        if ($httpCode >= 200 && $httpCode < 300) {
            $this->lastResponse = [
                'message'    => 'Email sent successfully via Resend',
                'message_id' => is_array($data) ? ($data['id'] ?? null) : null,
            ];
            return true;
        }

        // Error shape: {"name","message","statusCode"}.
        $name = is_array($data) ? ($data['name'] ?? 'error') : 'error';
        $msg  = is_array($data) ? ($data['message'] ?? 'Unknown error') : 'Unknown error';
        throw new Exception("Resend API Error ($httpCode - $name): " . $msg);
    }
}
