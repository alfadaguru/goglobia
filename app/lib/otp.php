<?php
// app/lib/otp.php
//
// Reusable, purpose-scoped one-time-password (OTP) engine for phone/account
// verification and passwordless login. Part of the GIA identity/phone-auth
// workstream (docs/research/gia-identity-personalization-audit.md, Phase 2).
//
// WHY THIS EXISTS — do not re-implement OTP inline again:
//   The app already had OTP logic, but ONLY inlined inside the mobile API
//   password-reset / signup routes (app/routes/api/users/passwordResetRoutes.php,
//   app/routes/api/users/signupRoutes.php). It used a SINGLE users.otp /
//   otp_expires pair with NO notion of purpose, so a second OTP flow (phone
//   verify, phone login) would silently overwrite a live code and the
//   burn-after-5 guard would clear whichever code was there. Phase 0 added the
//   users.otp_type column precisely so each code can be scoped to one purpose;
//   this engine honours it. New flows MUST call otp_issue()/otp_verify() here
//   rather than writing users.otp directly.
//
// Proven behaviour carried over verbatim from the password-reset flow:
//   - 6-digit numeric code, 5-minute expiry.
//   - 60-second resend cooldown (anti-bombing + defeats guess-by-refresh).
//   - burn-after-5-wrong-guesses (login_attempts reused as the OTP miss
//     counter, same column the password-reset flow already uses).
// Added here:
//   - otp_type scoping: otp_verify() rejects a code minted for another purpose.
//   - WhatsApp-first delivery via the existing SENDWHATSAPP(), email fallback.
//
// All functions are guarded with function_exists so a double-require is safe.

if (!defined('OTP_TTL_SECONDS'))      { define('OTP_TTL_SECONDS', 300); }   // 5 min
if (!defined('OTP_RESEND_COOLDOWN'))  { define('OTP_RESEND_COOLDOWN', 60); } // 60 s
if (!defined('OTP_MAX_ATTEMPTS'))     { define('OTP_MAX_ATTEMPTS', 5); }     // burn after 5

if (!function_exists('otp_purposes')) {
    /** Allowed OTP purposes (stored in users.otp_type). */
    function otp_purposes(): array
    {
        return ['signup', 'forgot_password', 'phone_verify', 'login'];
    }
}

if (!function_exists('otp_issue')) {
    /**
     * Mint (or refuse to re-mint during cooldown) a purpose-scoped OTP for a user.
     *
     * @param object $db       Medoo instance.
     * @param string $userId   users.user_id (custom string id) OR numeric id — both resolved.
     * @param string $purpose  one of otp_purposes().
     * @return array{ok:bool, code?:string, error?:string, message?:string}
     *   On success returns ['ok'=>true, 'code'=>'123456'] (caller delivers it).
     *   ['ok'=>false, 'error'=>'cooldown'|'not_found'|'bad_purpose'|'db'].
     */
    function otp_issue($db, string $userId, string $purpose): array
    {
        $purpose = trim($purpose);
        if (!in_array($purpose, otp_purposes(), true)) {
            return ['ok' => false, 'error' => 'bad_purpose', 'message' => 'Invalid verification purpose.'];
        }
        $user = otp__resolve_user($db, $userId);
        if (!$user) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Account not found.'];
        }

        // RESEND COOLDOWN — a live code of the SAME purpose issued < cooldown ago
        // blocks a new mint. A code of a different purpose never blocks (the new
        // purpose legitimately needs its own code); it is simply overwritten.
        if (
            !empty($user['otp_expires'])
            && (string) ($user['otp_type'] ?? '') === $purpose
            && strtotime((string) $user['otp_expires']) > (time() + OTP_TTL_SECONDS - OTP_RESEND_COOLDOWN)
        ) {
            return ['ok' => false, 'error' => 'cooldown', 'message' => 'Please wait a moment before requesting another code.'];
        }

        $code = sprintf('%06d', mt_rand(100000, 999999));
        try {
            $db->update('users', [
                'otp'            => $code,
                'otp_expires'    => date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS),
                'otp_type'       => $purpose,
                'login_attempts' => 0,
            ], ['id' => (int) $user['id']]);
        } catch (\Throwable $e) {
            error_log('otp_issue: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db', 'message' => 'Could not issue a code. Please try again.'];
        }

        return ['ok' => true, 'code' => $code];
    }
}

if (!function_exists('otp_verify')) {
    /**
     * Verify a purpose-scoped OTP. Honours expiry, purpose match and the
     * burn-after-5 guard. On success the code is cleared so it cannot be reused.
     *
     * @return array{ok:bool, error?:string, message?:string, user_id?:string}
     *   error ∈ 'bad_purpose'|'not_found'|'expired'|'wrong'|'wrong_purpose'|'burned'
     */
    function otp_verify($db, string $userId, string $purpose, string $code): array
    {
        $purpose = trim($purpose);
        $code    = trim($code);
        if (!in_array($purpose, otp_purposes(), true)) {
            return ['ok' => false, 'error' => 'bad_purpose', 'message' => 'Invalid verification purpose.'];
        }
        $user = otp__resolve_user($db, $userId);
        if (!$user) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Account not found.'];
        }

        $storedCode    = (string) ($user['otp'] ?? '');
        $storedType    = (string) ($user['otp_type'] ?? '');
        $storedExpires = (string) ($user['otp_expires'] ?? '');

        if ($storedCode === '' || $storedExpires === '') {
            return ['ok' => false, 'error' => 'expired', 'message' => 'No active code. Please request a new one.'];
        }
        if (strtotime($storedExpires) <= time()) {
            return ['ok' => false, 'error' => 'expired', 'message' => 'Your code has expired. Please request a new one.'];
        }
        // Purpose must match the code that was minted — a signup code cannot be
        // replayed to satisfy a login, etc.
        if ($storedType !== $purpose) {
            otp__count_miss($db, $user);
            return ['ok' => false, 'error' => 'wrong_purpose', 'message' => 'That code is not valid for this action.'];
        }
        if (!hash_equals($storedCode, $code)) {
            $burned = otp__count_miss($db, $user);
            return [
                'ok'      => false,
                'error'   => $burned ? 'burned' : 'wrong',
                'message' => $burned
                    ? 'Too many incorrect attempts. Please request a new code.'
                    : 'Incorrect code. Please try again.',
            ];
        }

        // Success — clear the code + attempt counter so it is single-use.
        try {
            $db->update('users', [
                'otp'            => null,
                'otp_expires'    => null,
                'otp_type'       => null,
                'login_attempts' => 0,
            ], ['id' => (int) $user['id']]);
        } catch (\Throwable $e) {
            error_log('otp_verify clear: ' . $e->getMessage());
        }

        return ['ok' => true, 'user_id' => (string) ($user['user_id'] ?? '')];
    }
}

if (!function_exists('otp__count_miss')) {
    /**
     * Count a failed OTP attempt; burn the code after OTP_MAX_ATTEMPTS misses.
     * Returns true if the code was just burned. Internal helper.
     * (Mirrors the proven guard in passwordResetRoutes.php.)
     */
    function otp__count_miss($db, array $user): bool
    {
        $attempts = (int) ($user['login_attempts'] ?? 0) + 1;
        try {
            if ($attempts >= OTP_MAX_ATTEMPTS) {
                $db->update('users', [
                    'otp'            => null,
                    'otp_expires'    => null,
                    'otp_type'       => null,
                    'login_attempts' => 0,
                ], ['id' => (int) $user['id']]);
                if (function_exists('logUserActivity')) {
                    logUserActivity($db, $user['id'], 'otp_failed', 'OTP invalidated after too many wrong attempts');
                }
                return true;
            }
            $db->update('users', ['login_attempts' => $attempts], ['id' => (int) $user['id']]);
        } catch (\Throwable $e) {
            error_log('otp__count_miss: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('otp__resolve_user')) {
    /** Resolve a user row by custom user_id OR numeric id. Internal helper. */
    function otp__resolve_user($db, string $userId): ?array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return null;
        }
        $cols = ['id', 'user_id', 'otp', 'otp_expires', 'otp_type', 'login_attempts', 'email', 'phone', 'phone_country_code', 'first_name'];
        try {
            $u = $db->get('users', $cols, ['user_id' => $userId]);
            if (!$u && ctype_digit($userId)) {
                $u = $db->get('users', $cols, ['id' => (int) $userId]);
            }
            return is_array($u) && $u !== [] ? $u : null;
        } catch (\Throwable $e) {
            error_log('otp__resolve_user: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('sendOtpWhatsApp')) {
    /**
     * Deliver an OTP over WhatsApp (preferred), falling back to SMS then email.
     * The OTP is a DETERMINISTIC system message — not composed by GIA/the model.
     * It is sent over the same WhatsApp number the business runs (the provider
     * configured in settings.whatsapp_provider, default greenapi).
     *
     * @param object $db
     * @param string $phone        National digits as stored in users.phone.
     * @param string $countryCode  users.phone_country_code (ISO or numeric).
     * @param string $code         The 6-digit code from otp_issue().
     * @param string $name         Recipient display name (optional).
     * @param int    $ttlMinutes   For the message copy.
     * @return array{ok:bool, channel?:string, error?:string}
     */
    function sendOtpWhatsApp($db, string $phone, string $countryCode, string $code, string $name = '', int $ttlMinutes = 5): array
    {
        $phone = trim($phone);
        if ($phone === '' || $code === '') {
            return ['ok' => false, 'error' => 'missing_params'];
        }

        // Build the dialable number using the existing normaliser (country code
        // prepended, leading zeros stripped) — the WhatsApp/SMS providers expect
        // a full international number.
        $dial = function_exists('formatPhoneNumber')
            ? formatPhoneNumber($phone, function_exists('getPhoneCode') ? getPhoneCode($countryCode, $db) : $countryCode)
            : ($countryCode . $phone);

        $brand = isset($GLOBALS['app']['name']) && $GLOBALS['app']['name'] !== '' ? $GLOBALS['app']['name'] : 'GoGlobia';
        $msg = "Your {$brand} verification code is {$code}. It expires in {$ttlMinutes} minutes. Do not share this code with anyone.";

        // 1) WhatsApp (preferred).
        if (function_exists('SENDWHATSAPP')) {
            try {
                if (SENDWHATSAPP($dial, $name, $msg)) {
                    return ['ok' => true, 'channel' => 'whatsapp'];
                }
            } catch (\Throwable $e) {
                error_log('sendOtpWhatsApp (whatsapp): ' . $e->getMessage());
            }
        }
        // 2) SMS fallback.
        if (function_exists('SENDSMS')) {
            try {
                if (SENDSMS($dial, $name, $msg)) {
                    return ['ok' => true, 'channel' => 'sms'];
                }
            } catch (\Throwable $e) {
                error_log('sendOtpWhatsApp (sms): ' . $e->getMessage());
            }
        }
        return ['ok' => false, 'error' => 'delivery_failed'];
    }
}
