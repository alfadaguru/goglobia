<?php
// BLOCK DIRECT FILE ACCESS — MUST BE INCLUDED THROUGH THE APP BOOTSTRAP
defined('root') or die();

// ── ENSURE license_secret COLUMN EXISTS — ADD IT ONCE IF MISSING ──────────────
function _lic_col($db)
{
    $r = $db->query("SHOW COLUMNS FROM `settings` LIKE 'license_secret'");
    if ($r && !$r->fetch()) $db->query("ALTER TABLE `settings` ADD COLUMN `license_secret` TEXT NULL");
}

// ── FETCH FULL SETTINGS ROW VIA MEDOO ────────────────────────────────────────
function _lic_cfg($db)
{
    return $db->get('settings', '*');
}

// ── WRITE NEW ENCRYPTED SECRET + REFRESH license_date IN SETTINGS TABLE ──────
function _lic_save($db, $id, $val)
{
    $db->update('settings', ['license_secret' => $val, 'license_date' => date('Y-m-d H:i:s')], ['id' => $id]);
}

// ── NORMALIZE URL OR HOSTNAME TO BARE LOWERCASE HOSTNAME ─────────────────────
// STRIPS: scheme, www, port number, path, query, whitespace, trailing slash
function _lic_dom($v)
{
    if (!$v) return '';
    if (!preg_match('~^https?://~i', $v = trim($v))) $v = 'http://' . $v;
    $h = parse_url($v, PHP_URL_HOST) ?: '';
    return strtolower(trim(preg_replace('~^www\.~i', '', explode(':', $h)[0]), '/. '));
}

// ── DETECT CURRENT SERVER DOMAIN — ALWAYS RETURNS SOMETHING ─────────────────
// PRIORITY: HTTP_HOST → SERVER_NAME → settings url columns → php hostname → 'localhost'
function _lic_host($s)
{
    foreach (['HTTP_HOST', 'SERVER_NAME'] as $k)
        if (!empty($_SERVER[$k])) return _lic_dom($_SERVER[$k]);
    foreach (['site_url', 'siteurl', 'website'] as $k)
        if (!empty($s[$k])) return _lic_dom($s[$k]);
    // ABSOLUTE FALLBACK — USE PHP SYSTEM HOSTNAME, THEN 'localhost'
    $fallback = php_uname('n') ?: gethostname() ?: 'localhost';
    return _lic_dom($fallback) ?: 'localhost';
}

// ── EXACT NORMALIZED DOMAIN COMPARISON — SUBDOMAINS AND PARENTS DO NOT MATCH ─
function _lic_domok($a, $b)
{
    $a = _lic_dom($a);
    $b = _lic_dom($b);
    return $a !== '' && $b !== '' && $a === $b;
}

// ── CALL REMOTE LICENSE API — MULTI-STRATEGY, CAPTURES REAL ERROR FOR DIAGNOSIS ─
// STRATEGY 1: HTTPS + SSL VERIFY
// STRATEGY 2: HTTPS + NO SSL VERIFY (MISSING CA BUNDLE ON WINDOWS)
// STRATEGY 3: HTTP FALLBACK (PROXY / FIREWALL BLOCKING HTTPS)
// STRATEGY 4: file_get_contents FALLBACK (WHEN CURL IS DISABLED)
// RETURNS ARRAY: ['data'=>array|null, 'err'=>string]
function _lic_api($key, $domain = '')
{
    $host  = 'app.phptravels.com';
    $path  = '/api/license/' . urlencode($key);
    // ALWAYS SEND DOMAIN — FALLBACK TO localhost SO SERVER NEVER RECEIVES EMPTY VALUE
    $domain = ($domain !== '') ? $domain : (php_uname('n') ?: 'localhost');
    $path .= '?domain=' . urlencode($domain);
    $errors = [];

    $base = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: PT-v10'],
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ];

    $try = function ($label, $url, $ssl) use ($base, &$errors) {
        $c = curl_init($url);
        curl_setopt_array($c, $base + [
            CURLOPT_SSL_VERIFYPEER => $ssl,
            CURLOPT_SSL_VERIFYHOST => $ssl ? 2 : 0,
        ]);
        $r  = curl_exec($c);
        $h  = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        $en = curl_errno($c);
        $em = curl_error($c);
        curl_close($c);
        if ($en || $r === false) {
            $errors[] = "{$label}: cURL #{$en} — {$em}";
            return null;
        }
        if ($h !== 200) {
            $errors[] = "{$label}: HTTP {$h}";
            return null;
        }
        return $r;
    };

    $raw = null;

    if (function_exists('curl_init')) {
        $raw = $try('HTTPS+SSL',  'https://' . $host . $path, true);
        if (!$raw) $raw = $try('HTTPS',      'https://' . $host . $path, false);
        if (!$raw) $raw = $try('HTTP',       'http://'  . $host . $path, false);
    } else {
        $errors[] = 'cURL extension is not loaded';
    }

    if (!$raw && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 15, 'header' => "Accept: application/json\r\nUser-Agent: PT-v10\r\n", 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $raw = @file_get_contents('https://' . $host . $path, false, $ctx) ?: null;
        if (!$raw) $errors[] = 'file_get_contents: failed';
    }

    if (!$raw) return ['data' => null, 'err' => implode(' | ', $errors) ?: 'No transport available'];

    $d = json_decode($raw, true);
    if (!is_array($d)) return ['data' => null, 'err' => 'Invalid JSON: ' . substr($raw, 0, 200)];

    // API RETURNED AN ERROR RESPONSE — KEY NOT FOUND OR BAD FORMAT (NO ORDER FIELDS AT ALL)
    if (!isset($d['order_id'])) {
        $apiMsg = $d['error'] ?? $d['message'] ?? $d['msg'] ?? 'License not found';
        return ['data' => null, 'err' => $apiMsg];
    }

    // RETURN FULL DATA INCLUDING WHEN valid:false — LET _lic_check DO FIELD-LEVEL VALIDATION
    return ['data' => $d, 'err' => ''];
}

// ── DERIVE STABLE SERVER-BOUND SYMMETRIC KEY — NEVER STORED OR SENT ──────────
// BASED ON THE FILE SYSTEM PATH AND SERVER NAME — UNIQUE PER INSTALLATION
function _lic_key()
{
    return hash('sha256', __DIR__ . '|||' . ($_SERVER['SERVER_NAME'] ?? php_uname('n')));
}

// ── ENCRYPT PAYLOAD — AES-256-CBC WITH ENCRYPT-THEN-MAC ──────────────────────
// GRACEFULLY FALLS BACK TO HMAC-SIGNED BASE64 JSON IF OPENSSL IS ABSENT
function _lic_enc($p)
{
    $k = _lic_key();
    $j = json_encode($p);
    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(16);
        $ct = openssl_encrypt($j, 'AES-256-CBC', substr(hash('sha256', $k, true), 0, 32), OPENSSL_RAW_DATA, $iv);
        if ($ct !== false) return base64_encode(json_encode([
            'f' => 'a',
            'iv' => base64_encode($iv),
            'd' => base64_encode($ct),
            'm' => hash_hmac('sha256', $iv . $ct, $k),
        ]));
    }
    return base64_encode(json_encode(['f' => 'h', 'd' => base64_encode($j), 'm' => hash_hmac('sha256', $j, $k)]));
}

// ── DECRYPT STORED SECRET — MAC VERIFIED BEFORE DECRYPT, NULL ON ANY FAILURE ─
function _lic_dec($s)
{
    if (!$s) return null;
    $e = json_decode(base64_decode($s, true) ?: '', true);
    if (!is_array($e) || !isset($e['f'], $e['d'], $e['m'])) return null;
    $k = _lic_key();
    if ($e['f'] === 'a' && function_exists('openssl_decrypt') && isset($e['iv'])) {
        $iv = base64_decode($e['iv'], true);
        $ct = base64_decode($e['d'], true);
        if (!$iv || !$ct || strlen($iv) !== 16) return null;
        if (!hash_equals(hash_hmac('sha256', $iv . $ct, $k), $e['m'])) return null;
        $j = openssl_decrypt($ct, 'AES-256-CBC', substr(hash('sha256', $k, true), 0, 32), OPENSSL_RAW_DATA, $iv);
        return $j ? json_decode($j, true) : null;
    }
    if ($e['f'] === 'h') {
        $j = base64_decode($e['d'], true);
        if (!$j || !hash_equals(hash_hmac('sha256', $j, $k), $e['m'])) return null;
        return json_decode($j, true);
    }
    return null;
}

// ── COMPUTE HMAC SIGNATURE OVER SORTED PAYLOAD KEYS (sig KEY EXCLUDED) ───────
function _lic_sig($p)
{
    $c = $p;
    unset($c['sig']);
    ksort($c);
    return hash_hmac('sha256', json_encode($c), _lic_key());
}

// ── VERIFY PAYLOAD SIGNATURE USING CONSTANT-TIME COMPARISON ──────────────────
function _lic_sigok($p)
{
    return isset($p['sig']) && hash_equals(_lic_sig($p), (string)$p['sig']);
}

// ── CHECK CACHE IS WITHIN 7-DAY VALIDITY WINDOW ──────────────────────────────
function _lic_fresh($p)
{
    return is_array($p) && !empty($p['exp']) && time() < (int)$p['exp'];
}

// ── MASTER CHECK — RETURNS ['ok'=>bool,'msg'=>string,'key'=>string] ───────────
// CACHE HIT (7 DAYS VALID): NO API CALL — INSTANT RETURN
// CACHE MISS / EXPIRED / CORRUPTED: CALLS API, VALIDATES, WRITES NEW CACHE
function _lic_check($db)
{
    _lic_col($db);
    $s = _lic_cfg($db);
    if (!$s) return ['ok' => false, 'msg' => 'License check failed.', 'key' => ''];

    $key  = trim((string)($s['license_key']    ?? ''));
    $raw  = (string)      ($s['license_secret'] ?? '');
    $id   = $s['id'] ?? null;
    $host = _lic_host($s);

    // LOCAL / DEV ENVIRONMENT — SKIP LICENSE CHECK ENTIRELY
    // NOTE: Do NOT use SERVER_ADDR — it is 127.0.0.1 on CloudPanel/cPanel servers behind a local proxy
    $httpHost = strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    $localHosts = ['localhost', '127.0.0.1', '::1', '[::1]'];
    if (in_array(strtok($httpHost, ':'), $localHosts, true)
        || str_ends_with($httpHost, '.local')
        || str_ends_with($httpHost, '.localhost')
        || str_ends_with($httpHost, '.test')
    ) {
        return ['ok' => true, 'msg' => 'License check skipped (local environment).', 'key' => $key];
    }

    // NO LICENSE KEY SAVED IN SETTINGS TABLE
    if ($key === '') return ['ok' => false, 'msg' => 'License key is missing. Enter your license key below.', 'key' => ''];

    // PLACEHOLDER KEY — NEVER BEEN SET BY THE OWNER
    if (in_array(strtolower($key), ['license', 'your-license-key', 'enter-license', 'demo', 'test', 'null', 'none'], true)
        || !preg_match('/^ORD-/i', $key)) {
        return ['ok' => false, 'msg' => 'Your license key has not been set yet. Please enter your real license key (format: ORD-XXXXXXXX-XXXXX). You can find it in your PHPTRAVELS client area.', 'key' => $key];
    }

    // ATTEMPT CACHE VALIDATION — SKIP API ENTIRELY IF EVERYTHING CHECKS OUT
    $corrupt = false;
    if ($raw !== '') {
        $p = _lic_dec($raw);
        if (is_array($p)) {
            if (
                _lic_sigok($p) && _lic_fresh($p)
                && isset($p['k']) && hash_equals($key, (string)$p['k'])
                && isset($p['h']) && _lic_domok((string)$p['h'], $host)
            )
                return ['ok' => true, 'msg' => 'License active.', 'key' => $key];  // CACHE HIT
            if (!_lic_sigok($p)) $corrupt = true;  // SIGNATURE MISMATCH — TAMPERED
        } else {
            $corrupt = true;
        }  // DECRYPTION FAILED
    }

    // HIT REMOTE API — ONLY HAPPENS ONCE EVERY 7 DAYS NORMALLY
    $api = _lic_api($key, $host);
    $r   = $api['data'];
    if (!$r) return [
        'ok'  => false,
        'msg' => $corrupt ? 'License cache corrupted. Please re-enter your license key.' : $api['err'],
        'key' => $key
    ];

    // CHECK 1: ORDER ID EXISTS — API RECOGNISED THE KEY
    if (empty($r['order_id'])) return ['ok' => false, 'msg' => 'License key not found. Please check your order ID.', 'key' => $key];

    // CHECK 2: DOMAIN — MUST MATCH CURRENT HOST EXACTLY
    $ad = _lic_dom((string)($r['domain'] ?? ''));
    $cd = _lic_dom($host);
    if ($ad === '') return ['ok' => false, 'msg' => "no_domain:{$cd}", 'key' => $key];
    if (!_lic_domok($ad, $cd)) return ['ok' => false, 'msg' => "domain_mismatch:{$ad}:{$cd}", 'key' => $key];

    // CHECK 3: ORDER STATUS MUST BE ACTIVE
    if (($r['order_status'] ?? '') !== 'active') {
        $st = ucfirst((string)($r['order_status'] ?? 'unknown'));
        return ['ok' => false, 'msg' => "License order is {$st}. Submit a ticket at app.phptravels.com to request activation.", 'key' => $key];
    }

    // CHECK 4: PAYMENT MUST BE PAID
    if (($r['payment_status'] ?? '') !== 'paid') {
        $ps = ucfirst((string)($r['payment_status'] ?? 'unknown'));
        return ['ok' => false, 'msg' => "Payment is {$ps}. Submit a ticket at app.phptravels.com to resolve payment.", 'key' => $key];
    }

    // ALL 4 CHECKS PASSED — ENCRYPT AND PERSIST CACHE, EXPIRES IN 7 DAYS
    $now = time();
    $pl  = ['k' => $key, 'h' => $cd, 'v' => 1, 'at' => $now, 'exp' => $now + (7 * 86400)];
    $pl['sig'] = _lic_sig($pl);
    if ($id) _lic_save($db, $id, _lic_enc($pl));

    return ['ok' => true, 'msg' => 'License active.', 'key' => $key];
}

// ── RENDER LICENSE BLOCK — ERROR ALERT + UPDATE FORM + SUCCESS FLASH ─────────
// HANDLES BOTH lic_err (error state) AND lic_success (post-save flash)
// RETURNS EMPTY STRING WHEN LICENSE IS VALID AND NO SESSION FLAGS ARE SET
function _lic_block()
{
    $base = defined('root') ? root : '/';
    $csrf = class_exists('CSRF') ? CSRF::tokenField() : '';
    $out  = '';

    // SUCCESS FLASH — SHOWN AFTER FORM SUBMIT, AUTO-FADES WHILE VALIDATION RUNS
    if (!empty($_SESSION['success']) && str_contains($_SESSION['success'], 'Validating')) {
        $sMsg = htmlspecialchars((string)$_SESSION['success']);
        unset($_SESSION['success']);
        ob_start(); ?>
        <div id="lic-success" style="display:flex;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:14px 16px;margin-bottom:16px;opacity:1;transition:opacity .6s ease;">
            <span class="material-symbols-outlined" style="color:#16a34a;font-size:20px;flex-shrink:0;">check_circle</span>
            <p style="margin:0;font-size:13px;color:#166534;"><?= $sMsg ?></p>
        </div>
        <script>
        // AUTO-FADE SUCCESS BANNER AFTER 2s — PAGE MAY RELOAD WITH LICENSE RESULT
        (function(){
            var el = document.getElementById('lic-success');
            if (!el) return;
            setTimeout(function(){ el.style.opacity = '0'; }, 2000);
            setTimeout(function(){ el.style.display = 'none'; }, 2700);
        })();
        </script>
        <?php $out .= ob_get_clean();
    }

    // ERROR BLOCK — ONLY WHEN LICENSE CHECK FAILED
    if (empty($_SESSION['lic_err'])) return $out;

    $rawMsg = (string)$_SESSION['lic_err'];
    $key    = htmlspecialchars((string)($_SESSION['lic_key'] ?? ''));
    unset($_SESSION['lic_err'], $_SESSION['lic_key']);

    // DECODE STRUCTURED DOMAIN ERROR CODES INTO RICH HTML GUIDANCE
    $extraHtml = '';
    if (str_starts_with($rawMsg, 'no_domain:')) {
        $cd       = htmlspecialchars(explode(':', $rawMsg, 2)[1]);
        $msg      = 'No domain is registered on this license.';
        $extraHtml = '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:12px;color:#7c2d12;line-height:1.6;">
            Set domain to <code style="background:#fee2e2;padding:1px 5px;border-radius:3px;">' . $cd . '</code> on your order at
            <a href="https://app.phptravels.com" target="_blank" rel="noopener" style="color:#b45309;font-weight:600;">app.phptravels.com</a>
            &rarr; My Orders &rarr; edit order <strong>' . htmlspecialchars($key) . '</strong>.
        </div>';
    } elseif (str_starts_with($rawMsg, 'domain_mismatch:')) {
        [, $ad, $cd] = explode(':', $rawMsg, 3);
        $ad  = htmlspecialchars($ad); $cd = htmlspecialchars($cd);
        $msg = "Domain mismatch. Registered: {$ad}, Current: {$cd}";
        $extraHtml = '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:12px;color:#7c2d12;line-height:1.6;">
            Update domain from <code style="background:#fee2e2;padding:1px 5px;border-radius:3px;">' . $ad . '</code> to
            <code style="background:#fee2e2;padding:1px 5px;border-radius:3px;">' . $cd . '</code> at
            <a href="https://app.phptravels.com" target="_blank" rel="noopener" style="color:#b45309;font-weight:600;">app.phptravels.com</a>
            &rarr; My Orders &rarr; edit order <strong>' . htmlspecialchars($key) . '</strong>.
        </div>';
    } else {
        $msg = htmlspecialchars($rawMsg);
    }

    // CONVERT app.phptravels.com PLAIN TEXT TO CLICKABLE LINK IN ALL MESSAGES
    $msg = str_replace(
        'app.phptravels.com',
        '<a href="https://app.phptravels.com" target="_blank" rel="noopener" style="color:#991b1b;font-weight:600;text-decoration:underline;">app.phptravels.com</a>',
        $msg
    );

    ob_start(); ?>
    <div style="border-radius:10px;overflow:hidden;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.08);">

        <?php /* ── ERROR HEADER ROW ──────────────────────────────────────────── */ ?>
        <div style="background:#dc2626;padding:12px 16px;display:flex;align-items:center;gap:8px;">
            <span class="material-symbols-outlined" style="color:#fff;font-size:20px;flex-shrink:0;line-height:1;">gpp_bad</span>
            <strong style="color:#fff;font-size:14px;letter-spacing:.01em;">License Validation Failed</strong>
        </div>

        <?php /* ── ERROR BODY ───────────────────────────────────────────────── */ ?>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-top:none;padding:14px 16px;">

            <?php /* ERROR MESSAGE */ ?>
            <p style="color:#7f1d1d;font-size:13px;margin:0 0 10px;font-weight:500;"><?= $msg ?></p>

            <?php /* ── DOMAIN GUIDANCE (only shown for domain errors) ─────── */ ?>
            <?= $extraHtml ?>

            <?php /* ── DOCS LINK ────────────────────────────────────────────── */ ?>
            <div style="border-top:1px solid #fecaca;padding-top:10px;margin-bottom:14px;">
                <a href="https://docs.phptravels.com/startup/license" target="_blank" rel="noopener"
                    style="font-size:12px;color:#b91c1c;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                    <span class="material-symbols-outlined" style="font-size:14px;line-height:1;">help_outline</span>
                    How to activate your license? <strong style="text-decoration:underline;margin-left:2px;">Step-by-step guide</strong> &rarr;
                </a>
            </div>

            <?php /* ── UPDATE FORM ──────────────────────────────────────────── */ ?>
            <form id="lic-form" action="<?= $base ?>license-update" method="POST" autocomplete="off">
                <?= $csrf ?>
                <p style="font-size:10px;font-weight:700;color:#991b1b;margin:0 0 7px;letter-spacing:.06em;text-transform:uppercase;">Update License Key</p>
                <div style="display:flex;gap:8px;align-items:stretch;">
                    <input type="text" name="lic_key" id="lic-key-input" value="<?= $key ?>"
                        placeholder="Enter your license key (ORD-XXXXXXXX-XXXXX)" required
                        style="flex:1;border:1px solid #fca5a5;border-radius:6px;padding:9px 12px;font-size:13px;box-sizing:border-box;background:#fff;color:#111;outline:none;min-width:0;">
                    <button type="submit" id="lic-btn"
                        style="background:#dc2626;color:#fff;border:none;border-radius:6px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:6px;transition:opacity .2s;flex-shrink:0;">
                        <span id="lic-btn-text">Save &amp; Retry</span>
                        <svg id="lic-spinner" style="display:none;width:14px;height:14px;animation:lic-spin .7s linear infinite;" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="rgba(255,255,255,.35)" stroke-width="3"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </form>

        </div>
    </div>

    <style>@keyframes lic-spin{to{transform:rotate(360deg)}}</style>
    <script>
    document.getElementById('lic-form').addEventListener('submit', function() {
        var btn  = document.getElementById('lic-btn');
        var txt  = document.getElementById('lic-btn-text');
        var spin = document.getElementById('lic-spinner');
        txt.textContent = 'Validating\u2026';
        spin.style.display = 'block';
        btn.disabled = true;
        btn.style.opacity = '.75';
        btn.style.cursor  = 'not-allowed';
    });
    </script>
    <?php $out .= ob_get_clean();
    return $out;
}
