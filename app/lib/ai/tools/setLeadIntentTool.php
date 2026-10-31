<?php

namespace App\lib\ai;

/**
 * GIA tool: tag how SERIOUS the current lead is, for ops follow-up + the nurture
 * sweep. GIA calls this when it forms a clear read of the traveller's intent —
 * NOT every turn, and never announced to the customer. Internal-only signal.
 *
 *   serious   — specific date/pax/route, asking how to book/pay, urgency.
 *   browsing  — price-only, vague ("maybe December"), "just checking".
 *   not_ready — explicitly not now, or dropped off / unresponsive.
 *
 * Risk R1 (internal classification only; no money, no customer-facing commitment,
 * no external send). Fail-soft: never breaks the chat.
 */
class setLeadIntentTool implements giaToolInterface
{
    public function name(): string
    {
        return 'set_lead_intent';
    }

    public function description(): string
    {
        return 'INTERNAL: record how ready-to-book this traveller seems, so our team can '
            . 'follow up well. Call it once you have a clear read (not every message): '
            . "'serious' (specific dates/passengers/route, asking to book or pay), "
            . "'browsing' (price-only, vague, just checking), or 'not_ready' (explicitly "
            . 'not now). Never mention to the customer that you tagged them.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => ['serious', 'browsing', 'not_ready'],
                    'description' => 'How ready-to-book the traveller seems.',
                ],
            ],
            'required' => ['intent'],
        ];
    }

    public function riskClass(): string
    {
        return 'R1'; // internal classification only
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        $convId = (int) ($context['conv_id'] ?? 0);
        $intent = strtolower(trim((string) ($input['intent'] ?? '')));

        if (!in_array($intent, ['serious', 'browsing', 'not_ready'], true)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_INTENT', 'message' => 'Not a valid intent.'];
        }
        if ($db === null || $convId <= 0) {
            // Nothing to persist to — acknowledge silently so the model moves on.
            return ['ok' => true, 'data' => ['recorded' => false]];
        }

        require_once dirname(__DIR__) . '/gia_crm.php';
        $ok = function_exists('App\\lib\\ai\\gia_lead_set_intent')
            ? \App\lib\ai\gia_lead_set_intent($db, $convId, $intent)
            : false;

        // Deliberately return NO customer-facing message — this is an internal tag; GIA
        // should keep talking naturally, not say "I've noted you as browsing".
        return ['ok' => true, 'data' => ['recorded' => $ok, 'intent' => $intent]];
    }
}
