<?php

namespace App\lib\ai;

/**
 * GIA tool: record a SCOPED approval for a checkout (R2), re-verifying the price.
 *
 * CRITICAL (BRD §10.5): natural-language consent is NOT approval. The MODEL must
 * never self-approve because it thinks the user "probably wants it". This tool
 * therefore requires an explicit approved=true that the HTTP/UI layer sets ONLY
 * from a real, identity-backed user action (a tapped Approve button) — the agent
 * runtime does not fabricate it. When approved, it re-prices the draft and calls
 * gia_request_approval(), which grants the approval ONLY if the live quote still
 * hashes to the frozen snapshot (expire-on-change). No money moves here; the
 * approval is the gate the later payment/commit step (Phase C) will require.
 */
class requestApprovalTool implements giaToolInterface
{
    public function name(): string
    {
        return 'request_booking_approval';
    }

    public function description(): string
    {
        return 'Record the traveler\'s explicit approval of a prepared checkout, after '
            . 'confirming the price has not changed. Only works when the traveler has '
            . 'actually approved via the interface — you cannot approve on their behalf. '
            . 'If the price changed, it returns PRICE_CHANGED and a new approval is needed. '
            . 'This does not take payment; it authorizes the later booking step.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checkout_ref' => [
                    'type' => 'string',
                    'description' => 'The checkout reference returned by create_checkout.',
                ],
                'draft_hash' => [
                    'type' => 'string',
                    'description' => 'The trip draft hash (to re-verify the live price against the snapshot).',
                ],
                'approved' => [
                    'type' => 'boolean',
                    'description' => 'True ONLY when the traveler has explicitly approved via the interface. '
                        . 'Never set this yourself based on the conversation.',
                ],
            ],
            'required' => ['checkout_ref', 'draft_hash', 'approved'],
        ];
    }

    public function riskClass(): string
    {
        return 'R2'; // records an approval row; still no money movement (R3+ = pay/commit)
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Approval is unavailable.'];
        }

        // The model cannot self-approve. The HTTP layer injects the REAL approval
        // flag from an identity-backed user action; a model-set value is ignored.
        $userApproved = ($context['user_approved'] ?? null) === true;
        if (!$userApproved) {
            return [
                'ok' => false,
                'error_code' => 'APPROVAL_REQUIRED',
                'message' => 'I can only record an approval after you approve this exact amount in the interface.',
            ];
        }

        $checkoutRef = trim((string) ($input['checkout_ref'] ?? ''));
        $hash = trim((string) ($input['draft_hash'] ?? ''));
        if ($checkoutRef === '' || !preg_match('/^gco_[a-f0-9]{20}$/', $checkoutRef)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_CHECKOUT', 'message' => 'A valid checkout reference is required.'];
        }
        if ($hash === '' || !preg_match('/^[a-f0-9]{8,64}$/i', $hash)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_DRAFT', 'message' => 'A valid trip draft reference is required.'];
        }

        require_once dirname(__DIR__) . '/gia_checkout.php';

        // Re-derive the live quote from the CURRENT draft (do not trust a stale copy).
        try {
            $draft = $db->get('logs_bookings', ['data', 'user_id'], ['hash' => $hash]);
        } catch (\Throwable $e) {
            error_log('[gia] request_approval load: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_DRAFT_ERROR', 'message' => 'Could not load your trip draft.'];
        }
        if (!$draft || empty($draft['data'])) {
            return ['ok' => false, 'error_code' => 'AI_DRAFT_NOT_FOUND', 'message' => 'That trip draft was not found or has expired.'];
        }
        $draftData = json_decode((string) $draft['data'], true);
        if (!is_array($draftData) || empty($draftData['items'])) {
            return ['ok' => false, 'error_code' => 'AI_DRAFT_EMPTY', 'message' => 'That draft has no trip items.'];
        }
        $liveQuote = [
            'currency' => (string) ($draftData['currency'] ?? 'USD'),
            'items'    => $draftData['items'],
            'total'    => (float) ($draftData['total'] ?? 0),
            'adults'   => (int) ($draftData['adults'] ?? ($draftData['pax']['adults'] ?? 0)),
            'children' => (int) ($draftData['children'] ?? ($draftData['pax']['children'] ?? 0)),
        ];

        $userId = (string) ($draft['user_id'] ?? ($context['account_id'] ?? ''));
        $res = \gia_request_approval($db, $checkoutRef, $liveQuote, $userId !== '' ? $userId : null);

        if (empty($res['ok'])) {
            // Surface PRICE_CHANGED / OFFER_EXPIRED etc. so the model re-quotes.
            return [
                'ok' => false,
                'error_code' => (string) ($res['error_code'] ?? 'APPROVAL_FAILED'),
                'message' => (string) ($res['error'] ?? 'Could not record the approval.'),
            ];
        }

        return [
            'ok' => true,
            'data' => [
                'checkout_ref'   => $checkoutRef,
                'approval_token' => (string) $res['approval_token'],
                'expires_at'     => (string) $res['expires_at'],
            ],
            'message' => 'Approval recorded. The booking can proceed to payment within the approval window.',
        ];
    }
}
