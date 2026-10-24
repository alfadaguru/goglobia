<?php

namespace App\lib\ai;

/**
 * GIA tool: register a NEW passwordless customer account from chat (R2).
 *
 * Two actions:
 *   start  — GIA has the traveller's name + phone. Creates an inactive, password-
 *            less, phone-first account (or reuses an abandoned one), then sends a
 *            6-digit OTP to that phone over WhatsApp.
 *   verify — GIA passes the code. On success the account goes active +
 *            phone_verified, and (on a messaging channel) the chat is linked to it
 *            so GIA immediately acts as the new user.
 *
 * FRAUD: an account is only ever activated after the phone OTP is confirmed — a
 * click/typed name alone never creates a usable account. A number that already
 * belongs to a real account is refused (GIA offers login instead). The code is
 * delivered to the phone, never echoed in chat.
 */
class registerAccountTool implements giaToolInterface
{
    public function name(): string
    {
        return 'register_account';
    }

    public function description(): string
    {
        return 'Create a new GoGlobia account for a traveller who does not have one, using just '
            . 'their name and phone number (no password — they log in by WhatsApp code). '
            . 'Call with action="start" (after collecting first_name, last_name and phone) to send '
            . 'a verification code, then action="verify" with the code they received. '
            . 'Only use when the traveller is NOT already signed in / identified.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action'       => ['type' => 'string', 'description' => 'start or verify'],
                'first_name'   => ['type' => 'string', 'description' => 'Traveller first name (for action=start).'],
                'last_name'    => ['type' => 'string', 'description' => 'Traveller last name (for action=start).'],
                'phone'        => ['type' => 'string', 'description' => 'Phone number (for action=start). On WhatsApp, omit to use the sender\'s own number.'],
                'country_code' => ['type' => 'string', 'description' => 'Phone country, ISO or dial code (for action=start).'],
                'code'         => ['type' => 'string', 'description' => 'The 6-digit code the traveller received (for action=verify).'],
            ],
            'required' => ['action'],
        ];
    }

    public function riskClass(): string
    {
        return 'R2'; // creates an account; no money; gated by phone OTP
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Account signup is unavailable right now.'];
        }
        // Already identified? Don't create a second account.
        if ((string) ($context['account_id'] ?? '') !== '') {
            return ['ok' => false, 'error_code' => 'AI_ALREADY_IDENTIFIED', 'message' => 'You already have an account linked to this chat.'];
        }

        require_once dirname(__DIR__) . '/gia_account.php';
        require_once dirname(__DIR__, 2) . '/lib/otp.php';

        $action  = strtolower(trim((string) ($input['action'] ?? '')));
        $channel = (string) ($context['channel'] ?? '');
        $channelUserId = (string) ($context['channel_user_id'] ?? '');

        if ($action === 'start') {
            $first = (string) ($input['first_name'] ?? '');
            $last  = (string) ($input['last_name'] ?? '');
            // On WhatsApp the sender IS a phone — default to it if none supplied.
            $phone = trim((string) ($input['phone'] ?? ''));
            $cc    = (string) ($input['country_code'] ?? '');
            if ($phone === '' && $channel === 'whatsapp' && $channelUserId !== '') {
                $phone = $channelUserId; // already digits incl. country code
            }

            $reg = \gia_register_user($db, $first, $last, $phone, $cc);
            if (empty($reg['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_REGISTER_' . strtoupper((string) ($reg['error'] ?? 'FAILED')), 'message' => (string) ($reg['message'] ?? 'Could not set up the account.')];
            }
            $newUserId = (string) $reg['user_id'];

            // Issue + deliver the signup OTP to the phone.
            $u = $db->get('users', ['phone', 'phone_country_code', 'first_name'], ['user_id' => $newUserId]);
            $otp = \otp_issue($db, $newUserId, 'signup');
            if (empty($otp['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_OTP_ISSUE', 'message' => (string) ($otp['message'] ?? 'Could not send a code just now.')];
            }
            \sendOtpWhatsApp($db, (string) ($u['phone'] ?? ''), (string) ($u['phone_country_code'] ?? ''), (string) $otp['code'], (string) ($u['first_name'] ?? ''));

            // Remember who we're registering for the verify step (trusted, server-side).
            $_SESSION['gia_register_user'] = $newUserId;
            return ['ok' => true, 'data' => ['started' => true], 'message' => 'I\'ve sent a 6-digit code to that WhatsApp number. What code did you get?'];
        }

        if ($action === 'verify') {
            $code = trim((string) ($input['code'] ?? ''));
            $newUserId = (string) ($_SESSION['gia_register_user'] ?? '');
            if ($newUserId === '') {
                return ['ok' => false, 'error_code' => 'AI_NO_PENDING_REGISTER', 'message' => 'Let\'s start again — tell me your name and phone number.'];
            }
            $res = \otp_verify($db, $newUserId, 'signup', $code);
            if (empty($res['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_OTP_BAD', 'message' => (string) ($res['message'] ?? 'That code was not right.')];
            }
            // Activate the account + mark the phone verified.
            try {
                $db->update('users', ['status' => 'active', 'phone_verified' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['user_id' => $newUserId]);
            } catch (\Throwable $e) {
                error_log('[gia] register verify activate: ' . $e->getMessage());
            }
            unset($_SESSION['gia_register_user']);

            // On a messaging channel, link THIS chat to the new account so GIA now
            // acts as them (the OTP proved they own the number this chat is on).
            if ($channel !== '' && $channelUserId !== '' && function_exists('gia_conv_get_or_create_channel')) {
                try { \gia_conv_get_or_create_channel($db, $channel, $channelUserId, $newUserId, ''); } catch (\Throwable $e) {}
            }
            if (function_exists('logUserActivity')) {
                $row = $db->get('users', ['id'], ['user_id' => $newUserId]);
                if ($row) { \logUserActivity($db, $row['id'], 'register', 'Account created + phone-verified via GIA'); }
            }
            return ['ok' => true, 'data' => ['registered' => true, 'user_id' => $newUserId], 'message' => 'You\'re all set — your account is created and your phone is verified.'];
        }

        return ['ok' => false, 'error_code' => 'AI_BAD_ACTION', 'message' => 'Use action "start" then "verify".'];
    }
}
