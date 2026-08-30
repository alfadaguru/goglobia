<?php
// ============================================================================
// KIKOTO FERRIES — REVALIDATE / PRICE ENDPOINT
// ============================================================================
// Route: POST ferries/kikoto/revalidate
// Re-prices a chosen sailing with the actual passenger/vehicle/pet breakdown
// before the user proceeds to the booking form.  Called from the front-end
// after the user selects a departure and fills in passenger counts.
//
// Input mirrors the /prices API body:
//   sailings   array  — selected sailing objects (from search results)
//   passengers array  — [{id, ticket_type_id}]
//   vehicles   array  — [{id, ticket_type_id, ...}]  (optional)
//   pets       array  — [{id, ticket_type_id}]  (optional)
// ============================================================================

global $router;

$router->post('ferries/kikoto/revalidate', function () use ($db) {
    @set_time_limit(30);
    header('Content-Type: application/json; charset=utf-8');

    try {
        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0') {
            return _kikoto_respond(false, 'Kikoto ferries module is not enabled.', null, 503);
        }

        $input = _kikoto_input();

        $sailings   = $input['sailings']   ?? [];
        $passengers = $input['passengers'] ?? [];

        // VALIDATION — at minimum one sailing and one passenger required
        if (empty($sailings) || !is_array($sailings)) {
            return _kikoto_respond(false, 'sailings array is required.', null, 422);
        }
        if (empty($passengers) || !is_array($passengers)) {
            return _kikoto_respond(false, 'passengers array is required.', null, 422);
        }

        // Always build passenger references and inject them into each sailing's accommodations
        $selectedBonusIds = _kikoto_normalize_bonus_ids($input['bonuses'] ?? []);
        $coupon = trim((string)($input['coupon'] ?? ''));

        $passengerRefs = _kikoto_passenger_refs($passengers, $selectedBonusIds);
        foreach ($sailings as &$sailing) {
            if ($coupon !== '') {
                $sailing['coupon'] = $coupon;
            }
            if (!empty($passengerRefs) && isset($sailing['accommodations']) && is_array($sailing['accommodations'])) {
                foreach ($sailing['accommodations'] as &$acc) {
                    $acc['passengers'] = $passengerRefs;
                }
                unset($acc);
            }
        }
        unset($sailing);

        // BUILD PRICES REQUEST BODY
        $body = [
            'sailings'   => $sailings,
            'passengers' => $passengers,
        ];

        if (!empty($input['vehicles'])) {
            $body['vehicles'] = $input['vehicles'];
        }
        if (!empty($input['pets'])) {
            $body['pets'] = $input['pets'];
        }

        // CALL KIKOTO /prices
        $res = _kikoto_request('POST', '/prices', [
            'cfg'     => $cfg,
            'body'    => $body,
            'timeout' => 20,
        ]);

        if (!$res['ok']) {
            return _kikoto_respond(false, 'Price validation failed: ' . ($res['error'] ?? 'Unknown error'), null, 502);
        }

        $priceData = $res['data']['data'] ?? [];

        // APPLY MARKUP TO RETURNED PRICES
        $agentCtx = _kikoto_resolve_agent_context($db);
        $channel  = $agentCtx['channel'] ?? 'b2c';
        $totalOriginal = 0;
        $totalFinal    = 0;

        if (!empty($priceData['sailings'])) {
            foreach ($priceData['sailings'] as &$s) {
                $orig  = (float)($s['price'] ?? 0);
                $final = _kikoto_apply_markup($orig, $cfg, $channel, $agentCtx['custom_markup'] ?? null);
                $s['original_price'] = $orig;
                $s['price']          = $final;
                $totalOriginal      += $orig;
                $totalFinal         += $final;
            }
        }

        $priceData['total_original'] = $totalOriginal;
        $priceData['total_price']    = $totalFinal;
        $priceData['currency']       = $cfg['currency'] ?? 'EUR';

        _kikoto_respond(true, 'Price validated.', $priceData);

    } catch (Throwable $e) {
        _kikoto_respond(false, 'Server error: ' . $e->getMessage(), null, 500);
    }
});
