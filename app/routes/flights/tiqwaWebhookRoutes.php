<?php
// ============================================================================
// TIQWA WEBHOOK RECEIVER — inbound ticket.issued / flight.booked events.
// ----------------------------------------------------------------------------
// Tiqwa POSTs an event ({event, data:{...}}) to the webhook URL configured in the
// Tiqwa dashboard whenever a key action occurs (docs/research/tiqwa/Webhooks.md):
//   • ticket.issued  — a ticket has been issued (payload carries pnr + tickets[])
//   • flight.booked  — a reservation was created
//
// WHY THIS LIVES HERE (not in modules/flights/tiqwa): Tiqwa's webhook is
// UNAUTHENTICATED BY DESIGN (Webhooks.md: "create a POST endpoint that does not
// require authentication"). The /modules/* central guard 403s anonymous
// state-changing POSTs, so the receiver must be a normal public app route.
//
// SECURITY (audit BLOCKER 4 — never trust the raw payload to flip a booking to
// issued): two independent gates —
//   (1) a SHARED-SECRET PATH TOKEN: the URL is /webhooks/flights/tiqwa/{secret};
//       {secret} is compared with hash_equals against a per-install secret derived
//       from the module's own access token. An attacker who does not know it can't
//       even reach the handler body.
//   (2) VERIFIED READ-BACK: the payload is treated ONLY as a trigger. We re-fetch
//       GET /v1/flight/{booking_reference} with OUR bearer and let
//       tiqwa_complete_from_booking_details() (modules/flights/tiqwa/utilities.php)
//       decide the real state (status===issued → write pnr + confirm + notify).
//       The authority is the authenticated read-back, exactly like the payment
//       webhooks re-verify with the gateway.
// ============================================================================

@$SECURE or die('Access Denied!');

if (!function_exists('tiqwa_webhook_secret')) {
    /**
     * Per-install webhook path secret. Derived (HMAC) from the module's access
     * token + a fixed label so it is stable, unguessable, and never the raw token.
     * The operator puts this value as the last path segment of the webhook URL they
     * register in the Tiqwa dashboard.
     */
    function tiqwa_webhook_secret($db): string
    {
        $tok = '';
        try {
            $m = $db->get('modules', ['c1'], ['name' => 'tiqwa', 'type' => 'flights']);
            $tok = trim((string) ($m['c1'] ?? ''));
        } catch (\Throwable $e) {}
        if ($tok === '') return '';
        return substr(hash_hmac('sha256', 'tiqwa-webhook', $tok), 0, 40);
    }
}

$router->post('/webhooks/flights/tiqwa/([a-f0-9]{40})', function ($secret) use ($SECURE, $db) {

    $ack = function (int $code = 200, string $body = 'OK') {
        http_response_code($code);
        header('Content-Type: text/plain');
        echo $body;
        exit;
    };

    // GATE 1 — shared-secret path token (constant-time compare).
    $expected = tiqwa_webhook_secret($db);
    if ($expected === '' || !hash_equals($expected, (string) $secret)) {
        error_log('TIQWA WEBHOOK | bad/absent path secret');
        $ack(401, 'unauthorized');
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
        error_log('TIQWA WEBHOOK | non-JSON body');
        $ack(200, 'ignored');
    }

    $event = (string) ($payload['event'] ?? '');
    $data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    // The booking reference is our correlation key (TW-...). Payload field is
    // `reference`; fall back to pnr-based lookup is NOT done (reference is the key
    // we stored at reserve time).
    $reference = trim((string) ($data['reference'] ?? $data['booking_reference'] ?? ''));

    if (function_exists('tiqwaLog')) {
        tiqwaLog('webhook/' . ($event ?: 'unknown'), ['reference' => $reference], substr($raw, 0, 1500), 200, null);
    }

    if ($reference === '') {
        error_log('TIQWA WEBHOOK | event=' . $event . ' has no reference — ignored');
        $ack(200, 'no-reference');
    }

    // GATE 2 — authoritative verified read-back. Ignore the payload's own claim;
    // re-fetch booking details with our bearer and let the shared completion
    // helper decide + persist (writes pnr + confirms + notifies only when Tiqwa's
    // own GET /{reference} reports status=issued). Idempotent. The helper lives in
    // the module; load it if the gateway include hasn't run in this request.
    if (!function_exists('tiqwa_complete_from_booking_details')) {
        $u = dirname(__DIR__, 2) . '/modules/flights/tiqwa/creds.php';
        if (is_file($u)) require_once $u; // defines getTiqwaCredentials + tiqwaApiRequest
        $v = dirname(__DIR__, 2) . '/modules/flights/tiqwa/utilities.php';
        if (is_file($v)) require_once $v; // defines tiqwa_complete_from_booking_details
    }
    if (!function_exists('tiqwa_complete_from_booking_details')) {
        error_log('TIQWA WEBHOOK | completion helper unavailable');
        $ack(200, 'deferred');
    }

    try {
        $r = tiqwa_complete_from_booking_details($db, $reference, null);
        error_log('TIQWA WEBHOOK | event=' . $event . ' ref=' . $reference . ' -> ' . json_encode($r));
    } catch (\Throwable $e) {
        error_log('TIQWA WEBHOOK | completion error: ' . $e->getMessage());
        // Still 200 so Tiqwa stops retrying; a poll on invoice-open will complete it.
    }

    $ack(200, 'OK');
});
