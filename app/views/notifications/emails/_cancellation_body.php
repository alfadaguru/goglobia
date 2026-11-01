<?php
/**
 * SHARED CANCELLATION EMAIL BODY — premium, reassuring, one source for all services.
 *
 * Rendered BETWEEN emails/header.php and emails/footer.php (NOTIFY::send includes
 * those around this body), so it emits only the inner body markup — no <html>/<head>/
 * <body>, no email container (the header/footer own those). Matches the email house
 * style (Plus Jakarta Sans, white card, soft shadows) but elevated to the premium
 * design language: a calm status band, a clean reference card, clear next-steps, and
 * a reassuring withdraw-request note. A cancellation is a sensitive moment — the tone
 * is composed and helpful, not alarming.
 *
 * Each service's booking_cancellation.php is a thin wrapper that sets:
 *   $cxServiceLabel   e.g. 'flight', 'hotel', 'tour', 'car', 'Umrah', 'ferry'  (lowercase noun;
 *                     used mid-sentence — wrappers pass it already-cased as they want)
 *   $cxModule         the invoice module slug for the CTA link: 'flights','stays', …
 * then `require __DIR__ . '/../_cancellation_body.php';`.
 *
 * Available from the NOTIFY render scope: $booking (array), $language, $settings, root,
 * translateTo(). Falls back gracefully if the wrapper vars are unset.
 */
@$SECURE or die('Access Denied!');

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$lang = $language ?? 'en';
$T = function ($key, $fallback = '') use ($lang) {
    if (function_exists('translateTo')) { $v = translateTo($key, $lang); if ($v !== '' && $v !== $key) { return $v; } }
    return $fallback;
};

$svcLabel = trim((string) ($cxServiceLabel ?? 'booking'));
if ($svcLabel === '') { $svcLabel = 'booking'; }
$module   = trim((string) ($cxModule ?? ''));
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$name = trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''));
$rootUrl = defined('root') ? root : '';
$manageUrl = $rootUrl . 'invoice/' . ($module !== '' ? $module . '/' : '') . rawurlencode($invoiceId);

// Design tokens (email-safe, inline). Mirrors the PDF palette: deep ink + brand blue,
// with a restrained amber for the "pending" status (reassuring, not an alarm red).
$ink   = '#0B1B2B';
$slate = '#64748B';
$brand = '#0A4FE6';
$hair  = '#E4E9F2';
$panel = '#F5F7FB';
$amber = '#B4690E';
$amberBg = '#FDF3E3';
$amberBd = '#F0D9A8';
?>
<!-- Premium status band -->
<div style="background: linear-gradient(115deg,#1b1205 0%,#3a2a10 55%,<?= $amber ?> 170%); padding: 26px 32px;">
    <div style="font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #e5c79a;">Cancellation Request</div>
    <div style="font-size: 19px; font-weight: 700; color: #ffffff; margin-top: 6px;">We&rsquo;ve received your request</div>
    <div style="font-size: 13px; color: #e8dcc6; margin-top: 4px;">Your <?= $e($svcLabel) ?> booking is now under review.</div>
</div>

<div style="padding: 32px;">
    <div style="font-size: 16px; color: <?= $ink ?>; margin-bottom: 18px;">
        Hello <strong><?= $e($name !== '' ? $name : 'there') ?></strong>,
    </div>

    <div style="font-size: 14px; color: <?= $slate ?>; margin-bottom: 26px; line-height: 1.65;">
        We&rsquo;ve received your request to cancel <?= $e($svcLabel) ?> booking
        <strong style="color: <?= $ink ?>;">#<?= $e($invoiceId) ?></strong>.
        Our team is reviewing it now and will process it in line with the applicable cancellation policy.
    </div>

    <!-- Reference / status card -->
    <div style="background: <?= $panel ?>; border: 1px solid <?= $hair ?>; border-radius: 12px; padding: 18px 20px; margin-bottom: 26px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; vertical-align: top;">
                    <div style="font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: <?= $slate ?>;">Booking Reference</div>
                    <div style="font-size: 17px; font-weight: 700; color: <?= $ink ?>; margin-top: 3px;">#<?= $e($invoiceId) ?></div>
                </td>
                <td style="padding: 6px 0; text-align: right; vertical-align: top;">
                    <div style="font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: <?= $slate ?>;">Status</div>
                    <div style="display: inline-block; margin-top: 4px; font-size: 12px; font-weight: 700; color: <?= $amber ?>; background: <?= $amberBg ?>; border: 1px solid <?= $amberBd ?>; padding: 4px 12px; border-radius: 999px;">&#9679; Cancellation pending</div>
                </td>
            </tr>
        </table>
    </div>

    <div style="font-size: 14px; color: <?= $slate ?>; margin-bottom: 28px; line-height: 1.65;">
        We&rsquo;ll email you again as soon as the cancellation is finalised, along with any refund that applies and how it will be returned to you. No further action is needed from you right now.
    </div>

    <!-- CTA -->
    <div style="text-align: center; margin: 0 0 28px;">
        <a href="<?= $e($manageUrl) ?>" style="display: inline-block; background: <?= $brand ?>; color: #ffffff; padding: 13px 30px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 700;">
            View booking status
        </a>
    </div>

    <!-- Reassurance / withdraw note -->
    <div style="background: #eef4ff; border: 1px solid #cfe0ff; border-left: 4px solid <?= $brand ?>; border-radius: 10px; padding: 16px 18px;">
        <div style="font-size: 13px; color: #28406b; line-height: 1.6;">
            <strong style="color: <?= $ink ?>;">Didn&rsquo;t request this, or changed your mind?</strong><br>
            Just reply to this email or contact our support team and we&rsquo;ll stop the cancellation before it&rsquo;s processed.
        </div>
    </div>
</div>
