<?php
// app/routes/admin/supportRoutes.php
// Customer Support module — Phase 1 shell: the landing/home route that makes the
// module reachable and links out to every sub-area. Design-of-record:
// docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md (§3 routes, §9 dashboard).
//
// Every support route pairs ADMIN_AUTH() with admin_can($db, '<area>', '<action>'); the
// RBAC gate (admin_can) + schema live in app/lib/admin_rbac.php (loaded in config.php).
@$SECURE or die('Access Denied!');

// ================================ SUPPORT HOME
// A simple home page with cards/links into the sub-areas. Some sub-areas exist
// today (tickets, bookings, roles, staff); the rest land in later phases and are
// marked "coming" in the view.
$router->get(admin.'/support', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    // Self-heal the RBAC foundation + seed the 4 default roles so an admin can
    // reach the module on a fresh install before any staff is provisioned.
    if (function_exists('ensureAdminRbacSchema')) { ensureAdminRbacSchema($db); }

    // Capability gate: anyone who may see the support dashboard. Admin is
    // all-allow (admin_can short-circuits on is_admin).
    if (!admin_can($db, 'support_dashboard', 'view')) {
        header('Location: ' . root . admin . '/dashboard');
        exit;
    }

    // --- Live dashboard data (all fail-soft: a helper error must NOT blank the
    //     page). The view reads $supportDash if present, else shows tiles only. ---
    $supportDash = [
        'agents_online' => null, 'needs_human' => null,
        'today_held' => null, 'today_paid' => null, 'today_revenue' => null,
        'targets' => [], 'leaderboard' => [],
        'can_targets' => admin_can($db, 'support_targets', 'view'),
        'can_board'   => admin_can($db, 'support_attendance', 'view_all') || admin_can($db, 'support_campaigns', 'view'),
    ];
    try {
        if (function_exists('support_who_online')) {
            $supportDash['agents_online'] = count(support_who_online($db));
        }
        if (function_exists('support_desk_queue')) {
            $supportDash['needs_human'] = count(support_desk_queue($db, 'needs_human', 100));
        }
        // Today's held/paid/revenue via a team/any "held"-style window using the
        // targets engine's actual() over a today window (reuse, don't re-implement).
        if (function_exists('support_target_actual')) {
            $todayT = ['scope' => 'team', 'metric' => 'held', 'product' => '', 'period' => 'daily', 'target_value' => 0];
            $supportDash['today_held'] = support_target_actual($db, $todayT, date('Y-m-d'));
            $supportDash['today_paid'] = support_target_actual($db, ['scope' => 'team', 'metric' => 'paid', 'product' => '', 'period' => 'daily', 'target_value' => 0], date('Y-m-d'));
            $supportDash['today_revenue'] = support_target_actual($db, ['scope' => 'team', 'metric' => 'revenue', 'product' => '', 'period' => 'daily', 'target_value' => 0], date('Y-m-d'));
        }
        if ($supportDash['can_targets'] && function_exists('support_targets_all')) {
            $supportDash['targets'] = array_slice(support_targets_all($db), 0, 6);
        }
        if ($supportDash['can_board'] && function_exists('support_leaderboard')) {
            $supportDash['leaderboard'] = array_slice(support_leaderboard($db, date('Y-m-01'), date('Y-m-d')), 0, 6);
        }
    } catch (\Throwable $e) {
        error_log('[support] dashboard data: ' . $e->getMessage());
    }

    $title = 'Customer Support'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/home.php";
    require_once views."includes/footer.php";
});
