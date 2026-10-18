<?php

namespace App\lib\ai;

/**
 * GIA tool: start payment for an approved checkout (R3 — the first money-moving
 * tool). It does NOT charge or confirm anything itself; it verifies the approval
 * and hands off to the platform's existing process_payment() engine (which applies
 * the server-side actor/currency/credential guards and gateway verification).
 *
 * TRIPLE GATE before any money moves:
 *   (a) the route must have set a valid approval context (user-driven, not model),
 *   (b) a prior request_booking_approval must have produced an approval token, and
 *   (c) gia_commit_initiate_payment() re-runs gia_verify_approval() — approved,
 *       unexpired, AND the live price still equals the frozen snapshot — else it
 *       refuses with PRICE_CHANGED / APPROVAL_REQUIRED.
 *
 * It also requires the real `bookings` row (invoice_id) to already exist — created
 * by the existing submit flow — and is idempotent on an already-paid booking.
 */
class initiatePaymentTool implements giaToolInterface
{
    public function name(): string
    {
        return 'initiate_payment';
    }

    public function description(): string
    {
        return 'Begin secure payment for a booking the traveler has approved. Returns the '
            . 'secure checkout link. Only works with a valid approval whose price has not '
            . 'changed. This starts the payment — it does not confirm the booking; '
            . 'confirmation comes only after the gateway verifies the charge and the supplier issues.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checkout_ref'   => ['type' => 'string', 'description' => 'The approved checkout reference.'],
                'approval_token' => ['type' => 'string', 'description' => 'The approval token from request_booking_approval.'],
                'invoice_id'     => ['type' => 'string', 'description' => 'The invoice id of the created booking to pay for.'],
                'draft_hash'     => ['type' => 'string', 'description' => 'The trip draft hash (to re-verify the live price against the snapshot).'],
            ],
            'required' => ['checkout_ref', 'approval_token', 'invoice_id', 'draft_hash'],
        ];
    }

    public function riskClass(): string
    {
        return 'R3'; // financial: starts a real payment
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Payment is unavailable.'];
        }

        // The model cannot pay on the user's behalf. The route sets user_approved from
        // a real, identity-backed action; a model-set value is ignored.
        if (($context['user_approved'] ?? null) !== true) {
            return ['ok' => false, 'error_code' => 'APPROVAL_REQUIRED', 'message' => 'Payment can only start after you approve it in the interface.'];
        }

        $checkoutRef = trim((string) ($input['checkout_ref'] ?? ''));
        $token = trim((string) ($input['approval_token'] ?? ''));
        $invoiceId = trim((string) ($input['invoice_id'] ?? ''));
        $hash = trim((string) ($input['draft_hash'] ?? ''));
        if (!preg_match('/^gco_[a-f0-9]{20}$/', $checkoutRef)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_CHECKOUT', 'message' => 'A valid checkout reference is required.'];
        }
        if (!preg_match('/^gap_[a-f0-9]{32}$/', $token)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_APPROVAL', 'message' => 'A valid approval is required.'];
        }
        if ($invoiceId === '' || !preg_match('/^[A-Za-z0-9]{4,40}$/', $invoiceId)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_INVOICE', 'message' => 'A valid booking reference is required.'];
        }
        if (!preg_match('/^[a-f0-9]{8,64}$/i', $hash)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_DRAFT', 'message' => 'A valid trip draft reference is required.'];
        }

        require_once dirname(__DIR__) . '/gia_commit.php';

        // Rebuild the live quote from the CURRENT draft so the gate re-checks the real price.
        try {
            $draft = $db->get('logs_bookings', ['data', 'user_id'], ['hash' => $hash]);
        } catch (\Throwable $e) {
            error_log('[gia] initiate_payment load: ' . $e->getMessage());
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
        $res = \gia_commit_initiate_payment($db, $checkoutRef, $token, $invoiceId, $liveQuote, $userId !== '' ? $userId : null);

        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'error_code' => (string) ($res['error_code'] ?? 'PAYMENT_FAILED'),
                'message' => (string) ($res['error'] ?? 'Payment could not be started.'),
            ];
        }

        return [
            'ok' => true,
            'data' => [
                'invoice_id'   => (string) $res['invoice_id'],
                'redirect_url' => (string) ($res['redirect_url'] ?? ''),
                'amount'       => (float) ($res['amount'] ?? 0),
                'currency'     => (string) ($res['currency'] ?? ''),
                'already_paid' => (bool) ($res['already_paid'] ?? false),
            ],
            'message' => (string) ($res['message'] ?? 'Payment started.'),
        ];
    }
}
