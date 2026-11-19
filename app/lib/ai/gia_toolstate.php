<?php
// ============================================================================
// FILE: app/lib/ai/gia_toolstate.php
// CROSS-TURN TOOL-STATE MEMORY for GIA.
//
// WHY: the conversation history GIA replays each turn is role+TEXT only
// (gia_conv_history / gia_log_turn store no tool results). So on a NEW turn the
// model has the words exchanged but NOT what it DID — the fares it showed, the
// option picked, the held booking/invoice. Cross-turn booking continuity leaned
// entirely on side-caches (web $_SESSION, channel offer_cache) that miss on a new
// device / expired session, making GIA feel like a fresh agent mid-conversation.
//
// This distils each turn's tool_runs into a SMALL, GROUNDED fact map persisted on
// gia_conversations.tool_state (merge, latest-wins per key), and rehydrates it as a
// short "EARLIER THIS CONVERSATION" system note. Containment-safe: every value comes
// verbatim from a real tool output — never invented — so it can never introduce a
// fabricated price/option (the reply-containment guard still validates anything GIA
// then states).
// ============================================================================

namespace App\lib\ai;

if (!function_exists('App\\lib\\ai\\gia_toolstate_summarise')) {
    /**
     * Distil ONE turn's tool_runs into a compact fact map. Only keys that actually
     * occurred this turn are returned; the caller MERGES them onto the running state
     * (so a later turn's search overwrites the earlier route, a book overwrites the
     * held invoice, etc). All values are copied verbatim from the tool output.
     *
     * @param array $toolRuns  [ ['name'=>, 'input'=>, 'output'=>['ok'=>,'data'=>...]], ... ]
     * @return array<string,mixed>  e.g. ['searched'=>'Lagos -> Dubai, 2026-12-01',
     *          'shown'=>'8 flight fares', 'picked'=>'Ibom Air 08:20 (NGN 114,405.79)',
     *          'held'=>'AITP27F0506BE1 — NGN 114,405.79 (awaiting passengers)']
     */
    function gia_toolstate_summarise(array $toolRuns): array
    {
        $s = [];
        foreach ($toolRuns as $tr) {
            if (!is_array($tr)) { continue; }
            $name = (string) ($tr['name'] ?? '');
            $in   = is_array($tr['input'] ?? null) ? $tr['input'] : [];
            $out  = is_array($tr['output'] ?? null) ? $tr['output'] : [];
            if (empty($out['ok'])) { continue; } // only remember what succeeded
            $data = is_array($out['data'] ?? null) ? $out['data'] : [];

            switch ($name) {
                // ---- SEARCHES: what route/where + how many real options were shown ----
                case 'flights_search': {
                    $route = gia_toolstate_flight_route($in, $data);
                    if ($route !== '') { $s['searched'] = 'flights ' . $route; }
                    $n = gia_toolstate_count($data, ['flights', 'offers', 'cards', 'searches']);
                    if ($n > 0) { $s['shown'] = $n . ' flight fare' . ($n === 1 ? '' : 's'); }
                    break;
                }
                case 'stays_search': {
                    $city = trim((string) ($in['city'] ?? $in['destination'] ?? ''));
                    if ($city !== '') { $s['searched'] = 'hotels in ' . $city; }
                    $n = gia_toolstate_count($data, ['stays', 'cards']);
                    if ($n > 0) { $s['shown'] = $n . ' propert' . ($n === 1 ? 'y' : 'ies'); }
                    break;
                }
                case 'umrah_search': {
                    $s['searched'] = 'Umrah departures';
                    $n = gia_toolstate_count($data, ['searches', 'cards']);
                    if ($n > 0) { $s['shown'] = $n . ' Umrah departure' . ($n === 1 ? '' : 's'); }
                    break;
                }
                case 'umrah_tiers': {
                    $n = gia_toolstate_count($data, ['umrah_tiers']);
                    if ($n > 0) { $s['shown'] = $n . ' Umrah tier' . ($n === 1 ? '' : 's'); }
                    break;
                }
                case 'stays_rooms': {
                    $n = gia_toolstate_count($data, ['stays_rooms']);
                    if ($n > 0) { $s['shown'] = $n . ' room option' . ($n === 1 ? '' : 's'); }
                    break;
                }
                case 'search_trip': {
                    $mods = [];
                    foreach ((is_array($data['searches'] ?? null) ? $data['searches'] : []) as $sr) {
                        $m = is_array($sr) ? (string) ($sr['module'] ?? $sr['kind'] ?? '') : '';
                        if ($m !== '') { $mods[$m] = true; }
                    }
                    if ($mods !== []) { $s['searched'] = implode(' + ', array_keys($mods)); }
                    break;
                }

                // ---- SELECTION: the specific option the traveller chose ----
                case 'flights_select':
                case 'select_option': {
                    $title = trim((string) ($data['title'] ?? $data['name'] ?? ''));
                    $price = $data['price'] ?? $data['amount'] ?? null;
                    $cur   = (string) ($data['currency'] ?? '');
                    $lbl = $title !== '' ? $title : 'an option';
                    if (is_numeric($price)) { $lbl .= ' (' . trim($cur . ' ' . number_format((float) $price, 2)) . ')'; }
                    $s['picked'] = $lbl;
                    break;
                }

                // ---- HELD / PAYABLE BOOKING: the invoice + amount in flight ----
                case 'flights_book':
                case 'stays_book':
                case 'umrah_book':
                case 'create_booking': {
                    $inv = (string) ($data['invoice_id'] ?? '');
                    if ($inv === '') { break; }
                    $amt = $data['amount'] ?? $data['amount_due_now'] ?? null;
                    $cur = (string) ($data['currency'] ?? '');
                    $state = !empty($data['needs_travellers']) ? ' (awaiting passenger details)'
                        : (!empty($data['needs_payment']) ? ' (awaiting payment)' : '');
                    $money = is_numeric($amt) ? ' — ' . trim($cur . ' ' . number_format((float) $amt, 2)) : '';
                    $s['held'] = $inv . $money . $state;
                    break;
                }
            }
        }
        return $s;
    }
}

if (!function_exists('App\\lib\\ai\\gia_toolstate_flight_route')) {
    /** Human route "LOS -> DXB, 2026-12-01" from a flights_search input/output. */
    function gia_toolstate_flight_route(array $in, array $data): string
    {
        $o = trim((string) ($in['origin'] ?? ''));
        $d = trim((string) ($in['destination'] ?? ''));
        $date = trim((string) ($in['departure_date'] ?? ''));
        if ($o === '' && $d === '' && is_array($in['segments'] ?? null) && $in['segments'] !== []) {
            // Multi-city: first + last leg.
            $segs = $in['segments'];
            $first = is_array($segs[0] ?? null) ? $segs[0] : [];
            $last  = is_array(end($segs) ?: null) ? end($segs) : [];
            $o = trim((string) ($first['origin'] ?? ''));
            $d = trim((string) ($last['destination'] ?? ''));
            $date = trim((string) ($first['date'] ?? ''));
        }
        if ($o === '' || $d === '') { return ''; }
        return $o . ' -> ' . $d . ($date !== '' ? ', ' . $date : '');
    }
}

if (!function_exists('App\\lib\\ai\\gia_toolstate_count')) {
    /** Count real result items under whichever key a tool used (searches[].cards too). */
    function gia_toolstate_count(array $data, array $keys): int
    {
        foreach ($keys as $k) {
            if ($k === 'searches' && is_array($data['searches'] ?? null)) {
                $n = 0;
                foreach ($data['searches'] as $sr) {
                    if (is_array($sr) && is_array($sr['cards'] ?? null)) { $n += count($sr['cards']); }
                }
                if ($n > 0) { return $n; }
                continue;
            }
            if (is_array($data[$k] ?? null) && $data[$k] !== []) { return count($data[$k]); }
        }
        return 0;
    }
}

if (!function_exists('App\\lib\\ai\\gia_toolstate_merge')) {
    /**
     * Merge this turn's facts onto the running state (latest-wins per key). A new
     * search replaces the old route; a book replaces a held invoice. Bounded to the
     * handful of keys above so it never grows unbounded. Returns the merged array.
     */
    function gia_toolstate_merge(array $prev, array $now): array
    {
        $allowed = ['searched', 'shown', 'picked', 'held'];
        $merged = is_array($prev) ? $prev : [];
        foreach ($now as $k => $v) {
            if (in_array($k, $allowed, true) && $v !== '' && $v !== null) { $merged[$k] = $v; }
        }
        // Keep only the allowed keys (drop anything stale/unknown).
        return array_intersect_key($merged, array_flip($allowed));
    }
}

if (!function_exists('App\\lib\\ai\\gia_toolstate_note')) {
    /**
     * Render the running state as a short, grounded system note so GIA recalls what
     * it did earlier this conversation. Returns '' when there's nothing to say.
     * Deliberately terse — the full data is still re-fetchable via tools; this just
     * reminds the model the thread's state so it doesn't act like a fresh agent.
     */
    function gia_toolstate_note(array $state): string
    {
        $bits = [];
        if (!empty($state['searched'])) { $bits[] = 'you searched ' . $state['searched']; }
        if (!empty($state['shown']))    { $bits[] = 'showed ' . $state['shown']; }
        if (!empty($state['picked']))   { $bits[] = 'they chose ' . $state['picked']; }
        if (!empty($state['held']))     { $bits[] = 'a booking is held: ' . $state['held']; }
        if ($bits === []) { return ''; }
        return 'EARLIER THIS CONVERSATION (trusted — from this chat\'s own tool results, '
            . 'not invented): ' . implode('; ', $bits) . '. '
            . 'Use this to continue smoothly — do NOT re-run a search or re-list options '
            . 'unless they ask; pick up where you left off. If you need exact prices/details '
            . 'again, call the relevant tool rather than quoting from memory.';
    }
}

if (!function_exists('App\\lib\\ai\\gia_toolstate_load')) {
    /** Read the running tool_state map for a conversation (fail-soft → []). */
    function gia_toolstate_load($db, int $convId): array
    {
        if ($convId <= 0) { return []; }
        try {
            if (function_exists('gia_toolstate_ensure_schema')) { gia_toolstate_ensure_schema($db); }
            $raw = $db->get('gia_conversations', 'tool_state', ['id' => $convId]);
            $m = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            return is_array($m) ? $m : [];
        } catch (\Throwable $e) {
            \error_log('[gia] toolstate load: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_toolstate_save')) {
    /**
     * Merge this turn's tool_runs onto the conversation's running tool_state and
     * persist. No-op when there are no new facts (so a chatty turn doesn't clear it).
     */
    function gia_toolstate_save($db, int $convId, array $toolRuns): void
    {
        if ($convId <= 0) { return; }
        $now = gia_toolstate_summarise($toolRuns);
        if ($now === []) { return; }
        try {
            if (function_exists('gia_toolstate_ensure_schema')) { gia_toolstate_ensure_schema($db); }
            $prev = gia_toolstate_load($db, $convId);
            $merged = gia_toolstate_merge($prev, $now);
            $db->update('gia_conversations', ['tool_state' => json_encode($merged)], ['id' => $convId]);
        } catch (\Throwable $e) {
            \error_log('[gia] toolstate save: ' . $e->getMessage());
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_toolstate_ensure_schema')) {
    /** Idempotent ALTER adding gia_conversations.tool_state (house self-healing). */
    function gia_toolstate_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        try {
            $exists = $db->query("SHOW COLUMNS FROM `gia_conversations` LIKE 'tool_state'")->fetch();
            if (!$exists) {
                $db->query("ALTER TABLE `gia_conversations` ADD COLUMN `tool_state` text DEFAULT NULL");
                // Re-check: only latch the one-shot guard once the column is CONFIRMED
                // present — otherwise a first-call ALTER that failed (transient lock, a
                // DB user without ALTER rights mid-grant) would latch $done and every
                // later load/save in this request would hit "column not found".
                $exists = $db->query("SHOW COLUMNS FROM `gia_conversations` LIKE 'tool_state'")->fetch();
            }
            if ($exists) { $done = true; }
        } catch (\Throwable $e) { /* best-effort, house pattern — $done stays false to retry */ }
    }
}
