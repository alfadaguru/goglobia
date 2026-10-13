<?php
// FILE: app/lib/supplier_sitebuilder.php
// MODULE 13 (direct booking engine & website builder → 360). The site-builder layer:
// branding (accent colour + tagline), a promotion banner, custom-page content blocks,
// SEO meta, and an analytics tracking id — all stored in stays_site.theme (JSON). The
// live room search / checkout / guest payments are ALREADY served by the branded page +
// the main price-integrity booking flow (brandedSiteRoutes.php); this adds the
// presentation layer around them. Multilingual + multi-currency come from the platform
// header switchers the branded page inherits. NOT runtime-verified.
//
// SECURITY: custom text is stored raw but ALWAYS rendered through htmlspecialchars by the
// view (sitebuilder_text) — we never emit supplier HTML. The accent colour is validated
// to a hex pattern; the analytics id to an alnum token. No script injection surface.

if (!function_exists('sitebuilder_decode')) {
    /** Decode stays_site.theme JSON into the canonical site-builder shape (safe defaults). */
    function sitebuilder_decode(?string $themeJson): array
    {
        $d = json_decode((string) $themeJson, true);
        if (!is_array($d)) { $d = []; }
        return [
            'accent'      => (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($d['accent'] ?? '')) ? $d['accent'] : '#7c3aed'),
            'tagline'     => (string) ($d['tagline'] ?? ''),
            'banner'      => (string) ($d['banner'] ?? ''),         // promotion banner text
            'banner_on'   => !empty($d['banner_on']),
            'about'       => (string) ($d['about'] ?? ''),          // custom "about" page block
            'meta_title'  => (string) ($d['meta_title'] ?? ''),     // SEO
            'meta_desc'   => (string) ($d['meta_desc'] ?? ''),
            'analytics_id'=> (preg_match('/^[A-Za-z0-9\-]{0,40}$/', (string) ($d['analytics_id'] ?? '')) ? (string) ($d['analytics_id'] ?? '') : ''),
            // Per-property social links (stored in the theme JSON — no new column).
            // Each value is a URL the supplier pastes; empty = not shown.
            'social'      => sitebuilder_social_clean(is_array($d['social'] ?? null) ? $d['social'] : []),
        ];
    }
}

if (!function_exists('sitebuilder_social_platforms')) {
    /** The social platforms a hotel can link (key => [label, icon-ish]). Single source. */
    function sitebuilder_social_platforms(): array
    {
        return [
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'tiktok'    => 'TikTok',
            'twitter'   => 'X / Twitter',
            'linkedin'  => 'LinkedIn',
            'youtube'   => 'YouTube',
            'whatsapp'  => 'WhatsApp',
        ];
    }
}

if (!function_exists('sitebuilder_social_clean')) {
    /** Keep only known platforms with a plausible http(s) URL (or tel/wa link). */
    function sitebuilder_social_clean(array $in): array
    {
        $out = [];
        foreach (sitebuilder_social_platforms() as $key => $_label) {
            $v = trim((string) ($in[$key] ?? ''));
            if ($v === '') { continue; }
            // Accept http(s) URLs only (length-capped). Anything else is dropped.
            if (preg_match('#^https?://[^\s<>"]{3,250}$#i', $v)) {
                $out[$key] = $v;
            }
        }
        return $out;
    }
}

if (!function_exists('sitebuilder_normalize')) {
    /** Build the stored theme JSON from a POST array (validated + length-capped). */
    function sitebuilder_normalize(array $in): string
    {
        $accent = (string) ($in['accent'] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) { $accent = '#7c3aed'; }
        $analytics = (string) ($in['analytics_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9\-]{0,40}$/', $analytics)) { $analytics = ''; }
        return json_encode([
            'accent'       => $accent,
            'tagline'      => substr(trim((string) ($in['tagline'] ?? '')), 0, 160),
            'banner'       => substr(trim((string) ($in['banner'] ?? '')), 0, 240),
            'banner_on'    => !empty($in['banner_on']),
            'about'        => substr(trim((string) ($in['about'] ?? '')), 0, 4000),
            'meta_title'   => substr(trim((string) ($in['meta_title'] ?? '')), 0, 120),
            'meta_desc'    => substr(trim((string) ($in['meta_desc'] ?? '')), 0, 300),
            'analytics_id' => $analytics,
            'social'       => sitebuilder_social_clean(is_array($in['social'] ?? null) ? $in['social'] : []),
        ]);
    }
}

if (!function_exists('sitebuilder_save')) {
    /** Persist the theme for a property (caller authorized the stay). Returns bool. */
    function sitebuilder_save($db, int $stayId, array $in): bool
    {
        if ($stayId <= 0) { return false; }
        try {
            $json = sitebuilder_normalize($in);
            if ($db->has('stays_site', ['stay_id' => $stayId])) {
                $db->update('stays_site', ['theme' => $json, 'updated_at' => date('Y-m-d H:i:s')], ['stay_id' => $stayId]);
            } else {
                $db->insert('stays_site', ['stay_id' => $stayId, 'theme' => $json, 'created_at' => date('Y-m-d H:i:s')]);
            }
            return true;
        } catch (\Throwable $e) { error_log('sitebuilder_save: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('sitebuilder_accent_safe')) {
    /** The accent colour, guaranteed safe to interpolate into a style attribute. */
    function sitebuilder_accent_safe(array $theme): string
    {
        $a = (string) ($theme['accent'] ?? '#7c3aed');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $a) ? $a : '#7c3aed';
    }
}

// ============================================================================
// GUEST BOOKING SYSTEM - CORE FUNCTIONS
// ============================================================================

if (!function_exists('booking_generate_reference')) {
    function booking_generate_reference(): string
    {
        return 'BK-' . strtoupper(substr(uniqid(), -8));
    }
}

if (!function_exists('booking_create')) {
    function booking_create($db, int $stayId, array $guestData): ?array
    {
        if ($stayId <= 0) { return null; }
        try {
            $reference = booking_generate_reference();
            $db->insert('stays_guest_bookings', [
                'stay_id' => $stayId,
                'booking_reference' => $reference,
                'guest_first_name' => substr(trim((string) ($guestData['first_name'] ?? '')), 0, 100),
                'guest_last_name' => substr(trim((string) ($guestData['last_name'] ?? '')), 0, 100),
                'guest_email' => substr(trim((string) ($guestData['email'] ?? '')), 0, 120),
                'guest_phone' => substr(trim((string) ($guestData['phone'] ?? '')), 0, 20),
                'checkin_date' => (string) ($guestData['checkin_date'] ?? ''),
                'checkout_date' => (string) ($guestData['checkout_date'] ?? ''),
                'num_adults' => (int) ($guestData['num_adults'] ?? 1),
                'num_children' => (int) ($guestData['num_children'] ?? 0),
                'room_id' => !empty($guestData['room_id']) ? (int) $guestData['room_id'] : null,
                'rate_option_id' => !empty($guestData['rate_option_id']) ? (int) $guestData['rate_option_id'] : null,
                'total_price' => (float) ($guestData['total_price'] ?? 0),
                'currency' => (string) ($guestData['currency'] ?? 'USD'),
                'special_requests' => substr(trim((string) ($guestData['special_requests'] ?? '')), 0, 1000),
                'payment_id' => (string) ($guestData['payment_id'] ?? ''),
                'payment_status' => 'completed',
                'booking_status' => 'confirmed',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $booking = $db->get('stays_guest_bookings', '*', ['booking_reference' => $reference]);
            return $booking ?: null;
        } catch (\Throwable $e) {
            error_log('booking_create: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('booking_get_by_reference')) {
    function booking_get_by_reference($db, string $reference): ?array
    {
        try {
            return $db->get('stays_guest_bookings', '*', ['booking_reference' => trim($reference)]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('booking_get_by_email_and_stay')) {
    function booking_get_by_email_and_stay($db, int $stayId, string $email): ?array
    {
        try {
            return $db->get('stays_guest_bookings', '*', ['stay_id' => $stayId, 'guest_email' => trim($email)]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('booking_get_by_id')) {
    function booking_get_by_id($db, int $bookingId): ?array
    {
        try {
            return $db->get('stays_guest_bookings', '*', ['id' => $bookingId]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('booking_generate_otp')) {
    function booking_generate_otp(): string
    {
        return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('booking_create_otp')) {
    function booking_create_otp($db, int $bookingId, string $guestEmail): ?string
    {
        if ($bookingId <= 0) { return null; }
        try {
            $otp = booking_generate_otp();
            $sentAt = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $db->insert('stays_booking_otps', [
                'booking_id' => $bookingId,
                'guest_email' => trim($guestEmail),
                'otp_token' => $otp,
                'otp_attempts' => 0,
                'otp_sent_at' => $sentAt,
                'otp_expires_at' => $expiresAt,
                'created_at' => $sentAt,
            ]);
            return $otp;
        } catch (\Throwable $e) {
            error_log('booking_create_otp: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('booking_verify_otp')) {
    function booking_verify_otp($db, int $bookingId, string $email, string $otpCode): bool
    {
        if ($bookingId <= 0) { return false; }
        try {
            $now = date('Y-m-d H:i:s');
            $otp = $db->get('stays_booking_otps', '*', [
                'booking_id' => $bookingId,
                'guest_email' => trim($email),
                'otp_token' => trim($otpCode),
            ]);
            if (!$otp) { return false; }
            if ($otp['otp_verified_at']) { return false; }
            if ($now > $otp['otp_expires_at']) { return false; }
            if ((int) $otp['otp_attempts'] >= 3) { return false; }
            $db->update('stays_booking_otps', ['otp_verified_at' => $now], ['id' => (int) $otp['id']]);
            $db->update('stays_guest_bookings', ['last_retrieved_at' => $now], ['id' => $bookingId]);
            return true;
        } catch (\Throwable $e) {
            error_log('booking_verify_otp: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('booking_get_settings')) {
    function booking_get_settings($db, int $stayId): array
    {
        try {
            $stay = $db->get('stays', ['booking_settings'], ['id' => $stayId]);
            if (!$stay) { return []; }
            $settings = json_decode((string) $stay['booking_settings'], true);
            if (!is_array($settings)) { $settings = []; }
            return [
                'allow_guest_bookings' => !empty($settings['allow_guest_bookings']) ?? true,
                'guest_contact_email' => (string) ($settings['guest_contact_email'] ?? ''),
                'guest_contact_phone' => (string) ($settings['guest_contact_phone'] ?? ''),
                'checkin_instructions' => (string) ($settings['checkin_instructions'] ?? ''),
                'cancellation_policy_text' => (string) ($settings['cancellation_policy_text'] ?? ''),
            ];
        } catch (\Throwable $e) {
            error_log('booking_get_settings: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('booking_save_settings')) {
    function booking_save_settings($db, int $stayId, array $settings): bool
    {
        if ($stayId <= 0) { return false; }
        try {
            $json = json_encode([
                'allow_guest_bookings' => !empty($settings['allow_guest_bookings']),
                'guest_contact_email' => substr(trim((string) ($settings['guest_contact_email'] ?? '')), 0, 120),
                'guest_contact_phone' => substr(trim((string) ($settings['guest_contact_phone'] ?? '')), 0, 20),
                'checkin_instructions' => substr(trim((string) ($settings['checkin_instructions'] ?? '')), 0, 2000),
                'cancellation_policy_text' => substr(trim((string) ($settings['cancellation_policy_text'] ?? '')), 0, 2000),
            ]);
            $db->update('stays', ['booking_settings' => $json], ['id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('booking_save_settings: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('booking_send_confirmation_email')) {
    function booking_send_confirmation_email($db, array $booking, array $stay): bool
    {
        try {
            $hotelName = (string) ($stay['name'] ?? 'Hotel');
            $hotelEmail = (string) ($stay['email'] ?? 'noreply@example.com');
            $guestEmail = (string) $booking['guest_email'];
            $guestName = (string) $booking['guest_first_name'];
            $reference = (string) $booking['booking_reference'];
            
            $subject = "Booking Confirmation #{$reference}";
            $body = "Hello {$guestName},\n\n";
            $body .= "Your booking at {$hotelName} has been confirmed.\n\n";
            $body .= "Confirmation Number: {$reference}\n";
            $body .= "Check-in: {$booking['checkin_date']}\n";
            $body .= "Check-out: {$booking['checkout_date']}\n";
            $body .= "Total: {$booking['currency']} " . number_format($booking['total_price'], 2) . "\n\n";
            $body .= "Thank you for your booking!\n";
            
            $headers = "From: noreply@" . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'example.com') . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            
            return mail($guestEmail, $subject, $body, $headers);
        } catch (\Throwable $e) {
            error_log('booking_send_confirmation_email: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('booking_cancel_booking')) {
    function booking_cancel_booking($db, int $bookingId, string $reason = ''): bool
    {
        if ($bookingId <= 0) { return false; }
        try {
            $db->update('stays_guest_bookings', [
                'booking_status' => 'cancelled',
                'cancellation_reason' => substr(trim($reason), 0, 500),
                'cancelled_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $bookingId]);
            return true;
        } catch (\Throwable $e) {
            error_log('booking_cancel_booking: ' . $e->getMessage());
            return false;
        }
    }
}
