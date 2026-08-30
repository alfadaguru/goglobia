<?php
// ============================================================================
// USER DASHBOARD - MAIN OVERVIEW PAGE
// ============================================================================
// PURPOSE: Central hub for users to view their booking statistics,
//          recent activity, and quick access to key features
// DATA SOURCE: $dashboardData array passed from usersRoutes.php
// FRAMEWORK: Alpine.js for reactive UI components
// STYLING: Tailwind CSS utility classes
// ============================================================================

// Security check - ensure this file is accessed through proper routing
@$SECURE or die('Access Denied!'); ?>

<!-- ============================================================================
     MAIN DASHBOARD CONTAINER
     ============================================================================
     Alpine.js Data Properties:
     - sidebarCollapsed: Responsive sidebar state (collapsed on mobile)
============================================================================ -->
<div class="bg" x-data="{
    sidebarCollapsed: window.innerWidth < 1024
}">
   <!-- Flex container for sidebar and main content -->
   <div class="flex gap-5 container mb-8">

      <!-- ======================================================================
           INCLUDE: Dynamic Sidebar Navigation
           Shows module-based menu items (stays, flights, tours, etc.)
      ====================================================================== -->
      <div class="flex-shrink-0">
         <?php include views."auth/sidebar.php"; ?>
      </div>

      <!-- ======================================================================
           MAIN DASHBOARD CONTENT AREA
           Responsive: ml-[40px] on mobile, ml-0 on desktop (lg:ml-0)
      ====================================================================== -->
      <div class="flex-1 min-w-0 pt-6 bg-white rounded-[8px] ml-0">

         <!-- ==================================================================
              DASHBOARD HEADER
              Displays page title with icon and subtitle
         ================================================================== -->
         <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
               <!-- Mobile Menu Toggle -->
               <button @click="sidebarCollapsed = false" class="lg:hidden flex items-center justify-center w-12 h-12 bg-slate-100 rounded-lg text-slate-500 hover:bg-slate-200 transition-colors">
                  <span class="material-symbols-outlined text-2xl">menu</span>
               </button>

               <!-- Dashboard Icon (Hidden on Mobile when menu button is shown) -->
               <div class="hidden lg:flex items-center justify-center w-12 h-12 bg-slate-100 rounded-lg">
                     <span class="material-symbols-outlined text-2xl text-slate-500">dashboard</span>
               </div>
               <!-- Page Title & Subtitle -->
               <div>
                     <h1 class="font-bold text-2xl text-gray-900"><?= T::dashboard ?></h1>
                     <p class="text-gray-600 text-sm"><?= T::your_travel_overview ?></p>
               </div>
            </div>
         </div>

         <?php if (!empty($dashboardData['is_agent'])): ?>
         <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6 px-4 lg:px-0">
            <div class="rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-4">
               <div class="text-xs font-medium text-emerald-700 mb-1"><?= T::total_earnings ?></div>
               <div class="text-2xl font-bold text-emerald-800">
                  <?= htmlspecialchars($dashboardData['currency'] ?? 'USD') ?>
                  <?= number_format((float)($dashboardData['total_agent_earning'] ?? 0), 2) ?>
               </div>
               <p class="text-[11px] text-emerald-600 mt-1"><?= T::from_your_bookings ?></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
               <div class="text-xs font-medium text-slate-500 mb-1"><?= T::markup_configuration ?></div>
               <div class="text-sm font-semibold text-slate-800 capitalize">
                  <?= htmlspecialchars((string)($dashboardData['apply_markup'] ?? 'global')) ?>
               </div>
               <?php if (($dashboardData['apply_markup'] ?? 'global') === 'custom'): ?>
               <p class="text-[11px] text-slate-500 mt-1">
                  <?= htmlspecialchars((string)($dashboardData['markup_type'] ?? 'percentage')) ?>:
                  <?= number_format((float)($dashboardData['markup_value'] ?? 0), 2) ?>
                  <?= (($dashboardData['markup_type'] ?? '') === 'percentage') ? '%' : '' ?>
               </p>
               <?php else: ?>
               <p class="text-[11px] text-slate-500 mt-1"><?= T::using_module_b2b_markup ?></p>
               <?php endif; ?>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
               <div class="text-xs font-medium text-slate-500 mb-1"><?= T::total_bookings ?></div>
               <div class="text-2xl font-bold text-slate-800"><?= (int)($dashboardData['total_bookings'] ?? 0) ?></div>
            </div>
         </div>
         <?php endif; ?>

         <!-- ==================================================================
              STATISTICS CARDS ROW
              Displays 4 key metrics in colored gradient cards
              Layout: Responsive grid (1 col mobile, 2 cols tablet, 3 cols desktop)
         ================================================================== -->
         
         <!-- ==================================================================
              MAIN CONTENT GRID
              Layout: 2 columns on desktop (2/3 for bookings, 1/3 for sidebar)
                      1 column on mobile (stacked)
         ================================================================== -->
         <div class="">

            <!-- ==============================================================
                 SECTION: Recent Bookings List
                 Spans 2 columns on desktop, full width on mobile
                 Shows last 5 bookings with status badges and amounts
            ============================================================== -->
            <div class="p-0">


                   <?php
                  $isAgentDash = !empty($dashboardData['is_agent']);
                  $dashCols = $isAgentDash
                     ? 'invoice_id,module_type,booking_status,payment_status,price_markup,agent_earning,created_at'
                     : 'invoice_id,module_type,booking_status,payment_status,price_markup,created_at';
                  $dashLabels = [
                     'invoice_id' => 'Invoice',
                     'module_type' => 'Module',
                     'booking_status' => 'Status',
                     'payment_status' => 'Payment',
                     'price_markup' => 'Price',
                     'created_at' => 'Date'
                  ];
                  if ($isAgentDash) {
                     $dashLabels['agent_earning'] = T::earning;
                  }

                  // Display recent bookings using CRUD table
                  $dashCrud = crud()->table('bookings')
                     ->title(T::recent_bookings)
                     ->where(['user_id' => $_SESSION['user_id']])
                     ->col($dashCols)
                     ->label($dashLabels)
                     ->order('id', 'DESC')
                     // ->limit(5)
                     ->actions([
                        'add' => false,
                        'view' => true,
                        'edit' => false,
                        'delete' => false,
                        'status' => false,
                        'search' => false,
                        'bulk_delete' => false,
                     ])
                     ->row([
                        'module_type' => '<span class="capitalize">{{module_type}}</span>',
                        'invoice_id' => '<a href="' . root . 'invoice/{{invoice_id}}" target="_blank" class="text-blue-600 hover:underline">{{invoice_id}}</a>',
                        'price_markup' => '{{currency_markup}} {{number_format(price_markup, 2)}}',
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
                           return '<span class="inline-block px-2 py-1 rounded text-xs ' . $statusClass . '">' . htmlspecialchars($row['payment_status']) . '</span>';
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
                           return '<span class="inline-block px-2 py-1 rounded text-xs ' . $statusClass . '">' . htmlspecialchars($row['booking_status']) . '</span>';
                        },
                     ])
                     ->action_urls([
                        'view' => root.'invoice/{invoice_id}',
                     ])
                     ->col_width('invoice_id', '140px')
                     ->col_width('module_type', '90px')
                     ->col_width('booking_status', '100px')
                     ->col_width('payment_status', '100px')
                     ->col_width('price_markup', '100px')
                     ->col_width('agent_earning', '110px')
                     ->col_width('created_at', '110px');
                  echo $dashCrud->render();
                  ?>
             </div>

            <!-- ==============================================================
                 RIGHT SIDEBAR: Quick Actions & Account Info
                 Contains 3 cards stacked vertically
            ============================================================== -->

         </div>
      </div>
   </div>
</div>
<!-- ============================================================================
     END OF DASHBOARD
     Data Requirements: $dashboardData array must contain:
     - total_bookings: int
     - pending_bookings: int
     - confirmed_bookings: int
     - wallet_balance: float
     - recent_bookings: array
     - member_since: string (date)
============================================================================ -->