<?php
// ============================================================================
// RAIL API — HOME / MODULE INFO
// ============================================================================
// GET  /api/rail/home          — module status, journey types, currency
// GET  /api/rail/config        — alias of home (mobile clients)
// ============================================================================

@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 4) . '/modules/rail/train/api.php';

$railHomeHandler = function () use ($db) {
    try {
        $cfg = _train_cfg($db);
        $ready = _train_module_ready($db);

        $journeyTypes = _train_journey_types_for_api();
        $regionPolicies = _train_region_policies_for_api();

        _train_respond(true, 'OK', [
            'module' => [
                'name'     => 'train',
                'type'     => 'rail',
                'active'   => ($cfg['active'] ?? '0') === '1',
                'status'   => ($cfg['status'] ?? '0') === '1',
                'ready'    => !empty($ready),
                'currency' => $cfg['currency'] ?? 'USD',
                'icon'     => $cfg['icon'] ?? 'directions_railway',
                'color'    => $cfg['module_color'] ?? '#4f46e5',
            ],
            'journey_types' => $journeyTypes,
            'region_policies' => $regionPolicies,
            'passenger_types' => [
                ['id' => 1, 'label' => 'Adult', 'age_range' => '14+ / height 1.5 m+', 'note' => 'Full fare ticket'],
                ['id' => 2, 'label' => 'Child', 'age_range' => '6–14 / height 1.2–1.5 m', 'note' => 'Discounted child ticket'],
                ['id' => 3, 'label' => 'Child (no seat) / Infant', 'age_range' => 'Under 6 / under 1.2 m / infant under 3', 'note' => 'Free or no separate ticket where permitted'],
            ],
            'child_age_policy' => _train_child_age_policy(1),
            'child_age_policy_by_journey' => [
                1 => _train_child_age_policy(1),
                2 => _train_child_age_policy(2),
                3 => _train_child_age_policy(3),
            ],
            'document_types' => _train_passenger_card_types_for_api(),
            'document_types_by_journey' => [
                1 => _train_passenger_card_types_for_api(1),
                2 => _train_passenger_card_types_for_api(2),
                3 => _train_passenger_card_types_for_api(3),
            ],
            'countries_endpoint' => rtrim(root, '/') . '/api/countries',
            'passenger_country_code_note' => 'Send countries.iso in booking; backend maps to countries.iso3 for the supplier API.',
            'seat_classes' => _train_seat_classes_for_api(),
            'endpoints' => [
                'stations'      => rtrim(root, '/') . '/api/rail/stations',
                'search'        => rtrim(root, '/') . '/api/rail/search',
                'stops'         => rtrim(root, '/') . '/api/rail/stops',
                'booking_draft' => rtrim(root, '/') . '/api/rail/booking/draft',
                'booking_submit'=> rtrim(root, '/') . '/api/rail/booking/submit',
                'booking_order' => rtrim(root, '/') . '/api/rail/booking/order',
                'invoice'       => rtrim(root, '/') . '/api/rail/invoice/{invoice_id}',
                'order_status'  => rtrim(root, '/') . '/api/rail/booking/order-status',
                'request_cancellation' => rtrim(root, '/') . '/api/rail/booking/request-cancellation',
                'reschedule'           => rtrim(root, '/') . '/api/rail/booking/reschedule',
                'reschedule_status'    => rtrim(root, '/') . '/api/rail/booking/reschedule-status',
                'payment'       => rtrim(root, '/') . '/payment/process',
            ],
        ]);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 500);
    }
};

$router->get('/api/rail/home', $railHomeHandler);
$router->get('/api/rail/config', $railHomeHandler);
