<?php
// ============================================================================
// USER BOOKINGS PAGE - MY BOOKINGS MANAGEMENT
// ============================================================================
// FILE: app/views/auth/bookings.php
// PURPOSE: DISPLAY ALL USER BOOKINGS WITH FILTERING & STATISTICS
// ACCESS: AUTHENTICATED USERS ONLY (CHECKED IN usersRoutes.php)
//
// DATA SOURCE: usersRoutes.php /bookings ROUTE
// DATABASE: bookings TABLE (FILTERED BY user_id)
//
// FEATURES:
// - MODULE FILTERING: ALL, STAYS, FLIGHTS, TOURS, CARS, BUS, UMRAH, FERRIES, ESIM, VISA
// - STATISTICS CARDS: TOTAL, CONFIRMED, PENDING, TOTAL SPENT
// - CRUD TABLE DISPLAY: INVOICE, MODULE TYPE, STATUS, PAYMENT, PRICE, DATE
// - RESPONSIVE LAYOUT: SIDEBAR + MAIN CONTENT
// - ALPINE.JS: SIDEBAR COLLAPSE STATE MANAGEMENT
//
// FILTERING LOGIC:
// - URL PARAMETER: ?filter=stays|flights|tours|cars|bus|umrah|ferries|esim|visa|all
// - DATABASE COLUMN: module_type (NOT module)
// - WHERE CLAUSE: ['user_id' => session, 'module_type' => filter]
//
// REQUIRED VARIABLES (SET IN usersRoutes.php):
// - $currentFilter : ACTIVE FILTER (stays/flights/tours/cars/bus/umrah/ferries/esim/visa/all)
// - $bookings      : ARRAY OF USER BOOKINGS FROM DATABASE
// - $stats         : ARRAY ['total', 'confirmed', 'pending', 'total_amount']
// - $db            : MEDOO DATABASE INSTANCE
//
// CRUD TABLE COLUMNS:
// - invoice_id     : CLICKABLE LINK TO INVOICE PAGE
// - module_type    : BOOKING CATEGORY (STAYS, FLIGHTS, ETC.)
// - booking_status : CONFIRMED, PENDING, CANCELLED
// - payment_status : PAID, UNPAID, FAILED
// - price_markup   : FINAL PRICE WITH MARKUP
// - created_at     : BOOKING DATE (FORMATTED: M d, Y)
//
// SECURITY: DIES IF $SECURE FLAG NOT SET
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// INITIALIZE PAGE VARIABLES
// ============================================================================
$currentFilter = $_GET['filter'] ?? 'all';  // GET FILTER FROM URL OR DEFAULT TO ALL
$bookings = $bookings ?? [];                 // BOOKING ARRAY FROM ROUTE
$stats = $stats ?? [                         // STATISTICS WITH DEFAULTS
    'total' => 0,
    'confirmed' => 0,
    'pending' => 0,
    'total_amount' => 0.00
];

// ============================================================================
// GET ENABLED MODULES FOR FILTER TABS
// ONLY SHOWS FILTERS FOR MODULES THAT ARE ENABLED IN SETTINGS
// ============================================================================
$enabledModules = $db->select('modules', 'type', ['status' => 1]);
$enabledModulesMap = array_flip($enabledModules);

// ============================================================================
// CALCULATE STATUS COUNTS FOR FILTERS
// ============================================================================
// Base condition - always filter by user_id
$baseCondition = ['user_id' => $_SESSION['user_id']];

// Add module filter if not 'all'
if ($currentFilter !== 'all') {
    $baseCondition['module_type'] = $currentFilter;
}

// Fetch all bookings for this user (with module filter if applicable)
$allUserBookings = $db->select('bookings', ['booking_status', 'payment_status'], $baseCondition);

// Count booking statuses
$bookingStatusCounts = [
    'confirmed' => 0,
    'pending' => 0,
    'cancelled' => 0
];

// Count payment statuses
$paymentStatusCounts = [
    'paid' => 0,
    'unpaid' => 0,
    'refunded' => 0
];

foreach ($allUserBookings as $booking) {
    // Count booking status
    if (isset($bookingStatusCounts[$booking['booking_status']])) {
        $bookingStatusCounts[$booking['booking_status']]++;
    }

    // Count payment status
    if (isset($paymentStatusCounts[$booking['payment_status']])) {
        $paymentStatusCounts[$booking['payment_status']]++;
    }
}

// Get current status filters from URL
$selectedBookingStatus = $_GET['booking_status'] ?? '';
$selectedPaymentStatus = $_GET['payment_status'] ?? '';
?>

<div class="bg" x-data="{
    sidebarCollapsed: window.innerWidth < 1024
}">
   <div class="flex gap-5 container mb-8">

      <div class="flex-shrink-0">
         <?php include views."auth/sidebar.php"; ?>
      </div>

      <div class="flex-1 min-w-0 pt-8 bg-white rounded-[8px] ml-0">

         <!-- Bookings Header -->
         <div class="flex items-center justify-between mb-6">
            <div>
               <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-3">
                     <a href="<?=root?>dashboard" class="hover:text-gray-700">Dashboard</a>
                     <span class="material-symbols-outlined !text-sm">chevron_right</span>
                     <span class="text-gray-900"><?= T::my_bookings ?></span>
               </nav>
         <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
               <!-- Mobile Menu Toggle -->
               <button @click="sidebarCollapsed = false" class="lg:hidden flex items-center justify-center w-12 h-12 bg-blue-50 rounded-lg text-blue-600 hover:bg-blue-100 transition-colors">
                  <span class="material-symbols-outlined text-2xl">menu</span>
               </button>

               <!-- Bookings Icon -->
               <div class="hidden lg:flex items-center justify-center w-12 h-12 bg-blue-100 rounded-lg">
                  <span class="material-symbols-outlined text-2xl text-blue-600">calendar_month</span>
               </div>
               <div>
                  <h1 class="font-bold text-2xl text-gray-900"><?= T::my_bookings ?></h1>
                  <p class="text-gray-600 text-sm"><?= T::manage_your_bookings ?></p>
               </div>
            </div>
         </div>
            </div>
         </div>

         <!-- ============================================================ -->
         <!-- STATISTICS CARDS ROW -->
         <!-- SHOWS: TOTAL, CONFIRMED, PENDING BOOKINGS & TOTAL SPENT -->
         <!-- CALCULATED IN: usersRoutes.php BEFORE RENDERING PAGE -->
         <!-- ============================================================ -->
         <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-3">
            <!-- ===== TOTAL BOOKINGS CARD ===== -->
            <div class="card col-span-2 md:col-span-1">
               <div class="card-content">
                  <div class="flex items-center justify-between mb-2">
                     <span class="material-symbols-outlined text-xl text-blue-600">book_online</span>
                     <p class="text-xs font-medium text-gray-600">Total Bookings</p>
                  </div>
                  <p class="text-3xl font-bold text-gray-900"><?= $stats['total'] ?></p>
               </div>
            </div>

            <!-- ===== BOOKING STATUS FILTERS ===== -->
            <div class="col-span-2 card">
               <div class="card-content">
                  <div class="flex items-center gap-2 mb-3">
                     <span class="material-symbols-outlined text-lg text-gray-700">event_note</span>
                     <p class="text-xs font-semibold text-gray-700"><?= T::booking_status ?></p>
                  </div>
                  <div class="grid grid-cols-4 gap-2">
                     <?php
                     // Build base URL for booking status filters
                     $baseUrl = root . 'bookings';
                     $urlParams = [];
                     if ($currentFilter !== 'all') {
                        $urlParams[] = 'filter=' . $currentFilter;
                     }
                     if ($selectedPaymentStatus) {
                        $urlParams[] = 'payment_status=' . $selectedPaymentStatus;
                     }
                     $baseUrlWithParams = $baseUrl . ($urlParams ? '?' . implode('&', $urlParams) : '');
                     ?>

                     <a href="<?= $baseUrlWithParams ?>"
                        class="flex flex-col items-center justify-center py-2 px-2 rounded-lg border transition-all hover:shadow-md
                        <?= empty($selectedBookingStatus) ? 'bg-gray-50 border-gray-300 shadow-sm' : 'bg-white border-gray-200 hover:bg-gray-50' ?>">
                        <span class="text-xl font-bold text-gray-900"><?= array_sum($bookingStatusCounts) ?></span>
                        <span class="text-[10px] text-gray-600 font-medium mt-0.5">All</span>
                     </a>

                     <a href="<?= $baseUrlWithParams . ($urlParams ? '&' : '?') ?>booking_status=confirmed"
                        class="flex flex-col items-center justify-center py-2 px-2 rounded-lg border transition-all hover:shadow-md
                        <?= $selectedBookingStatus === 'confirmed' ? 'bg-gray-900 border-gray-900 shadow-md' : 'bg-white border-gray-200 hover:bg-gray-50' ?>">
                        <span class="text-xl font-bold <?= $selectedBookingStatus === 'confirmed' ? 'text-white' : 'text-gray-900' ?>"><?= $bookingStatusCounts['confirmed'] ?></span>
                        <span class="text-[10px] font-medium mt-0.5 <?= $selectedBookingStatus === 'confirmed' ? 'text-gray-300' : 'text-gray-600' ?>">Confirmed</span>
                     </a>

                     <a href="<?= $baseUrlWithParams . ($urlParams ? '&' : '?') ?>booking_status=pending"
                        class="flex flex-col items-center justify-center py-2 px-2 rounded-lg border transition-all hover:shadow-md
                        <?= $selectedBookingStatus === 'pending' ? 'bg-gray-900 border-gray-900 shadow-md' : 'bg-white border-gray-200 hover:bg-gray-50' ?>">
                        <span class="text-xl font-bold <?= $selectedBookingStatus === 'pending' ? 'text-white' : 'text-gray-900' ?>"><?= $bookingStatusCounts['pending'] ?></span>
                        <span class="text-[10px] font-medium mt-0.5 <?= $selectedBookingStatus === 'pending' ? 'text-gray-300' : 'text-gray-600' ?>">Pending</span>
                     </a>

                     <a href="<?= $baseUrlWithParams . ($urlParams ? '&' : '?') ?>booking_status=cancelled"
                        class="flex flex-col items-center justify-center py-2 px-2 rounded-lg border transition-all hover:shadow-md
                        <?= $selectedBookingStatus === 'cancelled' ? 'bg-gray-900 border-gray-900 shadow-md' : 'bg-white border-gray-200 hover:bg-gray-50' ?>">
                        <span class="text-xl font-bold <?= $selectedBookingStatus === 'cancelled' ? 'text-white' : 'text-gray-900' ?>"><?= $bookingStatusCounts['cancelled'] ?></span>
                        <span class="text-[10px] font-medium mt-0.5 <?= $selectedBookingStatus === 'cancelled' ? 'text-gray-300' : 'text-gray-600' ?>">Cancelled</span>
                     </a>
                  </div>
               </div>
            </div>

            <!-- ===== PAYMENT STATUS FILTERS ===== -->
            <div class="col-span-2 card">
               <div class="card-content">
                  <div class="flex items-center gap-2 mb-3">
                     <span class="material-symbols-outlined text-lg text-gray-700">payments</span>
                     <p class="text-xs font-semibold text-gray-700"><?= T::payment_status ?></p>
                  </div>
                  <div class="grid grid-cols-4 gap-2">
                     <?php
                     // Build base URL for payment status filters
                     $urlParams2 = [];
                     if ($currentFilter !== 'all') {
                        $urlParams2[] = 'filter=' . $currentFilter;
                     }
                     if ($selectedBookingStatus) {
                        $urlParams2[] = 'booking_status=' . $selectedBookingStatus;
                     }
                     $baseUrlWithParams2 = $baseUrl . ($urlParams2 ? '?' . implode('&', $urlParams2) : '');
                     ?>

                     <a href="<?= $baseUrlWithParams2 ?>"
                        class="flex flex-col items-center justify-center py-2 px-2 rounded-lg border transition-all hover:shadow-md
                        <?= empty($selectedPaymentStatus) ? 'bg-gray-50 border-gray-300 shadow-sm' : 'bg-white border-gray-200 hover:bg-gray-50' ?>">
                        <span class="text-xl font-bold text-gray-900"><?= array_sum($paymentStatusCounts) ?></span>
                        <span class="text-[10px] text-gray-600 font-medium mt-0.5">All</span>
                     </a>

                     <a href="<?= $baseUrlWithParams2 . ($urlParams2 ? '&' : '?') ?>payment_status=paid"
                        class="flex flex-col items-center justify-center py-2 px-2 rounded-lg border transition-all hover:shadow-md
                        <?= $selectedPaymentStatus === 'paid' ? 'bg-gray-900 border-gray-900 shadow-md' : 'bg-white border-gray-200 hover:bg-gray-50' ?>">
                        <span class="text-xl font-bold <?= $selectedPaymentStatus === 'paid' ? 'text-white' : 'text-gray-900' ?>"><?= $paymentStatusCounts['paid'] ?></span>
                        <span class="text-[10px] font-medium mt-0.5 <?= $selectedPaymentStatus === 'paid' ? 'text-gray-300' : 'text-gray-600' ?>">Paid</span>
                     </a>

                     <a href="<?= $baseUrlWithParams2 . ($urlParams2 ? '&' : '?') ?>payment_status=unpaid"
                        class="flex flex-col items-center justify-center py-2 px-2 rounded-lg border transition-all hover:shadow-md
                        <?= $selectedPaymentStatus === 'unpaid' ? 'bg-gray-900 border-gray-900 shadow-md' : 'bg-white border-gray-200 hover:bg-gray-50' ?>">
                        <span class="text-xl font-bold <?= $selectedPaymentStatus === 'unpaid' ? 'text-white' : 'text-gray-900' ?>"><?= $paymentStatusCounts['unpaid'] ?></span>
                        <span class="text-[10px] font-medium mt-0.5 <?= $selectedPaymentStatus === 'unpaid' ? 'text-gray-300' : 'text-gray-600' ?>">Unpaid</span>
                     </a>

                     <a href="<?= $baseUrlWithParams2 . ($urlParams2 ? '&' : '?') ?>payment_status=refunded"
                        class="flex flex-col items-center justify-center py-2 px-2 rounded-lg border transition-all hover:shadow-md
                        <?= $selectedPaymentStatus === 'refunded' ? 'bg-gray-900 border-gray-900 shadow-md' : 'bg-white border-gray-200 hover:bg-gray-50' ?>">
                        <span class="text-xl font-bold <?= $selectedPaymentStatus === 'refunded' ? 'text-white' : 'text-gray-900' ?>"><?= $paymentStatusCounts['refunded'] ?></span>
                        <span class="text-[10px] font-medium mt-0.5 <?= $selectedPaymentStatus === 'refunded' ? 'text-gray-300' : 'text-gray-600' ?>">Refunded</span>
                     </a>
                  </div>
               </div>
            </div>


         </div>

         <!-- ============================================================ -->
         <!-- MODULE FILTER TABS -->
         <!-- FILTERS BY: module_type COLUMN IN DATABASE -->
         <!-- ONLY SHOWS: ENABLED MODULES FROM modules TABLE -->
         <!-- ACTIVE STATE: BLUE BACKGROUND FOR CURRENT FILTER -->
         <!-- ============================================================ -->
         <div class="tabs-container mb-3">
            <div class="tabs-list !flex-nowrap !justify-start !sm:justify-center sm:flex-wrap !bg-gray-100 w-full overflow-x-auto max-w-full scrollbar-">
               <!-- Always show "All Bookings" tab if any module is enabled -->
               <?php if (!empty($enabledModules)): ?>
               <a href="<?=root?>bookings" class="tabs-trigger <?= $currentFilter=='all' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">dashboard</span>
                  <?= T::all_bookings ?>
               </a>
               <?php endif; ?>

               <!-- Only show tabs for enabled modules -->
               <?php if (isset($enabledModulesMap['stays'])): ?>
               <a href="<?=root?>bookings?filter=stays" class="tabs-trigger <?= $currentFilter=='stays' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">hotel</span>
                  <?= T::stays ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['flights'])): ?>
               <a href="<?=root?>bookings?filter=flights" class="tabs-trigger <?= $currentFilter=='flights' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined !text-lg">flight_takeoff</span>
                  <?= T::flights ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['tours'])): ?>
               <a href="<?=root?>bookings?filter=tours" class="tabs-trigger <?= $currentFilter=='tours' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">tour</span>
                  <?= T::tours ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['cars'])): ?>
               <a href="<?=root?>bookings?filter=cars" class="tabs-trigger <?= $currentFilter=='cars' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">directions_car</span>
                  <?= T::cars ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['bus'])): ?>
               <a href="<?=root?>bookings?filter=bus" class="tabs-trigger <?= $currentFilter=='bus' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">directions_bus</span>
                  <?= T::bus ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['umrah'])): ?>
               <a href="<?=root?>bookings?filter=umrah" class="tabs-trigger <?= $currentFilter=='umrah' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">mosque</span>
                  <?= T::umrah ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['ferries'])): ?>
               <a href="<?=root?>bookings?filter=ferries" class="tabs-trigger <?= $currentFilter=='ferries' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">directions_boat</span>
                  <?= T::ferries ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['esim'])): ?>
               <a href="<?=root?>bookings?filter=esim" class="tabs-trigger <?= $currentFilter=='esim' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">sim_card</span>
                  <?= T::esim ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['visa'])): ?>
               <a href="<?=root?>bookings?filter=visa" class="tabs-trigger <?= $currentFilter=='visa' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">description</span>
                  <?= T::visa ?>
               </a>
               <?php endif; ?>

               <?php if (function_exists('aiTripIsEnabled') && aiTripIsEnabled($GLOBALS['db'] ?? ($db ?? null))): ?>
               <a href="<?=root?>bookings?filter=ai_trip" class="tabs-trigger <?= $currentFilter=='ai_trip' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">auto_awesome</span>
                  AI Trip
               </a>
               <?php endif; ?>
            </div>
         </div>

         <!-- ============================================================ -->
         <!-- BOOKINGS DATA TABLE - CRUD COMPONENT -->
         <!-- DATABASE: bookings TABLE -->
         <!-- FILTER: user_id + module_type + booking_status + payment_status -->
         <!-- COLUMNS: INVOICE, MODULE, STATUS, PAYMENT, PRICE, DATE -->
         <!-- ACTIONS: VIEW ONLY (NO ADD/EDIT/DELETE) -->
         <!-- ============================================================ -->
         <?php
         // ============================================================================
         // BUILD WHERE CONDITION FOR DATABASE QUERY
         // ALWAYS FILTER BY: user_id (SHOW ONLY THIS USER'S BOOKINGS)
         // CONDITIONALLY ADD: module_type, booking_status, payment_status
         // ============================================================================
         $whereCondition = ['user_id' => $_SESSION['user_id']];

         // Filter by module type if not 'all'
         if ($currentFilter !== 'all') {
            $whereCondition['module_type'] = $currentFilter;
         }

         // Filter by booking status if selected
         if (!empty($selectedBookingStatus) && in_array($selectedBookingStatus, ['confirmed', 'pending', 'cancelled'])) {
            $whereCondition['booking_status'] = $selectedBookingStatus;
         }

         // Filter by payment status if selected
         if (!empty($selectedPaymentStatus) && in_array($selectedPaymentStatus, ['paid', 'unpaid', 'refunded'])) {
            $whereCondition['payment_status'] = $selectedPaymentStatus;
         }

         // Build dynamic title based on active filters
         $titleParts = [];
         if ($currentFilter !== 'all') {
            $titleParts[] = ucfirst($currentFilter);
         }
         if (!empty($selectedBookingStatus)) {
            $titleParts[] = ucfirst($selectedBookingStatus);
         }
         if (!empty($selectedPaymentStatus)) {
            $titleParts[] = ucfirst($selectedPaymentStatus);
         }
         $tableTitle = empty($titleParts) ? 'All Bookings' : implode(' / ', $titleParts) . ' Bookings';

         $isAgentUser = strtolower((string)($dashboardData['user_role'] ?? $_SESSION['user_role'] ?? '')) === 'agent';
         $bookingCols = $isAgentUser
            ? 'invoice_id,module_type,booking_status,payment_status,price_markup,agent_earning,pnr,created_at'
            : 'invoice_id,module_type,booking_status,payment_status,price_markup,pnr,created_at';
         $bookingLabels = [
            'invoice_id' => T::invoice,
            'module_type' => T::module,
            'booking_status' => T::booking_status,
            'payment_status' => T::payment,
            'price_markup' => T::price,
            'pnr' => 'PNR',
            'created_at' => T::date
         ];
         if ($isAgentUser) {
            $bookingLabels['agent_earning'] = T::earning;
         }

         echo crud()->table('bookings')
            ->where($whereCondition)
            ->title($tableTitle)
            ->col($bookingCols)
            ->label($bookingLabels)
            ->order('id', 'DESC')
            ->actions([
               'add' => false,
               'view' => true,
               'edit' => false,
               'delete' => false,
               'status' => false,
               'search' => true,
               'bulk_delete' => false,
            ])
            ->row([
               'module_type' => function ($row) {
                  $mod = strtolower((string)($row['module_type'] ?? ''));
                  $label = $mod === 'ai_trip' ? 'AI Trip' : ucfirst($mod);
                  return '<span class="capitalize">' . htmlspecialchars($label) . '</span>';
               },
               'invoice_id' => '<a href="' . root . 'invoice/{{invoice_id}}" target="_blank" class="text-blue-600 hover:underline">{{invoice_id}} <i class="material-symbols-outlined text-xs">north_east</i></a>',
               'price_markup' => '{{currency_markup}} <strong>{{number_format(price_markup, 2)}}</strong>',
               'agent_earning' => '{{currency_markup}} <strong class="text-emerald-700">{{number_format(agent_earning, 2)}}</strong>',
               'created_at' => function ($row) {
                  return date('M d, Y', strtotime($row['created_at']));
               },
               'payment_status' => function ($row) {
                  $statusClass = 'bg-gray-100 text-gray-800 capitalize border border-gray-300';
                  if ($row['payment_status'] === 'paid') {
                     $statusClass = 'bg-green-100 text-green-800 border-green-300 uppercase text-xs font-semibold border';
                  } elseif ($row['payment_status'] === 'unpaid') {
                     $statusClass = 'bg-slate-100 text-slate-800 border-slate-300 uppercase text-xs font-semibold border';
                  } elseif ($row['payment_status'] === 'failed') {
                     $statusClass = 'bg-red-100 text-red-800 border-red-300 uppercase text-xs font-semibold border';
                  }
                  return '<span class="inline-block px-2 py-1 rounded ' . $statusClass . '">' . htmlspecialchars($row['payment_status']) . '</span>';
               },
               'booking_status' => function ($row) {
                  $statusClass = 'bg-slate-100 text-slate-800 border-slate-300 uppercase text-xs font-semibold border';
                  if ($row['booking_status'] === 'confirmed') {
                     $statusClass = 'bg-green-100 text-green-800 border-green-300 uppercase text-xs font-semibold border';
                  } elseif ($row['booking_status'] === 'pending') {
                     $statusClass = 'bg-slate-100 text-slate-800 border-slate-300 uppercase text-xs font-semibold border';
                  } elseif ($row['booking_status'] === 'cancelled') {
                     $statusClass = 'bg-red-100 text-red-800 border-red-300 uppercase text-xs font-semibold border';
                  }
                  return '<span class="inline-block px-2 py-1 rounded ' . $statusClass . '">' . htmlspecialchars($row['booking_status']) . '</span>';
               },
               'pnr' => function ($row) {
                  if (!$row['pnr']) {
                     return '<span class="text-xs text-slate-400 italic">No PNR</span>';
                  }
                  $pnr = htmlspecialchars($row['pnr']);
                  return '<div x-data="{ copied: false }" class="inline-block">
                     <button @click="navigator.clipboard.writeText(\'' . $pnr . '\'); copied = true; setTimeout(() => copied = false, 1500)" 
                        style="min-width: 120px;" 
                        class="relative inline-flex items-center justify-start gap-1 px-2 py-1 rounded border uppercase text-xs font-semibold transition-all cursor-pointer" 
                        :class="copied ? \'bg-green-100 text-green-800 border-green-300\' : \'bg-slate-100 text-slate-800 border-slate-300 hover:bg-slate-200\'">
                        <span class="material-symbols-outlined text-sm">confirmation_number</span>
                        <span class="font-mono" :class="copied ? \'invisible\' : \'\'">&#8203;' . $pnr . '</span>
                        <span x-show="copied" class="absolute inset-0 flex items-center justify-start px-2 gap-1" x-transition>
                            <span class="material-symbols-outlined text-sm">confirmation_number</span>COPIED!
                        </span>
                     </button>
                  </div>';
               },
            ])
            ->action_urls([
               'view' => root.'invoice/{invoice_id}',
            ])
            ->col_width('module_type', '80px')
            ->col_width('invoice_id', '100px')
            ->col_width('booking_status', '130px')
            ->col_width('payment_status', '120px')
            ->col_width('price_markup', '120px')
            ->col_width('agent_earning', '110px')
            ->col_width('created_at', '120px')
            ->render();
         ?>

      </div>
   </div>
</div>