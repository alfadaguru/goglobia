<?php

namespace App\lib\ai;

/**
 * GIA tool: list the signed-in traveller's SAVED travellers (R0 — read-only,
 * scoped to the trusted account_id). Lets GIA offer "Use Musa (passport
 * A1234567), or add someone new?" instead of making a returning customer re-type
 * everyone. The roster is the user's OWN data, auto-seeded from their past
 * bookings (gia_travellers_list seeds on first call).
 *
 * World-class-GIA workstream, GA1.
 */
class myTravellersTool implements giaToolInterface
{
    public function name(): string
    {
        return 'get_my_travellers';
    }

    public function description(): string
    {
        return 'List the signed-in traveller\'s SAVED travellers (people they have booked for '
            . 'before: name, DOB, nationality, passport). Call this BEFORE asking a returning '
            . 'customer to type passenger details, and offer the saved people for reuse '
            . '("Use Musa, or add someone new?"). Returns an empty list for new users. '
            . 'Only works when the traveller is identified.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function riskClass(): string
    {
        return 'R0';
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Saved travellers are unavailable right now.'];
        }
        $userId = (string) ($context['account_id'] ?? '');
        if ($userId === '') {
            return ['ok' => false, 'error_code' => 'AI_NOT_IDENTIFIED', 'message' => 'I can only show your saved travellers once I know who you are.'];
        }

        require_once dirname(__DIR__) . '/gia_travellers.php';
        try {
            $rows = \gia_travellers_list($db, $userId, 12);
        } catch (\Throwable $e) {
            error_log('[gia] get_my_travellers: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_TRAVELLERS_ERROR', 'message' => 'Could not load your saved travellers.'];
        }

        // Compact, model-friendly shape. Mask the passport in display text but keep
        // the id so a follow-up tool can reuse the full record.
        $out = [];
        foreach ($rows as $r) {
            $pp = (string) ($r['passport_number'] ?? '');
            $out[] = [
                'id'          => (int) ($r['id'] ?? 0),
                'name'        => trim((string) ($r['title'] ?? '') . ' ' . (string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')),
                'dob'         => (string) ($r['dob'] ?? ''),
                'nationality' => (string) ($r['nationality'] ?? ''),
                'passport'    => $pp !== '' ? (str_repeat('•', max(0, strlen($pp) - 4)) . substr($pp, -4)) : '',
                'is_self'     => (bool) ($r['is_self'] ?? false),
            ];
        }

        return [
            'ok'   => true,
            'data' => ['travellers' => $out, 'count' => count($out)],
            'message' => $out === []
                ? 'No saved travellers yet — collect fresh for this booking.'
                : ('You have ' . count($out) . ' saved traveller(s) to offer for reuse.'),
        ];
    }
}
