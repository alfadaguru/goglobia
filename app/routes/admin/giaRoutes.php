<?php
// ============================================================================
// FILE: app/routes/admin/giaRoutes.php
// Admin GIA reports — overview of the AI assistant: conversations, leads,
// conversion funnel (chats -> searches -> checkouts -> bookings), revenue
// attributed to GIA, token cost, top searched modules, and a conversation
// drill-down. Reads the gia_* tables (gia_analytics.php schema). ADMIN-only.
// ============================================================================
@$SECURE or die('Access Denied!');

// GET admin/gia — overview dashboard.
$router->get(admin . '/gia', function () use ($SECURE, $db) {
    ADMIN_AUTH();

    require_once dirname(__DIR__, 2) . '/lib/ai/gia_analytics.php';
    if (function_exists('ensureGiaAnalyticsSchema')) {
        ensureGiaAnalyticsSchema($db);
    }

    // --- Headline totals (guarded: tables may be empty / brand new). ---
    $g = static function (callable $fn, $default = 0) {
        try { return $fn(); } catch (\Throwable $e) { return $default; }
    };

    $stats = [
        'conversations' => (int) $g(fn() => $db->count('gia_conversations', '*')),
        'leads'         => (int) $g(fn() => $db->count('gia_leads', '*')),
        'leads_linked'  => (int) $g(fn() => $db->count('gia_leads', ['status' => ['linked', 'converted']])),
        'bookings'      => (int) $g(fn() => $db->count('gia_events', ['type' => 'book'])),
        'token_in'      => (int) $g(fn() => $db->sum('gia_conversations', 'token_in')),
        'token_out'     => (int) $g(fn() => $db->sum('gia_conversations', 'token_out')),
        'revenue'       => (float) $g(fn() => $db->sum('gia_conversations', 'revenue')),
    ];

    // --- Conversion funnel (distinct conversations that reached each stage). ---
    $distinctConvWith = static function (string $type) use ($db, $g): int {
        return (int) $g(function () use ($db, $type) {
            $rows = $db->select('gia_events', ['conv_id'], ['type' => $type, 'GROUP' => 'conv_id']);
            return is_array($rows) ? count($rows) : 0;
        });
    };
    $funnel = [
        'chats'     => $stats['conversations'],
        'searched'  => $distinctConvWith('search'),
        'checkout'  => $distinctConvWith('checkout'),
        'booked'    => $distinctConvWith('book'),
    ];

    // --- Top searched modules. ---
    $topModules = $g(function () use ($db) {
        // Use Medoo's aggregate helpers rather than raw PDO fetch so a null
        // statement (driver error) can't fatal. Count searches per module.
        $modules = ['umrah','stays','tours','bus','flights','cars','esim','rail','ferries','visa'];
        $out = [];
        foreach ($modules as $m) {
            $n = (int) $db->count('gia_events', ['type' => 'search', 'module' => $m]);
            if ($n > 0) {
                $out[] = ['module' => $m, 'n' => $n];
            }
        }
        usort($out, static fn($a, $b) => $b['n'] <=> $a['n']);
        return $out;
    }, []);

    // --- Recent leads. ---
    $leads = $g(function () use ($db) {
        return $db->select('gia_leads',
            ['id', 'name', 'email', 'phone', 'user_id', 'status', 'context', 'created_at'],
            ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 50]
        ) ?: [];
    }, []);

    // --- Recent conversations. ---
    $conversations = $g(function () use ($db) {
        return $db->select('gia_conversations',
            ['id', 'conv_key', 'user_id', 'lead_id', 'context', 'surface', 'msg_count',
             'search_count', 'booking_count', 'revenue', 'revenue_currency', 'token_in',
             'token_out', 'last_message', 'started_at', 'last_at', 'flagged', 'needs_human'],
            ['ORDER' => ['last_at' => 'DESC'], 'LIMIT' => 50]
        ) ?: [];
    }, []);

    // Current on/off state for the toggle + flagged flag per conversation.
    $giaEnabled = function_exists('aiTripIsEnabled') ? aiTripIsEnabled($db) : false;
    $conversations = array_map(static function ($c) {
        $c['flagged'] = (int) ($c['flagged'] ?? 0) === 1;
        return $c;
    }, $conversations);

    $title = 'GIA Assistant — Reports';
    $header = true;
    $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/gia/overview.php";
    require_once views . "includes/footer.php";
});

// GET admin/gia/conversation/{id} — a single conversation transcript + events.
$router->get(admin . '/gia/conversation/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    $id = (int) $id;

    require_once dirname(__DIR__, 2) . '/lib/ai/gia_analytics.php';
    if (function_exists('ensureGiaAnalyticsSchema')) {
        ensureGiaAnalyticsSchema($db);
    }

    $conv = null;
    $messages = [];
    $events = [];
    $lead = null;
    try {
        $conv = $db->get('gia_conversations', '*', ['id' => $id]);
        if ($conv) {
            $messages = $db->select('gia_messages', ['role', 'text', 'created_at'],
                ['conv_id' => $id, 'ORDER' => ['id' => 'ASC'], 'LIMIT' => 500]) ?: [];
            $events = $db->select('gia_events', ['type', 'module', 'ref', 'amount', 'currency', 'meta', 'created_at'],
                ['conv_id' => $id, 'ORDER' => ['id' => 'ASC'], 'LIMIT' => 200]) ?: [];
            if (!empty($conv['lead_id'])) {
                $lead = $db->get('gia_leads', '*', ['id' => (int) $conv['lead_id']]);
            }
        }
    } catch (\Throwable $e) {
        error_log('[gia] admin conversation: ' . $e->getMessage());
    }

    if (!$conv) {
        header('Location: ' . root . admin . '/gia');
        exit;
    }

    $title = 'GIA Conversation #' . $id;
    $header = true;
    $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/gia/conversation.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// ADMIN GIA CONTROLS (state-changing; ADMIN_AUTH + CSRF::guard). These turn the
// GIA reports from view-only into real oversight.
// ============================================================================

// POST admin/gia/leads/export — download all captured leads as CSV.
$router->get(admin . '/gia/leads/export', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_analytics.php';
    if (function_exists('ensureGiaAnalyticsSchema')) { ensureGiaAnalyticsSchema($db); }
    $rows = [];
    try {
        $leads = $db->select('gia_leads',
            ['id', 'name', 'email', 'phone', 'user_id', 'status', 'context', 'created_at'],
            ['ORDER' => ['id' => 'DESC']]) ?: [];
        foreach ($leads as $l) {
            $rows[] = [
                $l['id'], $l['name'] ?? '', $l['email'] ?? '', $l['phone'] ?? '',
                $l['user_id'] ?? '', $l['status'] ?? '', $l['context'] ?? '', $l['created_at'] ?? '',
            ];
        }
    } catch (\Throwable $e) {
        error_log('[gia] leads export: ' . $e->getMessage());
    }
    // Reuse the reports CSV streamer (loaded by reportsRoutes, which precedes this).
    if (function_exists('_report_stream_csv')) {
        _report_stream_csv('gia-leads.csv',
            ['ID', 'Name', 'Email', 'Phone', 'Linked user', 'Status', 'Context', 'Captured'], $rows);
    }
    // Fallback if the streamer isn't available.
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gia-leads.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Name', 'Email', 'Phone', 'Linked user', 'Status', 'Context', 'Captured']);
    foreach ($rows as $r) { fputcsv($out, $r); }
    fclose($out);
    exit;
});

// POST admin/gia/conversation/flag — flag/unflag or delete a conversation.
$router->post(admin . '/gia/conversation/action', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    header('Content-Type: application/json');
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_analytics.php';
    if (function_exists('ensureGiaAnalyticsSchema')) { ensureGiaAnalyticsSchema($db); }

    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($id <= 0) { echo json_encode(['status' => false, 'message' => 'Missing conversation.']); return; }
    try {
        if ($action === 'flag') {
            $db->update('gia_conversations', ['flagged' => 1], ['id' => $id]);
        } elseif ($action === 'unflag') {
            $db->update('gia_conversations', ['flagged' => 0], ['id' => $id]);
        } elseif ($action === 'delete') {
            // Delete the conversation + its messages + events (admin-initiated purge).
            $db->delete('gia_messages', ['conv_id' => $id]);
            $db->delete('gia_events', ['conv_id' => $id]);
            $db->delete('gia_conversations', ['id' => $id]);
        } else {
            echo json_encode(['status' => false, 'message' => 'Unknown action.']); return;
        }
        echo json_encode(['status' => true]);
    } catch (\Throwable $e) {
        error_log('[gia] conversation action: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'Action failed.']);
    }
});

// POST admin/gia/toggle — turn GIA (AI trip) on/off from the dashboard.
$router->post(admin . '/gia/toggle', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    header('Content-Type: application/json');
    try {
        $enable = ((string) ($_POST['enable'] ?? '')) === '1';
        $cfg = function_exists('passportAiSettings') ? passportAiSettings($db) : [];
        if (!is_array($cfg)) { $cfg = []; }
        $cfg['trip_enabled'] = $enable;
        if (function_exists('aiConfigurationEncode')) {
            $db->update('settings', ['ai_configuration' => aiConfigurationEncode($cfg)], ['id' => 1]);
        }
        echo json_encode(['status' => true, 'enabled' => $enable]);
    } catch (\Throwable $e) {
        error_log('[gia] toggle: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'Could not change the setting.']);
    }
});

// ============================================================================
// HUMAN HANDOFF — staff takeover of a live GIA conversation.
// Admin posts a reply that lands in the traveller's chat; while handoff is
// active, GIA pauses auto-replies and the widget polls for staff messages.
// ============================================================================

// POST admin/gia/conversation/staff-reply — staff message into the user's chat.
$router->post(admin . '/gia/conversation/staff-reply', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    header('Content-Type: application/json');
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_analytics.php';
    if (function_exists('ensureGiaAnalyticsSchema')) { ensureGiaAnalyticsSchema($db); }

    $id = (int) ($_POST['id'] ?? 0);
    $text = trim((string) ($_POST['text'] ?? ''));
    if ($id <= 0 || $text === '') { echo json_encode(['status' => false, 'message' => 'Missing message.']); return; }
    if (function_exists('mb_substr')) { $text = mb_substr($text, 0, 2000); } else { $text = substr($text, 0, 2000); }
    try {
        // Record as an assistant message flagged staff; activate handoff (pauses GIA).
        $db->insert('gia_messages', ['conv_id' => $id, 'role' => 'assistant', 'text' => $text, 'is_staff' => 1]);
        $db->update('gia_conversations', [
            'staff_active' => 1,
            'needs_human'  => 1,
            'msg_count[+]' => 1,
            'last_message' => (function_exists('mb_substr') ? mb_substr($text, 0, 480) : substr($text, 0, 480)),
        ], ['id' => $id]);

        // Realtime push to the traveller's chat (instant); fail-soft -> the widget's
        // polling fallback still delivers within a few seconds if Pusher isn't set.
        require_once dirname(__DIR__, 2) . '/lib/ai/gia_realtime.php';
        if (function_exists('App\\lib\\ai\\gia_realtime_staff_reply')) {
            $convKey = (string) ($db->get('gia_conversations', 'conv_key', ['id' => $id]) ?: '');
            if ($convKey !== '') {
                \App\lib\ai\gia_realtime_staff_reply($db, $convKey, $text);
            }
        }
        echo json_encode(['status' => true]);
    } catch (\Throwable $e) {
        error_log('[gia] staff reply: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'Could not send.']);
    }
});

// POST admin/gia/conversation/handoff — toggle handoff: hand back to GIA (resume AI).
$router->post(admin . '/gia/conversation/handoff', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    header('Content-Type: application/json');
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_analytics.php';
    if (function_exists('ensureGiaAnalyticsSchema')) { ensureGiaAnalyticsSchema($db); }
    $id = (int) ($_POST['id'] ?? 0);
    $mode = (string) ($_POST['mode'] ?? '');
    if ($id <= 0) { echo json_encode(['status' => false]); return; }
    try {
        if ($mode === 'take') {
            $db->update('gia_conversations', ['staff_active' => 1, 'needs_human' => 1], ['id' => $id]);
        } else { // 'release' -> back to GIA
            $db->update('gia_conversations', ['staff_active' => 0, 'needs_human' => 0], ['id' => $id]);
        }
        echo json_encode(['status' => true]);
    } catch (\Throwable $e) {
        error_log('[gia] handoff: ' . $e->getMessage());
        echo json_encode(['status' => false]);
    }
});

// ============================================================================
// GIA MESSAGING CHANNELS — admin has FULL control: enable/disable each channel,
// enter credentials, see the webhook URL + verify token to paste into each
// provider console, and test connectivity. ADMIN_AUTH on all; CSRF on writes.
// ============================================================================

// GET admin/gia/channels — the control panel.
$router->get(admin . '/gia/channels', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_channels_config.php';
    $channels = gia_channels_config($db);
    // Webhook URLs to paste into each provider console (built from this install's base URL).
    $webhooks = [
        'telegram'  => root . 'webhooks/gia/telegram',
        'whatsapp'  => root . 'webhooks/gia/whatsapp',
        'messenger' => root . 'webhooks/gia/messenger',
    ];
    require_once views . "includes/header.php";
    require_once "app/views/admin/gia/channels.php";
    require_once views . "includes/footer.php";
});

// POST admin/gia/channels/save — persist per-channel config (merge-safe).
$router->post(admin . '/gia/channels/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    header('Content-Type: application/json');
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_channels_config.php';

    // Accept a nested gia_channels[<channel>][<field>] form post.
    $incoming = $_POST['gia_channels'] ?? [];
    if (!is_array($incoming)) { $incoming = []; }
    // Normalize checkbox 'enabled' (absent => off) for each known channel.
    foreach (['whatsapp', 'messenger', 'telegram'] as $ch) {
        if (!isset($incoming[$ch]) || !is_array($incoming[$ch])) { $incoming[$ch] = []; }
        $incoming[$ch]['enabled'] = !empty($incoming[$ch]['enabled']);
    }
    $ok = gia_channels_save($db, $incoming);
    echo json_encode(['status' => $ok]);
});

// POST admin/gia/channels/test — lightweight connectivity check per channel.
$router->post(admin . '/gia/channels/test', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    header('Content-Type: application/json');
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_channels_config.php';
    $channel = (string) ($_POST['channel'] ?? '');
    $cfg = gia_channels_config($db)[$channel] ?? null;
    if (!is_array($cfg)) { echo json_encode(['status' => false, 'message' => 'Unknown channel']); return; }

    try {
        if ($channel === 'telegram') {
            $token = trim((string) ($cfg['bot_token'] ?? ''));
            if ($token === '') { echo json_encode(['status' => false, 'message' => 'No bot token set']); return; }
            // Telegram getMe confirms the token without sending anything.
            $ch = curl_init('https://api.telegram.org/bot' . $token . '/getMe');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $resp = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            $j = json_decode((string) $resp, true);
            if ($code === 200 && !empty($j['ok'])) {
                echo json_encode(['status' => true, 'message' => 'Connected as @' . ($j['result']['username'] ?? 'bot')]);
            } else {
                echo json_encode(['status' => false, 'message' => 'Telegram rejected the token']);
            }
            return;
        }
        if ($channel === 'whatsapp') {
            // Green API: reuse the provider's own testConnection via SENDWHATSAPP's loader path.
            $mode = (string) ($cfg['mode'] ?? 'greenapi');
            if ($mode === 'greenapi') {
                $settings = $db->get('settings', '*');
                $wcfg = json_decode($settings['whatsapp_providers_config'] ?? '{}', true);
                $gc = $wcfg['greenapi'] ?? [];
                if (empty($gc['instance_id']) || empty($gc['token'])) {
                    echo json_encode(['status' => false, 'message' => 'Green API credentials not set (Settings → WhatsApp)']); return;
                }
                require_once dirname(__DIR__, 2) . '/lib/notifications/whatsapp/greenapi.php';
                $p = new GreenapiProvider($gc);
                $r = $p->testConnection();
                echo json_encode(['status' => !empty($r['success']), 'message' => (string) ($r['message'] ?? '')]);
            } else {
                $ok = trim((string) ($cfg['meta_phone_number_id'] ?? '')) !== '' && trim((string) ($cfg['meta_access_token'] ?? '')) !== '';
                echo json_encode(['status' => $ok, 'message' => $ok ? 'Meta credentials present' : 'Meta phone id / token missing']);
            }
            return;
        }
        if ($channel === 'messenger') {
            $ok = trim((string) ($cfg['page_token'] ?? '')) !== '' && trim((string) ($cfg['app_secret'] ?? '')) !== '' && trim((string) ($cfg['verify_token'] ?? '')) !== '';
            echo json_encode(['status' => $ok, 'message' => $ok ? 'Page token + app secret + verify token present' : 'Missing page token / app secret / verify token']);
            return;
        }
        echo json_encode(['status' => false, 'message' => 'Unknown channel']);
    } catch (\Throwable $e) {
        error_log('[gia] channel test: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'Test failed']);
    }
});

// POST admin/gia/channels/greenapi-webhook — ONE-CLICK: register GoGlobia as the
// Green API incoming-webhook target (SetSettings incomingWebhook=yes). Saves the
// operator a manual trip to the Green API console. ADMIN_AUTH + CSRF.
$router->post(admin . '/gia/channels/greenapi-webhook', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    header('Content-Type: application/json');
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_channel_send.php';
    if (!function_exists('gia_greenapi_set_webhook')) {
        echo json_encode(['status' => false, 'message' => 'Unavailable.']); return;
    }
    $res = gia_greenapi_set_webhook($db);
    echo json_encode(['status' => !empty($res['ok']), 'message' => (string) ($res['message'] ?? '')]);
});
