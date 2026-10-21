<?php
// ============================================================================
// FILE: app/lib/umrah/listing.php
// Reusable Umrah package-card builder.
//
// Turns a search (destination city / dates / pilgrims) into real, priced, bookable
// Umrah cards from the UMRAH v2 model (umrah_departures + tiers) — the SAME source
// the website /umrah page reads (umrahV2PublishedDepartures), so GIA and the web
// can never show different inventory. Each card's id is the bookable v2
// `departure_tier_id`. The legacy v1 `umrah` table is intentionally NOT read here
// (it held stale test rows that made GIA show phantom packages).
//
// It is strictly READ-ONLY: it reads published departures and their resolved prices.
// It never writes, holds, books or charges anything.
// ============================================================================

if (!function_exists('umrahListingCards')) {
    /**
     * Build real, priced Umrah package cards for a search.
     *
     * @param array $input Keys (all optional): destination, start_date, duration,
     *                      umrah_type, services (csv|array|'any'), adults, children,
     *                      infants, items_limit|per_page (0 = no limit; default 8),
     *                      currency.
     * @param object $db   Medoo instance.
     * @return array{cards: array<int,array<string,mixed>>} Priced package cards.
     */
    function umrahListingCards(array $input, $db): array
    {
        // ====================================================================
        // UMRAH v2. GIA reads the SAME published-departure inventory the website
        // /umrah page shows (umrahV2PublishedDepartures), so chat and web can never
        // diverge. The old `umrah` (v1) table is NOT read here anymore — that dead
        // table held stale test rows (Multan/Simple/Ramadan) that were never on the
        // live site and caused GIA to show phantom packages. One card per published
        // departure; the card id IS the bookable `departure_tier_id` (cheapest tier
        // as the entry price), so select_option/reprice/book act on a real v2 unit.
        // ====================================================================
        $destination = strtolower(trim((string)($input['destination'] ?? '')));
        $durationReq = strtolower(trim((string)($input['duration'] ?? 'any')));
        $startYmd = trim((string)($input['start_date_ymd'] ?? ''));
        $startRaw = trim((string)($input['start_date'] ?? ''));
        // Pilgrims: v2 prices PER PILGRIM (flat), so total pax drives the "from"
        // total. We never invent a count — 0 means "show the per-pilgrim price".
        $pax = max(0, (int)($input['adults'] ?? 0)) + max(0, (int)($input['children'] ?? 0)) + max(0, (int)($input['infants'] ?? 0));

        $hasExplicitLimit = array_key_exists('items_limit', $input) || array_key_exists('per_page', $input);
        $rawLimit = $hasExplicitLimit ? (int)($input['items_limit'] ?? ($input['per_page'] ?? 0)) : 8;
        $limit = $rawLimit <= 0 ? 0 : max(1, min(500, $rawLimit));

        if (!function_exists('umrahV2PublishedDepartures')) {
            $svc = dirname(__DIR__) . '/umrah/services.php';
            if (is_file($svc)) { require_once $svc; }
        }
        if (!function_exists('umrahV2PublishedDepartures')) {
            return ['cards' => []]; // no v2 source available -> show nothing (never fabricate)
        }

        $departures = umrahV2PublishedDepartures($db);
        if (!is_array($departures) || $departures === []) {
            return ['cards' => []];
        }

        // Optional, CONSERVATIVE filtering — only narrow when the traveller clearly
        // asked for a city or month; a vague "umrah" shows everything (never hide
        // real inventory on an ambiguous query).
        $monthNeedle = '';
        if ($startYmd !== '' && strtotime($startYmd) !== false) {
            $monthNeedle = strtolower(date('F Y', strtotime($startYmd)));
        } elseif ($startRaw !== '' && strtotime($startRaw) !== false) {
            $monthNeedle = strtolower(date('F Y', strtotime($startRaw)));
        }

        $cards = [];
        foreach ($departures as $d) {
            $originCity = (string)($d['origin_city'] ?? '');
            $monthBucket = (string)($d['month_bucket'] ?? '');

            // City filter: match the departure city (v2 "location" = origin city).
            if ($destination !== '' && $destination !== 'any'
                && stripos($originCity, $destination) === false) {
                continue;
            }
            // Month filter: match the departure's month bucket when a date was given.
            if ($monthNeedle !== '' && stripos($monthBucket, $monthNeedle) === false) {
                continue;
            }

            $depDate = (string)($d['departure_date'] ?? '');
            $retDate = (string)($d['return_date'] ?? '');
            $days = 0;
            if ($depDate !== '' && $retDate !== '' && strtotime($depDate) && strtotime($retDate)) {
                $days = max(0, (int) round((strtotime($retDate) - strtotime($depDate)) / 86400));
            }

            $unit = round((float)($d['unit_price'] ?? 0), 2);
            $currency = strtoupper((string)($d['currency'] ?? 'NGN'));
            // "From" total: unit × pilgrims when a count was given, else the
            // per-pilgrim unit price. NO per-age split — v2 is flat per pilgrim.
            $displayTotal = $pax > 0 ? round($unit * $pax, 2) : $unit;

            // Image: v2 hero_image (absolute-ise relative paths the way the site does).
            $image = (string)($d['hero_image'] ?? '');
            if ($image !== '' && stripos($image, 'http') !== 0) {
                $base = defined('root') ? str_replace('modules/', '', root) : '/';
                $image = $base . ltrim(str_replace(['modules/modules/', 'modules/'], '', $image), '/');
            }

            $tierLabel = (string)($d['tier_label'] ?? 'Standard');
            $cityLabel = $originCity !== '' ? $originCity : 'Kano';
            $dateLabel = ($depDate !== '' && strtotime($depDate))
                ? date('j M', strtotime($depDate)) . ($retDate && strtotime($retDate) ? ' → ' . date('j M Y', strtotime($retDate)) : '')
                : $monthBucket;
            $name = $cityLabel . ($days > 0 ? ' · ' . $days . '-day Umrah' : ' Umrah');

            $cards[] = [
                // The bookable unit — select_option/reprice/book use this v2 id.
                'id'                => (string)($d['departure_tier_id'] ?? ''),
                'departure_tier_id' => (int)($d['departure_tier_id'] ?? 0),
                'departure_id'      => (int)($d['departure_id'] ?? 0),
                'kind'              => 'umrah',
                'supplier'          => 'umrah',
                'name'              => $name,
                'title'             => $name,
                'subtitle'          => trim($cityLabel . ' · ' . $dateLabel . ($tierLabel !== '' ? ' · from ' . $tierLabel : '')),
                'location'          => $cityLabel,
                'days'              => $days,
                'nights'            => $days > 0 ? max(0, $days - 1) : 0,
                'departure_date'    => $depDate,
                'return_date'       => $retDate,
                'month_bucket'      => $monthBucket,
                'tier_code'         => (string)($d['tier_code'] ?? ''),
                'tier_label'        => $tierLabel,
                'tier_count'        => (int)($d['tier_count'] ?? 1),
                'room_sharing'      => (string)($d['room_sharing'] ?? ''),
                'pax'               => $pax,
                // v2 unit is per pilgrim; price = the "from" total for display.
                'price'             => $displayTotal,
                'unit_price'        => $unit,
                'price_min'         => round((float)($d['price_min'] ?? $unit), 2),
                'price_max'         => round((float)($d['price_max'] ?? $unit), 2),
                'currency'          => $currency,
                'availability'      => (string)($d['availability'] ?? 'available'),
                'image'             => $image,
                'slug'              => (string)($d['slug'] ?? ''),
            ];

            if ($limit > 0 && count($cards) >= $limit) {
                break;
            }
        }

        return ['cards' => $cards];
    }
}
