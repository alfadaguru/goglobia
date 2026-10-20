<?php

// app/routes/newsletterRoutes.php
// Newsletter — Phase 1: double-opt-in subscribe, confirm, unsubscribe.
// Public routes. Storage = newsletter_subscribers (ensureNewsletterSchema).
// Sending reuses the existing SENDEMAIL() provider path (Resend when selected).
@$SECURE or die('Access Denied!');

if (!function_exists('newsletter_token')) {
    function newsletter_token(): string
    {
        return bin2hex(random_bytes(16)); // 32 hex chars (column is 64)
    }
}

/**
 * Send the double-opt-in confirmation email via the active provider.
 * Best-effort: never throws into the request; returns bool.
 */
if (!function_exists('newsletter_send_confirmation')) {
    function newsletter_send_confirmation(string $email, string $name, string $confirmToken): bool
    {
        if (!function_exists('SENDEMAIL')) { return false; }
        $brand    = function_exists('getBrandName') ? getBrandName($GLOBALS['db'] ?? null) : ($GLOBALS['app']['business_name'] ?? 'Our newsletter');
        $brand    = htmlspecialchars((string) $brand, ENT_QUOTES);
        $name     = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES);
        $confirmUrl = rtrim((string) root, '/') . '/newsletter/confirm/' . rawurlencode($confirmToken);
        $subject  = 'Please confirm your subscription';
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#111">'
              . '<h2 style="margin:0 0 12px">Confirm your subscription</h2>'
              . '<p>Hi ' . $name . ',</p>'
              . '<p>Thanks for signing up to receive travel deals and updates from <strong>' . $brand . '</strong>. '
              . 'Please confirm your email address to start receiving them:</p>'
              . '<p style="margin:24px 0"><a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES) . '" '
              . 'style="background:#2563eb;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;display:inline-block">Confirm subscription</a></p>'
              . '<p style="font-size:12px;color:#6b7280">If the button doesn\'t work, paste this link into your browser:<br>'
              . htmlspecialchars($confirmUrl, ENT_QUOTES) . '</p>'
              . '<p style="font-size:12px;color:#6b7280">If you didn\'t request this, you can safely ignore this email — no subscription will be created.</p>'
              . '</div>';
        try {
            return (bool) SENDEMAIL($email, $name, $subject, $html);
        } catch (\Throwable $e) {
            error_log('newsletter_send_confirmation: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================================
// POST /newsletter/subscribe  — capture an email, start double-opt-in.
// JSON or form POST. CSRF-guarded. Never reveals whether an email already
// exists (anti-enumeration); always returns the same friendly "check your
// inbox" message on valid input.
// ============================================================================
$router->post('/newsletter/subscribe', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    // Accept JSON body or form fields.
    $raw   = file_get_contents('php://input');
    $json  = $raw !== '' ? json_decode($raw, true) : null;
    $input = is_array($json) ? $json : $_POST;

    $csrf = $input['csrf_token'] ?? ($_POST['csrf_token'] ?? '');
    if (!CSRF::validateToken((string) $csrf)) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh and try again.']);
        exit;
    }

    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $name  = trim((string) ($input['name'] ?? $input['first_name'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }

    if (function_exists('ensureNewsletterSchema')) { ensureNewsletterSchema($db); }

    $friendly = ['success' => true, 'message' => 'Almost there! Check your inbox to confirm your subscription.'];

    try {
        $existing = $db->get('newsletter_subscribers', '*', ['email' => $email]);

        // Already an active subscriber → say the same thing, do nothing new.
        if ($existing && ($existing['status'] ?? '') === 'subscribed') {
            echo json_encode(['success' => true, 'message' => 'You are already subscribed — thank you!']);
            exit;
        }

        $confirmToken = newsletter_token();
        $ip = substr((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $lang = strtolower(substr((string) ($_SESSION['app_language'] ?? 'en'), 0, 8));

        if ($existing) {
            // Re-subscribe / resend confirmation for pending/unsubscribed/bounced.
            $db->update('newsletter_subscribers', [
                'first_name'    => $name !== '' ? $name : $existing['first_name'],
                'status'        => 'pending',
                'confirm_token' => $confirmToken,
                'source'        => $existing['source'] ?: 'website',
                'ip'            => $ip,
                'language'      => $lang,
            ], ['email' => $email]);
        } else {
            $db->insert('newsletter_subscribers', [
                'email'             => $email,
                'first_name'        => $name !== '' ? $name : null,
                'status'            => 'pending',
                'source'            => 'website',
                'confirm_token'     => $confirmToken,
                'unsubscribe_token' => newsletter_token(),
                'ip'                => $ip,
                'language'          => $lang,
            ]);
        }

        newsletter_send_confirmation($email, $name, $confirmToken);
        echo json_encode($friendly);
    } catch (\Throwable $e) {
        error_log('newsletter/subscribe: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again shortly.']);
    }
    exit;
});

// ============================================================================
// GET /newsletter/confirm/{token} — complete double-opt-in.
// ============================================================================
$router->get('/newsletter/confirm/([a-f0-9]{32})', function ($token) use ($SECURE, $db) {
    if (function_exists('ensureNewsletterSchema')) { ensureNewsletterSchema($db); }

    $ok = false;
    try {
        $row = $db->get('newsletter_subscribers', '*', ['confirm_token' => $token]);
        if ($row) {
            $db->update('newsletter_subscribers', [
                'status'        => 'subscribed',
                'confirm_token' => null,
                'confirmed_at'  => date('Y-m-d H:i:s'),
            ], ['id' => $row['id']]);
            $ok = true;
        }
    } catch (\Throwable $e) {
        error_log('newsletter/confirm: ' . $e->getMessage());
    }

    $title       = $ok ? 'Subscription confirmed' : 'Link expired';
    $description = $ok ? 'You are now subscribed.' : 'This confirmation link is invalid or already used.';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    ?>
    <div class="container mx-auto px-4 py-20 text-center">
      <span class="material-symbols-outlined !text-[56px] <?= $ok ? 'text-green-600' : 'text-gray-400' ?>">
        <?= $ok ? 'mark_email_read' : 'link_off' ?>
      </span>
      <h1 class="mt-4 text-2xl font-bold text-gray-900"><?= htmlspecialchars($title) ?></h1>
      <p class="mt-2 text-gray-600"><?= $ok
        ? 'Thanks for confirming — you\'ll now receive our travel deals and updates.'
        : 'This confirmation link is invalid or has already been used. Try subscribing again.' ?></p>
      <a href="<?= root ?>" class="inline-block mt-6 px-5 py-2.5 rounded-full bg-blue-600 text-white font-semibold hover:bg-blue-700 transition">Back to home</a>
    </div>
    <?php
    require_once views . "includes/footer.php";
    exit;
});

// ============================================================================
// GET /newsletter/unsubscribe/{token} — one-click unsubscribe (CAN-SPAM).
// ============================================================================
$router->get('/newsletter/unsubscribe/([a-f0-9]{32})', function ($token) use ($SECURE, $db) {
    if (function_exists('ensureNewsletterSchema')) { ensureNewsletterSchema($db); }

    $done = false;
    try {
        $row = $db->get('newsletter_subscribers', '*', ['unsubscribe_token' => $token]);
        if ($row) {
            $db->update('newsletter_subscribers', [
                'status'          => 'unsubscribed',
                'unsubscribed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $row['id']]);
            $done = true;
        }
    } catch (\Throwable $e) {
        error_log('newsletter/unsubscribe: ' . $e->getMessage());
    }

    $header = true; $footer = true;
    require_once views . "includes/header.php";
    ?>
    <div class="container mx-auto px-4 py-20 text-center">
      <span class="material-symbols-outlined !text-[56px] text-gray-400">unsubscribe</span>
      <h1 class="mt-4 text-2xl font-bold text-gray-900"><?= $done ? 'You\'ve been unsubscribed' : 'Link not found' ?></h1>
      <p class="mt-2 text-gray-600"><?= $done
        ? 'You will no longer receive our newsletter. You can re-subscribe any time from our homepage.'
        : 'This unsubscribe link is invalid. If you keep receiving emails, contact support.' ?></p>
      <a href="<?= root ?>" class="inline-block mt-6 px-5 py-2.5 rounded-full bg-blue-600 text-white font-semibold hover:bg-blue-700 transition">Back to home</a>
    </div>
    <?php
    require_once views . "includes/footer.php";
    exit;
});

// ============================================================================
// POST /newsletter/unsubscribe/{token} — RFC 8058 one-click unsubscribe.
// Triggered by the mail client's List-Unsubscribe-Post header (Gmail/Yahoo).
// This is a machine-to-machine request: NO cookies, NO CSRF token — the random
// 128-bit token in the URL is the capability. Must NOT require CSRF. Responds
// with a bare 200 (no HTML page); the mail client doesn't render a body.
// ============================================================================
$router->post('/newsletter/unsubscribe/([a-f0-9]{32})', function ($token) use ($SECURE, $db) {
    if (function_exists('ensureNewsletterSchema')) { ensureNewsletterSchema($db); }
    try {
        $row = $db->get('newsletter_subscribers', '*', ['unsubscribe_token' => $token]);
        if ($row && ($row['status'] ?? '') !== 'unsubscribed') {
            $db->update('newsletter_subscribers', [
                'status'          => 'unsubscribed',
                'unsubscribed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $row['id']]);
        }
    } catch (\Throwable $e) {
        error_log('newsletter/unsubscribe(POST): ' . $e->getMessage());
    }
    // Always 200 so the mail provider records the unsubscribe as honoured (and we
    // don't leak whether the token existed).
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'OK';
    exit;
});
