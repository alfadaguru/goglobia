<?php

namespace App\lib\ai;

/**
 * GIA tool: link THIS messaging chat to the traveller's GoGlobia account, so GIA
 * can act on their account (see their bookings, and — once Phase 3 is on — pay
 * from their wallet). Two actions:
 *
 *   action='start' + email  -> find that account, generate a one-time code, and
 *                              send it to the account's REGISTERED EMAIL (a
 *                              trusted side-channel). The code is NEVER shown in
 *                              chat, so only the real account owner can complete.
 *   action='confirm' + code -> verify the code the traveller types back; on
 *                              success the chat is bound to that account.
 *
 * Only meaningful on messaging channels (needs channel + channel_user_id in
 * context, set by the inbound spine). Risk R2: it links identity but moves no
 * money and exposes nothing until the code (sent out-of-band) is confirmed.
 */
class verifyIdentityTool implements giaToolInterface
{
    public function name(): string
    {
        return 'verify_identity';
    }

    public function description(): string
    {
        return 'Link this chat to the traveller\'s GoGlobia account so you can see their '
            . 'bookings and (later) pay from their wallet. Use action="start" with their '
            . 'account email to send a one-time code to that email; then action="confirm" '
            . 'with the code they read back. Use this when they ask about "my bookings", '
            . 'want to pay from their wallet, or ask you to act on their account.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['start', 'confirm'], 'description' => 'start = send a code to their email; confirm = check the code they typed.'],
                'email'  => ['type' => 'string', 'description' => 'Their GoGlobia account email (for action=start).'],
                'code'   => ['type' => 'string', 'description' => 'The one-time code they received (for action=confirm).'],
            ],
            'required' => ['action'],
        ];
    }

    public function riskClass(): string
    {
        return 'R2'; // links identity; no money; nothing exposed until out-of-band code confirmed
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        $channel = (string) ($context['channel'] ?? '');
        $channelUserId = (string) ($context['channel_user_id'] ?? '');
        if ($db === null || $channel === '' || $channelUserId === '') {
            return ['ok' => false, 'error_code' => 'AI_NO_CHANNEL', 'message' => 'Account linking is only available in a messaging chat.'];
        }
        // Ensure the identity helpers are loaded (the spine loads them, but be safe).
        if (!function_exists('gia_identity_begin_link')) {
            $f = dirname(__DIR__) . '/gia_identity.php';
            if (is_file($f)) { require_once $f; }
        }
        if (!function_exists('gia_identity_begin_link') || !function_exists('gia_identity_confirm_link')) {
            return ['ok' => false, 'error_code' => 'AI_NO_IDENTITY', 'message' => 'Account linking is unavailable right now.'];
        }

        $action = strtolower(trim((string) ($input['action'] ?? '')));

        if ($action === 'start') {
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error_code' => 'AI_BAD_EMAIL', 'message' => 'Please give me the email on your GoGlobia account.'];
            }
            // Resolve the account by email. We DON'T reveal whether it exists (avoid
            // account enumeration): same friendly reply either way.
            $u = $db->get('users', ['user_id', 'first_name'], ['email' => $email]);
            if (!is_array($u) || empty($u['user_id'])) {
                return [
                    'ok' => true,
                    'data' => ['started' => false],
                    'message' => "If that email matches a GoGlobia account, I've sent a 6-digit code to it. Please type the code here to continue.",
                ];
            }
            $begin = gia_identity_begin_link($db, $channel, $channelUserId, (string) $u['user_id']);
            if (empty($begin['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_LINK_START', 'message' => 'I could not start verification just now — please try again shortly.'];
            }
            // Deliver the code to the ACCOUNT'S email (out-of-band). Never echo it.
            $code = (string) $begin['code'];
            $toName = trim((string) ($u['first_name'] ?? '')) ?: 'there';
            if (function_exists('SENDEMAIL')) {
                $subject = 'Your GoGlobia verification code';
                $body = '<p>Hi ' . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . ',</p>'
                      . '<p>Your one-time code to link your ' . htmlspecialchars(ucfirst($channel), ENT_QUOTES, 'UTF-8') . ' chat to your GoGlobia account is:</p>'
                      . '<p style="font-size:22px;font-weight:bold;letter-spacing:3px">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
                      . '<p>It expires in 10 minutes. If you didn\'t request this, you can ignore this email.</p>';
                @SENDEMAIL($email, $toName, $subject, $body);
            }
            return [
                'ok' => true,
                'data' => ['started' => true],
                'message' => "I've sent a 6-digit code to your account email. Please type it here and I'll link this chat to your account.",
            ];
        }

        if ($action === 'confirm') {
            $code = preg_replace('/[^\d]/', '', (string) ($input['code'] ?? ''));
            if ($code === '') {
                return ['ok' => false, 'error_code' => 'AI_BAD_CODE', 'message' => 'Please type the 6-digit code I sent to your email.'];
            }
            $res = gia_identity_confirm_link($db, $channel, $channelUserId, $code);
            if (!empty($res['ok'])) {
                return [
                    'ok' => true,
                    'data' => ['verified' => true, 'user_id' => (string) ($res['user_id'] ?? '')],
                    'message' => 'Your account is linked. I can now help with your bookings.',
                ];
            }
            $err = (string) ($res['error'] ?? '');
            $msg = $err === 'expired'           ? 'That code has expired — say "verify" and I\'ll send a fresh one.'
                 : ($err === 'too_many_attempts' ? 'Too many attempts. Please wait a little, then we can try again.'
                 : ($err === 'no_pending_link'    ? 'I don\'t have a code pending for this chat — give me your account email to start.'
                 : 'That code didn\'t match. Please double-check and try again.'));
            return ['ok' => false, 'error_code' => 'AI_VERIFY_FAILED', 'message' => $msg];
        }

        return ['ok' => false, 'error_code' => 'AI_BAD_ACTION', 'message' => 'Unknown verification step.'];
    }
}
