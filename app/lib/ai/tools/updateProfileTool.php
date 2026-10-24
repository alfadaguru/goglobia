<?php

namespace App\lib\ai;

/**
 * GIA tool: update the signed-in traveller's own profile (R2 — reversible write,
 * no money). Strict allow-list: name, preferred currency, language. Scoped to the
 * trusted account_id; the model cannot target another user.
 *
 * NOT writable here: email (changing it must re-verify — out of scope for a chat
 * tool), role, status, password, balance, loyalty — any of those is refused.
 */
class updateProfileTool implements giaToolInterface
{
    /** Fields GIA may change and how to sanitise each. */
    private const ALLOWED = ['first_name', 'last_name', 'currency', 'language'];

    public function name(): string
    {
        return 'update_my_profile';
    }

    public function description(): string
    {
        return 'Update the signed-in traveller\'s own profile details. Allowed fields: '
            . 'first_name, last_name, currency (3-letter code), language (2-letter code). '
            . 'Cannot change email, password, role or balance. Only works when the traveller '
            . 'is identified. Confirm the new values with the traveller before calling.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'first_name' => ['type' => 'string', 'description' => 'New first name.'],
                'last_name'  => ['type' => 'string', 'description' => 'New last name.'],
                'currency'   => ['type' => 'string', 'description' => 'Preferred currency, 3-letter ISO code (e.g. NGN, USD).'],
                'language'   => ['type' => 'string', 'description' => 'Preferred language, 2-letter code (e.g. en, fr).'],
            ],
        ];
    }

    public function riskClass(): string
    {
        return 'R2'; // reversible write, no money
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Profile updates are unavailable right now.'];
        }
        $userId = (string) ($context['account_id'] ?? '');
        if ($userId === '') {
            return ['ok' => false, 'error_code' => 'AI_NOT_IDENTIFIED', 'message' => 'I can only update your profile once I know who you are.'];
        }

        // Build the sanitised update from allow-listed fields only.
        $update = [];
        foreach (self::ALLOWED as $field) {
            if (!array_key_exists($field, $input)) { continue; }
            $val = trim((string) $input[$field]);
            if ($val === '') { continue; }
            if ($field === 'currency') {
                $val = strtoupper(preg_replace('/[^A-Za-z]/', '', $val));
                if (strlen($val) !== 3) {
                    return ['ok' => false, 'error_code' => 'AI_BAD_CURRENCY', 'message' => 'Currency must be a 3-letter code like NGN or USD.'];
                }
            } elseif ($field === 'language') {
                $val = strtolower(preg_replace('/[^A-Za-z]/', '', $val));
                if (strlen($val) < 2 || strlen($val) > 5) {
                    return ['ok' => false, 'error_code' => 'AI_BAD_LANGUAGE', 'message' => 'Language must be a short code like en or fr.'];
                }
            } else {
                // Names: strip control chars, cap length.
                $val = preg_replace('/[\x00-\x1F\x7F]/', '', $val);
                if (mb_strlen($val) > 100) { $val = mb_substr($val, 0, 100); }
            }
            $update[$field] = $val;
        }

        if ($update === []) {
            return ['ok' => false, 'error_code' => 'AI_NOTHING_TO_UPDATE', 'message' => 'Tell me which detail to change (name, currency or language).'];
        }

        $update['updated_at'] = date('Y-m-d H:i:s');
        try {
            $db->update('users', $update, ['user_id' => $userId]);
        } catch (\Throwable $e) {
            error_log('[gia] update_my_profile: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_UPDATE_FAILED', 'message' => 'Could not save your changes just now.'];
        }

        if (function_exists('logUserActivity')) {
            $row = $db->get('users', ['id'], ['user_id' => $userId]);
            if ($row) {
                \logUserActivity($db, $row['id'], 'profile_update', 'Profile updated via GIA: ' . implode(', ', array_keys($update)));
            }
        }

        unset($update['updated_at']);
        return [
            'ok'      => true,
            'data'    => ['updated' => array_keys($update)],
            'message' => 'Done — I\'ve updated your ' . implode(', ', array_keys($update)) . '.',
        ];
    }
}
