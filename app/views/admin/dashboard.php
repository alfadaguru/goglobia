
<?php
// Access the global $db variable
global $db;

// GET DEFAULT CURRENCY FROM CURRENCIES TABLE
$defaultCurrency = $db->get('currencies', ['name'], ['default' => 1, 'status' => 1]);
$currencySymbol = $defaultCurrency ? $defaultCurrency['name'] : 'USD';

// CHECK MODULE STATUS - Determine which modules are enabled
$flights = $db->count('modules', ['type' => 'flights', 'status' => 1, 'active' => 1]);
$stays = $db->count('modules', ['type' => 'stays', 'status' => 1, 'active' => 1]);
$tours = $db->count('modules', ['type' => 'tours', 'status' => 1, 'active' => 1]);
$visa_count = $db->count('modules', ['type' => 'visa', 'status' => 1, 'active' => 1]);
$cars = $db->count('modules', ['type' => 'cars', 'status' => 1, 'active' => 1]);

// Get all counts directly - no try-catch
$totalBookings = $db->count("bookings") ?: 0;
$totalUsers = $db->count("users") ?: 0;

// This month bookings - check if booking_date column exists
$thisMonthBookings = $db->count("bookings", [
    "created_at[>=]" => date('Y-m-01'),
    "created_at[<]" => date('Y-m-01', strtotime('+1 month'))
]) ?: 0;

$thisMonthUsers = $db->count("users", [
    "created_at[>=]" => date('Y-m-01'),
    "created_at[<]" => date('Y-m-01', strtotime('+1 month'))
]) ?: 0;

// This month revenue — commission earned on every booking of the month,
// matching the per-booking "Earning" shown in Recent Bookings.
$thisMonthRevenue = $db->sum("bookings", "commission", [
    "created_at[>=]" => date('Y-m-01'),
    "created_at[<]" => date('Y-m-01', strtotime('+1 month'))
]) ?: 0;

if ($thisMonthRevenue == 0) {
    $thisMonthRevenue = $db->sum("bookings", "price_original", [
        "created_at[>=]" => date('Y-m-01'),
        "created_at[<]" => date('Y-m-01', strtotime('+1 month'))
    ]) ?: 0;
}

// Get module types and their counts
$moduleTypes = $db->select("modules", [
    "type",
    "icon"
], [
    "active" => "1",
    "GROUP" => "type",
    "ORDER" => ["order" => "ASC"]
]) ?: [];

// Get all modules grouped by type
$allModules = $db->select("modules", [
    "id",
    "name",
    "type",
    "status",
    "active",
    "icon",
    "module_color"
], [
    "active" => "1",
    "ORDER" => ["order" => "ASC"]
]) ?: [];

// Group modules by type
$modulesByType = [];
foreach ($allModules as $module) {
    $modulesByType[$module['type']][] = $module;
}

// LAST MONTH DATA - Define these BEFORE using them in module bookings
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('last day of last month')) . ' 23:59:59';

$lastMonthBookings = $db->count("bookings", [
    "created_at[>=]" => $lastMonthStart,
    "created_at[<=]" => $lastMonthEnd
]) ?: 0;

$lastMonthRevenue = $db->sum("bookings", "commission", [
    "created_at[>=]" => $lastMonthStart,
    "created_at[<=]" => $lastMonthEnd
]) ?: 0;

if ($lastMonthRevenue == 0) {
    $lastMonthRevenue = $db->sum("bookings", "price_original", [
        "created_at[>=]" => $lastMonthStart,
        "created_at[<=]" => $lastMonthEnd
    ]) ?: 0;
}

// Get bookings by module type (only for enabled modules with status=1)
$moduleBookings = [];
$thisMonthModuleBookings = [];
$lastMonthModuleBookings = [];

// Per-module bookings + revenue for a period, grouped by the booking's OWN
// currency (currency_markup). Different modules book in different currencies
// (core modules store the base currency, but cars/esim/ferries/rail/etc. store
// their own), so revenue is reported per currency instead of one static symbol.
$moduleStats = function ($moduleType, $start, $end) use ($db, $currencySymbol) {
    $typeQ = $db->pdo->quote($moduleType);
    $rows = $db->query(
        "SELECT currency_markup AS cur,
                COUNT(*) AS cnt,
                SUM(CAST(NULLIF(commission, '') AS DECIMAL(18,2)))   AS commission_sum,
                SUM(CAST(NULLIF(price_markup, '') AS DECIMAL(18,2))) AS markup_sum
         FROM bookings
         WHERE module_type = {$typeQ}
           AND created_at >= '{$start}' AND created_at <= '{$end}'
         GROUP BY currency_markup"
    )->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    $commissionRev = [];   // currency => summed commission (earning)
    $markupRev     = [];   // currency => summed gross value (fallback)
    $commissionAll = 0.0;
    foreach ($rows as $r) {
        $cur = trim((string) $r['cur']) !== '' ? strtoupper(trim($r['cur'])) : strtoupper($currencySymbol);
        $count += (int) $r['cnt'];
        $commissionRev[$cur] = ($commissionRev[$cur] ?? 0) + (float) $r['commission_sum'];
        $markupRev[$cur]     = ($markupRev[$cur] ?? 0) + (float) $r['markup_sum'];
        $commissionAll      += (float) $r['commission_sum'];
    }

    // Mirror the card's headline Revenue (commission earned); if a module has no
    // commission recorded, fall back to the gross booking value so it still
    // reports the revenue it generated.
    $revenue = ($commissionAll == 0.0 && !empty($markupRev)) ? $markupRev : $commissionRev;

    return ['count' => $count, 'revenue' => $revenue];
};

// This-month period boundaries (last month boundaries are already set above).
$thisMonthStart = date('Y-m-01');
$thisMonthEnd   = date('Y-m-t') . ' 23:59:59';

foreach ($moduleTypes as $moduleType) {
    // Check if this module type has at least one enabled provider
    $enabledCount = $db->count("modules", [
        "type" => $moduleType['type'],
        "status" => 1,
        "active" => 1
    ]);

    // Only show bookings for module types that have enabled providers
    if ($enabledCount > 0) {
        // All time bookings
        $count = $db->count("bookings", ["module_type" => $moduleType['type']]);
        if ($count > 0) {
            $moduleBookings[$moduleType['type']] = [
                'count' => $count,
                'name' => ucfirst($moduleType['type']),
                'icon' => $moduleType['icon']
            ];
        }

        // This month — always add so EVERY enabled module is listed (same as last month)
        $tm = $moduleStats($moduleType['type'], $thisMonthStart, $thisMonthEnd);
        $thisMonthModuleBookings[$moduleType['type']] = [
            'count'   => $tm['count'],
            'name'    => ucfirst($moduleType['type']),
            'icon'    => $moduleType['icon'],
            'revenue' => $tm['revenue']
        ];

        // Last month — always add even if 0, to show all enabled modules
        $lm = $moduleStats($moduleType['type'], $lastMonthStart, $lastMonthEnd);
        $lastMonthModuleBookings[$moduleType['type']] = [
            'count'   => $lm['count'],
            'name'    => ucfirst($moduleType['type']),
            'icon'    => $moduleType['icon'],
            'revenue' => $lm['revenue']
        ];
    }
}

// Get recent bookings (exclude AI trip child invoices: AITC + exactly 8 chars;
// parents are AIT + 10 hex and may also start with AITC when hex begins with C)
$recentBookings = $db->select("bookings", "*", [
    "invoice_id[!~]" => "AITC________",
    "ORDER" => ["created_at" => "DESC"],
    "LIMIT" => 25
]) ?: [];

// GET PENDING ACTIONS DATA FOR ADMIN
// BOOKINGS REQUIRING CANCELLATION — customer requested cancellation, not yet
// processed by admin, and not already cancelled (requests come from confirmed
// bookings, so this must NOT be scoped to booking_status = pending).
$pendingCancellations = $db->count("bookings", [
    "cancellation_request" => 1,
    "cancellation_status"  => 0,
    "booking_status[!]"    => "cancelled"
]) ?: 0;

// UNPAID BOOKINGS (PAYMENT PENDING)
$unpaidBookings = $db->count("bookings", [
    "payment_status" => "unpaid",
    "booking_status[!]" => "cancelled"
]) ?: 0;

// PENDING BOOKINGS (NEED CONFIRMATION)
$pendingConfirmations = $db->count("bookings", [
    "booking_status" => "pending",
    "payment_status" => "paid"
]) ?: 0;

// FAILED PAYMENTS (NEED ATTENTION)
$failedPayments = $db->count("bookings", [
    "payment_status" => "failed"
]) ?: 0;

// PENDING TRANSACTIONS (DEPOSITS/CREDITS AWAITING APPROVAL)
$pendingTransactions = $db->count("transactions", [
    "status" => "pending"
]) ?: 0;

// PENDING USER VERIFICATIONS (ACTIVE USERS WHO HAVEN'T VERIFIED EMAIL)
$pendingVerifications = $db->count("users", [
    "OR" => [
        "email_verified" => "0",
        "email_verified" => null
    ],
    "status" => "active"
]) ?: 0;

// PENDING DEPOSITS (AWAITING APPROVAL)
$pendingDeposits = $db->count("deposit", [
    "status" => "pending"
]) ?: 0;

// INACTIVE USERS (ACCOUNTS WITH STATUS = INACTIVE)
$inactiveUsers = $db->count("users", [
    "status" => "inactive"
]) ?: 0;

// CALCULATE TOTAL ACTIONS REQUIRED
$totalActionsRequired = $pendingCancellations + $unpaidBookings + $failedPayments + $pendingDeposits + $inactiveUsers;

// GET LAST 30 DAYS DATA FOR REVENUE ANALYTICS CHART
$daysToShow = 30; // Show last 30 days from today

$lastDays = [];
$dailyBookings = [];
$dailyRevenue = [];
$dailyPaid = [];
$dailyUnpaid = [];
$dailyConfirmed = [];
$dailyPending = [];
$dailyCancelled = [];

for ($i = $daysToShow - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dateLabel = date('M d', strtotime("-$i days"));
    $lastDays[] = $dateLabel;

    $startDate = $date . ' 00:00:00';
    $endDate = $date . ' 23:59:59';

    // Total bookings for this day
    $dayBookings = $db->count("bookings", [
        "created_at[>=]" => $startDate,
        "created_at[<=]" => $endDate
    ]) ?: 0;
    $dailyBookings[] = $dayBookings;

    // Revenue (commission) for this day — all bookings, same basis as the
    // month cards above.
    $dayRevenue = $db->sum("bookings", "commission", [
        "created_at[>=]" => $startDate,
        "created_at[<=]" => $endDate
    ]) ?: 0;

    // If commission is 0, try price_original
    if ($dayRevenue == 0) {
        $dayRevenue = $db->sum("bookings", "price_original", [
            "created_at[>=]" => $startDate,
            "created_at[<=]" => $endDate
        ]) ?: 0;
    }
    $dailyRevenue[] = round($dayRevenue, 2);

    // Payment status counts
    $dayPaid = $db->count("bookings", [
        "created_at[>=]" => $startDate,
        "created_at[<=]" => $endDate,
        "payment_status" => "paid"
    ]) ?: 0;
    $dailyPaid[] = $dayPaid;

    $dayUnpaid = $db->count("bookings", [
        "created_at[>=]" => $startDate,
        "created_at[<=]" => $endDate,
        "payment_status" => "unpaid"
    ]) ?: 0;
    $dailyUnpaid[] = $dayUnpaid;

    // Booking status counts
    $dayConfirmed = $db->count("bookings", [
        "created_at[>=]" => $startDate,
        "created_at[<=]" => $endDate,
        "booking_status" => "confirmed"
    ]) ?: 0;
    $dailyConfirmed[] = $dayConfirmed;

    $dayPending = $db->count("bookings", [
        "created_at[>=]" => $startDate,
        "created_at[<=]" => $endDate,
        "booking_status" => "pending"
    ]) ?: 0;
    $dailyPending[] = $dayPending;

    $dayCancelled = $db->count("bookings", [
        "created_at[>=]" => $startDate,
        "created_at[<=]" => $endDate,
        "booking_status" => "cancelled"
    ]) ?: 0;
    $dailyCancelled[] = $dayCancelled;
}

// Convert to JSON for JavaScript
$chartData = [
    'labels' => $lastDays,
    'daysCount' => $daysToShow,
    'periodLabel' => 'Last 30 Days',
    'dailyBookings' => $dailyBookings,
    'dailyRevenue' => $dailyRevenue,
    'dailyPaid' => $dailyPaid,
    'dailyUnpaid' => $dailyUnpaid,
    'dailyConfirmed' => $dailyConfirmed,
    'dailyPending' => $dailyPending,
    'dailyCancelled' => $dailyCancelled
];
$chartDataJson = json_encode($chartData);
?>

<div class="container mx-auto px-4 py-4">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-900"><?php echo T::dashboard; ?></h1>
        <p class="text-sm text-gray-600">OTA Travel Management System</p>
    </div>

    <!-- Compact Metrics Grid -->
    <div class="card p-0 mb-4">
        <div class="card-header">
            <div>
                <span class="card-header-icon">analytics</span>
                <h3><?php echo T::overview; ?></h3>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <a href="<?php echo root.admin; ?>/bookings" class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm hover:border-blue-600 transition-all">
            <div class="flex items-center">
                <div class="flex-shrink-0 w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="material-symbols-outlined text-blue-600 text-lg">event</i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-gray-500 truncate"><?php echo T::total . ' ' . T::bookings; ?></p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo number_format($totalBookings); ?></p>
                </div>
            </div>
        </a>

        <a href="<?php echo root.admin; ?>/users" class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm hover:border-green-600 transition-all">
            <div class="flex items-center">
                <div class="flex-shrink-0 w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="material-symbols-outlined text-green-600 text-lg">people</i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-gray-500 truncate"><?php echo T::total . ' ' . T::users; ?></p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo number_format($totalUsers); ?></p>
                </div>
            </div>
        </a>

        <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
            <div class="flex items-center">
                <div class="flex-shrink-0 w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="material-symbols-outlined text-purple-600 text-lg">trending_up</i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-gray-500 truncate"><?php echo T::this_month . ' ' . T::bookings; ?></p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo number_format($thisMonthBookings); ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
            <div class="flex items-center">
                <div class="flex-shrink-0 w-8 h-8 bg-cyan-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="material-symbols-outlined text-cyan-600 text-lg">account_balance</i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-gray-500 truncate"><?php echo T::this_month . ' ' . T::revenue; ?></p>
                    <p class="text-lg font-semibold text-gray-900"><?php echo $currencySymbol . ' ' . number_format($thisMonthRevenue, 2); ?></p>
                </div>
            </div>
        </div>
            </div>
        </div>
    </div>

    <!-- ACTIONS REQUIRED & QUICK ACTIONS ROW -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        
        <!-- ACTIONS REQUIRED CARD -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">notification_important</span>
                    <h3><?php echo T::actions; ?> <?php echo T::required; ?></h3>
                </div>
                <span class="<?php echo $totalActionsRequired > 0 ? 'bg-orange-500' : 'bg-green-500'; ?> text-white text-xs font-bold px-3 py-1 rounded-full">
                    <?php echo $totalActionsRequired; ?> <?php echo T::pending; ?>
                </span>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 gap-3">
                
                <!-- UNPAID BOOKINGS -->
                <a href="<?php echo root.admin; ?>/bookings?search_col=payment_status&q=unpaid" 
                   class="flex items-center justify-between p-4 bg-red-50 border border-red-200 rounded-lg transition-all group">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center group-hover:bg-red-200">
                            <i class="material-symbols-outlined text-red-700 text-xl">money_off</i>
                        </div>
                        <div>
                            <p class="text-xs text-red-700 font-medium uppercase"><?php echo T::unpaid; ?> <?php echo T::bookings; ?></p>
                            <p class="text-sm text-red-900"><?php echo T::payment; ?> <?php echo T::pending; ?></p>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-red-700"><?php echo $unpaidBookings; ?></span>
                </a>

                <!-- FAILED PAYMENTS -->
                <a href="<?php echo root.admin; ?>/bookings?search_col=payment_status&q=failed" 
                   class="flex items-center justify-between p-4 bg-rose-50 border border-rose-200 rounded-lg transition-all group">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-rose-100 rounded-lg flex items-center justify-center group-hover:bg-rose-200">
                            <i class="material-symbols-outlined text-rose-700 text-xl">error</i>
                        </div>
                        <div>
                            <p class="text-xs text-rose-700 font-medium uppercase"><?php echo T::failed; ?> <?php echo T::payments; ?></p>
                            <p class="text-sm text-rose-900"><?php echo T::require; ?> <?php echo T::attention; ?></p>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-rose-700"><?php echo $failedPayments; ?></span>
                </a>

                <!-- PENDING CANCELLATIONS -->
                <a href="<?php echo root.admin; ?>/bookings?search_col=cancellation_request&q=1" 
                   class="flex items-center justify-between p-4 bg-orange-50 border border-orange-200 rounded-lg transition-all group">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center group-hover:bg-orange-200">
                            <i class="material-symbols-outlined text-orange-700 text-xl">cancel</i>
                        </div>
                        <div>
                            <p class="text-xs text-orange-700 font-medium uppercase"><?php echo T::cancellation; ?> <?php echo T::requests; ?></p>
                            <p class="text-sm text-orange-900"><?php echo T::process; ?> <?php echo T::cancellation; ?></p>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-orange-700"><?php echo $pendingCancellations; ?></span>
                </a>

                <!-- PENDING DEPOSITS -->
                <a href="<?php echo root.admin; ?>/finance/deposit?search_col=status&q=pending" 
                   class="flex items-center justify-between p-4 bg-emerald-50 border border-emerald-200 rounded-lg transition-all group">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center group-hover:bg-emerald-200">
                            <i class="material-symbols-outlined text-emerald-700 text-xl">payments</i>
                        </div>
                        <div>
                            <p class="text-xs text-emerald-700 font-medium uppercase"><?php echo T::pending; ?> <?php echo T::deposits; ?></p>
                            <p class="text-sm text-emerald-900"><?php echo T::deposit; ?> <?php echo T::approval; ?> <?php echo T::required; ?></p>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-emerald-700"><?php echo $pendingDeposits; ?></span>
                </a>

                <!-- INACTIVE USERS -->
                <a href="<?php echo root.admin; ?>/users?search_col=status&q=inactive" 
                   class="flex items-center justify-between p-4 bg-slate-50 border border-slate-200 rounded-lg transition-all group">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-slate-100 rounded-lg flex items-center justify-center group-hover:bg-slate-200">
                            <i class="material-symbols-outlined text-slate-700 text-xl">person_off</i>
                        </div>
                        <div>
                            <p class="text-xs text-slate-700 font-medium uppercase"><?php echo T::inactive; ?> <?php echo T::users; ?></p>
                            <p class="text-sm text-slate-900"><?php echo T::review; ?> <?php echo T::inactive; ?> <?php echo T::accounts; ?></p>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-slate-700"><?php echo $inactiveUsers; ?></span>
                </a>

            </div>
        </div>
        </div>

        <!-- Quick Actions -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">bolt</span>
                    <h3><?php echo T::quick_actions; ?></h3>
                </div>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-2 gap-3">
                    <a href="<?php echo root.admin; ?>/bookings" class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="material-symbols-outlined text-blue-600 mr-2 text-lg">library_books</i>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo T::bookings; ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo T::manage . ' ' . T::bookings; ?></p>
                        </div>
                    </a>

                    <a href="<?php echo root.admin; ?>/users" class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="material-symbols-outlined text-blue-600 mr-2 text-lg">group</i>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo T::users; ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo T::manage . ' ' . T::users; ?></p>
                        </div>
                    </a>

                    <?php if ($flights > 0): ?>
                    <a href="<?php echo root.admin; ?>/flights" class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="material-symbols-outlined text-blue-600 mr-2 text-lg">flight_takeoff</i>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo T::flights; ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo T::manage . ' ' . T::flights; ?></p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($stays > 0): ?>
                    <a href="<?php echo root.admin; ?>/stays" class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="material-symbols-outlined text-blue-600 mr-2 text-lg">hotel</i>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo T::stays; ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo T::manage . ' ' . T::stays; ?></p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($tours > 0): ?>
                    <a href="<?php echo root.admin; ?>/tours" class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="material-symbols-outlined text-blue-600 mr-2 text-lg">tour</i>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo T::tours; ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo T::manage . ' ' . T::tours; ?></p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($cars > 0): ?>
                    <a href="<?php echo root.admin; ?>/cars" class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="material-symbols-outlined text-blue-600 mr-2 text-lg">directions_car</i>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo T::cars; ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo T::manage . ' ' . T::cars; ?></p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <a href="<?php echo root.admin; ?>/cms/pages" class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="material-symbols-outlined text-blue-600 mr-2 text-lg">article</i>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo T::pages; ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo T::manage . ' ' . T::pages; ?></p>
                        </div>
                    </a>

                    <a href="<?php echo root.admin; ?>/blogs" class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="material-symbols-outlined text-blue-600 mr-2 text-lg">newspaper</i>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo T::blogs; ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo T::manage . ' ' . T::blogs; ?></p>
                        </div>
                    </a>

                    <a href="<?php echo root.admin; ?>/finance" class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="material-symbols-outlined text-blue-600 mr-2 text-lg">finance</i>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo T::finance; ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo T::transactions; ?></p>
                        </div>
                    </a>

                    <a href="<?php echo root.admin; ?>/settings" class="flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="material-symbols-outlined text-blue-600 mr-2 text-lg">settings</i>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo T::settings; ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo T::system_info; ?></p>
                        </div>
                    </a>
                </div>
            </div>
        </div>

    </div>

    <!-- Bookings Performance -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <!-- This Month Performance -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">calendar_today</span>
                    <h3><?php echo T::this_month; ?> <?php echo T::bookings; ?> <?php echo T::performance; ?></h3>
                </div>
            </div>
            <div class="card-body">
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm text-gray-600 dark:text-gray-400"><?php echo date('F Y'); ?></span>
                        <span class="text-xs text-gray-500"><?php echo date('M 1'); ?> - <?php echo date('M d'); ?></span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg border border-blue-100 dark:border-blue-800">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="material-symbols-outlined text-blue-600 text-lg">event</i>
                                <span class="text-xs text-gray-600 dark:text-gray-400"><?php echo T::bookings; ?></span>
                            </div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($thisMonthBookings); ?></div>
                        </div>
                        <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg border border-green-100 dark:border-green-800">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="material-symbols-outlined text-green-600 text-lg">payments</i>
                                <span class="text-xs text-gray-600 dark:text-gray-400"><?php echo T::revenue; ?></span>
                            </div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $currencySymbol . ' ' . number_format($thisMonthRevenue, 2); ?></div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($thisMonthModuleBookings)): ?>
                    <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-3"><?php echo T::by_module; ?></div>
                        <div class="space-y-2">
                            <?php foreach ($thisMonthModuleBookings as $type => $data): ?>
                                <a href="<?php echo root.admin; ?>/bookings/<?php echo urlencode($type); ?>" class="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-800 rounded hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors cursor-pointer group">
                                    <div class="flex items-center gap-2">
                                        <i class="material-symbols-outlined text-blue-600 text-base"><?php echo $data['icon']; ?></i>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300 group-hover:text-blue-600"><?php echo $data['name']; ?></span>
                                    </div>
                                    <div class="text-right leading-tight">
                                        <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                            <?php echo number_format($data['count']); ?><span class="text-[11px] font-normal text-gray-400 ml-0.5"><?php echo T::bookings; ?></span>
                                        </div>
                                        <?php if (!empty($data['revenue'])): ?>
                                            <div class="text-xs font-semibold text-green-600 dark:text-green-400">
                                                <?php
                                                $revParts = [];
                                                foreach ($data['revenue'] as $revCur => $revAmt) {
                                                    $revParts[] = htmlspecialchars($revCur) . ' ' . number_format($revAmt, 2);
                                                }
                                                echo implode(' · ', $revParts);
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Last Month Performance -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">calendar_month</span>
                    <h3><?php echo T::last_month; ?> <?php echo T::bookings; ?> <?php echo T::performance; ?></h3>
                </div>
            </div>
            <div class="card-body">
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm text-gray-600 dark:text-gray-400"><?php echo date('F Y', strtotime('last month')); ?></span>
                        <span class="text-xs text-gray-500"><?php echo date('M 1', strtotime('first day of last month')); ?> - <?php echo date('M t', strtotime('last day of last month')); ?></span>
                    </div>
                    <div class="grid gird-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-purple-50 dark:bg-purple-900/20 p-4 rounded-lg border border-purple-100 dark:border-purple-800">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="material-symbols-outlined text-purple-600 text-lg">event</i>
                                <span class="text-xs text-gray-600 dark:text-gray-400"><?php echo T::bookings; ?></span>
                            </div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($lastMonthBookings); ?></div>
                        </div>
                        <div class="bg-emerald-50 dark:bg-emerald-900/20 p-4 rounded-lg border border-emerald-100 dark:border-emerald-800">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="material-symbols-outlined text-emerald-600 text-lg">payments</i>
                                <span class="text-xs text-gray-600 dark:text-gray-400"><?php echo T::revenue; ?></span>
                            </div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $currencySymbol . ' ' . number_format($lastMonthRevenue, 2); ?></div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($lastMonthModuleBookings)): ?>
                    <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-3"><?php echo T::by_module; ?></div>
                        <div class="space-y-2">
                            <?php foreach ($lastMonthModuleBookings as $type => $data): ?>
                                <a href="<?php echo root.admin; ?>/bookings/<?php echo urlencode($type); ?>" class="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-800 rounded hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors cursor-pointer group">
                                    <div class="flex items-center gap-2">
                                        <i class="material-symbols-outlined text-blue-600 text-base"><?php echo $data['icon']; ?></i>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300 group-hover:text-blue-600"><?php echo $data['name']; ?></span>
                                    </div>
                                    <div class="text-right leading-tight">
                                        <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                            <?php echo number_format($data['count']); ?><span class="text-[11px] font-normal text-gray-400 ml-0.5"><?php echo T::bookings; ?></span>
                                        </div>
                                        <?php if (!empty($data['revenue'])): ?>
                                            <div class="text-xs font-semibold text-green-600 dark:text-green-400">
                                                <?php
                                                $revParts = [];
                                                foreach ($data['revenue'] as $revCur => $revAmt) {
                                                    $revParts[] = htmlspecialchars($revCur) . ' ' . number_format($revAmt, 2);
                                                }
                                                echo implode(' · ', $revParts);
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Revenue Analytics Chart (Full Width) -->
    <div class="card p-0 mb-4">
        <div class="card-header">
            <div>
                <span class="card-header-icon">account_balance</span>
                <h3><?php echo T::revenue . ' ' . T::analytics; ?> - <?php echo T::last; ?> 30 <?php echo T::days; ?></h3>
            </div>
        </div>
        <div class="card-body">
            <div class="overflow-x-auto overflow-y-hidden">
                <div class="h-96" style="min-width: 800px;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Bookings Table -->
    <div class="card p-0">
        <div class="card-header">
            <div>
                <span class="card-header-icon">schedule</span>
                <h3><?php echo T::recent . ' ' . T::bookings; ?></h3>
            </div>
            <a href="<?php echo root.admin; ?>/bookings" class="text-blue-600 text-sm font-medium hover:text-blue-700"><?php echo T::view_all; ?></a>
        </div>

        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo T::invoice; ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo T::module; ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Booking</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo T::price; ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo T::customer; ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">PNR</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo T::date; ?></th>
                        </tr>
                    </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (!empty($recentBookings)): ?>
                        <?php foreach ($recentBookings as $booking): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <a href="<?php echo root; ?>invoice/<?php echo $booking['module_type']; ?>/<?php echo $booking['invoice_id']; ?>" target="_blank" class="text-blue-600 hover:underline text-sm">
                                        <?php echo $booking['invoice_id'] ?: 'N/A'; ?> <i class="material-symbols-outlined text-xs">north_east</i>
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="capitalize">
                                        <strong><?php echo htmlspecialchars($booking['module'] ?: ''); ?></strong>
                                    </div>
                                    <div class="capitalize text-xs text-gray-500"><?php echo htmlspecialchars($booking['module_type'] ?: ''); ?></div>
                                </td>
                                <td class="px-4 py-3">
                                    <?php
                                    $bookingStatusClass = 'bg-slate-100 text-slate-800 border-slate-300';
                                    if ($booking['booking_status'] === 'confirmed') {
                                        $bookingStatusClass = 'bg-green-100 text-green-800 border-green-300';
                                    } elseif ($booking['booking_status'] === 'cancelled') {
                                        $bookingStatusClass = 'bg-red-100 text-red-800 border-red-300';
                                    }
                                    ?>
                                    <span class="inline-block px-2 py-1 rounded <?php echo $bookingStatusClass; ?> uppercase text-xs font-semibold border w-full text-center"><?php echo htmlspecialchars($booking['booking_status']); ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <?php
                                    $paymentStatusClass = 'bg-slate-100 text-slate-800 border-slate-300';
                                    if ($booking['payment_status'] === 'paid') {
                                        $paymentStatusClass = 'bg-green-100 text-green-800 border-green-300';
                                    } elseif ($booking['payment_status'] === 'failed') {
                                        $paymentStatusClass = 'bg-red-100 text-red-800 border-red-300';
                                    }
                                    ?>
                                    <span class="inline-block px-2 py-1 rounded <?php echo $paymentStatusClass; ?> uppercase text-xs font-semibold border w-full text-center"><?php echo htmlspecialchars($booking['payment_status']); ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm flex flex-col sm:block">
                                        <!-- PRICE -->
                                        <strong class="flex flex-row gap-1 border-b sm:border-b ">
                                            <span> <?php echo $currencySymbol; ?></span>
                                        
                                            <span><?php echo number_format($booking['price_markup'] ?: 0, 2); ?></span>
                                        </strong>

                                        <!-- EARNING -->
                                        <div class="text-xs flex flex-col md:flex-row gap-1 text-slate-700 mt-1 sm:mt-0">
                                            Earning
                                            <strong class="text-green-600 flex flex-row gap-1">
                                                <?php echo $currencySymbol; ?>
                                                <?php echo number_format($booking['commission'] ?: 0, 2); ?>
                                            </strong>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <div>
                                        <b class="capitalize block text-sm"><?php echo htmlspecialchars($booking['first_name']); ?> <?php echo htmlspecialchars($booking['last_name']); ?></b>
                                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($booking['email']); ?></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if ($booking['pnr']): ?>
                                        <div x-data="{ copied: false }" class="inline-block">
                                            <button @click="navigator.clipboard.writeText('<?php echo htmlspecialchars($booking['pnr']); ?>'); copied = true; setTimeout(() => copied = false, 1500)"
                                                style="min-width: 100px;"
                                                class="relative inline-flex items-center justify-start gap-1 px-2 py-1 rounded border uppercase text-xs font-semibold transition-all cursor-pointer"
                                                :class="copied ? 'bg-green-100 text-green-800 border-green-300' : 'bg-slate-100 text-slate-800 border-slate-300 hover:bg-slate-200'">
                                                <span class="material-symbols-outlined text-sm">confirmation_number</span>
                                                <span class="font-mono" :class="copied ? 'invisible' : ''">&#8203;<?php echo htmlspecialchars($booking['pnr']); ?></span>
                                                <span x-show="copied" class="absolute inset-0 flex items-center justify-start px-2 gap-1" x-transition>
                                                    <span class="material-symbols-outlined text-sm">confirmation_number</span>COPIED!
                                                </span>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400 italic">No PNR</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    <?php echo date('M d, H:i', strtotime($booking['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                <i class="material-symbols-outlined text-4xl mb-2 opacity-50">book</i>
                                <p>No bookings found</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Chart data from PHP
const chartData = <?php echo $chartDataJson; ?>;
const tickSettings = {
    autoSkip: false,
    maxRotation: 45,
    minRotation: 45
};

// Toggle individual module type sections
function toggleModuleType(moduleType) {
    const moduleSection = document.getElementById('modules-' + moduleType);
    const arrow = document.getElementById('arrow-' + moduleType);

    // Check if current section is already visible
    const isCurrentlyVisible = !moduleSection.classList.contains('hidden');

    // Hide all module sections and reset all arrows
    document.querySelectorAll('.module-type-section').forEach(section => {
        section.classList.add('hidden');
    });
    document.querySelectorAll('.module-arrow').forEach(otherArrow => {
        otherArrow.style.transform = 'rotate(0deg)';
    });

    // If the current section was not visible, show it
    if (!isCurrentlyVisible) {
        moduleSection.classList.remove('hidden');
        arrow.style.transform = 'rotate(180deg)';
    }
}

// Handle module toggle switches
document.addEventListener('DOMContentLoaded', function() {
    // Module toggles
    document.querySelectorAll('.module-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            const moduleId = this.dataset.module;
            const status = this.checked ? '1' : '0';

            // Send AJAX request to update module status
            const params = new URLSearchParams();
            params.append('ajax_update', '1');
            params.append('field', 'status');
            params.append('value', status);
            params.append('module_id', moduleId);

            fetch('<?php echo root.admin; ?>/settings/modules/ajax-update', {
                method: 'POST',
                body: params,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    // Revert toggle if failed
                    this.checked = !this.checked;
                    alert('Failed to update module status: ' + (data.message || 'Unknown error'));
                } else {
                    // Find the main module container
                    const moduleContainer = this.closest('.bg-white.border.rounded-lg');
                    if (moduleContainer) {
                        // Find the status span (it's inside a p.text-gray-500)
                        const statusSpan = moduleContainer.querySelector('p.text-gray-500 span');
                        if (statusSpan) {
                            statusSpan.textContent = status === '1' ? '<?php echo T::active; ?>' : '<?php echo T::inactive; ?>';
                            statusSpan.className = status === '1' ? 'text-green-600' : 'text-red-600';
                        }

                        // Show a brief success indicator
                        const successIndicator = document.createElement('div');
                        successIndicator.className = 'absolute top-0 right-0 bg-green-500 text-white text-xs px-2 py-1 rounded shadow-lg z-10';
                        successIndicator.textContent = '<?php echo T::updated; ?>!';
                        moduleContainer.style.position = 'relative';
                        moduleContainer.appendChild(successIndicator);

                        setTimeout(() => {
                            successIndicator.remove();
                        }, 2000);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                this.checked = !this.checked;
                alert('Error updating module status');
            });
        });
    });

    // Initialize charts
    const bookingCtx = document.getElementById('bookingChart');
    if (bookingCtx) {
        new Chart(bookingCtx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Total Bookings',
                    data: chartData.dailyBookings,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Confirmed',
                    data: chartData.dailyConfirmed,
                    borderColor: 'rgb(34, 197, 94)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    tension: 0.4,
                    fill: false
                }, {
                    label: 'Pending',
                    data: chartData.dailyPending,
                    borderColor: 'rgb(234, 179, 8)',
                    backgroundColor: 'rgba(234, 179, 8, 0.1)',
                    tension: 0.4,
                    fill: false
                }, {
                    label: 'Cancelled',
                    data: chartData.dailyCancelled,
                    borderColor: 'rgb(239, 68, 68)',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    tension: 0.4,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            footer: function(context) {
                                let sum = 0;
                                context.forEach(function(tooltipItem) {
                                    sum += tooltipItem.parsed.y;
                                });
                                return 'Total: ' + sum;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: tickSettings
                    }
                }
            }
        });
    }

    const revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx) {
        // Create gradients for better visual appeal
        const revenueGradient = revenueCtx.getContext('2d').createLinearGradient(0, 0, 0, 400);
        revenueGradient.addColorStop(0, 'rgba(59, 130, 246, 0.9)');
        revenueGradient.addColorStop(1, 'rgba(59, 130, 246, 0.3)');

        const paidGradient = revenueCtx.getContext('2d').createLinearGradient(0, 0, 0, 400);
        paidGradient.addColorStop(0, 'rgba(34, 197, 94, 0.8)');
        paidGradient.addColorStop(1, 'rgba(34, 197, 94, 0.2)');

        const unpaidGradient = revenueCtx.getContext('2d').createLinearGradient(0, 0, 0, 400);
        unpaidGradient.addColorStop(0, 'rgba(239, 68, 68, 0.8)');
        unpaidGradient.addColorStop(1, 'rgba(239, 68, 68, 0.2)');

        new Chart(revenueCtx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: '<?php echo T::revenue; ?> (<?php echo T::commission; ?>)',
                    data: chartData.dailyRevenue,
                    backgroundColor: revenueGradient,
                    borderColor: 'rgb(59, 130, 246)',
                    borderWidth: 2,
                    borderRadius: 6,
                    borderSkipped: false,
                }, {
                    label: '<?php echo T::paid; ?> <?php echo T::bookings; ?>',
                    data: chartData.dailyPaid,
                    backgroundColor: paidGradient,
                    borderColor: 'rgb(34, 197, 94)',
                    borderWidth: 2,
                    borderRadius: 6,
                    borderSkipped: false,
                    yAxisID: 'y1',
                }, {
                    label: '<?php echo T::unpaid; ?> <?php echo T::bookings; ?>',
                    data: chartData.dailyUnpaid,
                    backgroundColor: unpaidGradient,
                    borderColor: 'rgb(239, 68, 68)',
                    borderWidth: 2,
                    borderRadius: 6,
                    borderSkipped: false,
                    yAxisID: 'y1',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 12,
                                weight: '500'
                            }
                        }
                    },
                    title: {
                        display: true,
                        text: chartData.periodLabel + ' - <?php echo T::revenue . " & " . T::bookings . " " . T::analytics; ?>',
                        font: {
                            size: 16,
                            weight: 'bold'
                        },
                        padding: {
                            top: 10,
                            bottom: 20
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        cornerRadius: 8,
                        titleFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.datasetIndex === 0) {
                                    label += '$' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                } else {
                                    label += context.parsed.y + ' <?php echo strtolower(T::bookings); ?>';
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: '<?php echo T::revenue; ?> ($)',
                            font: {
                                size: 13,
                                weight: 'bold'
                            },
                            padding: {top: 0, bottom: 10}
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            },
                            font: {
                                size: 11
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        grid: {
                            drawOnChartArea: false,
                        },
                        title: {
                            display: true,
                            text: '<?php echo T::bookings . " " . T::count; ?>',
                            font: {
                                size: 13,
                                weight: 'bold'
                            },
                            padding: {top: 0, bottom: 10}
                        },
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 11
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            ...tickSettings,
                            font: {
                                size: 10,
                                weight: '500'
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>