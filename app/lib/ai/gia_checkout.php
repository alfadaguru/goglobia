<?php
// ============================================================================
// GIA checkout snapshot + scoped approval (BRD Phase B).
//
// What this adds that GoGlobia did NOT have (verified absent — §36.8):
//   1. An IMMUTABLE checkout snapshot: an HMAC-signed hash of the exact priced
//      quote {items, price_original, tax, currency, pax counts, dates}. Freezing a
//      draft produces a snapshot that cannot change without changing its hash.
//   2. A SCOPED approval bound to that snapshot hash + amount + pax (not just the
//      session or the amount-only payment token).
//   3. APPROVAL-EXPIRES-ON-CHANGE: at verify time the snapshot is re-derived from
//      the current quote; if it no longer matches (price/pax/dates moved), the
//      approval is rejected so the model/UI must re-quote and re-approve
//      (BRD PAY-002, PAY-003, §18.1).
//
// IMPORTANT — Phase B does NOT move money. It produces a snapshot + an approval.
// The approval is the GATE that Phase C (payment/commit) will require. Nothing
// here pays, issues, or confirms a supplier booking.
//
// It REUSES (does not fork) the existing draft store (logs_bookings) and the same
// server-side recompute the submit path already trusts. The HMAC key comes from
// the SAME private per-install .env material the JWT secret uses (never a
// hardcoded constant) — see gia_checkout_secret().
//
// Plain procedural helpers (the codebase's house style for app/lib/*), global
// functions guarded by function_exists so re-including is safe.
// ============================================================================

if (!function_exists('gia_checkout_secret')) {
    /**
     * Per-install HMAC key for GIA snapshot/approval signing. Derived from the
     * SAME private .env material JWT uses (DB creds + license + path), under a
     * GIA-specific salt so it is a distinct key domain from JWT. Never a public
     * constant; fails closed to a per-process value if .env is unreadable (then
     * approvals simply won't verify across requests — safe, not insecure).
     */
    function gia_checkout_secret(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $secret = '';
        $envVal = getenv('JWT_SECRET');
        if ($envVal !== false && trim((string) $envVal) !== '') {
            // Bind GIA's key to the operator's JWT_SECRET but in its own domain.
            $secret = hash('sha256', 'gia-checkout-v1|' . trim((string) $envVal));
        }
        if ($secret === '') {
            $envFile = __DIR__ . '/../../../.env';
            if (is_file($envFile)) {
                $vars = @parse_ini_file($envFile);
                if (is_array($vars)) {
                    if (!empty($vars['JWT_SECRET']) && trim((string) $vars['JWT_SECRET']) !== '') {
                        $secret = hash('sha256', 'gia-checkout-v1|' . trim((string) $vars['JWT_SECRET']));
                    } else {
                        $material = ($vars['DB_PASSWORD'] ?? '')
                            . '|' . ($vars['DB_DATABASE'] ?? '')
                            . '|' . ($vars['DB_USERNAME'] ?? '')
                            . '|' . ($vars['LICENSE_KEY'] ?? '')
                            . '|' . __DIR__;
                        if (trim($material, '|') !== '') {
                            $secret = hash('sha256', 'gia-checkout-v1|' . $material);
                        }
                    }
                }
            }
        }
        if ($secret === '') {
            $secret = hash('sha256', 'gia-checkout-v1|' . __DIR__ . '|' . (getenv('HOSTNAME') ?: php_uname('n')));
        }
        $cached = $secret;
        return $cached;
    }
}

if (!function_exists('gia_canonical_quote')) {
    /**
     * Reduce a quote to the CANONICAL, order-stable set of fields that define its
     * identity for signing. Only money- and identity-material fields are included;
     * presentational fields (titles, images) are excluded so cosmetic changes do
     * not invalidate an approval, but any price/pax/date/currency change does.
     *
     * @param array $quote ['currency'=>, 'items'=>[['module'=>,'supplier'=>,
     *                      'net_base'=>,'markup_base'=>,'tax_base'=>,'final_base'=>,
     *                      'params'=>['checkin'=>,'checkout'=>,'date'=>,...]]],
     *                      'adults'=>,'children'=>,'final_base'=>]
     */
    function gia_canonical_quote(array $quote): array
    {
        $num = static function ($v): string {
            // Money as a fixed-precision string (never float) — BRD DATA-006.
            return number_format((float) $v, 2, '.', '');
        };
        $items = [];
        foreach ((array) ($quote['items'] ?? []) as $it) {
            $p = (array) ($it['params'] ?? []);
            // final_base falls back across the shapes GIA may receive: an explicitly
            // priced line (final_base), the AI-trip package line (price_with_tax_base /
            // price_base), or the repriced draft item (price = display sell total).
            // net/markup/tax are optional (platform-internal split recomputed by the
            // submit path); the SNAPSHOT binds the customer-facing total + identity.
            $items[] = [
                'module'     => (string) ($it['module'] ?? $it['kind'] ?? ''),
                'supplier'   => (string) ($it['supplier'] ?? ''),
                'net_base'   => $num($it['net_base'] ?? 0),
                'markup_base'=> $num($it['markup_base'] ?? 0),
                'tax_base'   => $num($it['tax_base'] ?? 0),
                'final_base' => $num($it['final_base'] ?? $it['price_with_tax_base'] ?? $it['price_base'] ?? $it['price'] ?? 0),
                'checkin'    => (string) ($p['checkin'] ?? $p['check_in'] ?? ''),
                'checkout'   => (string) ($p['checkout'] ?? $p['check_out'] ?? ''),
                'date'       => (string) ($p['date'] ?? $p['departure'] ?? ''),
                'return'     => (string) ($p['return'] ?? $p['return_date'] ?? ''),
            ];
        }
        // Stable order so hashing is deterministic regardless of input order.
        usort($items, static function ($a, $b) {
            return strcmp(
                $a['module'] . '|' . $a['supplier'] . '|' . $a['date'] . '|' . $a['checkin'] . '|' . $a['final_base'],
                $b['module'] . '|' . $b['supplier'] . '|' . $b['date'] . '|' . $b['checkin'] . '|' . $b['final_base']
            );
        });

        return [
            'v'        => 1,
            'currency' => strtoupper((string) ($quote['currency'] ?? '')),
            'adults'   => (int) ($quote['adults'] ?? 0),
            'children' => (int) ($quote['children'] ?? 0),
            'final'    => $num($quote['final_base'] ?? $quote['total'] ?? array_sum(array_map(static fn ($i) => (float) $i['final_base'], $items))),
            'items'    => $items,
        ];
    }
}

if (!function_exists('gia_snapshot_hash')) {
    /** HMAC-SHA256 of the canonical quote — the immutable snapshot identity. */
    function gia_snapshot_hash(array $quote): string
    {
        $canon = gia_canonical_quote($quote);
        $json = json_encode($canon, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash_hmac('sha256', (string) $json, gia_checkout_secret());
    }
}

if (!function_exists('ensureGiaCheckoutSchema')) {
    /**
     * Idempotent — creates the gia_checkouts table if missing. Mirrors the house
     * `CREATE TABLE IF NOT EXISTS` pattern (functions.php:792+). Also update
     * install/db.sql when this ships.
     */
    function ensureGiaCheckoutSchema($db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `gia_checkouts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `checkout_ref` varchar(40) NOT NULL,
                `draft_hash` varchar(255) DEFAULT NULL,
                `snapshot_hash` varchar(64) NOT NULL,
                `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
                `currency` varchar(10) NOT NULL DEFAULT '',
                `adults` int(11) NOT NULL DEFAULT 0,
                `children` int(11) NOT NULL DEFAULT 0,
                `quote_json` longtext DEFAULT NULL,
                `status` enum('QUOTED','PENDING_APPROVAL','APPROVED','EXPIRED','CONSUMED') NOT NULL DEFAULT 'QUOTED',
                `approval_token` varchar(80) DEFAULT NULL,
                `approved_at` datetime DEFAULT NULL,
                `expires_at` datetime DEFAULT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `checkout_ref` (`checkout_ref`),
                KEY `snapshot_hash` (`snapshot_hash`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (\Throwable $e) {
            error_log('[gia] ensureGiaCheckoutSchema: ' . $e->getMessage());
        }
        $done = true;
    }
}

if (!function_exists('gia_create_checkout')) {
    /**
     * Freeze a priced quote into an immutable checkout snapshot (status QUOTED).
     * Returns ['ok'=>bool, 'checkout_ref'=>, 'snapshot_hash'=>, 'amount'=>,
     * 'currency'=>, 'expires_at'=>, 'error'=>?]. Does NOT move money.
     *
     * @param array $quote the priced quote (see gia_canonical_quote()).
     * @param int   $ttlSeconds snapshot validity window (default 15 min).
     */
    function gia_create_checkout($db, array $quote, ?string $userId = null, int $ttlSeconds = 900): array
    {
        ensureGiaCheckoutSchema($db);

        $canon = gia_canonical_quote($quote);
        $amount = (float) $canon['final'];
        if ($amount <= 0) {
            // BRD: never create a checkout for an unpriced/zero quote (price-trust).
            return ['ok' => false, 'error' => 'This trip has no confirmed total yet. Please reprice before checkout.'];
        }
        if ($canon['currency'] === '') {
            return ['ok' => false, 'error' => 'Missing currency on the quote.'];
        }

        $snapshotHash = gia_snapshot_hash($quote);
        $ref = 'gco_' . bin2hex(random_bytes(10));
        $expiresAt = date('Y-m-d H:i:s', time() + max(60, $ttlSeconds));

        try {
            $db->insert('gia_checkouts', [
                'checkout_ref'  => $ref,
                'draft_hash'    => (string) ($quote['draft_hash'] ?? ''),
                'snapshot_hash' => $snapshotHash,
                'amount'        => round($amount, 2),
                'currency'      => $canon['currency'],
                'adults'        => (int) $canon['adults'],
                'children'      => (int) $canon['children'],
                'quote_json'    => json_encode($canon, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'status'        => 'QUOTED',
                'expires_at'    => $expiresAt,
                'user_id'       => $userId !== null && $userId !== '' ? $userId : null,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[gia] gia_create_checkout insert: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not create the checkout. Please try again.'];
        }

        return [
            'ok' => true,
            'checkout_ref'  => $ref,
            'snapshot_hash' => $snapshotHash,
            'amount'        => round($amount, 2),
            'currency'      => $canon['currency'],
            'expires_at'    => $expiresAt,
        ];
    }
}

if (!function_exists('gia_request_approval')) {
    /**
     * Issue a SCOPED approval for a specific checkout snapshot. This is the
     * explicit-approval gate (BRD PAY-002). The approval is bound to the EXACT
     * snapshot hash: it is only valid while the quote is unchanged and unexpired.
     *
     * @param array $liveQuote the CURRENT quote at approval time — re-hashed and
     *                         compared to the stored snapshot (expire-on-change).
     * Returns ['ok'=>, 'approval_token'=>, 'expires_at'=>, 'error'=>?,
     *          'error_code'=>?('PRICE_CHANGED'|'OFFER_EXPIRED'|...)].
     */
    function gia_request_approval($db, string $checkoutRef, array $liveQuote, ?string $userId = null): array
    {
        ensureGiaCheckoutSchema($db);

        $row = $db->get('gia_checkouts', '*', ['checkout_ref' => $checkoutRef]);
        if (!$row) {
            return ['ok' => false, 'error' => 'Checkout not found.', 'error_code' => 'NOT_FOUND'];
        }
        if (in_array((string) $row['status'], ['EXPIRED', 'CONSUMED'], true)) {
            return ['ok' => false, 'error' => 'This checkout can no longer be approved.', 'error_code' => 'ACTION_NOT_PERMITTED'];
        }
        // Owner check: if the checkout is bound to a user, the approver must match.
        if (!empty($row['user_id']) && $userId !== null && (string) $row['user_id'] !== (string) $userId) {
            return ['ok' => false, 'error' => 'You are not authorized to approve this checkout.', 'error_code' => 'ACTION_NOT_PERMITTED'];
        }
        // Expiry.
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            $db->update('gia_checkouts', ['status' => 'EXPIRED', 'updated_at' => date('Y-m-d H:i:s')], ['checkout_ref' => $checkoutRef]);
            return ['ok' => false, 'error' => 'This quote expired. Please reprice and try again.', 'error_code' => 'OFFER_EXPIRED'];
        }
        // EXPIRE-ON-CHANGE: the live quote must still hash to the frozen snapshot.
        $liveHash = gia_snapshot_hash($liveQuote);
        if (!hash_equals((string) $row['snapshot_hash'], $liveHash)) {
            $db->update('gia_checkouts', ['status' => 'EXPIRED', 'updated_at' => date('Y-m-d H:i:s')], ['checkout_ref' => $checkoutRef]);
            return [
                'ok' => false,
                'error' => 'The price or details changed since this quote. Please review the new total and approve again.',
                'error_code' => 'PRICE_CHANGED',
            ];
        }

        $token = 'gap_' . bin2hex(random_bytes(16));
        $approvalTtl = 15 * 60; // approval valid 15 minutes once granted
        $approvalExpires = date('Y-m-d H:i:s', time() + $approvalTtl);
        try {
            $db->update('gia_checkouts', [
                'status'         => 'APPROVED',
                'approval_token' => $token,
                'approved_at'    => date('Y-m-d H:i:s'),
                'expires_at'     => $approvalExpires,
                'user_id'        => $userId !== null && $userId !== '' ? $userId : ($row['user_id'] ?? null),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['checkout_ref' => $checkoutRef]);
        } catch (\Throwable $e) {
            error_log('[gia] gia_request_approval update: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record the approval. Please try again.', 'error_code' => 'INTERNAL'];
        }

        return ['ok' => true, 'approval_token' => $token, 'expires_at' => $approvalExpires];
    }
}

if (!function_exists('gia_verify_approval')) {
    /**
     * Verify a scoped approval before a money action (Phase C will call this). The
     * approval must be APPROVED, unexpired, token-matched, and the live quote must
     * STILL hash to the approved snapshot — otherwise it is rejected and the
     * caller must re-quote + re-approve (BRD §18.1 "price increases → invalidate").
     *
     * Returns ['ok'=>, 'checkout'=>row, 'error'=>?, 'error_code'=>?].
     */
    function gia_verify_approval($db, string $checkoutRef, string $approvalToken, array $liveQuote, ?string $userId = null): array
    {
        ensureGiaCheckoutSchema($db);

        $row = $db->get('gia_checkouts', '*', ['checkout_ref' => $checkoutRef]);
        if (!$row) {
            return ['ok' => false, 'error' => 'Checkout not found.', 'error_code' => 'NOT_FOUND'];
        }
        if ((string) $row['status'] !== 'APPROVED') {
            return ['ok' => false, 'error' => 'This checkout is not approved.', 'error_code' => 'APPROVAL_REQUIRED'];
        }
        if (empty($row['approval_token']) || !hash_equals((string) $row['approval_token'], $approvalToken)) {
            return ['ok' => false, 'error' => 'Invalid approval.', 'error_code' => 'APPROVAL_REQUIRED'];
        }
        if (!empty($row['user_id']) && $userId !== null && (string) $row['user_id'] !== (string) $userId) {
            return ['ok' => false, 'error' => 'You are not authorized to use this approval.', 'error_code' => 'ACTION_NOT_PERMITTED'];
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            $db->update('gia_checkouts', ['status' => 'EXPIRED', 'updated_at' => date('Y-m-d H:i:s')], ['checkout_ref' => $checkoutRef]);
            return ['ok' => false, 'error' => 'This approval expired. Please approve again.', 'error_code' => 'OFFER_EXPIRED'];
        }
        $liveHash = gia_snapshot_hash($liveQuote);
        if (!hash_equals((string) $row['snapshot_hash'], $liveHash)) {
            $db->update('gia_checkouts', ['status' => 'EXPIRED', 'updated_at' => date('Y-m-d H:i:s')], ['checkout_ref' => $checkoutRef]);
            return ['ok' => false, 'error' => 'The price or details changed. A new approval is required.', 'error_code' => 'PRICE_CHANGED'];
        }

        return ['ok' => true, 'checkout' => $row];
    }
}
