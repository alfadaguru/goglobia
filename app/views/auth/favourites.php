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
// - MODULE FILTERING: ALL, STAYS, FLIGHTS, TOURS, CARS, VISA
// - STATISTICS CARDS: TOTAL, CONFIRMED, PENDING, TOTAL SPENT
// - CRUD TABLE DISPLAY: INVOICE, MODULE TYPE, STATUS, PAYMENT, PRICE, DATE
// - RESPONSIVE LAYOUT: SIDEBAR + MAIN CONTENT
// - ALPINE.JS: SIDEBAR COLLAPSE STATE MANAGEMENT
//
// FILTERING LOGIC:
// - URL PARAMETER: ?filter=stays|flights|tours|cars|visa|all
// - DATABASE COLUMN: module_type (NOT module)
// - WHERE CLAUSE: ['user_id' => session, 'module_type' => filter]
//
// REQUIRED VARIABLES (SET IN usersRoutes.php):
// - $currentFilter : ACTIVE FILTER (stays/flights/tours/cars/visa/all)
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
$allowedFilters = ['all', 'stays', 'flights', 'tours', 'cars', 'visa'];
$currentFilter = $_GET['filter'] ?? 'all';
if (!in_array($currentFilter, $allowedFilters, true)) {
    $currentFilter = 'all';
}

// ============================================================================
// GET ENABLED MODULES FOR FILTER TABS
// ONLY SHOWS FILTERS FOR MODULES THAT ARE ENABLED IN SETTINGS
// ============================================================================
$enabledModules = $db->select('modules', 'type', ['status' => 1]);
$enabledModulesMap = array_flip($enabledModules);
?>

<div class="bg" x-data="{
    sidebarCollapsed: window.innerWidth < 1024
}">
   <div class="flex lg:gap-3 container">

      <div class="flex-shrink-0">
         <?php include views."auth/sidebar.php"; ?>
      </div>

      <div class="flex-1 min-w-0 py-6 lg:p-6 bg-white rounded-[8px] ml-0">

         <!-- Bookings Header -->
         <div class="flex items-center justify-between mb-6">
            <div>
               <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-3">
                     <a href="<?=root?>dashboard" class="hover:text-gray-700">Dashboard</a>
                     <span class="material-symbols-outlined !text-sm">chevron_right</span>
                     <span class="text-gray-900"><?= T::favorites ?></span>
               </nav>
               <div class="flex items-center gap-4">
                     <button @click="sidebarCollapsed = false" class="lg:hidden flex items-center justify-center w-12 h-12 bg-blue-50 rounded-lg text-blue-600 hover:bg-blue-100 transition-colors">
                        <span class="material-symbols-outlined text-2xl">menu</span>
                     </button>
                     <div class="hidden lg:flex items-center justify-center w-12 h-12 bg-blue-100 rounded-lg">
                        <span class="material-symbols-outlined text-2xl text-blue-600">calendar_month</span>
                     </div>
                     <div>
                        <h1 class="font-bold text-2xl text-gray-900"><?= T::my ?> <?= T::favorites ?></h1>
                        <p class="text-gray-600 text-sm"><?= T::manage ?> <?= T::favorites ?></p>
                     </div>
               </div>
            </div>
         </div>

         <!-- ============================================================ -->
         <!-- STATISTICS CARDS ROW -->
         <!-- SHOWS: TOTAL, CONFIRMED, PENDING BOOKINGS & TOTAL SPENT -->
         <!-- CALCULATED IN: usersRoutes.php BEFORE RENDERING PAGE -->
         <!-- ============================================================ -->

         <!-- ============================================================ -->
         <!-- MODULE FILTER TABS -->
         <!-- FILTERS BY: module_type COLUMN IN DATABASE -->
         <!-- ONLY SHOWS: ENABLED MODULES FROM modules TABLE -->
         <!-- ACTIVE STATE: BLUE BACKGROUND FOR CURRENT FILTER -->
         <!-- ============================================================ -->
         <div class="tabs-container mb-6">
            <div class="tabs-list !flex-nowrap !justify-start !sm:justify-center sm:flex-wrap !bg-gray-100 w-full overflow-x-auto max-w-full">
               <!-- Always show "All Favorites" tab if any module is enabled -->
               <?php if (!empty($enabledModules)): ?>
               <a href="<?=root?>favourites" class="tabs-trigger <?= $currentFilter=='all' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">dashboard</span>
                  <?= T::all ?> <?= T::favorites ?>
               </a>
               <?php endif; ?>

               <!-- Only show tabs for enabled modules -->
               <?php if (isset($enabledModulesMap['stays'])): ?>
               <a href="<?=root?>favourites?filter=stays" class="tabs-trigger <?= $currentFilter=='stays' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">hotel</span>
                  <?= T::stays ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['flights'])): ?>
               <a href="<?=root?>favourites?filter=flights" class="tabs-trigger <?= $currentFilter=='flights' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined !text-lg">flight_takeoff</span>
                  <?= T::flights ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['tours'])): ?>
               <a href="<?=root?>favourites?filter=tours" class="tabs-trigger <?= $currentFilter=='tours' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">tour</span>
                  <?= T::tours ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['cars'])): ?>
               <a href="<?=root?>favourites?filter=cars" class="tabs-trigger <?= $currentFilter=='cars' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">directions_car</span>
                  <?= T::cars ?>
               </a>
               <?php endif; ?>

               <?php if (isset($enabledModulesMap['visa'])): ?>
               <a href="<?=root?>favourites?filter=visa" class="tabs-trigger <?= $currentFilter=='visa' ? 'active' : '' ?> shrink-0 flex-none sm:flex-1">
                  <span class="material-symbols-outlined text-sm">description</span>
                  <?= T::visa ?>
               </a>
               <?php endif; ?>
            </div>
         </div>

         <!-- ============================================================ -->
         <!-- BOOKINGS DATA TABLE - CRUD COMPONENT -->
         <!-- DATABASE: bookings TABLE -->
         <!-- FILTER: user_id + module_type (IF NOT 'ALL') -->
         <!-- COLUMNS: INVOICE, MODULE, STATUS, PAYMENT, PRICE, DATE -->
         <!-- ACTIONS: VIEW ONLY (NO ADD/EDIT/DELETE) -->
         <!-- ============================================================ -->
         <?php
         // ============================================================================
         // BUILD WHERE CONDITION FOR DATABASE QUERY
         // ALWAYS FILTER BY: user_id (SHOW ONLY THIS USER'S BOOKINGS)
         // CONDITIONALLY ADD: module_type (IF SPECIFIC MODULE SELECTED)
         // ============================================================================
         $whereCondition = ['user_id' => $_SESSION['user_id']];
         if ($currentFilter !== 'all') {
            $whereCondition['module_type'] = $currentFilter;
         }

         echo crud()->table('favorites')
            ->where($whereCondition)
            ->title(T::favorites)
            ->label([
               'module_type' => T::module,
               'created_at' => T::date,
            ])
            ->order('id', 'DESC')
            ->actions([
               'add' => false,
               'view' => false,
               'edit' => false,
               'delete' => true,
               'status' => false,
               'search' => true,
               'bulk_delete' => false,
            ])
            ->row([
               'module_type' => '<span class="capitalize">{{module_type}}</span>',
               'created_at' => function ($row) {
                  return date('M d, Y', strtotime($row['created_at']));
               },
            ])
            ->col_width('module_type', '100px')
            ->col_width('created_at', '120px')
            ->render();
         ?>

      </div>
   </div>
</div>