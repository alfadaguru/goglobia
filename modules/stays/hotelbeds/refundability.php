<?php
/**
 * Hotelbeds refundability helpers — safe to load from modules API (no helpers.php conflicts).
 */

if (function_exists('hotelbedsResolveRefundabilityFromRate')) {
    return;
}

function hotelbedsParsePolicyDate(?string $date): ?DateTime
{
    if ($date === null || trim($date) === '') {
        return null;
    }

    // Hotelbeds returns cancellation deadlines in the hotel destination's local time with an
    // explicit UTC offset (e.g. 2026-08-10T23:59:00+02:00). That format must be tried first so
    // the offset is preserved on the returned DateTime — the API doc is explicit that these
    // times are destination-local, not the customer's local time, so we must not normalize them
    // to server-default timezone.
    $formats = [
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s',
        'Y-m-d H:i:s',
        'Y-m-d',
        'd/m/Y H:i:s',
        'd/m/Y',
        'd-m-Y',
        'D, d M Y H:i:s O',
    ];

    foreach ($formats as $format) {
        $parsed = DateTime::createFromFormat($format, $date);
        $errors = DateTime::getLastErrors();
        if ($parsed && empty($errors['warning_count']) && empty($errors['error_count'])) {
            return $parsed;
        }
    }

    // Native constructor also preserves an offset found in the string (unlike
    // strtotime()+setTimestamp(), which collapses to server-default timezone).
    try {
        return new DateTime($date);
    } catch (Exception $e) {
        // fall through to strtotime() below
    }

    $timestamp = strtotime($date);
    if ($timestamp !== false && $timestamp > 0) {
        return (new DateTime())->setTimestamp($timestamp);
    }

    return null;
}

function hotelbedsRateKeyRateClass(?string $rateKey): string
{
    if ($rateKey === null || trim($rateKey) === '') {
        return '';
    }

    if (preg_match('/~~~(NOR|NRF)~~/i', $rateKey, $matches)) {
        return strtoupper($matches[1]);
    }

    $parts = explode('|', $rateKey);

    return strtoupper(trim((string) ($parts[6] ?? '')));
}

function hotelbedsNormalizeRateCancellationPolicies(array $rate, array $override = []): array
{
    if (!empty($override)) {
        $raw = $override;
    } else {
        $raw = $rate['cancellationPolicies'] ?? [];
    }

    if (!is_array($raw)) {
        return [];
    }

    $normalized = [];
    foreach ($raw as $policy) {
        if (!is_array($policy)) {
            continue;
        }

        $from = $policy['from'] ?? $policy['dateFrom'] ?? $policy['fromDate'] ?? '';
        $normalized[] = [
            'amount' => (float) ($policy['amount'] ?? $policy['hotelAmount'] ?? 0),
            'from' => is_string($from) ? trim($from) : '',
        ];
    }

    return $normalized;
}

function hotelbedsRateIsNonRefundable(array $rate): bool
{
    $rateClass = strtoupper(trim((string) ($rate['rateClass'] ?? '')));
    if ($rateClass === '') {
        $rateClass = hotelbedsRateKeyRateClass($rate['rateKey'] ?? '');
    }

    if ($rateClass === 'NRF') {
        return true;
    }

    $contract = hotelbedsRateKeyRateClass($rate['rateKey'] ?? '');
    if ($contract === 'NRF' || strpos($contract, 'NRF-') === 0 || strpos($contract, 'NRF_') === 0) {
        return true;
    }

    if (!empty($rate['promotions']) && is_array($rate['promotions'])) {
        foreach ($rate['promotions'] as $promo) {
            if (!is_array($promo)) {
                continue;
            }

            $code = trim((string) ($promo['code'] ?? ''));
            if ($code === '073') {
                return true;
            }

            $name = strtolower(trim((string) ($promo['name'] ?? '')));
            if ($name !== '' && preg_match('/^non-?refundable\b/', $name)) {
                return true;
            }
        }
    }

    return false;
}

function hotelbedsResolveRefundabilityFromRate(array $rate, array $cancellationPolicies = []): array
{
    if (hotelbedsRateIsNonRefundable($rate)) {
        return ['refundable' => 0, 'cancellation_free' => 0];
    }

    if (empty($cancellationPolicies)) {
        $cancellationPolicies = hotelbedsNormalizeRateCancellationPolicies($rate);
    } else {
        $cancellationPolicies = hotelbedsNormalizeRateCancellationPolicies(['cancellationPolicies' => $cancellationPolicies]);
    }

    if (empty($cancellationPolicies)) {
        return ['refundable' => 1, 'cancellation_free' => 1];
    }

    usort($cancellationPolicies, static function ($a, $b) {
        $aDate = hotelbedsParsePolicyDate($a['from'] ?? null);
        $bDate = hotelbedsParsePolicyDate($b['from'] ?? null);
        if ($aDate === null && $bDate === null) {
            return 0;
        }
        if ($aDate === null) {
            return 1;
        }
        if ($bDate === null) {
            return -1;
        }

        return $aDate <=> $bDate;
    });

    $now = new DateTime();
    $cancellationFree = 0;

    foreach ($cancellationPolicies as $policy) {
        if (!is_array($policy)) {
            continue;
        }
        $from = hotelbedsParsePolicyDate($policy['from'] ?? null);
        if ($from !== null && $from > $now) {
            $cancellationFree = 1;
            break;
        }
    }

    if ($cancellationFree === 0) {
        $first = $cancellationPolicies[0] ?? [];
        $firstFrom = hotelbedsParsePolicyDate($first['from'] ?? null);
        $firstAmount = (float) ($first['amount'] ?? 0);
        if ($firstFrom === null && $firstAmount <= 0.0001) {
            $cancellationFree = 1;
        } elseif ($firstFrom !== null && $firstFrom <= $now && $firstAmount <= 0.0001) {
            $cancellationFree = 1;
        }
    }

    return [
        'refundable' => 1,
        'cancellation_free' => $cancellationFree,
    ];
}

/**
 * Human-readable cancellation policy sentence, shared by rooms listing and the
 * book-time CheckRate route so the room-selection page and the post-RECHECK
 * refresh always describe the same policy the same way.
 *
 * @param array  $cancellationPolicies Normalized [['amount' => float, 'from' => string], ...],
 *                                     as produced by hotelbedsNormalizeRateCancellationPolicies()
 *                                     or built inline with the same shape.
 * @param string $currency             Currency label to display next to the fee amount.
 */
function hotelbedsFormatCancellationText(array $cancellationPolicies, string $currency = ''): string
{
    $defaultText = 'No cancellation information available.';

    $first = $cancellationPolicies[0] ?? null;
    if (!is_array($first)) {
        return $defaultText;
    }

    $amount = $first['amount'] ?? null;
    $date = $first['from'] ?? null;

    if ($amount === null || $amount === '' || empty($date)) {
        return $defaultText;
    }

    $dt = hotelbedsParsePolicyDate((string) $date);
    if ($dt === null) {
        return $defaultText;
    }

    if ($dt <= new DateTime()) {
        return 'The free cancellation period for this room has passed. A cancellation fee now applies.';
    }

    $formattedAmount = number_format((float) $amount, 2);
    $currencySuffix = $currency !== '' ? ' ' . $currency : '';

    return "Free cancellation until {$dt->format('F d, Y')} at {$dt->format('H:i')}. "
        . "After that, a cancellation fee of {$formattedAmount}{$currencySuffix} will apply.";
}
