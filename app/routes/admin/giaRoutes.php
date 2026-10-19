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
             'token_out', 'last_message', 'started_at', 'last_at'],
            ['ORDER' => ['last_at' => 'DESC'], 'LIMIT' => 50]
        ) ?: [];
    }, []);

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
