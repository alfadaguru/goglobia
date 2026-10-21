<?php

namespace App\lib\ai;

/**
 * GIA tool: fetch real, individual, bookable offers for a LIVE-SUPPLIER module
 * (flights / cars / rail / ferries / esim) on demand.
 *
 * search_trip returns local-DB cards for umrah/stays/tours/bus/visa but only a
 * deep-link intent for live-supplier modules (their real offers need a slow
 * external call). This tool runs that call when the traveller actually wants to
 * pick one, and returns a short list of individual offers. Each offer's stable
 * identifiers are cached server-side keyed by a short `ref`; the model only ever
 * sees/handles the ref + display fields — never the raw pricing or tokens — and
 * passes the ref to select_option to start a booking.
 *
 * Risk R1 (read-only live lookup — same as search). It can be slow (external
 * supplier call); the runtime's wall-clock bound still applies.
 */
class fetchLiveOffersTool implements giaToolInterface
{
    public function name(): string
    {
        return 'fetch_live_offers';
    }

    public function description(): string
    {
        return 'Fetch real, individual, bookable options for a live-supplier service '
            . '(flights, cars, rail, ferries or esim) when the traveler wants to pick '
            . 'one. Pass the module and the search params from the earlier search_trip '
            . 'result for that module. Returns a short list of options with prices and a '
            . 'ref for each; use select_option with a ref to start the booking. This runs '
            . 'a live supplier lookup; it does not book or charge anything.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'module' => [
                    'type' => 'string',
                    'description' => 'flights, cars, rail, ferries or esim.',
                ],
            ],
            'required' => ['module'],
        ];
    }

    public function riskClass(): string
    {
        return 'R1'; // read-only live lookup
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Live options are unavailable.'];
        }
        $module = strtolower(trim((string) ($input['module'] ?? '')));
        $live = ['flights', 'cars', 'rail', 'ferries', 'esim'];
        if (!in_array($module, $live, true)) {
            return ['ok' => false, 'error_code' => 'AI_NOT_LIVE_MODULE', 'message' => 'That service shows its options directly — no live lookup needed.'];
        }

        // The search params + suppliers come from the LAST search_trip for this module,
        // cached by the route in the trusted context (never model-supplied pricing).
        $byModule = is_array($context['last_search_params'] ?? null) ? $context['last_search_params'] : [];
        $entry = is_array($byModule[$module] ?? null) ? $byModule[$module] : [];
        $params = is_array($entry['params'] ?? null) ? $entry['params'] : [];
        $suppliers = is_array($entry['suppliers'] ?? null) ? $entry['suppliers'] : [];
        if ($params === []) {
            return ['ok' => false, 'error_code' => 'AI_NO_SEARCH', 'message' => "Let's search first — tell me the trip details and I'll pull up live options."];
        }

        require_once dirname(__DIR__) . '/gia_live_offers.php';
        // The helper functions live in THIS namespace (App\lib\ai), so check the
        // namespaced name — function_exists() on a bare string checks global only.
        if (!function_exists(__NAMESPACE__ . '\\gia_live_offers_fetch')) {
            return ['ok' => false, 'error_code' => 'AI_OFFERS_UNAVAILABLE', 'message' => 'Live options are unavailable right now.'];
        }

        $res = gia_live_offers_fetch($db, $module, $params, 6, $suppliers);
        if (empty($res['ok']) || empty($res['offers'])) {
            return ['ok' => false, 'error_code' => 'AI_NO_OFFERS', 'message' => (string) ($res['message'] ?? 'No live options came back. Want me to try different details?')];
        }

        // Cache each offer's STABLE payload server-side keyed by ref, so select_option
        // can retrieve the authoritative fields without the model ever handling them.
        // DB-backed (per conversation) — NOT $_SESSION — so it survives stateless
        // messaging webhooks (WhatsApp/Telegram/Messenger have no PHP session). The
        // conv_id comes from the trusted tool context (route/adapter-set).
        $convId = (int) ($context['conv_id'] ?? 0);
        if ($convId > 0 && !function_exists('gia_offer_cache_put')) {
            $crm = dirname(__DIR__) . '/gia_crm.php';
            if (is_file($crm)) { require_once $crm; }
        }
        $modelOffers = [];
        foreach ($res['offers'] as $o) {
            $ref = (string) ($o['ref'] ?? '');
            if ($ref === '') { continue; }
            if ($convId > 0 && function_exists('gia_offer_cache_put')) {
                gia_offer_cache_put($db, $convId, $module . ':' . $ref, [
                    'stable'   => is_array($o['stable'] ?? null) ? $o['stable'] : [],
                    'supplier' => (string) ($o['supplier'] ?? ''),
                    'title'    => (string) ($o['title'] ?? ''),
                    'expires'  => (bool) ($o['expires'] ?? false),
                    'cached_at'=> date('Y-m-d H:i:s'),
                ]);
            }
            // Model-facing: display only + the ref it will pass to select_option.
            $modelOffers[] = array_filter([
                'ref'      => $ref,
                'title'    => (string) ($o['title'] ?? ''),
                'subtitle' => (string) ($o['subtitle'] ?? ''),
                'price'    => $o['price'] ?? null,
                'currency' => (string) ($o['currency'] ?? ''),
                'expires'  => (bool) ($o['expires'] ?? false),
            ], static fn($v) => $v !== '' && $v !== null);
        }

        return [
            'ok' => true,
            'data' => [
                'module'  => $module,
                'offers'  => $modelOffers,
                'expires' => (bool) ($res['offers'][0]['expires'] ?? false),
                // Full offers (incl. display image/subtitle) for the UI to render as cards.
                'cards'   => $res['offers'],
            ],
            'message' => 'Found ' . count($modelOffers) . ' live ' . $module . ' options.',
        ];
    }
}
