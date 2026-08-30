<?php if (!isset($SECURE)) {
    die('Direct access not permitted');
}

$leg = is_array($journey ?? null) && isset($journey[0]) ? $journey[0] : (is_array($journey ?? null) ? $journey : []);
$fromTs = (int)($leg['from_date_time'] ?? 0);
$toTs = (int)($leg['to_date_time'] ?? 0);
$fromCode = trim((string)($leg['from_station_code'] ?? ''));
$toCode = trim((string)($leg['to_station_code'] ?? ''));
if (!function_exists('_train_station_label') && isset($db)) {
    require_once dirname(__DIR__, 5) . '/modules/rail/train/stations.php';
}
if (isset($db) && function_exists('_train_station_label')) {
    $fromStation = $fromCode !== '' ? _train_station_label($db, $fromCode) : '';
    $toStation = $toCode !== '' ? _train_station_label($db, $toCode) : '';
} else {
    $fromStation = trim((string)($leg['from_station_english'] ?? $fromCode));
    $toStation = trim((string)($leg['to_station_english'] ?? $toCode));
}
if ($fromStation === '' && $fromCode !== '') {
    $fromStation = $fromCode;
}
if ($toStation === '' && $toCode !== '') {
    $toStation = $toCode;
}
$departureLabel = $fromTs > 0 ? date('D, M d, Y H:i', $fromTs) : 'TBC';
$arrivalLabel = $toTs > 0 ? date('D, M d, Y H:i', $toTs) : 'TBC';
$trainNo = trim((string)($leg['traffic_no'] ?? ''));
$seatLabel = trim((string)($leg['seat_name'] ?? $leg['seat_class'] ?? ''));
$pnrLabel = trim((string)($pnr ?? ''));
$invoiceUrl = $invoiceUrl ?? (root . 'invoice/rail/' . ($invoiceId ?? ''));
?>

<?php if (($paymentStatus ?? '') === 'paid'): ?>
<div style="background: #ecfdf5; border-left: 4px solid #10b981; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #065f46; margin-bottom: 4px;">PAYMENT RECEIVED</div>
    <div style="font-size: 13px; color: #047857;">Your train booking payment has been processed successfully.</div>
</div>
<?php else: ?>
<div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #92400e; margin-bottom: 4px;">BOOKING RECEIVED</div>
    <div style="font-size: 13px; color: #b45309;">Your train reservation is pending payment.</div>
</div>
<?php endif; ?>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 24px;">
        Hello <strong><?= htmlspecialchars(trim(($firstName ?? '') . ' ' . ($lastName ?? ''))) ?></strong>,
    </div>

    <div style="font-size: 14px; color: #4a5568; margin-bottom: 32px; line-height: 1.6;">
        <?= (($paymentStatus ?? '') === 'paid')
            ? 'Your train booking <strong>#' . htmlspecialchars((string)($invoiceId ?? '')) . '</strong> is confirmed.'
            : 'Thank you for your train booking <strong>#' . htmlspecialchars((string)($invoiceId ?? '')) . '</strong>. Please complete payment to confirm your tickets.' ?>
        <?php if ($pnrLabel !== ''): ?>
            PNR: <strong><?= htmlspecialchars($pnrLabel) ?></strong>
        <?php endif; ?>
    </div>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
        <div style="background: #4338ca; color: #ffffff; padding: 12px 16px;">
            <div style="font-size: 15px; font-weight: 600;">Train <?= htmlspecialchars($trainNo) ?></div>
            <?php if ($seatLabel !== ''): ?>
            <div style="font-size: 12px; opacity: 0.9;"><?= htmlspecialchars($seatLabel) ?></div>
            <?php endif; ?>
        </div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 12px 16px; border-right: 1px solid #e2e8f0; width: 50%;">
                    <div style="font-size: 11px; color: #718096; text-transform: uppercase; font-weight: 600;">Departure</div>
                    <div style="font-size: 14px; font-weight: 600; color: #2d3748;"><?= htmlspecialchars($fromStation) ?></div>
                    <div style="font-size: 12px; color: #4a5568;"><?= htmlspecialchars($departureLabel) ?></div>
                </td>
                <td style="padding: 12px 16px; width: 50%;">
                    <div style="font-size: 11px; color: #718096; text-transform: uppercase; font-weight: 600;">Arrival</div>
                    <div style="font-size: 14px; font-weight: 600; color: #2d3748;"><?= htmlspecialchars($toStation) ?></div>
                    <div style="font-size: 12px; color: #4a5568;"><?= htmlspecialchars($arrivalLabel) ?></div>
                </td>
            </tr>
        </table>
    </div>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <div style="font-size: 12px; color: #718096; margin-bottom: 8px; text-transform: uppercase; font-weight: 600;">Payment Summary</div>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; color: #718096; font-size: 14px;">Amount:</td>
                <td style="padding: 8px 0; color: #2d3748; font-weight: 600; text-align: right; font-size: 14px;">
                    <?= htmlspecialchars((string)($currency ?? 'USD')) ?> <?= number_format((float)($finalTotal ?? $amount ?? 0), 2) ?>
                </td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #718096; font-size: 14px;">Status:</td>
                <td style="padding: 8px 0; color: <?= (($paymentStatus ?? '') === 'paid') ? '#10b981' : '#f59e0b' ?>; font-weight: 600; text-align: right; text-transform: uppercase; font-size: 14px;">
                    <?= (($paymentStatus ?? '') === 'paid') ? 'Paid' : 'Pending Payment' ?>
                </td>
            </tr>
        </table>
    </div>

    <div style="text-align: center; margin: 32px 0;">
        <a href="<?= htmlspecialchars($invoiceUrl) ?>" style="display: inline-block; background: #4338ca; color: #ffffff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">
            View Invoice
        </a>
    </div>

    <div style="font-size: 13px; color: #4a5568; line-height: 1.6;">
        A PDF copy of your invoice is attached to this email when available.
    </div>
</div>
