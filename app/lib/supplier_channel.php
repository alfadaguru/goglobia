<?php
// FILE: app/lib/supplier_channel.php
// MODULE 12 (channel manager → 360). HONEST build: the ONE channel that needs no OTA
// vendor account — iCal — is REAL (a standards-compliant .ics export of booked dates
// that Airbnb/Booking.com/Google Calendar can subscribe to, token-gated, dates-only, no
// PII). Everything else (two-way OTA sync, rate push, reservation import via a vendor
// API) requires a real channel-manager account and is a STUB that reports 'not connected'
// via the existing integration framework (supplier_growth.php). Never faked. The channel
// MAP (room-type ↔ OTA room) is persisted now so it's ready when an adapter is wired.
// NOT runtime-verified (the iCal string builder is pure → harnessable).

if (!function_exists('ical_escape')) {
    /** Escape a text value per RFC 5545 (comma, semicolon, backslash, newline). */
    function ical_escape(string $s): string
    {
        $s = str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $s);
        return $s;
    }
}

if (!function_exists('ical_build')) {
    /**
     * PURE: build an RFC-5545 VCALENDAR string from booked-date ranges. Each event is one
     * booking's [checkin, checkout) as an all-day VEVENT (DTEND exclusive — the iCal
     * convention, matching our checkout-exclusive nights). $events: list of
     * ['uid','checkin'(Y-m-d),'checkout'(Y-m-d),'summary'(optional)]. $stampYmd is the
     * DTSTAMP date (pass today's date — callers stamp it; the builder never calls time()).
     * Returns the .ics body with CRLF line endings.
     */
    function ical_build(array $events, string $calName, string $stampYmd): string
    {
        $stamp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $stampYmd) ? str_replace('-', '', $stampYmd) . 'T000000Z' : '19700101T000000Z';
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GoGlobia//PMS Channel//EN',
            'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . ical_escape($calName),
        ];
        foreach ($events as $ev) {
            $ci = (string) ($ev['checkin'] ?? ''); $co = (string) ($ev['checkout'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ci) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $co)) { continue; }
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . ical_escape((string) ($ev['uid'] ?? ($ci . '-' . $co))) . '@goglobia';
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $ci);
            $lines[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', $co); // exclusive end
            $lines[] = 'SUMMARY:' . ical_escape((string) ($ev['summary'] ?? 'Booked'));
            $lines[] = 'TRANSP:OPAQUE';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }
}

if (!function_exists('channel_ical_token_ensure')) {
    /** Get (or mint) the property's public iCal feed token. Stored on stays_site. */
    function channel_ical_token_ensure($db, int $stayId): string
    {
        if ($stayId <= 0) { return ''; }
        try {
            $row = $db->get('stays_site', ['ical_token'], ['stay_id' => $stayId]);
            if ($row && !empty($row['ical_token'])) { return (string) $row['ical_token']; }
            $token = bin2hex(random_bytes(16));
            if ($row) {
                $db->update('stays_site', ['ical_token' => $token, 'updated_at' => date('Y-m-d H:i:s')], ['stay_id' => $stayId]);
            } else {
                $db->insert('stays_site', ['stay_id' => $stayId, 'ical_token' => $token, 'created_at' => date('Y-m-d H:i:s')]);
            }
            return $token;
        } catch (\Throwable $e) { error_log('channel_ical_token_ensure: ' . $e->getMessage()); return ''; }
    }
}

if (!function_exists('channel_ical_events_for_stay')) {
    /**
     * Booked-date events for a property's iCal feed: non-cancelled own-inventory stays
     * bookings → [uid, checkin, checkout, summary]. Dates only; NO guest PII in the feed
     * (OTAs only need availability). Reuses the ownership-agnostic hold/booking data —
     * the FEED route authorizes by token, not by session.
     */
    function channel_ical_events_for_stay($db, int $stayId): array
    {
        $events = [];
        if ($stayId <= 0) { return $events; }
        try {
            // Active holds already encode committed nights per property (held+consumed),
            // which is exactly what an OTA needs to avoid double-booking. Use them.
            $holds = $db->select('stays_holds', ['id', 'date_from', 'date_to', 'state', 'expires_at'],
                ['stay_id' => $stayId]) ?: [];
            $now = date('Y-m-d H:i:s');
            foreach ($holds as $h) {
                $st = (string) $h['state'];
                $live = $st === 'consumed' || ($st === 'held' && (string) ($h['expires_at'] ?? '') > $now);
                if (!$live) { continue; }
                $events[] = [
                    'uid' => 'hold-' . (int) $h['id'],
                    'checkin' => (string) $h['date_from'],
                    'checkout' => (string) $h['date_to'],
                    'summary' => 'Booked (GoGlobia)',
                ];
            }
        } catch (\Throwable $e) { error_log('channel_ical_events_for_stay: ' . $e->getMessage()); }
        return $events;
    }
}

if (!function_exists('channel_map_list')) {
    function channel_map_list($db, int $stayId): array
    {
        if ($stayId <= 0) { return []; }
        try { return $db->select('stays_channel_map', '*', ['stay_id' => $stayId, 'ORDER' => ['id' => 'DESC']]) ?: []; }
        catch (\Throwable $e) { error_log('channel_map_list: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('channel_map_add')) {
    /** Record an OTA channel mapping (room ↔ remote room, or an iCal import URL). The
     *  actual sync needs a wired adapter — see channel_sync_status(). */
    function channel_map_add($db, int $orgId, int $stayId, array $in): int
    {
        $channel = strtolower(trim((string) ($in['channel'] ?? '')));
        if ($stayId <= 0 || $channel === '') { return 0; }
        try {
            $db->insert('stays_channel_map', [
                'org_id' => $orgId ?: null, 'stay_id' => $stayId, 'channel' => substr($channel, 0, 40),
                'room_id' => ($v = (int) ($in['room_id'] ?? 0)) > 0 ? $v : null,
                'remote_room_id' => substr(trim((string) ($in['remote_room_id'] ?? '')), 0, 120) ?: null,
                'ical_import_url' => substr(trim((string) ($in['ical_import_url'] ?? '')), 0, 500) ?: null,
                'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('channel_map_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('channel_map_delete')) {
    function channel_map_delete($db, int $stayId, int $id): bool
    {
        try { $db->delete('stays_channel_map', ['id' => $id, 'stay_id' => $stayId]); return true; }
        catch (\Throwable $e) { error_log('channel_map_delete: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('channel_sync_status')) {
    /**
     * HONEST per-channel status. iCal EXPORT is 'live' (it genuinely works — no vendor
     * needed). OTA two-way sync (Booking.com/Expedia/Airbnb API) is 'stub' unless a real
     * `{channel}_adapter` function exists (none ship) — never reported as connected.
     */
    function channel_sync_status(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if ($channel === 'ical') { return 'live'; }
        return function_exists($channel . '_adapter') ? 'connected' : 'stub';
    }
}
