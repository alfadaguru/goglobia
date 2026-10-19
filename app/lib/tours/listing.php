<?php
// ============================================================================
// FILE: app/lib/tours/listing.php
// Reusable local-inventory Tours card builder for GIA.
//
// The public tours listing (app/routes/api/tours/listingRoutes.php, POST
// /api/tours) enforces a strict field whitelist, throws on missing fields, and
// fires triggerWebhook('tours/search', ...). GIA must not throw on a loose NL
// query nor fire that side-effect webhook, so this extracts ONLY the read-only
// local lookup + card assembly (tour-type resolution, duration/pax filter,
// MARKUP pricing) into a plain function GIA calls directly with $db.
//
// Prices use the SAME MARKUP() engine and discount logic as the source listing,
// so GIA quotes exactly what the tours listing page shows. Card shape is
// normalized to the GIA result-card contract (name / subtitle / price /
// currency / image / slug / inclusions).
// ============================================================================

if (!function_exists('toursListingCards')) {
    /**
     * Build real, local tours cards for a destination/duration/pax.
     *
     * @param array $input Keys: destination, duration (any|1|2-3|4-7|8-14|15+),
     *                     tour_type (label|'any'), adults, children, currency,
     *                     items_limit (0 = default cap).
     * @param object $db   Medoo instance.
     * @return array{cards: array<int,array<string,mixed>>}
     */
    function toursListingCards(array $input, $db): array
    {
        $destination = trim((string)($input['destination'] ?? ''));
        if ($destination === '' || strtolower($destination) === 'any') {
            return ['cards' => []];
        }
        $duration = strtolower(trim((string)($input['duration'] ?? 'any')));
        $validDurations = ['any', '1', '2-3', '4-7', '8-14', '15+'];
        if (!in_array($duration, $validDurations, true)) {
            $duration = 'any';
        }
        $tourTypeRaw = strtolower(trim((string)($input['tour_type'] ?? '')));
        $tourType = ($tourTypeRaw === '' || $tourTypeRaw === 'any') ? '' : $tourTypeRaw;
        $adults = max(1, min(20, (int)($input['adults'] ?? 1)));
        $children = max(0, min(20, (int)($input['children'] ?? 0)));
        $rawLimit = (int)($input['items_limit'] ?? 0);
        $limit = $rawLimit <= 0 ? 6 : max(1, min(50, $rawLimit));

        // ---- Tour-type label map + fuzzy resolution (verbatim from the route) ----
        $tourTypeMap = [];
        foreach (($db->select('tours_settings', ['id', 'setting_label'], [
            'setting_type' => 'tour_type',
            'status' => 1,
        ]) ?: []) as $type) {
            $tourTypeMap[(int)$type['id']] = $type['setting_label'];
        }
        $normalizeType = static function ($value) {
            $value = strtolower(trim((string)$value));
            return preg_replace('/[^a-z0-9]+/', '', $value);
        };
        $tourTypeId = null;
        if ($tourType !== '') {
            $tourTypeNorm = $normalizeType($tourType);
            foreach ($tourTypeMap as $typeId => $typeLabel) {
                $labelNorm = $normalizeType($typeLabel);
                if ($labelNorm !== '' && $labelNorm === $tourTypeNorm) {
                    $tourTypeId = (int)$typeId;
                    break;
                }
            }
            if ($tourTypeId === null) {
                foreach ($tourTypeMap as $typeId => $typeLabel) {
                    $labelNorm = $normalizeType($typeLabel);
                    if ($labelNorm === '') {
                        continue;
                    }
                    if (strpos($labelNorm, $tourTypeNorm) !== false || strpos($tourTypeNorm, $labelNorm) !== false) {
                        $tourTypeId = (int)$typeId;
                        break;
                    }
                }
            }
            if ($tourTypeId === null) {
                $aliases = [
                    'cultural' => ['cultural', 'historical', 'heritage', 'sightseeing', 'city'],
                    'city' => ['city', 'sightseeing'],
                    'wildlife' => ['wildlife', 'safari'],
                    'beach' => ['beach'],
                    'historical' => ['historical', 'heritage'],
                    'food' => ['food', 'drink', 'dinner', 'lunch'],
                    'shopping' => ['shopping'],
                    'adventure' => ['adventure'],
                ];
                if (isset($aliases[$tourTypeNorm])) {
                    foreach ($tourTypeMap as $typeId => $typeLabel) {
                        $labelNorm = $normalizeType($typeLabel);
                        foreach ($aliases[$tourTypeNorm] as $keyword) {
                            $keywordNorm = $normalizeType($keyword);
                            if ($keywordNorm !== '' && strpos($labelNorm, $keywordNorm) !== false) {
                                $tourTypeId = (int)$typeId;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        // ---- Inclusion map (for card chips) ----
        $settingsMap = ['inclusion' => []];
        foreach (($db->select('tours_settings', ['id', 'setting_type', 'setting_label'], [
            'setting_type' => ['inclusion', 'exclusion'],
            'status' => 1,
        ]) ?: []) as $row) {
            if (($row['setting_type'] ?? '') === 'inclusion') {
                $settingsMap['inclusion'][(int)$row['id']] = $row['setting_label'];
            }
        }

        // ---- Currency + module (for MARKUP) ----
        $selectedCurrency = $db->get('currencies', ['name'], ['default' => '1']);
        $targetCurrency = $selectedCurrency['name'] ?? 'USD';
        $module = $db->get('modules', '*', ['name' => 'tours', 'type' => 'tours', 'status' => 1])
            ?: $db->get('modules', '*', ['name' => 'tours', 'status' => 1]);

        // ---- Filter (verbatim) ----
        $where = ['status' => 1];
        $searchTerms = array_filter(explode(' ', trim($destination)));
        if (!empty($searchTerms)) {
            $where['OR #search_filter'] = [
                'name[~]' => $searchTerms,
                'location[~]' => $searchTerms,
                'address[~]' => $searchTerms,
            ];
        }
        if ($duration !== 'any') {
            if ($duration === '1') {
                $where['days'] = 1;
            } elseif ($duration === '2-3') {
                $where['days[>=]'] = 2;
                $where['days[<=]'] = 3;
            } elseif ($duration === '4-7') {
                $where['days[>=]'] = 4;
                $where['days[<=]'] = 7;
            } elseif ($duration === '8-14') {
                $where['days[>=]'] = 8;
                $where['days[<=]'] = 14;
            } elseif ($duration === '15+') {
                $where['days[>=]'] = 15;
            }
        }
        if ($tourTypeId !== null) {
            $where['tour_type_id'] = $tourTypeId;
        }
        $where['max_adults[>=]'] = $adults;
        if ($children > 0) {
            $where['max_children[>=]'] = $children;
        }
        $where['ORDER'] = ['id' => 'DESC'];
        $where['LIMIT'] = $limit;

        $rows = $db->select('tours', '*', $where) ?: [];
        $baseUrl = rtrim(root, '/');

        $cards = [];
        foreach ($rows as $tour) {
            $tourCurrency = strtoupper((string)($tour['currency'] ?? 'USD'));
            $adultBase = (float)($tour['adult_price'] ?? 0);
            $childBase = (float)($tour['child_price'] ?? 0);

            $actualTotal = ($adultBase * $adults) + ($childBase * $children);
            $discountPercentage = (float)($tour['discount_percentage'] ?? 0);
            if ($discountPercentage > 0) {
                $actualTotal -= ($actualTotal * ($discountPercentage / 100));
            }
            $actualTotal = max(0, round($actualTotal, 2));

            $marked = function_exists('MARKUP')
                ? MARKUP($actualTotal, $module ?: [], $db, $tourCurrency, $targetCurrency)
                : ['price' => $actualTotal];
            $displayTotal = round((float)($marked['price'] ?? 0), 2);

            // Primary image (verbatim resolution).
            $images = [];
            $primaryImage = '';
            if (!empty($tour['img'])) {
                $decodedImages = json_decode((string)$tour['img'], true);
                if (is_array($decodedImages)) {
                    foreach ($decodedImages as $imgRow) {
                        $imgPath = is_array($imgRow) ? trim((string)($imgRow['url'] ?? '')) : trim((string)$imgRow);
                        if ($imgPath === '') {
                            continue;
                        }
                        $full = (stripos($imgPath, 'http://') === 0 || stripos($imgPath, 'https://') === 0)
                            ? $imgPath
                            : $baseUrl . $imgPath;
                        $images[] = $full;
                        if ($primaryImage === '' && is_array($imgRow) && !empty($imgRow['default'])) {
                            $primaryImage = $full;
                        }
                    }
                }
            }
            if ($primaryImage === '' && !empty($images)) {
                $primaryImage = $images[0];
            }

            // Inclusions as card chips.
            $inclusions = [];
            $inclusionIds = json_decode((string)($tour['inclusions'] ?? '[]'), true);
            if (is_array($inclusionIds)) {
                foreach ($inclusionIds as $id) {
                    $id = (int)$id;
                    if (isset($settingsMap['inclusion'][$id])) {
                        $inclusions[] = ['name' => $settingsMap['inclusion'][$id], 'icon' => 'check_circle'];
                    }
                }
            }

            $loc = (string)($tour['location'] ?? '');
            $days = (int)($tour['days'] ?? 0);
            $typeLabel = $tourTypeMap[(int)($tour['tour_type_id'] ?? 0)] ?? '';

            $cards[] = [
                'id'          => (string)$tour['id'],
                'kind'        => 'tours',
                'supplier'    => 'tours',
                'name'        => (string)($tour['name'] ?? 'Tour'),
                'title'       => (string)($tour['name'] ?? 'Tour'),
                'subtitle'    => trim(($loc !== '' ? $loc : '') . ($days > 0 ? ' · ' . $days . ' days' : '') . ($typeLabel !== '' ? ' · ' . $typeLabel : '')),
                'location'    => $loc,
                'days'        => $days,
                'nights'      => (int)($tour['nights'] ?? 0),
                'stars'       => (int)($tour['stars'] ?? 0),
                'rating'      => (float)($tour['rating_average'] ?? 0),
                'tour_type'   => (string)$typeLabel,
                'adults'      => $adults,
                'children'    => $children,
                'price'       => $displayTotal,
                'currency'    => $targetCurrency,
                'image'       => $primaryImage,
                'slug'        => (string)($tour['slug'] ?? ''),
                'inclusions'  => $inclusions,
            ];
        }

        return ['cards' => $cards];
    }
}
