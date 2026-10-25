<?php

namespace App\lib\ai;

/**
 * GIA tool: manage the signed-in traveller's opt-in travel preferences (R2 — a
 * small, consented, reversible write; GIA BRD MEM-001/002/005/006/008).
 *
 * Actions: get (list), set (remember — requires explicit consent), forget
 * (delete one or all), disable/enable (MEM-008 master switch). GIA must only call
 * set after the traveller explicitly agrees ("want me to remember you fly
 * economy?"). Sensitive data (passport/payment) is NOT storable here (allow-list).
 */
class preferencesTool implements giaToolInterface
{
    public function name(): string
    {
        return 'my_preferences';
    }

    public function description(): string
    {
        return 'View or update the signed-in traveller\'s saved travel preferences (home airport, '
            . 'cabin, airline, seat, meal, budget, hotel stars, language). action=get lists them; '
            . 'action=set remembers one (ONLY after the traveller explicitly agrees — pass consent=true); '
            . 'action=forget deletes one (key) or all; action=disable/enable toggles personalization. '
            . 'Use saved preferences to rank and pre-fill, never to override an explicit request.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action'  => ['type' => 'string', 'description' => 'get | set | forget | disable | enable'],
                'key'     => ['type' => 'string', 'description' => 'Preference key for set/forget: home_airport, preferred_cabin, preferred_airline, seat_preference, meal_preference, budget_ceiling, hotel_stars, language.'],
                'value'   => ['type' => 'string', 'description' => 'Value for action=set.'],
                'consent' => ['type' => 'boolean', 'description' => 'Must be true for action=set — the traveller explicitly agreed to be remembered.'],
            ],
            'required' => ['action'],
        ];
    }

    public function riskClass(): string
    {
        return 'R2';
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Preferences are unavailable right now.'];
        }
        $userId = (string) ($context['account_id'] ?? '');
        if ($userId === '') {
            return ['ok' => false, 'error_code' => 'AI_NOT_IDENTIFIED', 'message' => 'I can only manage your preferences once I know who you are.'];
        }
        require_once dirname(__DIR__) . '/gia_preferences.php';
        $action = strtolower(trim((string) ($input['action'] ?? 'get')));

        if ($action === 'get') {
            $prefs = \gia_pref_list($db, $userId);
            return ['ok' => true, 'data' => ['preferences' => $prefs, 'count' => count($prefs)],
                'message' => $prefs === [] ? 'No saved preferences yet.' : 'Here are your saved preferences.'];
        }
        if ($action === 'set') {
            $res = \gia_pref_set($db, $userId, (string) ($input['key'] ?? ''), (string) ($input['value'] ?? ''), (bool) ($input['consent'] ?? false), 'stated');
            if (empty($res['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_PREF_SET', 'message' => (string) ($res['message'] ?? 'Could not save that preference.')];
            }
            return ['ok' => true, 'data' => ['saved' => $res['label'] ?? ''], 'message' => 'Got it — I\'ll remember your ' . ($res['label'] ?? 'preference') . '.'];
        }
        if ($action === 'forget') {
            \gia_pref_forget($db, $userId, (string) ($input['key'] ?? ''));
            return ['ok' => true, 'message' => 'Done — I\'ve forgotten that.'];
        }
        if ($action === 'disable') {
            \gia_pref_set_personalization($db, $userId, false);
            return ['ok' => true, 'message' => 'Personalization is off — I won\'t use saved preferences. You can still book normally.'];
        }
        if ($action === 'enable') {
            \gia_pref_set_personalization($db, $userId, true);
            return ['ok' => true, 'message' => 'Personalization is back on.'];
        }
        return ['ok' => false, 'error_code' => 'AI_PREF_ACTION', 'message' => 'Use get, set, forget, disable or enable.'];
    }
}
