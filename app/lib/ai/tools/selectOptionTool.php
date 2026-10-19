<?php

namespace App\lib\ai;

/**
 * GIA tool: turn a chosen search option (a real card the traveller picked) into a
 * saved trip DRAFT that the existing checkout chain consumes.
 *
 * This is the missing link between search_trip (which shows real cards) and
 * reprice_trip → create_checkout → request_booking_approval → initiate_payment
 * (which all operate on a draft_hash in `logs_bookings`). Without it GIA could
 * show packages but never start a booking.
 *
 * SAFETY — no model-supplied pricing ever enters the money path. The tool stores
 * ONLY the chosen option's module + authoritative id (+ pax). The very next step,
 * reprice_trip, re-fetches the live price from the DB (aiTripRevalidate*Item), so
 * the quote the traveller approves is always the platform's own number, never a
 * value the model typed. Risk R2 (reversible controlled-write: it writes a draft
 * row; it holds/charges nothing).
 *
 * Input: { module, option_id, adults?, children?, infants? }. option_id is the
 * `ref`/`id` GIA received in the search options for that card.
 *
 * Draft shape matches POST /api/ai/trip/save-draft exactly (tripBookingRoutes.php)
 * so the rest of the chain is unchanged.
 */
class selectOptionTool implements giaToolInterface
{
    public function name(): string
    {
        return 'select_option';
    }

    public function description(): string
    {
        return 'Select a specific option the traveler chose from the search results '
            . '(by its module and id) and start a booking draft for it. Call this when '
            . 'the traveler says they want a particular package/stay/tour/etc. Returns a '
            . 'draft reference; then call reprice_trip to confirm the live price before '
            . 'creating a checkout. It does not book or charge anything.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'module' => [
                    'type' => 'string',
                    'description' => 'The service of the chosen option: umrah, stays, tours, bus or visa.',
                ],
                'option_id' => [
                    'type' => 'string',
                    'description' => 'The id/ref of the chosen option exactly as shown in the search results.',
                ],
                'adults' => ['type' => 'integer', 'description' => 'Number of adults (default 1).'],
                'children' => ['type' => 'integer', 'description' => 'Number of children (default 0).'],
                'infants' => ['type' => 'integer', 'description' => 'Number of infants (default 0).'],
            ],
            'required' => ['module', 'option_id'],
        ];
    }

    public function riskClass(): string
    {
        return 'R2'; // reversible: creates a draft row; no money moves
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Selection is unavailable.'];
        }

        $module = strtolower(trim((string) ($input['module'] ?? '')));
        $optionId = trim((string) ($input['option_id'] ?? ''));
        if ($optionId === '') {
            return ['ok' => false, 'error_code' => 'AI_BAD_OPTION', 'message' => 'Tell me which option to set up.'];
        }

        // Only LOCAL-DB modules can be turned into a draft here — their price is
        // re-derived authoritatively by reprice_trip. Live-supplier modules
        // (flights/cars/rail/ferries/esim) go through their own booking pages.
        $allowed = ['umrah', 'stays', 'tours', 'bus', 'visa'];
        if (!in_array($module, $allowed, true)) {
            return [
                'ok' => false,
                'error_code' => 'AI_MODULE_NOT_BOOKABLE_INLINE',
                'message' => 'This one is booked on its own page — I can open it for you instead.',
            ];
        }

        $adults = max(0, (int) ($input['adults'] ?? 0));
        $children = max(0, (int) ($input['children'] ?? 0));
        $infants = max(0, (int) ($input['infants'] ?? 0));
        if ($adults <= 0) {
            $adults = 1; // sensible default; reprice caps to the package's own max_*
        }
        $currency = (string) ($context['currency'] ?? ($_SESSION['app_currency'] ?? 'USD'));
        if ($currency === '') {
            $currency = 'USD';
        }

        // --- Verify the option EXISTS and is live, and capture a display title.
        //     We do NOT trust any price here; reprice_trip re-derives it. ---
        $verified = $this->verifyOption($db, $module, $optionId);
        if (!$verified['ok']) {
            return ['ok' => false, 'error_code' => 'AI_OPTION_NOT_FOUND', 'message' => "I couldn't find that option anymore — it may have sold out. Want me to search again?"];
        }

        // --- Build the single-item draft. `item` carries ONLY identifiers + pax,
        //     so reprice_trip re-fetches the authoritative price from the DB. ---
        $item = $this->buildDraftItem($module, $verified, $adults, $children, $infants, $currency);

        $payload = [
            'type'         => 'ai_trip',
            'query'        => '',
            'currency'     => $currency,
            'items'        => [$item],
            'module_order' => [$module],
            'total'        => 0.0, // unknown until reprice; never a model-supplied figure
            'created_at'   => date('Y-m-d H:i:s'),
            'source'       => 'gia_select',
        ];
        $encoded = json_encode($payload);
        if ($encoded === false) {
            return ['ok' => false, 'error_code' => 'AI_DRAFT_ENCODE', 'message' => 'Could not prepare that selection. Please try again.'];
        }

        try {
            $hash = bin2hex(random_bytes(8));
            $ok = $db->insert('logs_bookings', [
                'hash'       => $hash,
                'data'       => $encoded,
                'user_id'    => (string) ($context['account_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$ok) {
                return ['ok' => false, 'error_code' => 'AI_DRAFT_SAVE', 'message' => 'Could not save your selection. Please try again.'];
            }
        } catch (\Throwable $e) {
            error_log('[gia] select_option save: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_DRAFT_SAVE', 'message' => 'Could not save your selection. Please try again.'];
        }

        return [
            'ok' => true,
            'data' => [
                'draft_hash' => $hash,
                'module'     => $module,
                'title'      => (string) $verified['title'],
                'adults'     => $adults,
                'children'   => $children,
                'infants'    => $infants,
                'currency'   => $currency,
                // Explicit signal to the model: price is NOT known yet; reprice next.
                'next'       => 'reprice_trip',
            ],
            'message' => 'Selected "' . $verified['title'] . '". Next: confirm the live price (reprice_trip) before checkout.',
        ];
    }

    /**
     * Confirm the chosen option exists + is live, returning its authoritative id
     * and a display title. Price is deliberately NOT returned/trusted here.
     *
     * @return array{ok:bool, id:int|string, title:string, extra?:array}
     */
    private function verifyOption($db, string $module, string $optionId): array
    {
        try {
            switch ($module) {
                case 'umrah': {
                    $id = (int) $optionId;
                    if ($id <= 0) { return ['ok' => false]; }
                    $row = $db->get('umrah', ['id', 'name'], ['id' => $id, 'status' => 1]);
                    if (!$row) { return ['ok' => false]; }
                    return ['ok' => true, 'id' => $id, 'title' => (string) ($row['name'] ?? 'Umrah package')];
                }
                case 'tours': {
                    $id = (int) $optionId;
                    if ($id <= 0) { return ['ok' => false]; }
                    $row = $db->get('tours', ['id', 'name'], ['id' => $id, 'status' => 1]);
                    if (!$row) { return ['ok' => false]; }
                    return ['ok' => true, 'id' => $id, 'title' => (string) ($row['name'] ?? 'Tour')];
                }
                case 'stays': {
                    $id = (int) $optionId;
                    if ($id <= 0) { return ['ok' => false]; }
                    $row = $db->get('stays', ['id', 'name'], ['id' => $id, 'status' => 1, 'listing_status' => 'approved']);
                    if (!$row) { return ['ok' => false]; }
                    return ['ok' => true, 'id' => $id, 'title' => (string) ($row['name'] ?? 'Hotel')];
                }
                case 'bus': {
                    $id = (int) $optionId;
                    if ($id <= 0) { return ['ok' => false]; }
                    $row = $db->get('bus_routes', ['id', 'bus_id'], ['id' => $id, 'status' => '1']);
                    if (!$row) { return ['ok' => false]; }
                    return ['ok' => true, 'id' => $id, 'title' => 'Bus trip', 'extra' => ['bus_id' => (int) ($row['bus_id'] ?? 0)]];
                }
                case 'visa': {
                    // Visa option_id is a composite (visaId-type-speed) or inquiry; we
                    // cannot cheaply re-verify a numeric row, so accept a non-empty id
                    // and let reprice re-derive (it fails closed if it can't price).
                    return ['ok' => true, 'id' => $optionId, 'title' => 'Visa'];
                }
            }
        } catch (\Throwable $e) {
            error_log('[gia] verifyOption ' . $module . ': ' . $e->getMessage());
        }
        return ['ok' => false];
    }

    /**
     * Build the draft item in the shape each module's revalidator reads: the
     * identifier lives under `item` (umrah_id/id/tour_id/...) plus pax; `params`
     * carries pax too (some revalidators read it there). No price is set.
     */
    private function buildDraftItem(string $module, array $verified, int $adults, int $children, int $infants, string $currency): array
    {
        $id = $verified['id'];
        $extra = is_array($verified['extra'] ?? null) ? $verified['extra'] : [];

        $itemPayload = [
            'id'       => (string) $id,
            'adults'   => $adults,
            'children' => $children,
            'infants'  => $infants,
        ];
        // Module-specific id key the revalidator expects.
        if ($module === 'umrah') {
            $itemPayload['umrah_id'] = (int) $id;
        } elseif ($module === 'tours') {
            $itemPayload['tour_id'] = (string) $id;
        } elseif ($module === 'bus') {
            $itemPayload['route_id'] = (int) $id;
            if (!empty($extra['bus_id'])) { $itemPayload['bus_id'] = (int) $extra['bus_id']; }
        }

        return [
            'module'   => $module,
            'kind'     => $module,
            'supplier' => $module,
            'title'    => (string) $verified['title'],
            'price'    => 0.0, // placeholder; reprice_trip fills the authoritative price
            'currency' => $currency,
            'item'     => $itemPayload,
            'params'   => [
                'adults'   => (string) $adults,
                'children' => (string) $children,
                'infants'  => (string) $infants,
                'currency' => $currency,
            ],
        ];
    }
}
