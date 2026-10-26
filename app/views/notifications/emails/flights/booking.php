<?php if (!isset($SECURE)) die('Direct access not permitted'); ?>
<?php $language = $language ?: 'en'; ?>

<?php if ($paymentStatus === 'paid'): ?>
<div style="background: #ecfdf5; border-left: 4px solid #10b981; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #065f46; margin-bottom: 4px;">✅ <?= htmlspecialchars(translateTo('flights_payment_received_title', $language)) ?></div>
    <div style="font-size: 13px; color: #047857;"><?= htmlspecialchars(translateTo('flights_payment_received_desc', $language)) ?></div>
</div>
<?php else: ?>
<div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 20px 32px; margin: 0;">
    <div style="font-size: 14px; font-weight: 600; color: #92400e; margin-bottom: 4px;">⌛ <?= htmlspecialchars(translateTo('flights_booking_received_title', $language)) ?></div>
    <div style="font-size: 13px; color: #b45309;"><?= htmlspecialchars(translateTo('flights_booking_received_desc', $language)) ?></div>
</div>
<?php endif; ?>

<div style="padding: 32px; background: #f8fafc;">
    <div style="font-size: 16px; color: #2d3748; margin-bottom: 24px;">
        <?= htmlspecialchars(translateTo('hello', $language)) ?> <strong><?= htmlspecialchars($firstName . ' ' . $lastName) ?></strong>,
    </div>

    <div style="font-size: 14px; color: #4a5568; margin-bottom: 32px; line-height: 1.6;">
        <?= ($paymentStatus === 'paid')
            ? translateTo('flights_email_intro_paid', $language, ['%invoice%' => "<strong>#$invoiceId</strong>"])
            : translateTo('flights_email_intro_pending', $language, ['%invoice%' => "<strong>#$invoiceId</strong>"])
        ?>
    </div>

    <!-- Details Card -->
    <?php
        // A manual / off-system flight (e.g. recorded via the Booking Manager) has
        // no live flight_data, so the route/airline/date fields are blank or unset.
        // Guard every field (extract() only defines what was supplied) and only
        // render the airline/flight-number line and the route when we actually have
        // them — otherwise the card showed "✈️ →" with a 1970 epoch date.
        $fFrom      = trim((string) ($from ?? ''));
        $fTo        = trim((string) ($to ?? ''));
        $fAirline   = trim((string) ($airlineName ?? ''));
        $fFlightNo  = trim((string) ($flightNumber ?? ''));
        $fDepRaw    = trim((string) ($departure_date ?? ''));
        $fDepTs     = $fDepRaw !== '' ? strtotime($fDepRaw) : false;
        $hasRoute   = ($fFrom !== '' || $fTo !== '');
    ?>
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
        <div style="background: #2d3748; color: #ffffff; padding: 12px 16px;">
            <?php if ($hasRoute): ?>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 45%;">
                        <div style="font-size: 16px; font-weight: 700;"><?= htmlspecialchars($fFrom) ?></div>
                    </td>
                    <td style="width: 10%; text-align: center;">
                        <span style="font-size: 16px;">✈️</span>
                    </td>
                    <td style="width: 45%; text-align: right;">
                        <div style="font-size: 16px; font-weight: 700;"><?= htmlspecialchars($fTo) ?></div>
                    </td>
                </tr>
            </table>
            <?php else: ?>
            <div style="font-size: 16px; font-weight: 700;">✈️ <?= htmlspecialchars(translateTo('flights', $language)) ?> #<?= htmlspecialchars((string) $invoiceId) ?></div>
            <?php endif; ?>
            <?php if ($fDepTs): ?>
            <div style="font-size: 12px; opacity: 0.8; margin-top: 4px;"><?= date('l, M d, Y', $fDepTs) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($fAirline !== '' || $fFlightNo !== ''): ?>
        <div style="padding: 12px 16px; border-top: 1px solid #e2e8f0; font-size: 13px; color: #4a5568;">
            <strong><?= htmlspecialchars($fAirline) ?></strong><?= $fFlightNo !== '' ? ' (' . htmlspecialchars($fFlightNo) . ')' : '' ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Payment Details Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <div style="font-size: 12px; color: #718096; margin-bottom: 8px; text-transform: uppercase; font-weight: 600;"><?= htmlspecialchars(translateTo('payment_summary', $language)) ?></div>
        <table style="width: 100%; border-collapse: collapse;">
            <?php /* Price shown only on the PAID receipt. The booking confirmation
                     (unpaid) is price-free travel evidence; the invoice carries price.
                     $hide_price is set true when NOTIFY renders the TRAVELLER copy —
                     the traveller's document must never carry a price (3-doc model). */ ?>
            <?php if (($paymentStatus ?? '') === 'paid' && empty($hide_price)): ?>
            <tr>
                <td style="padding: 8px 0; color: #718096; font-size: 14px;"><?= htmlspecialchars(translateTo('amount_paid', $language)) ?></td>
                <td style="padding: 8px 0; color: #2d3748; font-weight: 600; text-align: right; font-size: 14px;">
                    <?= $currency ?> <?= number_format($finalTotal, 2) ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td style="padding: 8px 0; color: #718096; font-size: 14px;"><?= htmlspecialchars(translateTo('status', $language)) ?>:</td>
                <td style="padding: 8px 0; color: <?= ($paymentStatus === 'paid') ? '#10b981' : '#f59e0b' ?>; font-weight: 600; text-align: right; text-transform: uppercase; font-size: 14px;">
                    <?= htmlspecialchars(($paymentStatus === 'paid') ? translateTo('paid', $language) : translateTo('pending_payment', $language)) ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- CTA Button -->
    <?php if (empty($hide_price)): ?>
    <div style="text-align: center; margin: 32px 0;">
        <a href="<?= $invoiceUrl ?>" style="display: inline-block; background: <?= ($paymentStatus === 'paid') ? '#10b981' : '#f59e0b' ?>; color: #ffffff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">
            <?= htmlspecialchars(translateTo('view_invoice', $language)) ?>
        </a>
    </div>
    <?php else: ?>
    <!-- TRAVELLER copy: the no-price TICKET is attached to this email; we do not
         link the login-gated invoice page. -->
    <div style="text-align: center; margin: 32px 0; font-size: 13px; color: #4a5568;">
        <?= htmlspecialchars(translateTo('flights_ticket_attached', $language)) ?>
    </div>
    <?php endif; ?>

    <!-- Next Steps Box -->
    <div style="background: <?= ($paymentStatus === 'paid') ? '#ecfdf5' : '#eff6ff' ?>; border: 1px solid <?= ($paymentStatus === 'paid') ? '#d1fae5' : '#bfdbfe' ?>; border-radius: 8px; padding: 20px; margin-top: 24px;">
        <div style="font-size: 13px; color: <?= ($paymentStatus === 'paid') ? '#065f46' : '#1e3a8a' ?>; line-height: 1.6;">
            <strong><?= htmlspecialchars(translateTo('next_steps', $language)) ?></strong><br>
            <?= ($paymentStatus === 'paid')
                ? htmlspecialchars(translateTo('flights_next_steps_paid', $language))
                : htmlspecialchars(translateTo('flights_next_steps_pending', $language))
            ?>
        </div>
    </div>
</div>
