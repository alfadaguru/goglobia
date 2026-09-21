<?php
// ============================================================================
// SAVED CARDS (card-on-file vault) — Stripe + Paystack adapters behind one lib.
// ----------------------------------------------------------------------------
// PCI: this platform NEVER receives or stores a card number / CVV / expiry
// secret. The card is captured in the BROWSER by the provider's JS (Stripe.js /
// Paystack Inline); our server only ever handles a provider TOKEN
// (Stripe pm_... PaymentMethod, or Paystack authorization_code) plus non-secret
// display data (brand, last4, exp month/year). Those tokens are bound to OUR
// provider account and are useless without our secret keys.
//
// Charging a saved card feeds the EXISTING money spine (txn_create +
// wallet_topup_success), so wallet crediting, the ledger and idempotency are
// unchanged from a normal top-up. Idempotency key: CARD-{provider_ref}.
// ============================================================================

if (!function_exists('cards_gateway_for_currency')) {
    /**
     * The payment_gateways row that will vault/charge a card in $currency, with
     * per-service credential overrides applied for $booking-like context (optional).
     * NGN -> Paystack, everything else -> Stripe (the platform rule). Returns null
     * if the matching gateway isn't configured/enabled.
     */
    function cards_gateway_for_currency($db, string $currency, ?array $scope = null): ?array
    {
        $currency = strtoupper(trim($currency)) ?: 'USD';
        $wantType = 'credit_card'; // both Stripe + Paystack are credit_card rows
        $wantName = ($currency === 'NGN') ? 'Paystack' : 'Stripe';
        $gw = $db->get('payment_gateways', '*', ['name' => $wantName, 'type' => $wantType, 'status' => 1]);
        if (!$gw) { return null; }
        // Apply per-service credential overrides when we know the booking context.
        if ($scope && function_exists('payment_gateway_apply_service_creds')) {
            payment_gateway_apply_service_creds($db, $gw, $scope);
        }
        return $gw;
    }
}

if (!function_exists('cards_provider_of')) {
    /** 'stripe' | 'paystack' from a gateway row, or '' if unsupported. */
    function cards_provider_of(array $gateway): string
    {
        $n = strtolower((string) ($gateway['name'] ?? ''));
        if (strpos($n, 'paystack') !== false) { return 'paystack'; }
        if (strpos($n, 'stripe') !== false) { return 'stripe'; }
        return '';
    }
}

if (!function_exists('cards_secret_key')) {
    /**
     * The SECRET key for a gateway. Stripe stores it in c2, Paystack in c1
     * (verified against the existing gateway views + DVA code). Never logged.
     */
    function cards_secret_key(array $gateway): string
    {
        $provider = cards_provider_of($gateway);
        if ($provider === 'stripe')   { return trim((string) ($gateway['c2'] ?? '')); }
        if ($provider === 'paystack') { return trim((string) ($gateway['c1'] ?? '')); }
        return '';
    }
}

if (!function_exists('cards_publishable_key')) {
    /** Publishable/public key (browser-safe). Stripe c1, Paystack c2. */
    function cards_publishable_key(array $gateway): string
    {
        $provider = cards_provider_of($gateway);
        if ($provider === 'stripe')   { return trim((string) ($gateway['c1'] ?? '')); }
        if ($provider === 'paystack') { return trim((string) ($gateway['c2'] ?? '')); }
        return '';
    }
}

if (!function_exists('cards_list')) {
    /** A customer's active saved cards — display data only (no token exposed). */
    function cards_list($db, string $userId): array
    {
        if ($userId === '') { return []; }
        $rows = $db->select('saved_cards', ['id', 'provider', 'currency', 'brand', 'last4', 'exp_month', 'exp_year', 'is_default', 'created_at'], [
            'user_id' => $userId, 'status' => 'active', 'ORDER' => ['is_default' => 'DESC', 'id' => 'DESC'],
        ]) ?: [];
        return array_map(function ($r) {
            return [
                'id'         => (int) $r['id'],
                'provider'   => $r['provider'],
                'currency'   => $r['currency'],
                'brand'      => $r['brand'],
                'last4'      => $r['last4'],
                'exp'        => $r['exp_month'] ? sprintf('%02d/%02d', (int) $r['exp_month'], (int) $r['exp_year'] % 100) : null,
                'is_default' => (int) $r['is_default'] === 1,
                'expired'    => cards_is_expired($r),
            ];
        }, $rows);
    }
}

if (!function_exists('cards_is_expired')) {
    /** Soft expiry check for the display label (not a charge guard). */
    function cards_is_expired(array $r): bool
    {
        $m = (int) ($r['exp_month'] ?? 0); $y = (int) ($r['exp_year'] ?? 0);
        if ($m < 1 || $y < 1) { return false; }
        // Compare year+month against the DB clock via PHP is fine here (display only).
        $now = getdate();
        return ($y < $now['year']) || ($y === $now['year'] && $m < $now['mon']);
    }
}

if (!function_exists('cards_get_owned')) {
    /** Fetch a full card row ONLY if it belongs to $userId and is active, else null. */
    function cards_get_owned($db, int $cardId, string $userId): ?array
    {
        if ($cardId <= 0 || $userId === '') { return null; }
        $r = $db->get('saved_cards', '*', ['id' => $cardId, 'user_id' => $userId, 'status' => 'active']);
        return $r ?: null;
    }
}

if (!function_exists('cards_save')) {
    /**
     * Persist a vaulted card. Called after a provider mints the token (Stripe
     * SetupIntent confirm, or a Paystack top-up returning a reusable authorization).
     * Stores ONLY token + display fields. Idempotent per (user, token). The first
     * card a user saves becomes their default.
     *
     * @param array $d provider, gateway_id, currency, provider_customer, token,
     *                 brand, last4, exp_month, exp_year
     * @return array{ok:bool,id?:int,message?:string}
     */
    function cards_save($db, string $userId, array $d): array
    {
        $userId = trim($userId);
        $provider = strtolower((string) ($d['provider'] ?? ''));
        $token = trim((string) ($d['token'] ?? ''));
        if ($userId === '' || !in_array($provider, ['stripe', 'paystack'], true) || $token === '') {
            return ['ok' => false, 'message' => 'Invalid card data'];
        }
        // NEVER accept a raw PAN. Defensive: reject anything that looks like one.
        if (preg_match('/^\d{13,19}$/', preg_replace('/\s+/', '', $token))) {
            error_log('cards_save: refused a token that looks like a PAN');
            return ['ok' => false, 'message' => 'Invalid card token'];
        }
        // De-dupe: same token already saved (active) → return it.
        $existing = $db->get('saved_cards', ['id'], ['user_id' => $userId, 'token' => $token]);
        if ($existing) {
            $db->update('saved_cards', ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $existing['id']]);
            return ['ok' => true, 'id' => (int) $existing['id'], 'already' => true];
        }
        $isFirst = (int) $db->count('saved_cards', ['user_id' => $userId, 'status' => 'active']) === 0;
        try {
            $db->insert('saved_cards', [
                'user_id'           => $userId,
                'provider'          => $provider,
                'gateway_id'        => isset($d['gateway_id']) ? (int) $d['gateway_id'] : null,
                'currency'          => strtoupper((string) ($d['currency'] ?? 'USD')),
                'provider_customer' => trim((string) ($d['provider_customer'] ?? '')) ?: null,
                'token'             => $token,
                'brand'             => mb_substr((string) ($d['brand'] ?? ''), 0, 32) ?: null,
                'last4'             => preg_replace('/\D/', '', (string) ($d['last4'] ?? '')) ?: null,
                'exp_month'         => ($d['exp_month'] ?? null) !== null ? (int) $d['exp_month'] : null,
                'exp_year'          => ($d['exp_year'] ?? null) !== null ? (int) $d['exp_year'] : null,
                'is_default'        => $isFirst ? 1 : 0,
                'status'            => 'active',
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'id' => (int) $db->id()];
        } catch (\Throwable $e) {
            error_log('cards_save: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save card'];
        }
    }
}

if (!function_exists('cards_set_default')) {
    /** Make one owned card the default (clears the others). */
    function cards_set_default($db, int $cardId, string $userId): array
    {
        $card = cards_get_owned($db, $cardId, $userId);
        if (!$card) { return ['ok' => false, 'message' => 'Card not found']; }
        $db->update('saved_cards', ['is_default' => 0], ['user_id' => $userId]);
        $db->update('saved_cards', ['is_default' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $cardId]);
        return ['ok' => true];
    }
}

if (!function_exists('cards_delete')) {
    /**
     * Remove a saved card: detach at the provider (best-effort) + soft-delete
     * locally (so historical charges keep a reference). Owner-checked.
     */
    function cards_delete($db, int $cardId, string $userId): array
    {
        $card = cards_get_owned($db, $cardId, $userId);
        if (!$card) { return ['ok' => false, 'message' => 'Card not found']; }
        // Best-effort provider detach; never fail the local removal on a provider error.
        try {
            $gw = $db->get('payment_gateways', '*', ['id' => (int) ($card['gateway_id'] ?? 0)]);
            $secret = $gw ? cards_secret_key($gw) : '';
            if ($secret !== '') {
                if ($card['provider'] === 'stripe') {
                    cards_http('POST', 'https://api.stripe.com/v1/payment_methods/' . rawurlencode((string) $card['token']) . '/detach', $secret, [], 'stripe');
                } elseif ($card['provider'] === 'paystack' && !empty($card['token'])) {
                    cards_http('POST', 'https://api.paystack.co/customer/deactivate_authorization', $secret, ['authorization_code' => $card['token']], 'paystack');
                }
            }
        } catch (\Throwable $e) { error_log('cards_delete provider detach: ' . $e->getMessage()); }

        $wasDefault = (int) ($card['is_default'] ?? 0) === 1;
        $db->update('saved_cards', ['status' => 'removed', 'is_default' => 0, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $cardId]);
        // Promote another card to default if we removed the default one.
        if ($wasDefault) {
            $next = $db->get('saved_cards', ['id'], ['user_id' => $userId, 'status' => 'active', 'ORDER' => ['id' => 'DESC']]);
            if ($next) { $db->update('saved_cards', ['is_default' => 1], ['id' => (int) $next['id']]); }
        }
        return ['ok' => true];
    }
}

if (!function_exists('cards_stripe_customer')) {
    /**
     * Return this user's Stripe customer id, creating one if absent. Stored in
     * users.stripe_customer_code (added idempotently). Read-only if already set.
     * @return string cus_... or '' on failure
     */
    function cards_stripe_customer($db, string $userId, array $gateway): string
    {
        $u = $db->get('users', ['stripe_customer_code', 'email', 'first_name', 'last_name'], ['user_id' => $userId]);
        if ($u && !empty($u['stripe_customer_code'])) { return (string) $u['stripe_customer_code']; }
        $secret = cards_secret_key($gateway);
        if ($secret === '') { return ''; }
        $r = cards_http('POST', 'https://api.stripe.com/v1/customers', $secret, [
            'email' => (string) ($u['email'] ?? ''),
            'name'  => trim(((string) ($u['first_name'] ?? '')) . ' ' . ((string) ($u['last_name'] ?? ''))),
            'metadata[user_id]' => $userId,
        ], 'stripe');
        $cus = (string) ($r['json']['id'] ?? '');
        if ($cus !== '') {
            try { $db->update('users', ['stripe_customer_code' => $cus], ['user_id' => $userId]); }
            catch (\Throwable $e) { error_log('cards_stripe_customer store: ' . $e->getMessage()); }
        }
        return $cus;
    }
}

if (!function_exists('cards_stripe_setup_intent')) {
    /**
     * Create a Stripe SetupIntent for off-session future use. Returns the
     * client_secret (for Stripe.js to confirm in the browser) + publishable key +
     * customer. The card is never seen by us — the browser confirms this intent.
     * @return array{ok:bool,client_secret?:string,publishable_key?:string,customer?:string,message?:string}
     */
    function cards_stripe_setup_intent($db, string $userId): array
    {
        $gw = cards_gateway_for_currency($db, 'USD');
        if (!$gw) { return ['ok' => false, 'message' => 'Stripe is not configured']; }
        $secret = cards_secret_key($gw);
        if ($secret === '') { return ['ok' => false, 'message' => 'Stripe key missing']; }
        $cus = cards_stripe_customer($db, $userId, $gw);
        if ($cus === '') { return ['ok' => false, 'message' => 'Could not create Stripe customer']; }
        $r = cards_http('POST', 'https://api.stripe.com/v1/setup_intents', $secret, [
            'customer' => $cus,
            'usage'    => 'off_session',
            'payment_method_types[]' => 'card',
        ], 'stripe');
        $cs = (string) ($r['json']['client_secret'] ?? '');
        if ($cs === '') {
            error_log('cards_stripe_setup_intent: http=' . $r['http'] . ' ' . substr((string) ($r['json']['error']['message'] ?? $r['raw']), 0, 200));
            return ['ok' => false, 'message' => 'Could not start card setup'];
        }
        return [
            'ok' => true,
            'client_secret'    => $cs,
            'publishable_key'  => cards_publishable_key($gw),
            'customer'         => $cus,
            'gateway_id'       => (int) $gw['id'],
            'setup_intent_id'  => (string) ($r['json']['id'] ?? ''),
        ];
    }
}

if (!function_exists('cards_stripe_save_from_setup_intent')) {
    /**
     * After the browser confirms the SetupIntent, retrieve it server-side to get
     * the real payment_method, then fetch that PaymentMethod's display fields and
     * vault it. We DO NOT trust a client-sent pm_ id — we resolve it from the
     * SetupIntent id the server minted, so a customer can only save a card they
     * actually confirmed. Idempotent via cards_save (unique per token).
     * @return array{ok:bool,id?:int,message?:string}
     */
    function cards_stripe_save_from_setup_intent($db, string $userId, string $setupIntentId): array
    {
        $setupIntentId = trim($setupIntentId);
        if ($setupIntentId === '' || strpos($setupIntentId, 'seti_') !== 0) {
            return ['ok' => false, 'message' => 'Invalid setup reference'];
        }
        $gw = cards_gateway_for_currency($db, 'USD');
        $secret = $gw ? cards_secret_key($gw) : '';
        if ($secret === '') { return ['ok' => false, 'message' => 'Stripe not configured']; }

        // 1) Retrieve the SetupIntent — server-side truth about what was confirmed.
        $si = cards_http('GET', 'https://api.stripe.com/v1/setup_intents/' . rawurlencode($setupIntentId), $secret, [], 'stripe');
        $status = (string) ($si['json']['status'] ?? '');
        $pm     = (string) ($si['json']['payment_method'] ?? '');
        $cus    = (string) ($si['json']['customer'] ?? '');
        if ($status !== 'succeeded' || $pm === '') {
            return ['ok' => false, 'message' => 'Card was not confirmed (status: ' . ($status ?: 'unknown') . ')'];
        }
        // Ownership: the SetupIntent's customer must be THIS user's customer.
        $userCus = (string) ($db->get('users', 'stripe_customer_code', ['user_id' => $userId]) ?: '');
        if ($userCus === '' || $userCus !== $cus) {
            error_log('cards_stripe_save: setup intent customer mismatch for user ' . $userId);
            return ['ok' => false, 'message' => 'Card setup does not belong to this account'];
        }
        // 2) Fetch the PaymentMethod display fields (brand/last4/exp) — non-secret.
        $pmR = cards_http('GET', 'https://api.stripe.com/v1/payment_methods/' . rawurlencode($pm), $secret, [], 'stripe');
        $card = $pmR['json']['card'] ?? [];
        return cards_save($db, $userId, [
            'provider'          => 'stripe',
            'gateway_id'        => (int) $gw['id'],
            'currency'          => 'USD',
            'provider_customer' => $cus,
            'token'             => $pm,
            'brand'             => (string) ($card['brand'] ?? ''),
            'last4'             => (string) ($card['last4'] ?? ''),
            'exp_month'         => (int) ($card['exp_month'] ?? 0) ?: null,
            'exp_year'          => (int) ($card['exp_year'] ?? 0) ?: null,
        ]);
    }
}

if (!function_exists('cards_charge')) {
    /**
     * Off-session charge of a saved card, settling the proceeds into the wallet
     * through the EXISTING spine (txn_create -> provider charge -> wallet_topup_success).
     * Idempotent per (card, amount) via CARD-{provider_ref} — a retried charge never
     * double-credits. Owner-checked. For Stripe, an SCA step-up returns
     * requires_action + a client_secret for the browser to complete, then re-settle.
     *
     * @return array{ok:bool,balance?:float,requires_action?:bool,client_secret?:string,message?:string}
     */
    function cards_charge($db, int $cardId, string $userId, float $amount): array
    {
        $amount = round($amount, 2);
        $card = cards_get_owned($db, $cardId, $userId);
        if (!$card) { return ['ok' => false, 'message' => 'Card not found']; }
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }
        $currency = strtoupper((string) $card['currency']);
        $provider = (string) $card['provider'];

        // Resolve the gateway (with per-service key overrides) for this card.
        $gw = $db->get('payment_gateways', '*', ['id' => (int) ($card['gateway_id'] ?? 0)])
            ?: cards_gateway_for_currency($db, $currency);
        $secret = $gw ? cards_secret_key($gw) : '';
        if ($secret === '') { return ['ok' => false, 'message' => 'Payment gateway not configured']; }

        // 1) Create the pending top-up transaction on the spine (idempotency added
        //    once we have a provider reference — but we need a txn first to charge
        //    against, so we use a deterministic pre-key on the card+amount+minute
        //    to guard rapid double-clicks, then reconcile on the provider ref).
        $wallet_lib = __DIR__ . '/wallet.php';
        if (!function_exists('txn_create')) { require_once $wallet_lib; }

        if ($provider === 'stripe') {
            $minor = (int) round($amount * 100); // Stripe wants minor units
            $r = cards_http('POST', 'https://api.stripe.com/v1/payment_intents', $secret, [
                'amount'         => $minor,
                'currency'       => strtolower($currency),
                'customer'       => (string) ($card['provider_customer'] ?? ''),
                'payment_method' => (string) $card['token'],
                'off_session'    => 'true',
                'confirm'        => 'true',
                'metadata[user_id]' => $userId,
                'metadata[reason]'  => 'wallet_topup',
            ], 'stripe');
            $j = $r['json'] ?? [];
            $status = (string) ($j['status'] ?? '');
            $piId   = (string) ($j['id'] ?? '');
            if ($status === 'requires_action' || $status === 'requires_confirmation') {
                // SCA needed — hand the browser a client_secret to complete 3-DS.
                return [
                    'ok' => false, 'requires_action' => true,
                    'client_secret' => (string) ($j['client_secret'] ?? ''),
                    'payment_intent' => $piId,
                    'message' => 'This card needs extra verification.',
                ];
            }
            if ($status !== 'succeeded') {
                $msg = (string) ($j['error']['message'] ?? $j['last_payment_error']['message'] ?? 'Card was declined');
                error_log('cards_charge stripe: http=' . $r['http'] . ' status=' . $status . ' ' . substr($msg, 0, 160));
                return ['ok' => false, 'message' => $msg];
            }
            return cards_settle_topup($db, $userId, $amount, $currency, (int) ($gw['id'] ?? 0), 'CARD-STRIPE-' . $piId, $piId);
        }

        if ($provider === 'paystack') {
            $email = (string) ($db->get('users', 'email', ['user_id' => $userId]) ?: '');
            $minor = (int) round($amount * 100); // kobo
            $r = cards_http('POST', 'https://api.paystack.co/transaction/charge_authorization', $secret, [
                'authorization_code' => (string) $card['token'],
                'email'    => $email,
                'amount'   => $minor,
                'currency' => $currency,
            ], 'paystack');
            $j = $r['json'] ?? [];
            $ok  = !empty($j['status']) && strtolower((string) ($j['data']['status'] ?? '')) === 'success';
            $ref = (string) ($j['data']['reference'] ?? '');
            if (!$ok || $ref === '') {
                $msg = (string) ($j['data']['gateway_response'] ?? $j['message'] ?? 'Card was declined');
                error_log('cards_charge paystack: http=' . $r['http'] . ' ' . substr($msg, 0, 160));
                return ['ok' => false, 'message' => $msg];
            }
            return cards_settle_topup($db, $userId, $amount, $currency, (int) ($gw['id'] ?? 0), 'CARD-PSK-' . $ref, $ref);
        }

        return ['ok' => false, 'message' => 'Unsupported card provider'];
    }
}

if (!function_exists('cards_settle_topup')) {
    /**
     * Turn a confirmed provider charge into a wallet credit through the spine.
     * Idempotent on $idem: a repeat with the same provider reference returns the
     * existing balance without a second credit.
     */
    function cards_settle_topup($db, string $userId, float $amount, string $currency, int $gatewayId, string $idem, string $providerRef): array
    {
        try {
            $existing = $db->get('money_transactions', ['id', 'status'], ['idempotency_key' => $idem]);
            if ($existing && $existing['status'] === 'success') {
                return ['ok' => true, 'balance' => wallet_balance($db, $userId, $currency), 'already' => true];
            }
            $txn = ($existing && !empty($existing['id']))
                ? $db->get('money_transactions', '*', ['id' => (int) $existing['id']])
                : txn_create($db, [
                    'user_id' => $userId, 'direction' => 'credit', 'reason' => 'wallet_topup',
                    'amount' => $amount, 'currency' => $currency, 'method' => 'gateway',
                    'gateway_id' => $gatewayId ?: null, 'idempotency_key' => $idem,
                    'description' => 'Saved-card top-up ' . $providerRef,
                ]);
            if (!$txn || empty($txn['id'])) { return ['ok' => false, 'message' => 'Could not record top-up']; }
            $res = wallet_topup_success($db, (int) $txn['id'], $providerRef);
            if (empty($res['ok'])) { return ['ok' => false, 'message' => $res['message'] ?? 'Top-up failed to credit']; }
            return ['ok' => true, 'balance' => (float) ($res['balance'] ?? wallet_balance($db, $userId, $currency))];
        } catch (\Throwable $e) {
            error_log('cards_settle_topup: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Top-up settlement error'];
        }
    }
}

if (!function_exists('cards_http')) {
    /**
     * Minimal HTTPS client for provider APIs. Stripe uses form-encoded + Bearer;
     * Paystack uses JSON + Bearer. Returns ['http'=>int,'json'=>array|null,'raw'=>string].
     */
    function cards_http(string $method, string $url, string $secret, array $body, string $style): array
    {
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $secret];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (!empty($body)) {
            if ($style === 'stripe') {
                // Stripe wants form-encoded with bracketed nesting.
                $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
                $headers[] = 'Content-Type: application/json';
            }
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { return ['http' => 0, 'json' => null, 'raw' => '', 'error' => $err]; }
        return ['http' => $code, 'json' => json_decode((string) $raw, true), 'raw' => (string) $raw];
    }
}
