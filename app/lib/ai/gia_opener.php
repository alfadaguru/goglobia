<?php
// ============================================================================
// FILE: app/lib/ai/gia_opener.php
// GIA personalized opener + context-aware shortcuts.
//
// Replaces the widget's fully-static greeting + fixed starter chips with a
// per-traveler opener derived from REAL data:
//   - greeting : name-aware line (and, when it adds value, a GIA-authored line
//                via the existing agent — guarded + cached so it is not a paid
//                call on every page open).
//   - shortcuts: one-tap suggestions built from the traveler's own recent
//                activity (last booking module, last GIA module) with sensible
//                fallbacks — never invented.
//
// FAIL-SOFT: every path returns usable defaults; it must NEVER break the widget.
// Channel-agnostic data (greeting text + shortcut strings) so the messaging
// adapters (Phase 1+) can reuse gia_build_opener() for their first turn too.
// ============================================================================

if (!function_exists('gia_opener_default_shortcuts')) {
    /** The house default starters (match the widget's historical defaults). */
    function gia_opener_default_shortcuts(string $context = ''): array
    {
        $byContext = [
            'umrah'   => ['Show me Umrah packages', 'Umrah for 2 adults', 'Cheapest Umrah option'],
            'flights' => ['Lagos to London', 'Abuja to Dubai next month', 'Cheapest flight out'],
            'stays'   => ['Hotels in Dubai', 'Family hotel in Zanzibar', 'Budget stay this weekend'],
            'tours'   => ['Tours in Dubai', 'City tour this week', 'Adventure day trip'],
        ];
        if ($context !== '' && isset($byContext[$context])) {
            return $byContext[$context];
        }
        return ['Flights to Dubai', 'Hotels in Lagos', 'Plan a trip to Zanzibar'];
    }
}

if (!function_exists('gia_opener_module_label')) {
    /** Human, traveler-facing label for a module key. */
    function gia_opener_module_label(string $module): string
    {
        $map = [
            'flights' => 'flights', 'hotels' => 'hotels', 'stays' => 'hotels',
            'tours' => 'tours', 'cars' => 'car hire', 'visa' => 'a visa',
            'esim' => 'an eSIM', 'ferries' => 'ferries', 'rail' => 'trains',
            'bus' => 'buses', 'umrah' => 'Umrah',
        ];
        return $map[strtolower($module)] ?? '';
    }
}

if (!function_exists('gia_build_opener')) {
    /**
     * Build the personalized opener for a traveler.
     *
     * @param object $db
     * @param string $userId    users.user_id ('' => guest)
     * @param string $browserId gia_bid ('' ok)
     * @param string $context   module context the widget was opened in ('' = generic)
     * @param bool   $allowLlm  permit ONE short agent call to author the greeting
     *                          (only used when there is real personalization to
     *                          justify it; callers pass false to force the cheap path)
     * @return array{greeting:string,shortcuts:array<int,string>,source:string}
     */
    function gia_build_opener($db, string $userId = '', string $browserId = '', string $context = '', bool $allowLlm = false): array
    {
        $userId    = trim($userId);
        $browserId = trim($browserId);
        $context   = trim($context);

        // --- Resolve safe identity (name/currency). Fail-soft to []. ---
        $profile = function_exists('gia_user_profile') ? gia_user_profile($db, $userId) : [];
        $first   = trim((string) ($profile['first_name'] ?? ''));

        // --- Recent activity (REAL data only) for context-aware shortcuts. ---
        $recentModule = gia_opener_recent_module($db, $userId);

        // --- Shortcuts: lead with the traveler's own recent interest, then fill
        //     with sensible defaults (deduped, max 3). ---
        $shortcuts = [];
        if ($recentModule !== '') {
            $label = gia_opener_module_label($recentModule);
            if ($label !== '') {
                // A continue-style suggestion first.
                $shortcuts[] = 'Find ' . $label . ' again';
            }
        }
        foreach (gia_opener_default_shortcuts($context) as $s) {
            if (!in_array($s, $shortcuts, true)) {
                $shortcuts[] = $s;
            }
        }
        $shortcuts = array_slice($shortcuts, 0, 3);

        // --- Greeting (cheap, name-aware default). ---
        $greeting = gia_opener_default_greeting($first, $context);
        $source   = 'static';

        // --- Optional GIA-authored greeting: only when it genuinely adds value
        //     (we know the traveler AND have a recent interest), gated by the
        //     feature flag, cached per conversation so it is not paid-per-open. ---
        if ($allowLlm && $first !== '' && $recentModule !== ''
            && function_exists('aiTripIsEnabled') && aiTripIsEnabled($db)) {
            $authored = gia_opener_llm_greeting($db, $first, $recentModule, $context);
            if ($authored !== '') {
                $greeting = $authored;
                $source   = 'gia';
            }
        }

        return [
            'greeting'  => $greeting,
            'shortcuts' => array_values($shortcuts),
            'source'    => $source,
        ];
    }
}

if (!function_exists('gia_opener_recent_module')) {
    /**
     * The traveler's most recent module of interest, from their own data:
     * last real booking first, else their last GIA search/engagement module.
     * Returns '' for guests or when nothing is on record. FAIL-SOFT.
     */
    function gia_opener_recent_module($db, string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            return '';
        }
        try {
            // 1) Most recent booking (strongest signal).
            $b = $db->get('bookings', ['module', 'module_type'], [
                'user_id' => $userId,
                'ORDER'   => ['created_at' => 'DESC'],
                'LIMIT'   => 1,
            ]);
            if (is_array($b)) {
                $m = trim((string) ($b['module'] ?? $b['module_type'] ?? ''));
                if ($m !== '') {
                    return strtolower($m);
                }
            }
        } catch (\Throwable $e) {
            error_log('[gia] opener recent booking: ' . $e->getMessage());
        }
        try {
            // 2) Else the last module this user searched/engaged in GIA — the last
            //    event with a module, for a conversation owned by this user.
            if (function_exists('ensureGiaAnalyticsSchema')) {
                ensureGiaAnalyticsSchema($db);
            }
            // Alias the joined column (house convention, e.g. supplier_gl_ext.php) so
            // the result key is a reliable 'module' regardless of Medoo join keying.
            $rows = $db->select('gia_events', [
                '[>]gia_conversations' => ['conv_id' => 'id'],
            ], [
                'gia_events.module(module)',
            ], [
                'gia_conversations.user_id' => $userId,
                'gia_events.module[!]'      => null,
                'ORDER'                     => ['gia_events.created_at' => 'DESC'],
                'LIMIT'                     => 1,
            ]);
            if (is_array($rows) && isset($rows[0]['module'])) {
                $m = trim((string) $rows[0]['module']);
                if ($m !== '') {
                    return strtolower($m);
                }
            }
        } catch (\Throwable $e) {
            error_log('[gia] opener recent event: ' . $e->getMessage());
        }
        return '';
    }
}

if (!function_exists('gia_opener_default_greeting')) {
    /** Name-aware, varied-but-deterministic greeting. No LLM, instant. */
    function gia_opener_default_greeting(string $first, string $context = ''): string
    {
        if ($context === 'umrah') {
            return $first !== ''
                ? "Assalamu alaikum, {$first} 🕋 Want to look at Umrah packages? I can show you in seconds."
                : "Assalamu alaikum 🕋 Ask me about Umrah and I'll show you our packages right away.";
        }
        if ($first !== '') {
            return "Welcome back, {$first} 👋 Where are we headed next?";
        }
        return "Hi, I'm GIA 👋 Where would you like to go, or what can I help you book?";
    }
}

if (!function_exists('gia_opener_llm_greeting')) {
    /**
     * One short, GIA-authored opener line for a KNOWN traveler with a recent
     * interest. Cached per (user, module) in gia_opener_cache for a day so it is
     * not a paid call on every page open. Returns '' on any failure (caller keeps
     * the cheap default). Reuses the existing agent — no new provider wiring.
     */
    function gia_opener_llm_greeting($db, string $first, string $recentModule, string $context): string
    {
        $label = gia_opener_module_label($recentModule);
        if ($first === '' || $label === '') {
            return '';
        }
        // --- Cache (idempotent table; 24h freshness). ---
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `gia_opener_cache` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `cache_key` VARCHAR(191) NOT NULL,
                `line` VARCHAR(400) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_cache_key` (`cache_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $key = substr(md5($first . '|' . $recentModule . '|' . $context), 0, 32);
            $row = $db->get('gia_opener_cache', ['line', 'created_at'], ['cache_key' => $key]);
            if (is_array($row) && !empty($row['line'])) {
                // Fresh within 24h?
                $age = time() - strtotime((string) ($row['created_at'] ?? 'now'));
                if ($age >= 0 && $age < 86400) {
                    return (string) $row['line'];
                }
            }
        } catch (\Throwable $e) {
            // cache unavailable -> just skip caching, still try to author below
            $key = '';
        }

        // --- Author a single line via the agent (no tools; one short turn). ---
        try {
            $aiDir = __DIR__ . '/';
            require_once $aiDir . 'giaToolInterface.php';
            require_once $aiDir . 'giaChatProvider.php';
            require_once $aiDir . 'giaAgent.php';
            $agent = new \App\lib\ai\giaAgent($db);
            $agent->setSystemPrompt(
                "You are GIA, GoGlobia's travel assistant. Write ONE short, warm opening line "
                . "(max 18 words, at most one emoji) to greet a returning traveler by first name and "
                . "gently nod to their recent interest in {$label}, inviting the next step. "
                . "Plain text only, no quotes, no lists."
            );
            $res = $agent->run("Greet {$first}, who recently looked at {$label}.", []);
            $line = trim((string) ($res['reply'] ?? ''));
            // Guardrails: keep it a single tidy line.
            $line = trim(preg_replace('/\s+/', ' ', $line));
            if ($line !== '' && mb_strlen($line) <= 300) {
                if (!empty($key)) {
                    try {
                        $db->insert('gia_opener_cache', ['cache_key' => $key, 'line' => $line]);
                    } catch (\Throwable $e) {
                        // likely a race on the unique key; update instead
                        try { $db->update('gia_opener_cache', ['line' => $line], ['cache_key' => $key]); } catch (\Throwable $e2) {}
                    }
                }
                return $line;
            }
        } catch (\Throwable $e) {
            error_log('[gia] opener llm: ' . $e->getMessage());
        }
        return '';
    }
}
