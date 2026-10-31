<?php
// ============================================================================
// TRAVELSURE — PAID TRAVEL-INSURANCE SUPPLIER (SCAFFOLDING / STUBBED)
// ============================================================================
//
// WHAT THIS IS: a paid travel-insurance supplier module, structured exactly like
// the live eSIM supplier (modules/esim/airalo/) so that when a REAL provider +
// API docs + credentials are supplied, the remote calls drop straight in behind
// this one interface. Until then every remote call is a SAFE NO-OP — NO fake
// credentials or invented endpoints are ever sent (that would be a lie, not a
// stub). "travelsure" is a neutral placeholder supplier name; rename to the real
// provider when wiring it.
//
// SHAPE MIRRORS AIRALO:
//   api.php     — config (_travelsure_cfg), env, the stubbed HTTP seam
//                 (_travelsure_request) + the price oracle
//                 (travelsure_authoritative_price), mirroring
//                 airalo_authoritative_price (price-trust).
//   creds.php   — POST modules/insurance/travelsure/creds (connection tester)
//   search.php  — POST modules/insurance/travelsure/search (quote plans)
//   issue.php   — POST modules/insurance/travelsure/issue  (issue the policy)
//   refund.php  — POST modules/insurance/travelsure/refund
//   cancel.php  — POST modules/insurance/travelsure/cancel
//   index.php   — requires them all (loaded by the MODULES API GATEWAY)
//
// CREDENTIAL MAP (modules table row: name='travelsure', type='insurance'):
//   c1 = API key / client id      c2 = API secret
//   c3 = (provider-specific, e.g. agency/product code)
//   dev_mode: '1' = sandbox, '0' = production
//
// STATUS: SCAFFOLDING. travelsure_is_configured() returns false until real creds
// are set AND a provider is wired, so search/issue degrade to a clear
// 'pending_supplier' response instead of pretending to transact.
// ============================================================================

if (!function_exists('_travelsure_root')) {
    function _travelsure_root()
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('_travelsure_cfg')) {
    /** The modules row for this supplier (name='travelsure', type='insurance'). */
    function _travelsure_cfg($db)
    {
        try {
            $m = $db->get('modules', '*', ['name' => 'travelsure', 'type' => 'insurance']);
            if (!$m) { $m = $db->get('modules', '*', ['type' => 'insurance', 'name[!]' => 'airhelp']); }
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($m)) { return []; }
        if (empty($m['env'])) {
            $m['env'] = (!empty($m['dev_mode']) && (string) $m['dev_mode'] === '1') ? 'sandbox' : 'production';
        }
        return $m;
    }
}

if (!function_exists('_travelsure_env')) {
    function _travelsure_env($env)
    {
        $env = strtolower(trim((string) $env));
        return in_array($env, ['production', 'prod', 'live'], true) ? 'production' : 'sandbox';
    }
}

if (!function_exists('travelsure_is_configured')) {
    /**
     * True only when a REAL, non-placeholder credential set exists AND the module
     * has been explicitly marked supplier-ready (c3 carries the provider's
     * product/agency code, which only exists once a real provider is wired).
     * Guards every remote call so placeholders are never sent and the stub never
     * pretends to transact.
     */
    function travelsure_is_configured($cfg): bool
    {
        $key    = trim((string) ($cfg['c1'] ?? ''));
        $secret = trim((string) ($cfg['c2'] ?? ''));
        $product= trim((string) ($cfg['c3'] ?? ''));
        if ($key === '' || $secret === '' || $product === '') {
            return false;
        }
        foreach (['REPLACE_WITH', 'YOUR_', 'PLACEHOLDER', 'STUB', 'TEST_KEY'] as $bad) {
            if (stripos($key, $bad) !== false || stripos($secret, $bad) !== false) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('_travelsure_request')) {
    /**
     * The single supplier HTTP seam. STUBBED: until a real provider is wired this
     * returns ['ok'=>false,'pending'=>true,...] WITHOUT making any network call —
     * no invented endpoint, no fake credentials. When the real provider is known,
     * replace the body with the real cURL call (mirror modules/esim/airalo/api.php
     * _airalo_request: SSL on, bearer/key auth, 12s timeout, JSON decode) — every
     * caller (search/issue/refund/cancel) already routes through here, so the live
     * wiring is a single-function change.
     *
     * @return array ['ok'=>bool,'pending'=>bool,'status'=>int,'data'=>array|null,'error'=>?string]
     */
    function _travelsure_request($db, string $method, string $path, array $opts = []): array
    {
        $cfg = _travelsure_cfg($db);
        if (!travelsure_is_configured($cfg)) {
            return [
                'ok'      => false,
                'pending' => true,
                'status'  => 0,
                'data'    => null,
                'error'   => 'Insurance supplier is not yet configured (no live API). '
                           . 'Set real credentials on the travelsure module and wire _travelsure_request() to the provider API.',
            ];
        }

        // ── LIVE WIRING GOES HERE (drop-in, mirrors _airalo_request) ───────────
        // $base = _travelsure_env($cfg['env']) === 'production' ? '<prod base>' : '<sandbox base>';
        // $url  = rtrim($base,'/') . '/' . ltrim($path,'/');
        // $ch = curl_init($url); curl_setopt_array($ch, [ ...bearer/key auth, JSON,
        //   CONNECTTIMEOUT 6, TIMEOUT 12, SSL_VERIFYPEER true... ]); ... decode ...
        // return ['ok'=>$status>=200&&$status<300,'pending'=>false,'status'=>$status,'data'=>$decoded,'error'=>...];
        //
        // Reaching here means is_configured() passed but no live client is wired —
        // fail CLOSED (never a silent success) so a half-config can't mint a policy.
        error_log('travelsure: credentials present but _travelsure_request has no live client wired (' . $method . ' ' . $path . ')');
        return ['ok' => false, 'pending' => true, 'status' => 0, 'data' => null, 'error' => 'Insurance supplier client not wired.'];
    }
}

if (!function_exists('travelsure_authoritative_price')) {
    /**
     * PRICE-TRUST oracle, mirroring airalo_authoritative_price: re-derive the
     * authoritative sell price for an insurance plan SERVER-SIDE (never trust the
     * client). Returns ['price'=>float,'base_price'=>float,'currency'=>string] or
     * null when it can't be priced.
     *
     * STUB behaviour: with no live supplier, there are no live quotes, so this
     * returns null (callers then refuse the booking — nothing is priced from thin
     * air). When the provider is wired, fetch the live quote via _travelsure_request
     * and apply the DB commission/markup rule here (same as airalo: net + rule).
     */
    function travelsure_authoritative_price($db, array $module, string $planId, array $quoteParams = [], ?string $agentOwnerId = null): ?array
    {
        $cfg = _travelsure_cfg($db);
        if (!travelsure_is_configured($cfg)) {
            return null; // no live quotes available — caller must refuse, not guess a price
        }
        // LIVE: $q = _travelsure_request($db,'POST','/quote',['json'=>$quoteParams]);
        //       derive net from $q, apply MARKUP()/commission rule, return priced array.
        return null;
    }
}
