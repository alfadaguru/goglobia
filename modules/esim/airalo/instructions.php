<?php

global $router;

// Supplier-hiding: the customer-facing link uses the NEUTRAL path
// /modules/esim/instructions (no provider name in the URL). The legacy
// provider-named path stays registered for backward compatibility; both run the
// same handler, which resolves the provider server-side. A customer never sees
// "airalo" in the URL, and the response carries no provider name either.
$__esimInstructionsHandler = function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $cfg = _airalo_cfg($db);
        $env = _airalo_env($_GET['env'] ?? ($cfg['env'] ?? 'sandbox'));
        $iccid = trim((string) ($_GET['sim_iccid'] ?? ''));
        $language = trim((string) ($_GET['lang'] ?? 'en'));

        if ($iccid === '') {
            throw new Exception('sim_iccid is required.');
        }

        // OWNERSHIP (audit HIGH — IDOR): this endpoint returns Airalo's activation
        // instructions (QR / LPA / activation code) for ANY iccid. The central
        // supplier_action_guard only gates issue/cancel/refund/void/orders — NOT this
        // GET — so without a check anyone who knows/guesses a victim's ICCID could pull
        // their eSIM credentials. Restrict to the owner of the booking that carries this
        // iccid: admin, the booking's user_id, or the creating session's owned_invoices.
        // (The modules gateway does not load functions.php, so this is self-contained.)
        $__esimOwned = false;
        if (strtolower((string) ($_SESSION['user_role'] ?? '')) === 'admin' || !empty($_SESSION['admin_logged_in'])) {
            $__esimOwned = true;
        } else {
            // iccids are long unique numeric strings — a LIKE on the JSON blob is safe
            // to locate the candidate booking; we then confirm by decoding + re-matching.
            $__cands = $db->select('bookings', ['invoice_id', 'user_id', 'booking_data'], [
                'module'      => 'airalo',
                'booking_data[~]' => '%' . $iccid . '%',
                'LIMIT'       => 20,
            ]) ?: [];
            $__sessUser  = (string) ($_SESSION['user_id'] ?? '');
            $__ownedInv  = (array) ($_SESSION['owned_invoices'] ?? []);
            foreach ($__cands as $__b) {
                $__bd = json_decode((string) ($__b['booking_data'] ?? ''), true) ?: [];
                $__prov = (array) ($__bd['airalo_provision'] ?? []);
                $__firstSim = (array) ($__prov['first_sim'] ?? []);
                $__bIccid = (string) ($__prov['iccid'] ?? ($__firstSim['iccid'] ?? ''));
                if ($__bIccid !== '' && hash_equals($__bIccid, $iccid)) {
                    if (($__sessUser !== '' && (string) ($__b['user_id'] ?? '') === $__sessUser)
                        || in_array((string) $__b['invoice_id'], $__ownedInv, true)) {
                        $__esimOwned = true;
                    }
                    break; // iccid is unique to one booking
                }
            }
        }
        if (!$__esimOwned) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not authorised for this eSIM.'], JSON_UNESCAPED_SLASHES);
            return;
        }

        $res = _airalo_request_with_token($db, 'GET', '/v2/sims/' . rawurlencode($iccid) . '/instructions', [
            'env' => $env,
            'accept_language' => $language !== '' ? $language : 'en',
            'timeout' => 45,
        ]);

        if (!$res['ok']) {
            http_response_code($res['status'] > 0 ? $res['status'] : 400);
            echo json_encode([
                'success' => false,
                // Supplier-hiding: generic message, never the provider name.
                'message' => $res['error'] ?? 'Failed to fetch installation instructions',
                'data' => $res['data'],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return;
        }

        echo json_encode([
            'success' => true,
            // Supplier-hiding: generic message; NO meta.endpoint (it exposed the
            // provider's API base URL to the customer).
            'message' => 'Installation instructions fetched successfully.',
            'data' => $res['data'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        error_log('AIRALO_INSTRUCTIONS: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Could not fetch installation instructions.',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
};

// Neutral customer-facing path + legacy provider-named path (same handler).
$router->get('esim/instructions', $__esimInstructionsHandler);
$router->get('esim/airalo/instructions', $__esimInstructionsHandler);
