<?php
// FILE: app/lib/esim_delivery.php
// eSIM activation-QR delivery helpers, shared by:
//   - the public QR-image endpoint (/esim/qr/{inv}) — a BARE QR png (not the PDF)
//   - GIA / WhatsApp delivery of a just-issued eSIM to the customer's phone
//
// The QR encodes the canonical eSIM activation string LPA:1$<smdp>$<matching>
// (same string the ticket PDF builds), generated OFFLINE from the stored Airalo
// provisioning so it reveals no supplier. No new dependency: uses the already
// vendored mpdf/qrcode PNG writer.

@$SECURE or (defined('root') or die('Access Denied!'));

if (!function_exists('esim_resolve_activation')) {
    /**
     * Resolve a booking's eSIM activation fields from its stored provisioning.
     * Mirrors app/views/notifications/emails/esim/ticket.php:$resolveSim so the
     * QR here encodes EXACTLY what the ticket shows. Returns:
     *   ['ready'=>bool, 'lpa'=>string, 'iccid'=>, 'smdp'=>, 'matching'=>, 'hostedQr'=>]
     * $ready is true when there's something installable (lpa OR hosted QR OR iccid).
     */
    function esim_resolve_activation(array $booking): array
    {
        $bd = $booking['booking_data'] ?? '';
        $bd = is_array($bd) ? $bd : (json_decode((string) $bd, true) ?: []);
        $prov = (array) ($bd['airalo_provision'] ?? ($bd['provision'] ?? []));
        $sims = $prov['sims'] ?? ($bd['airalo_sims'] ?? []);
        $s = (is_array($sims) && !empty($sims)) ? (array) reset($sims) : (array) ($prov['first_sim'] ?? $prov);

        $iccid    = trim((string) ($s['iccid'] ?? ($prov['iccid'] ?? '')));
        $smdp     = trim((string) ($s['smdp_address'] ?? ($s['lpa'] ?? ($prov['smdp_address'] ?? ''))));
        $matching = trim((string) ($s['matching_id'] ?? ($prov['matching_id'] ?? '')));
        $hostedQr = trim((string) ($s['qrcode_url'] ?? ($s['qr_code_url'] ?? ($prov['qrcode_url'] ?? ''))));

        $manualRaw = $s['manual_installation'] ?? ($prov['manual_installation'] ?? '');
        $manual = is_array($manualRaw) ? $manualRaw : [];
        $activationCode = trim((string) ($manual['code'] ?? ($manual['activation_code'] ?? ($manual['lpa'] ?? ''))));
        if ($smdp === '' && isset($manual['smdp_address'])) { $smdp = trim((string) $manual['smdp_address']); }

        $lpa = '';
        if ($activationCode !== '' && stripos($activationCode, 'LPA:') === 0) { $lpa = $activationCode; }
        elseif ($smdp !== '' && $matching !== '') { $lpa = 'LPA:1$' . $smdp . '$' . $matching; }
        elseif ($activationCode !== '') { $lpa = 'LPA:1$' . $smdp . '$' . $activationCode; }

        return [
            'ready'    => ($lpa !== '' || $hostedQr !== '' || $iccid !== ''),
            'lpa'      => $lpa,
            'iccid'    => $iccid,
            'smdp'     => $smdp,
            'matching' => $matching,
            'hostedQr' => $hostedQr,
        ];
    }
}

if (!function_exists('esim_qr_public_token')) {
    /** HMAC token for the public bare-QR image link (mirrors ticket_public_token). */
    function esim_qr_public_token(string $invoiceId, int $exp): string
    {
        $secret = function_exists('supplier_internal_secret') ? supplier_internal_secret() : 'esim-qr-fallback';
        return hash_hmac('sha256', 'esim-qr-public:' . $invoiceId . '|' . $exp, $secret);
    }
}

if (!function_exists('esim_qr_public_token_valid')) {
    function esim_qr_public_token_valid(string $invoiceId, int $exp, string $sig): bool
    {
        if ($exp < time()) { return false; }
        $expected = esim_qr_public_token($invoiceId, $exp);
        return hash_equals($expected, $sig);
    }
}

if (!function_exists('esim_qr_public_url')) {
    /** Absolute public URL for the BARE activation-QR png, valid for $ttl seconds. */
    function esim_qr_public_url(string $invoiceId, int $ttl = 1800): string
    {
        $base = defined('root') ? rtrim((string) root, '/') . '/' : '/';
        $exp  = time() + max(60, $ttl);
        $tok  = esim_qr_public_token($invoiceId, $exp);
        return $base . 'esim/qr/' . rawurlencode($invoiceId) . '?exp=' . $exp . '&sig=' . $tok;
    }
}

if (!function_exists('esim_render_qr_png')) {
    /**
     * Render a bare activation QR to PNG bytes from an LPA string, using the
     * vendored mpdf/qrcode PNG writer (no new dependency). Returns binary PNG or
     * null if the value is empty / GD unavailable.
     */
    function esim_render_qr_png(string $lpa, int $size = 320): ?string
    {
        $lpa = trim($lpa);
        if ($lpa === '' || !function_exists('imagepng')) { return null; }
        try {
            $qr = new \Mpdf\QrCode\QrCode($lpa, 'M');
            $png = new \Mpdf\QrCode\Output\Png();
            // Png::output() RETURNS the binary PNG (it does its own output buffering
            // internally) — use the return value, do not capture echo.
            $bytes = $png->output($qr, $size, [255, 255, 255], [0, 0, 0]);
            return (is_string($bytes) && $bytes !== '') ? $bytes : null;
        } catch (\Throwable $e) {
            error_log('esim_render_qr_png: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('esim_deliver_to_phone')) {
    /**
     * Deliver a just-issued eSIM to the customer's PHONE over WhatsApp (Green API —
     * the GIA WhatsApp channel): a warm message, the BARE QR image, and the ticket
     * PDF. Best-effort and idempotent per booking (stamps esim_phone_delivered_at).
     * Returns ['ok'=>bool, 'sent'=>[...channels...], 'message'=>...].
     *
     * Used by bm_notify_paid (admin BM eSIM) and the web/GIA paid paths. Phone is
     * taken from the booking (eSIM now requires it). No-ops cleanly when Green API
     * isn't configured, when there's no phone, or when the eSIM isn't ready yet.
     */
    function esim_deliver_to_phone($db, string $invoiceId): array
    {
        try {
            $b = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'esim']);
            if (!$b) { return ['ok' => false, 'message' => 'not an esim booking']; }

            // Idempotency: only deliver once. Self-healing column.
            if (!empty($b['esim_phone_delivered_at'])) { return ['ok' => true, 'sent' => [], 'message' => 'already delivered']; }

            $act = esim_resolve_activation($b);
            if (empty($act['ready'])) {
                // Not provisioned yet — don't send a half-baked message; a later call
                // (recovery/retry) will deliver once the QR exists.
                return ['ok' => false, 'message' => 'esim not provisioned yet'];
            }

            // Phone (eSIM requires it). Normalise with the GIA helper if present.
            $phoneRaw = (string) ($b['phone'] ?? '');
            $phoneCc  = (string) ($b['phone_country_code'] ?? '');
            $phone = function_exists('gia_bp_normalise_phone')
                ? gia_bp_normalise_phone($phoneRaw, $phoneCc)
                : preg_replace('/[^0-9+]/', '', $phoneRaw);
            if ($phone === '' || $phone === '+' ) { return ['ok' => false, 'message' => 'no phone on booking']; }

            // Green API (the GIA WhatsApp channel) must be configured.
            $settings = $db->get('settings', ['whatsapp_providers_config'], ['id' => 1]) ?: [];
            $allCfg = json_decode((string) ($settings['whatsapp_providers_config'] ?? '{}'), true);
            $gc = (is_array($allCfg) && is_array($allCfg['greenapi'] ?? null)) ? $allCfg['greenapi'] : [];
            if (empty($gc['instance_id']) || empty($gc['token'])) {
                return ['ok' => false, 'message' => 'green api not configured'];
            }
            $provFile = dirname(__DIR__) . '/lib/notifications/whatsapp/greenapi.php';
            if (!class_exists('GreenapiProvider') && is_file($provFile)) { require_once $provFile; }
            if (!class_exists('GreenapiProvider')) { return ['ok' => false, 'message' => 'green api provider missing']; }
            $prov = new \GreenapiProvider($gc);

            $name = trim((string) ($b['first_name'] ?? '') . ' ' . (string) ($b['last_name'] ?? '')) ?: 'there';
            $pkg  = '';
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true) ?: [];
            $pkg = trim((string) ($bd['package_name'] ?? ''));

            $sent = [];

            // 1) Warm intro message.
            $intro = "Hi {$name} 👋 Your eSIM"
                . ($pkg !== '' ? " ({$pkg})" : '')
                . " is ready! 📶\n\nScan the QR code below on the phone you'll use it on "
                . "(Settings → Cellular/Mobile → Add eSIM → scan), connect to Wi-Fi during setup, "
                . "and turn on Data Roaming for the eSIM when you arrive.";
            if (method_exists($prov, 'send')) {
                try { if ($prov->send($phone, $name, $intro)) { $sent[] = 'text'; } } catch (\Throwable $e) {}
            }

            // 2) BARE QR IMAGE — a public, signed PNG URL Green API fetches by URL.
            if (function_exists('esim_qr_public_url') && method_exists($prov, 'sendFileByUrl')) {
                $qrUrl = esim_qr_public_url($invoiceId);
                try {
                    if ($prov->sendFileByUrl($phone, $qrUrl, 'esim-qr-' . $invoiceId . '.png', 'Your eSIM activation QR')) {
                        $sent[] = 'qr_image';
                    }
                } catch (\Throwable $e) { error_log('esim_deliver_to_phone qr: ' . $e->getMessage()); }
            }

            // 3) Full voucher PDF (QR + ICCID + SM-DP+ + install steps) for the record.
            if (function_exists('ticket_public_url') && method_exists($prov, 'sendFileByUrl')) {
                $pdfUrl = ticket_public_url($invoiceId);
                if ($pdfUrl !== '') {
                    try {
                        if ($prov->sendFileByUrl($phone, $pdfUrl, 'esim-voucher-' . $invoiceId . '.pdf', 'Your full eSIM voucher & install guide')) {
                            $sent[] = 'pdf';
                        }
                    } catch (\Throwable $e) { error_log('esim_deliver_to_phone pdf: ' . $e->getMessage()); }
                }
            }

            // Stamp delivered only if we actually sent the QR (the whole point).
            if (in_array('qr_image', $sent, true) || in_array('text', $sent, true)) {
                static $ensured = false;
                if (!$ensured) {
                    try { $db->query("ALTER TABLE `bookings` ADD COLUMN `esim_phone_delivered_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
                    $ensured = true;
                }
                try { $db->update('bookings', ['esim_phone_delivered_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoiceId]); } catch (\Throwable $e) {}
            }

            return ['ok' => !empty($sent), 'sent' => $sent, 'message' => !empty($sent) ? 'delivered' : 'nothing sent'];
        } catch (\Throwable $e) {
            error_log('esim_deliver_to_phone: ' . $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
