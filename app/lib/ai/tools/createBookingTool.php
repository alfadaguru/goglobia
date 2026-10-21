<?php

namespace App\lib\ai;

/**
 * GIA tool: turn an approved/checked-out trip DRAFT into a real `bookings` row so
 * it can be paid. This is the missing link between the GIA draft (logs_bookings)
 * and initiate_payment (which requires an existing bookings row + invoice_id).
 *
 * It MIRRORS the web cart's ai_trip booking insert (app/routes/cartRoutes.php:450)
 * exactly — same module_type='ai_trip', AITP invoice prefix, payable_total in
 * booking_data, price_markup = payable — so the SAME invoice + process_payment +
 * issue path handles it. It does NOT move money (creates an UNPAID/pending row).
 *
 * Money safety: totals are taken from the CURRENT repriced draft (the same source
 * initiate_payment re-verifies against the frozen snapshot), so the row can never
 * disagree with what the approval gate will check. Idempotent per draft: if a
 * booking already exists for this draft, it returns that one (no duplicate).
 *
 * Risk R2 (reversible controlled-write: creates a pending, unpaid booking; no
 * charge, no issue). Safe within the base ceiling, so it works on channels.
 */
class createBookingTool implements giaToolInterface
{
    public function name(): string
    {
        return 'create_booking';
    }

    public function description(): string
    {
        return 'Turn the approved trip draft into a booking that can be paid, and return its '
            . 'invoice reference. Call this AFTER create_checkout (and the traveler approving) '
            . 'and BEFORE initiate_payment. Safe: it creates an unpaid, pending booking — it '
            . 'does not charge anything.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'draft_hash' => ['type' => 'string', 'description' => 'The trip draft hash (logs_bookings.hash) being booked.'],
            ],
            'required' => ['draft_hash'],
        ];
    }

    public function riskClass(): string
    {
        return 'R2'; // creates a pending unpaid booking; reversible; no money
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Booking is unavailable.'];
        }
        $hash = trim((string) ($input['draft_hash'] ?? ''));
        if (!preg_match('/^[a-f0-9]{8,64}$/i', $hash)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_DRAFT', 'message' => 'A valid trip draft reference is required.'];
        }

        // Load the draft (same store initiate_payment reads).
        try {
            $draft = $db->get('logs_bookings', ['data', 'user_id'], ['hash' => $hash]);
        } catch (\Throwable $e) {
            error_log('[gia] create_booking load: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_DRAFT_ERROR', 'message' => 'Could not load your trip draft.'];
        }
        if (!$draft || empty($draft['data'])) {
            return ['ok' => false, 'error_code' => 'AI_DRAFT_NOT_FOUND', 'message' => 'That trip draft was not found or has expired.'];
        }
        $d = json_decode((string) $draft['data'], true);
        if (!is_array($d) || empty($d['items']) || !is_array($d['items'])) {
            return ['ok' => false, 'error_code' => 'AI_DRAFT_EMPTY', 'message' => 'That draft has no trip items.'];
        }

        $currency = strtoupper(trim((string) ($d['currency'] ?? 'USD'))) ?: 'USD';
        $userId   = (string) ($draft['user_id'] ?? ($context['account_id'] ?? ''));

        // Idempotency: one booking per draft. We record the draft hash in
        // booking_data.gia_draft_hash; if a row already exists for it, reuse it.
        try {
            $existing = $db->get('bookings', ['invoice_id', 'price_markup', 'currency_markup', 'payment_status'],
                ['booking_data[~]' => '%"gia_draft_hash":"' . $hash . '"%']);
            if (is_array($existing) && !empty($existing['invoice_id'])) {
                return [
                    'ok' => true,
                    'data' => [
                        'invoice_id'   => (string) $existing['invoice_id'],
                        'amount'       => (float) ($existing['price_markup'] ?? 0),
                        'currency'     => (string) ($existing['currency_markup'] ?? $currency),
                        'already_paid' => (string) ($existing['payment_status'] ?? '') === 'paid',
                        'reused'       => true,
                    ],
                    'message' => 'Using the booking already created for this trip.',
                ];
            }
        } catch (\Throwable $e) {
            // non-fatal: fall through and create
        }

        // --- Derive authoritative base totals from the CURRENT repriced draft
        //     items (same fields gia_commit sums; same numbers the approval gate
        //     re-verifies). We don't re-price here — compose_trip/reprice already did. ---
        $items = $d['items'];
        $netBase = 0.0; $taxBase = 0.0;
        foreach ($items as $it) {
            if (!is_array($it)) { continue; }
            $lineNet = (float) ($it['price_base'] ?? $it['price'] ?? 0);
            $lineTax = (float) ($it['tax_base'] ?? 0);
            // If a final (tax-incl) base is present, derive tax from it when no explicit tax.
            $lineFinal = (float) ($it['final_base'] ?? $it['price_with_tax_base'] ?? ($lineNet + $lineTax));
            if ($lineTax <= 0 && $lineFinal > $lineNet) { $lineTax = round($lineFinal - $lineNet, 2); }
            $netBase += $lineNet;
            $taxBase += $lineTax;
        }
        $netBase = round($netBase, 2);
        $taxBase = round($taxBase, 2);
        // Trust the draft's explicit total when present (it reflects coupons/markup
        // applied during reprice); else fall back to net+tax.
        $payableBase = round((float) ($d['total'] ?? ($netBase + $taxBase)), 2);
        if ($payableBase <= 0) {
            return ['ok' => false, 'error_code' => 'AI_ZERO_TOTAL', 'message' => 'This trip has no payable total yet — let me reprice it.'];
        }
        $commission = round(max(0, $payableBase - $netBase - $taxBase), 2);

        $adults = (int) ($d['adults'] ?? ($d['pax']['adults'] ?? 1)); if ($adults < 1) { $adults = 1; }
        $children = (int) ($d['children'] ?? ($d['pax']['children'] ?? 0));
        $infants = (int) ($d['infants'] ?? ($d['pax']['infants'] ?? 0));

        // Guest/contact: from the draft, else the user record, else blank.
        $guest = is_array($d['guest'] ?? null) ? $d['guest'] : (is_array($d['contact'] ?? null) ? $d['contact'] : []);
        $firstName = trim((string) ($guest['first_name'] ?? ''));
        $lastName  = trim((string) ($guest['last_name'] ?? ''));
        $email     = trim((string) ($guest['email'] ?? ''));
        $phone     = trim((string) ($guest['phone'] ?? ''));
        $userData = null;
        if ($userId !== '') {
            try {
                $userData = $db->get('users', '*', ['user_id' => $userId]);
                if (is_array($userData)) {
                    if ($firstName === '') { $firstName = (string) ($userData['first_name'] ?? ''); }
                    if ($lastName === '')  { $lastName = (string) ($userData['last_name'] ?? ''); }
                    if ($email === '')     { $email = (string) ($userData['email'] ?? ''); }
                    if ($phone === '')     { $phone = (string) ($userData['phone'] ?? ''); }
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        // AITP prefix + ai_trip module, matching the cart convention exactly.
        try {
            $packageId = 'AITP' . strtoupper(bin2hex(random_bytes(5)));
        } catch (\Throwable $e) {
            $packageId = 'AITP' . strtoupper(substr(md5((string) mt_rand()), 0, 10));
        }
        $invoiceId = $packageId;
        $primaryGuest = ['first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone' => $phone];

        $bookingData = [
            'source'             => 'gia_chat',
            'gia_draft_hash'     => $hash,       // idempotency key
            'package_id'         => $packageId,
            'ai_trip_package_id' => $packageId,
            'items'              => $items,
            'items_count'        => count($items),
            'base_price'         => $netBase,
            'tax_amount'         => $taxBase,
            // payable_total is the authoritative charged amount the ai_trip invoice
            // reads FIRST (app/views/ai/invoice.php). Must equal price_markup.
            'payable_total'      => $payableBase,
            'final_total'        => $payableBase,
            'final_total_base'   => $payableBase,
            'display_total'      => $payableBase,
            'base_currency'      => $currency,
            'display_currency'   => $currency,
            'guest'              => $primaryGuest,
        ];

        try {
            $ok = $db->insert('bookings', [
                'invoice_id'           => $invoiceId,
                'language'             => function_exists('getCurrentLanguage') ? \getCurrentLanguage() : 'en',
                'booking_status'       => 'pending',
                'payment_status'       => 'unpaid',
                'price_original'       => $netBase,
                'price_markup'         => $payableBase,
                'agent_earning'        => '0',
                'tax_type'             => '',
                'tax'                  => (string) $taxBase,
                'first_name'           => $firstName,
                'last_name'            => $lastName,
                'email'                => $email,
                'phone_country_code'   => '',
                'phone'                => $phone,
                'country'              => '',
                'address'              => '',
                'adults'               => $adults,
                'infants'              => (string) $infants,
                'childs'               => $children,
                'child_ages'           => '[]',
                'currency_markup'      => $currency,
                'cancellation_request' => 0,
                'cancellation_status'  => 0,
                'booking_data'         => json_encode($bookingData),
                'transaction_id'       => $packageId,
                'user_id'              => $userId,
                'user_data'            => $userData ? json_encode($userData) : null,
                'travellers'           => json_encode(['primary_guest' => $primaryGuest]),
                'nationality'          => '',
                'payment_gateway'      => '',
                'module_type'          => 'ai_trip',
                'pnr'                  => '',
                'commission'           => (string) $commission,
                'module'               => 'ai_trip',
                'special_requests'     => '',
                'booking_date'         => date('Y-m-d H:i:s'),
                'created_at'           => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[gia] create_booking insert: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_BOOKING_FAILED', 'message' => 'Could not create the booking. Please try again.'];
        }
        if (!$ok) {
            return ['ok' => false, 'error_code' => 'AI_BOOKING_FAILED', 'message' => 'Could not create the booking. Please try again.'];
        }

        return [
            'ok' => true,
            'data' => [
                'invoice_id' => $invoiceId,
                'amount'     => $payableBase,
                'currency'   => $currency,
            ],
            'message' => 'Booking created (unpaid). Ready for payment.',
        ];
    }
}
