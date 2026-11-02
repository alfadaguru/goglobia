<?php
/**
 * FLIGHT TICKET HELPERS — data resolvers for the premium e-ticket PDF.
 *
 * The ticket template (emails/flights/ticket.php) must NEVER show placeholder
 * rubbish ("--", "Airline") or a wrong airline code guessed from the name. These
 * helpers turn the stored booking segment data into correct, display-ready values:
 *
 *  - ft_airline_resolve(): real airline NAME + 2-letter code + a PDF-safe LOGO
 *    reference (local uploads/flights/airlines/<CODE>.png registered on mPDF via
 *    imageVars, else the avs.io remote logo, else '' → caller shows a monogram).
 *  - ft_airport_resolve(): a 3-letter code → ['code','city','name'] from
 *    flights_airports (so "LOS" shows "Lagos — Murtala Muhammed International").
 *  - ft_minutes_between() / ft_fmt_duration(): EXACT layover + total-journey math
 *    from raw ISO timestamps (departure_iso/arrival_iso) when present (timezone-
 *    proof, UTC), with a graceful fallback to the stored local display date/time
 *    (flagged approximate) for older bookings or suppliers that don't carry ISO.
 *
 * All helpers are read-only, never throw, and are guarded with function_exists so
 * they load safely from the template or config. They use the global Medoo $db when
 * one is not passed.
 */

if (!function_exists('ft_db')) {
    /** Resolve a usable Medoo handle: explicit arg → global $db → null. */
    function ft_db($db = null)
    {
        if ($db !== null) { return $db; }
        if (isset($GLOBALS['db'])) { return $GLOBALS['db']; }
        return null;
    }
}

if (!function_exists('ft_passenger_list')) {
    /**
     * The REAL passenger list for a flight ticket — the people who actually fly.
     *
     * `bookings.travellers` holds the passengers as a map keyed adult_N / child_N /
     * infant_N (the traveller TYPE is the key prefix, not a field). The booking's
     * first_name/last_name is the PAYER/contact (primary_guest), stored SEPARATELY —
     * it must NEVER be listed as a passenger. (GIA/legacy bookings sometimes nest a
     * {"primary_guest":{...}} / {"contact":{...}} key inside travellers; we drop it.)
     *
     * TWO guards so this survives even a partial regression of the caller:
     *   1) drop a 'primary_guest' / 'contact' KEY from the travellers map;
     *   2) drop any traveller whose full name EXACTLY matches the payer name.
     *
     * Returns a plain list of passenger arrays, each with a resolved 'type'
     * (ADT/CHD/INF). Falls back to the contact name ONLY when there are genuinely no
     * passengers, so the ticket is never blank. Never throws.
     *
     * @param array $booking  the bookings row (needs 'travellers','first_name','last_name')
     */
    function ft_passenger_list(array $booking): array
    {
        $payerFn = strtolower(trim((string) ($booking['first_name'] ?? '')));
        $payerLn = strtolower(trim((string) ($booking['last_name'] ?? '')));
        $payerKey = $payerFn . '|' . $payerLn;

        $out = [];
        $raw = $booking['travellers'] ?? '';
        $t = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (is_array($t)) {
            // A plain 0-indexed list OR an adult_N/child_N/... map.
            $isList = array_key_exists(0, $t);
            foreach ($t as $key => $pax) {
                if (!is_array($pax)) { continue; }
                $k = strtolower((string) $key);
                // Guard 1: never the payer/contact KEY.
                if (!$isList && ($k === 'primary_guest' || $k === 'contact')) { continue; }
                // Derive type from the key prefix when the row has none.
                if (empty($pax['type']) && empty($pax['passenger_type'])) {
                    if (!$isList && strpos($k, 'child_') === 0)       { $pax['type'] = 'CHD'; }
                    elseif (!$isList && strpos($k, 'infant_') === 0)  { $pax['type'] = 'INF'; }
                    else                                              { $pax['type'] = 'ADT'; }
                }
                // Guard 2: never a traveller whose name == the payer name (belt &
                // suspenders — catches a payer nested WITHOUT the primary_guest key).
                $fn = strtolower(trim((string) ($pax['first_name'] ?? '')));
                $ln = strtolower(trim((string) ($pax['last_name'] ?? '')));
                if ($payerKey !== '|' && ($fn . '|' . $ln) === $payerKey) {
                    // Only skip if there are OTHER passengers; if the payer is the only
                    // traveller (a solo trip the payer is on), keep them — handled by
                    // the empty-fallback below, so just skip here.
                    continue;
                }
                $out[] = $pax;
            }
        }

        // Last resort: no real passengers found → show the contact so the ticket is
        // never blank (covers a solo booking where the payer IS the traveller and the
        // name-match guard above removed them).
        if (empty($out)) {
            $out = [[
                'first_name' => $booking['first_name'] ?? '',
                'last_name'  => $booking['last_name'] ?? '',
                'type'       => 'ADT',
            ]];
        }
        return $out;
    }
}

if (!function_exists('ft_airline_logo_dir')) {
    function ft_airline_logo_dir(): string
    {
        return __DIR__ . '/../../uploads/flights/airlines/';
    }
}

if (!function_exists('ft_airline_code_from')) {
    /**
     * Best 2-letter airline code from a segment. Priority:
     *   1) an explicit code field (img / airline_code / code) — what search stores;
     *   2) the leading token of the flight number ("P4 7120" → "P4");
     *   3) '' (caller falls back to a monogram — NEVER guess from the full name,
     *      which produced the "Air Peace → AI" bug).
     */
    function ft_airline_code_from(array $seg): string
    {
        foreach (['img', 'airline_code', 'code', 'carrier_code', 'marketing_carrier'] as $k) {
            $v = strtoupper(trim((string) ($seg[$k] ?? '')));
            // A real IATA/ICAO carrier code is 2–3 alnum; reject a full name.
            if ($v !== '' && preg_match('/^[A-Z0-9]{2,3}$/', $v)) { return $v; }
        }
        // Flight number like "P4 7120" / "P47120" / "BA54".
        $fl = strtoupper(trim((string) ($seg['flight_no'] ?? $seg['flight_number'] ?? '')));
        if ($fl !== '' && preg_match('/^([A-Z0-9]{2,3})\s*\d+/', $fl, $m)) {
            // Guard: a leading "2–3 char then digits" where the prefix is letters or
            // the standard 1-letter+digit IATA (e.g. "P4", "9W"). Accept 2 chars.
            $code = $m[1];
            if (preg_match('/^[A-Z][A-Z0-9]$/', substr($code, 0, 2))) { return substr($code, 0, 2); }
        }
        return '';
    }
}

if (!function_exists('ft_airline_resolve')) {
    /**
     * Resolve a segment's airline into display-ready parts.
     *
     * @param mixed $mpdf  optional mPDF instance — when passed, a found LOCAL logo
     *                     PNG is registered as imageVars['airline_<CODE>'] and the
     *                     returned logo_src is 'var:airline_<CODE>' (renders
     *                     identically on local + production, no open_basedir issue).
     * @return array{name:string, code:string, flight_no:string, logo_src:string, monogram:string}
     *   logo_src: 'var:airline_XX' | 'https://pics.avs.io/...' | '' (→ use monogram)
     */
    function ft_airline_resolve(array $seg, $db = null, $mpdf = null): array
    {
        $db   = ft_db($db);
        $code = ft_airline_code_from($seg);

        // NAME: the stored, already shell-corrected name wins; else look it up by
        // code in flights_airlines; else fall back to the code; else "Airline".
        $name = trim((string) ($seg['airline'] ?? $seg['airline_name'] ?? ''));
        // A "name" that is really just the bare code (shell carrier) is not a name.
        if ($name !== '' && $code !== '' && strtoupper($name) === $code) { $name = ''; }
        if ($name === '' && $code !== '' && $db) {
            try {
                $row = $db->get('flights_airlines', ['name'], ['code' => $code]);
                if (is_array($row) && trim((string) ($row['name'] ?? '')) !== '') {
                    $name = trim((string) $row['name']);
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }
        if ($name === '') { $name = $code !== '' ? $code : 'Airline'; }

        // FLIGHT NUMBER: normalise to "<CODE> <digits>" (e.g. "P47120" or "P4 7120"
        // → "P4 7120"). Strip a leading carrier-code prefix first so we don't fold
        // the code's own digits into the number ("P47120" must NOT become "P4 47120").
        $flRaw = strtoupper(trim((string) ($seg['flight_no'] ?? $seg['flight_number'] ?? '')));
        $flightNo = $flRaw;
        if ($flRaw !== '' && $code !== '') {
            $rest = $flRaw;
            if (strpos($rest, $code) === 0) { $rest = substr($rest, strlen($code)); } // drop leading code
            $digits = ltrim(preg_replace('/\D/', '', $rest), '0');
            if ($digits !== '') { $flightNo = $code . ' ' . $digits; }
        }

        // LOGO: prefer the LOCAL png (robust in PDF). We register it on mPDF via
        // imageVars (a 'var:airline_<CODE>' src) so it renders WITHOUT a filesystem
        // lookup at paint time — the same hardening the GoGlobia logo uses, immune to
        // open_basedir / path differences on production. Because GENERATE_BOOKING_PDF
        // builds the HTML BEFORE it creates the mPDF instance, $mpdf is usually null
        // here; so we also stash the (key => binary) in a request-global that the
        // engine drains into imageVars AFTER creating mPDF (ft_drain_airline_logos).
        // Fallbacks: direct file path (preview / when draining is unavailable) →
        // remote avs.io logo → '' (caller draws a lettered monogram).
        $logoSrc = '';
        if ($code !== '') {
            $file = ft_airline_logo_dir() . $code . '.png';
            if (is_file($file)) {
                $key = 'airline_' . $code;
                $bin = @file_get_contents($file);
                if ($bin !== false && $bin !== '') {
                    // Register now if we were handed a live mPDF; otherwise queue it.
                    if ($mpdf !== null && isset($mpdf->imageVars)) {
                        $mpdf->imageVars[$key] = $bin;
                    } else {
                        if (!isset($GLOBALS['__ft_airline_logo_vars']) || !is_array($GLOBALS['__ft_airline_logo_vars'])) {
                            $GLOBALS['__ft_airline_logo_vars'] = [];
                        }
                        $GLOBALS['__ft_airline_logo_vars'][$key] = $bin;
                    }
                    $logoSrc = 'var:' . $key;
                } else {
                    $logoSrc = $file; // last-ditch direct path
                }
            } else {
                // Remote fallback (works on the web invoice; mPDF may fetch it).
                $logoSrc = 'https://pics.avs.io/80/80/' . $code . '@2x.png';
            }
        }

        // MONOGRAM: 2-letter fallback mark when there is no logo at all.
        $monogram = $code !== '' ? $code : strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'A', 0, 2));

        return [
            'name'      => $name,
            'code'      => $code,
            'flight_no' => $flightNo,
            'logo_src'  => $logoSrc,
            'monogram'  => $monogram,
        ];
    }
}

if (!function_exists('ft_drain_airline_logos')) {
    /**
     * Register any airline logos the ticket template queued (because mPDF did not
     * yet exist when the HTML was built) onto the now-live mPDF instance, then clear
     * the queue. Call this right AFTER `new Mpdf(...)` and BEFORE WriteHTML(). Safe
     * to call always — a no-op when nothing was queued.
     */
    function ft_drain_airline_logos($mpdf): void
    {
        if ($mpdf === null || !isset($mpdf->imageVars)) { return; }
        $q = $GLOBALS['__ft_airline_logo_vars'] ?? null;
        if (is_array($q)) {
            foreach ($q as $key => $bin) {
                if ($bin !== '' && !isset($mpdf->imageVars[$key])) { $mpdf->imageVars[$key] = $bin; }
            }
        }
        $GLOBALS['__ft_airline_logo_vars'] = [];
    }
}

if (!function_exists('ft_airport_resolve')) {
    /**
     * A 3-letter airport code → ['code','city','name']. Reads flights_airports;
     * falls back to any city/name already on the segment, then to the bare code.
     * Cached per-request so a multi-segment ticket doesn't re-query the same code.
     */
    function ft_airport_resolve(string $code, $db = null, array $segHint = []): array
    {
        static $cache = [];
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['code' => '', 'city' => '', 'name' => ''];
        }
        if (isset($cache[$code])) {
            return $cache[$code];
        }
        $db = ft_db($db);
        $city = ''; $name = '';
        if ($db) {
            try {
                $row = $db->get('flights_airports', ['airport', 'city', 'country'], ['code' => $code]);
                if (is_array($row)) {
                    $name = trim((string) ($row['airport'] ?? ''));
                    $city = trim((string) ($row['city'] ?? ''));
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }
        // Segment-provided names as a fallback (some suppliers send full airport names).
        if ($name === '') {
            foreach (['departure_airport', 'arrival_airport', 'airport_name'] as $k) {
                $v = trim((string) ($segHint[$k] ?? ''));
                if ($v !== '' && strtoupper($v) !== $code) { $name = $v; break; }
            }
        }
        // Tidy: drop a trailing "Airport" so "London Heathrow Airport" reads cleaner
        // as a secondary line (kept if that leaves it empty).
        $nameClean = preg_replace('/\s+International Airport$/i', ' International', $name);
        $nameClean = preg_replace('/\s+Airport$/i', '', (string) $nameClean);
        if (trim((string) $nameClean) !== '') { $name = trim((string) $nameClean); }

        return $cache[$code] = ['code' => $code, 'city' => $city, 'name' => $name];
    }
}

if (!function_exists('ft_parse_dt')) {
    /**
     * Parse a segment endpoint into a UTC-comparable timestamp.
     *   - If an ISO field is present (departure_iso/arrival_iso, or departing_at/
     *     arriving_at), use it → EXACT (carries offset/UTC).
     *   - Else combine the stored local display date + time → approximate (no tz).
     * Returns ['ts'=>int|null, 'exact'=>bool]. ts is null when nothing parses.
     */
    function ft_parse_dt($dateStr, $timeStr, $isoStr = ''): array
    {
        $iso = trim((string) $isoStr);
        if ($iso !== '') {
            $t = strtotime($iso);
            if ($t !== false) { return ['ts' => $t, 'exact' => true]; }
        }
        $d = trim((string) $dateStr);
        $tm = trim((string) $timeStr);
        if ($d === '' && $tm === '') { return ['ts' => null, 'exact' => false]; }
        // Normalise common "d-m-Y" to something strtotime reads unambiguously.
        if (preg_match('#^(\d{2})-(\d{2})-(\d{4})$#', $d, $m)) {
            $d = $m[3] . '-' . $m[2] . '-' . $m[1]; // Y-m-d
        }
        $combined = trim($d . ' ' . $tm);
        $t = strtotime($combined);
        if ($t === false && $tm !== '') { $t = strtotime($tm); }
        return ['ts' => $t !== false ? $t : null, 'exact' => false];
    }
}

if (!function_exists('ft_seg_dep')) {
    /** Departure datetime of a segment → ['ts','exact']. */
    function ft_seg_dep(array $s): array
    {
        return ft_parse_dt(
            $s['departure_date'] ?? '',
            $s['departure_time'] ?? '',
            $s['departure_iso'] ?? ($s['departing_at'] ?? ($s['departure_timestamp'] ?? ''))
        );
    }
}

if (!function_exists('ft_seg_arr')) {
    /** Arrival datetime of a segment → ['ts','exact']. */
    function ft_seg_arr(array $s): array
    {
        return ft_parse_dt(
            $s['arrival_date'] ?? '',
            $s['arrival_time'] ?? '',
            $s['arrival_iso'] ?? ($s['arriving_at'] ?? ($s['arrival_timestamp'] ?? ''))
        );
    }
}

if (!function_exists('ft_minutes_between')) {
    /**
     * Minutes between two ['ts','exact'] points. Returns
     * ['minutes'=>int|null, 'exact'=>bool] — minutes null when either side is
     * unknown or the gap is negative/absurd (>48h, likely a bad/missing date).
     */
    function ft_minutes_between(array $from, array $to): array
    {
        $a = $from['ts'] ?? null; $b = $to['ts'] ?? null;
        if ($a === null || $b === null) { return ['minutes' => null, 'exact' => false]; }
        $min = (int) round(($b - $a) / 60);
        if ($min < 0 || $min > 48 * 60) { return ['minutes' => null, 'exact' => false]; }
        return ['minutes' => $min, 'exact' => !empty($from['exact']) && !empty($to['exact'])];
    }
}

if (!function_exists('ft_fmt_duration')) {
    /** 155 → "2h 35m"; 60 → "1h"; 45 → "45m"; null → ''. */
    function ft_fmt_duration($minutes): string
    {
        if ($minutes === null || $minutes === '' || (int) $minutes <= 0) { return ''; }
        $minutes = (int) $minutes;
        $h = intdiv($minutes, 60); $m = $minutes % 60;
        if ($h > 0 && $m > 0) { return $h . 'h ' . $m . 'm'; }
        if ($h > 0) { return $h . 'h'; }
        return $m . 'm';
    }
}

if (!function_exists('ft_leg_summary')) {
    /**
     * Summarise a LEG (array of segments): true origin→final-destination codes,
     * total journey time (first dep → last arr), stop count + the connection
     * (layover) between each consecutive pair.
     *
     * @return array{
     *   from:string, to:string, stops:int,
     *   total_minutes:?int, total_exact:bool, total_display:string,
     *   layovers: array<int,array{code:string,minutes:?int,exact:bool,display:string}>
     * }
     *   layovers[i] is the connection AFTER segment i (at segment i's arrival code).
     */
    function ft_leg_summary(array $segs): array
    {
        $segs = array_values(array_filter($segs, 'is_array'));
        $n = count($segs);
        $out = ['from' => '', 'to' => '', 'stops' => max(0, $n - 1),
                'total_minutes' => null, 'total_exact' => false, 'total_display' => '',
                'layovers' => []];
        if ($n === 0) { return $out; }

        $first = $segs[0]; $last = $segs[$n - 1];
        $out['from'] = strtoupper(trim((string) ($first['departure_code'] ?? $first['from_airport'] ?? '')));
        $out['to']   = strtoupper(trim((string) ($last['arrival_code'] ?? $last['to_airport'] ?? '')));

        // Total journey: first departure → last arrival.
        $tot = ft_minutes_between(ft_seg_dep($first), ft_seg_arr($last));
        $out['total_minutes'] = $tot['minutes'];
        $out['total_exact']   = $tot['exact'];
        $out['total_display'] = ft_fmt_duration($tot['minutes']);

        // Layover after each segment except the last (= connection before the next).
        for ($i = 0; $i < $n - 1; $i++) {
            $arrCode = strtoupper(trim((string) ($segs[$i]['arrival_code'] ?? $segs[$i]['to_airport'] ?? '')));
            $lay = ft_minutes_between(ft_seg_arr($segs[$i]), ft_seg_dep($segs[$i + 1]));
            $out['layovers'][$i] = [
                'code'    => $arrCode,
                'minutes' => $lay['minutes'],
                'exact'   => $lay['exact'],
                'display' => ft_fmt_duration($lay['minutes']),
            ];
        }
        return $out;
    }
}
