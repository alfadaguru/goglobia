<?php
// ============================================================================
// FILE: app/lib/umrah/listing.php
// Reusable Umrah package-card builder.
//
// This is the SINGLE source of truth for turning a search (destination / dates /
// duration / type / services / pax / currency) into real, priced, bookable Umrah
// package cards from the `umrah` table. It was extracted VERBATIM (behaviour-
// preserving) from the POST /api/umrah/listing handler so that:
//   - the web endpoint (app/routes/umrah/listingRoutes.php) keeps returning the
//     exact same cards it always did, and
//   - the GIA agent's search_trip tool can obtain the SAME real cards by calling
//     this function directly (no internal HTTP round-trip, no CSRF dance).
//
// It is strictly READ-ONLY: it selects rows and computes marked-up display prices.
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
        $destination = trim((string)($input['destination'] ?? ''));
        $startDate = trim((string)($input['start_date'] ?? ''));
        $duration = trim((string)($input['duration'] ?? 'any'));
        $umrahType = trim((string)($input['umrah_type'] ?? 'any'));
        $services = $input['services'] ?? 'any';
        $adultsReq = (int)($input['adults'] ?? 0);
        $childrenReq = (int)($input['children'] ?? 0);
        $infantsReq = (int)($input['infants'] ?? 0);
        // 0 (or omitted with explicit 0 from AI) = no LIMIT; positive values capped for safety
        $hasExplicitLimit = array_key_exists('items_limit', $input) || array_key_exists('per_page', $input);
        $rawLimit = $hasExplicitLimit
            ? (int)($input['items_limit'] ?? ($input['per_page'] ?? 0))
            : 8;
        $limit = $rawLimit <= 0 ? 0 : max(1, min(500, $rawLimit));
        $currency = strtoupper(trim((string)($input['currency'] ?? ($_SESSION['app_currency'] ?? 'USD'))));
        if ($currency === '') {
            $currency = 'USD';
        }

        $module = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah'])
            ?: $db->get('modules', '*', ['name' => 'umrah'])
            ?: ['markup_b2c' => 0, 'markup_type_b2c' => 'percentage'];

        $where = ['status' => 1];

        if (!empty($services) && $services !== 'any') {
            $serviceIds = is_array($services) ? $services : explode(',', (string)$services);
            $i = 0;
            foreach ($serviceIds as $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) {
                    continue;
                }
                $active = $db->get('umrah_settings', 'id', [
                    'id' => $sid,
                    'setting_type' => ['service', 'hotel', 'flight', 'car'],
                    'status' => 1,
                ]);
                if (!$active) {
                    continue;
                }
                $where['AND']['OR #svc' . $i] = [
                    'services[~]' => '[' . $sid . ']',
                    'services[~] #1' => '[' . $sid . ',',
                    'services[~] #2' => ',' . $sid . ']',
                    'services[~] #3' => ',' . $sid . ',',
                ];
                $i++;
            }
        }

        if ($umrahType !== '' && strtolower($umrahType) !== 'any') {
            $typeId = ctype_digit($umrahType) ? (int)$umrahType : 0;
            if ($typeId <= 0) {
                $typeId = (int)($db->get('umrah_settings', 'id', [
                    'setting_type' => 'umrah_type',
                    'setting_label' => $umrahType,
                    'status' => 1,
                ]) ?: 0);
            }
            if ($typeId > 0 && $db->get('umrah_settings', 'id', [
                'id' => $typeId,
                'setting_type' => 'umrah_type',
                'status' => 1,
            ])) {
                $where['umrah_type_id'] = $typeId;
            }
        }

        // Duration: umrah_settings.metadata only (no hardcoded buckets)
        if ($duration !== '' && strtolower($duration) !== 'any') {
            $durMeta = null;
            if (ctype_digit($duration)) {
                $durRow = $db->get('umrah_settings', ['metadata', 'status'], [
                    'id' => (int)$duration,
                    'setting_type' => 'duration',
                ]);
                if ($durRow && (int)($durRow['status'] ?? 0) === 1) {
                    $durMeta = json_decode((string)($durRow['metadata'] ?? ''), true);
                }
            }
            if (!is_array($durMeta)) {
                foreach (($db->select('umrah_settings', ['metadata'], [
                    'setting_type' => 'duration',
                    'status' => 1,
                ]) ?: []) as $cand) {
                    $m = json_decode((string)($cand['metadata'] ?? ''), true);
                    if (is_array($m) && strtolower((string)($m['code'] ?? '')) === strtolower($duration)) {
                        $durMeta = $m;
                        break;
                    }
                }
            }
            if (is_array($durMeta)) {
                $min = (int)($durMeta['min_days'] ?? 0);
                $max = array_key_exists('max_days', $durMeta) && $durMeta['max_days'] !== null && $durMeta['max_days'] !== ''
                    ? (int)$durMeta['max_days'] : null;
                if ($min >= 1) {
                    if ($max === null) {
                        $where['days[>=]'] = $min;
                    } elseif ($min === $max) {
                        $where['days'] = $min;
                    } else {
                        $where['days[<>]'] = [$min, $max];
                    }
                }
            }
        }

        // Destination = umrah.location (filter before LIMIT so city search is not truncated away)
        if ($destination !== '' && strtolower($destination) !== 'any') {
            $where['location[~]'] = $destination;
        }

        $where['ORDER'] = ['id' => 'DESC'];
        if ($limit > 0) {
            $where['LIMIT'] = $limit;
        }
        $rows = $db->select('umrah', '*', $where) ?: [];

        $cards = [];
        foreach ($rows as $u) {
            $maxAdults = (int)($u['max_adults'] ?? 0);
            $maxChildren = (int)($u['max_children'] ?? 0);
            $maxInfants = (int)($u['max_infants'] ?? 0);

            // Cap requested counts to this package's max_* from DB (no invented defaults)
            $adults = $adultsReq;
            if ($maxAdults > 0 && $adults > $maxAdults) {
                $adults = $maxAdults;
            }
            $children = $childrenReq;
            if ($maxChildren > 0 && $children > $maxChildren) {
                $children = $maxChildren;
            }
            $infants = $infantsReq;
            if ($maxInfants > 0 && $infants > $maxInfants) {
                $infants = $maxInfants;
            }

            $uCurrency = $u['currency'] ?: 'USD';
            $markedAdult = function_exists('MARKUP')
                ? MARKUP((float)($u['adult_price'] ?? 0), $module, $db, $uCurrency, $currency)
                : ['price' => (float)($u['adult_price'] ?? 0)];
            $markedChild = function_exists('MARKUP')
                ? MARKUP((float)($u['child_price'] ?? 0), $module, $db, $uCurrency, $currency)
                : ['price' => (float)($u['child_price'] ?? 0)];
            $markedInfant = function_exists('MARKUP')
                ? MARKUP((float)($u['infant_price'] ?? 0), $module, $db, $uCurrency, $currency)
                : ['price' => (float)($u['infant_price'] ?? 0)];
            $adultPrice = round((float)($markedAdult['price'] ?? 0), 2);
            $childPrice = round((float)($markedChild['price'] ?? 0), 2);
            $infantPrice = round((float)($markedInfant['price'] ?? 0), 2);
            $displayTotal = round(($adultPrice * $adults) + ($childPrice * $children) + ($infantPrice * $infants), 2);

            $typeLabel = '';
            if (!empty($u['umrah_type_id'])) {
                $typeLabel = (string)($db->get('umrah_settings', 'setting_label', [
                    'id' => $u['umrah_type_id'],
                    'status' => 1,
                ]) ?: '');
            }

            $image = '';
            if (!empty($u['img'])) {
                $decoded = json_decode($u['img'], true);
                if (is_array($decoded) && !empty($decoded[0])) {
                    $img = $decoded[0];
                    $url = is_array($img) ? (string)($img['url'] ?? '') : (string)$img;
                    if ($url !== '') {
                        $clean = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                        $image = (stripos($url, 'http') === 0) ? $url : (str_replace('modules/', '', root) . $clean);
                    }
                }
            }

            $loc = trim((string)($u['location'] ?? ''));
            $days = (int)($u['days'] ?? 0);

            $serviceIds = [];
            if (!empty($u['services'])) {
                $decodedSvc = json_decode((string)$u['services'], true);
                if (is_array($decodedSvc)) {
                    $serviceIds = $decodedSvc;
                } else {
                    $serviceIds = array_filter(array_map('intval', explode(',', (string)$u['services'])));
                }
            }
            $inclusions = [];
            if ($serviceIds !== []) {
                foreach (($db->select('umrah_settings', ['setting_label', 'icon'], [
                    'id' => $serviceIds,
                    'status' => 1,
                ]) ?: []) as $sd) {
                    $inclusions[] = [
                        'name' => (string)($sd['setting_label'] ?? ''),
                        'icon' => (string)($sd['icon'] ?? 'check_circle'),
                    ];
                }
            }

            $cards[] = [
                'id' => (string)$u['id'],
                'umrah_id' => (int)$u['id'],
                'kind' => 'umrah',
                'supplier' => 'umrah',
                'name' => (string)($u['name'] ?? 'Umrah'),
                'title' => (string)($u['name'] ?? 'Umrah'),
                'subtitle' => trim(($loc !== '' ? $loc . ' · ' : '') . ($days > 0 ? $days . ' days' : '') . ($typeLabel !== '' ? ' · ' . $typeLabel : '')),
                'location' => $loc,
                'description' => trim(strip_tags((string)($u['description'] ?? ($u['desc'] ?? '')))),
                'days' => $days,
                'nights' => (int)($u['nights'] ?? 0),
                'umrah_type' => $typeLabel,
                'umrah_type_id' => (int)($u['umrah_type_id'] ?? 0),
                'start_date' => $startDate,
                'adults' => $adults,
                'children' => $children,
                'infants' => $infants,
                'max_adults' => $maxAdults,
                'max_children' => $maxChildren,
                'max_infants' => $maxInfants,
                'price' => $displayTotal,
                'adult_price' => $adultPrice,
                'child_price' => $childPrice,
                'infant_price' => $infantPrice,
                'currency' => $currency,
                'image' => $image,
                'slug' => (string)($u['slug'] ?? ''),
                'inclusions' => $inclusions,
                'services' => array_values(array_map('strval', $serviceIds)),
            ];
        }

        return ['cards' => $cards];
    }
}
