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

         <?php if (empty($dashboardData['is_agent'])):
                  $walletCur = htmlspecialchars($dashboardData['currency'] ?? 'USD');
         ?>
         <!-- ==================================================================
              WALLET — balance + self-service top-up (customers). NGN routes to
              Paystack, other currencies to Stripe (server-side).
         ================================================================== -->
         <div class="mb-6 px-4 lg:px-0"
              x-data="{ open: false, amount: '' }">
            <div class="rounded-xl border border-slate-200 bg-gradient-to-br from-slate-50 to-white p-5">
               <div class="flex flex-wrap items-center justify-between gap-4">
                  <div>
                     <div class="text-xs font-medium text-slate-500 mb-1 flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">account_balance_wallet</span> Wallet Balance
                     </div>
                     <div class="text-2xl font-bold text-slate-800 tabular-nums">
                        <?= $walletCur ?> <?= htmlspecialchars((string)($dashboardData['wallet_balance'] ?? '0.00')) ?>
                     </div>
                  </div>
                  <button type="button" class="btn primary inline-flex items-center gap-2" @click="open = !open">
                     <span class="material-symbols-outlined text-xl">add</span> Add Funds
                  </button>
               </div>
               <div x-show="open" x-collapse style="display:none" class="mt-4 pt-4 border-t border-slate-200">
                  <form method="POST" action="<?= root ?>wallet/topup" class="flex flex-wrap items-end gap-3">
                     <input type="hidden" name="csrf_token" value="<?= CSRF::getToken() ?>">
                     <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-slate-600">Amount (<?= $walletCur ?>)</label>
                        <input type="number" name="amount" min="1" step="0.01" x-model="amount" class="input w-40" placeholder="0.00" required>
                     </div>
                     <button type="submit" class="btn primary inline-flex items-center gap-2" :disabled="!amount || amount <= 0">
                        <span class="material-symbols-outlined text-xl">payments</span> Top up
                     </button>
                     <p class="text-[11px] text-slate-400 basis-full">You'll be taken to a secure payment page. NGN is processed by Paystack; other currencies by Stripe.</p>
                  </form>
               </div>

               <?php
                  // -----------------------------------------------------------
                  // PAYSTACK VIRTUAL ACCOUNT (NUBAN) — NGN customers only.
                  // If one already exists, show its details; otherwise offer to
                  // activate it. Fund the wallet by bank transfer into this NUBAN.
                  // -----------------------------------------------------------
                  $dvaCur  = strtoupper((string)($dashboardData['display_currency'] ?? ''));
                  $dvaAcct = (string)($dashboardData['dva_account_number'] ?? '');
               ?>
               <?php if ($dvaCur === 'NGN'): ?>
                  <div class="mt-4 pt-4 border-t border-slate-200">
                     <?php if ($dvaAcct !== ''): ?>
                        <div class="text-xs font-medium text-slate-500 mb-2 flex items-center gap-1">
                           <span class="material-symbols-outlined text-base">account_balance</span> Your virtual account (bank transfer)
                        </div>
                        <div class="rounded-lg bg-white border border-slate-200 p-3 flex flex-wrap items-center justify-between gap-3"
                             x-data="{ copied: false }">
                           <div>
                              <div class="text-lg font-bold text-slate-800 tabular-nums tracking-wide"><?= htmlspecialchars($dvaAcct) ?></div>
                              <div class="text-xs text-slate-500">
                                 <?= htmlspecialchars((string)($dashboardData['dva_bank_name'] ?? '')) ?>
                                 <?php if (!empty($dashboardData['dva_account_name'])): ?>
                                    &middot; <?= htmlspecialchars((string)$dashboardData['dva_account_name']) ?>
                                 <?php endif; ?>
                              </div>
                           </div>
                           <button type="button" class="btn ghost text-xs inline-flex items-center gap-1"
                                   @click="navigator.clipboard.writeText('<?= htmlspecialchars($dvaAcct) ?>'); copied = true; setTimeout(() => copied = false, 1500)">
                              <span class="material-symbols-outlined text-base" x-text="copied ? 'check' : 'content_copy'"></span>
                              <span x-text="copied ? 'Copied' : 'Copy'"></span>
                           </button>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-2">Transfer any amount to this account and your wallet is topped up automatically.</p>
                     <?php else: ?>
                        <form method="POST" action="<?= root ?>wallet/virtual-account" class="flex flex-wrap items-center gap-3">
                           <input type="hidden" name="csrf_token" value="<?= CSRF::getToken() ?>">
                           <button type="submit" class="btn ghost inline-flex items-center gap-2">
                              <span class="material-symbols-outlined text-xl">account_balance</span> Activate virtual account
                           </button>
                           <p class="text-[11px] text-slate-400 basis-full">Get a dedicated Naira bank account (Paystack). Fund your wallet by bank transfer — no card needed.</p>
                        </form>
                     <?php endif; ?>
                  </div>
               <?php endif; ?>
            </div>
         </div>
         <?php endif; ?>

         <?php if (!empty($dashboardData['tier_info']) && !empty($dashboardData['tier_info']['current'])):
                  $ti = $dashboardData['tier_info'];
                  $curTier = $ti['current'];
                  $nextTier = $ti['next'] ?? null;
                  $lifetime = (float)($ti['lifetime_topup'] ?? 0);
                  $curMin = (float)($curTier['min_lifetime_topup'] ?? 0);
                  $cur = htmlspecialchars($dashboardData['currency'] ?? 'USD');
                  // progress from current tier threshold → next tier threshold
                  $pct = 100; $toNext = 0;
                  if ($nextTier) {
                      $nextMin = (float)($nextTier['min_lifetime_topup'] ?? 0);
                      $span = max(1, $nextMin - $curMin);
                      $pct = max(0, min(100, round((($lifetime - $curMin) / $span) * 100)));
                      $toNext = max(0, $nextMin - $lifetime);
                  }
         ?>
         <!-- ==================================================================
              MEMBERSHIP TIER (agents) — current tier, discount, progress to next
         ================================================================== -->
         <div class="mb-6 px-4 lg:px-0">
            <div class="rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-white p-5">
               <div class="flex flex-wrap items-start justify-between gap-4">
                  <div>
                     <div class="text-xs font-medium text-indigo-700 mb-1 flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">workspace_premium</span> <?= 'Membership Tier' ?>
                     </div>
                     <div class="text-2xl font-bold text-indigo-900"><?= htmlspecialchars($curTier['name'] ?? '') ?></div>
                     <p class="text-[11px] text-indigo-600 mt-1">
                        <?= rtrim(rtrim(number_format((float)($curTier['discount_percent'] ?? 0), 2), '0'), '.') ?>% <?= 'markup discount on every booking' ?>
                     </p>
                  </div>
                  <div class="text-right">
                     <div class="text-[11px] text-slate-500"><?= 'Lifetime wallet top-up' ?></div>
                     <div class="text-lg font-bold text-slate-800 tabular-nums"><?= $cur ?> <?= number_format($lifetime, 2) ?></div>
                  </div>
               </div>
               <?php if ($nextTier): ?>
               <div class="mt-4">
                  <div class="flex items-center justify-between text-[11px] text-slate-500 mb-1">
                     <span><?= htmlspecialchars($curTier['name'] ?? '') ?></span>
                     <span>
                        <?= T::next ?? 'Next' ?>: <strong class="text-indigo-700"><?= htmlspecialchars($nextTier['name'] ?? '') ?></strong>
                        (<?= rtrim(rtrim(number_format((float)($nextTier['discount_percent'] ?? 0), 2), '0'), '.') ?>%)
                     </span>
                  </div>
                  <div class="w-full h-2 bg-indigo-100 rounded-full overflow-hidden">
                     <div class="h-full bg-indigo-500 rounded-full" style="width: <?= (int)$pct ?>%"></div>
                  </div>
                  <p class="text-[11px] text-slate-500 mt-1">
                     <?= 'Top up' ?> <strong><?= $cur ?> <?= number_format($toNext, 2) ?></strong>
                     <?= 'more to reach' ?> <?= htmlspecialchars($nextTier['name'] ?? '') ?>.
                  </p>
               </div>
               <?php else: ?>
               <p class="text-[11px] text-indigo-600 mt-3"><?= 'You have reached the highest tier — enjoy the best rate.' ?></p>
               <?php endif; ?>
            </div>
         </div>
         <?php endif; ?>

         <?php if (!empty($dashboardData['loyalty_enabled'])): ?>
         <!-- ==================================================================
              LOYALTY POINTS — balance, cash worth, and convert-to-wallet
         ================================================================== -->
         <div class="mb-6 px-4 lg:px-0"
              x-data="{
                 points: <?= (int)($dashboardData['loyalty_points'] ?? 0) ?>,
                 redeemValue: <?= json_encode((float)($dashboardData['loyalty_redeem_value'] ?? 0)) ?>,
                 currency: '<?= htmlspecialchars($dashboardData['currency'] ?? 'USD', ENT_QUOTES) ?>',
                 amount: 0,
                 busy: false,
                 msg: '',
                 msgType: '',
                 get worth() { return (this.points * this.redeemValue); },
                 fmt(n) { return Number(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); },
                 async redeem() {
                    const pts = parseInt(this.amount, 10);
                    if (!pts || pts <= 0) { this.msg = 'Enter a positive number of points'; this.msgType = 'error'; return; }
                    if (pts > this.points) { this.msg = 'You do not have that many points'; this.msgType = 'error'; return; }
                    this.busy = true; this.msg = '';
                    try {
                       const r = await fetch('<?= root ?>loyalty/redeem', {
                          method: 'POST',
                          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= CSRF::getToken() ?>' },
                          body: JSON.stringify({ points: pts, csrf_token: '<?= CSRF::getToken() ?>' })
                       });
                       const j = await r.json();
                       if (j.success) {
                          this.points = j.points_left;
                          this.amount = 0;
                          this.msg = 'Converted to ' + this.currency + ' ' + this.fmt(j.amount) + ' wallet credit.';
                          this.msgType = 'success';
                       } else {
                          this.msg = j.message || 'Conversion failed'; this.msgType = 'error';
                       }
                    } catch (e) { this.msg = 'Network error'; this.msgType = 'error'; }
                    this.busy = false;
                 }
              }">
            <div class="rounded-xl border border-amber-100 bg-gradient-to-br from-amber-50 to-white p-5">
               <div class="flex flex-wrap items-center justify-between gap-4">
                  <div>
                     <div class="text-xs font-medium text-amber-700 mb-1 flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">loyalty</span> <?= T::loyalty_points ?? 'Loyalty Points' ?>
                     </div>
                     <div class="text-2xl font-bold text-amber-800">
                        <span x-text="points.toLocaleString()"></span> <span class="text-base font-medium"><?= 'pts' ?></span>
                     </div>
                     <p class="text-[11px] text-amber-600 mt-1">
                        <?= 'Worth' ?> <span x-text="currency + ' ' + fmt(worth)"></span>
                        <template x-if="redeemValue > 0">
                           <span> · <span x-text="redeemValue"></span> <span x-text="currency"></span> / pt</span>
                        </template>
                     </p>
                  </div>
                  <div class="flex items-end gap-2" x-show="points > 0">
                     <div class="flex flex-col gap-1">
                        <label class="text-[11px] font-medium text-amber-700"><?= 'Convert to wallet' ?></label>
                        <input type="number" min="1" :max="points" x-model="amount" class="input py-1.5 px-3 text-sm w-36" placeholder="Points">
                     </div>
                     <button type="button" class="btn primary text-sm py-1.5 px-4" :disabled="busy" @click="redeem()">
                        <span x-show="!busy"><?= 'Redeem' ?></span>
                        <span x-show="busy">…</span>
                     </button>
                  </div>
               </div>
               <template x-if="msg">
                  <div class="mt-3 text-sm" :class="msgType === 'success' ? 'text-emerald-700' : 'text-red-600'" x-text="msg"></div>
               </template>

               <?php if (!empty($dashboardData['loyalty_history'])): ?>
               <details class="mt-4">
                  <summary class="text-xs font-medium text-amber-700 cursor-pointer hover:text-amber-900"><?= 'Points history' ?></summary>
                  <div class="mt-2 overflow-x-auto">
                     <table class="w-full text-xs">
                        <thead>
                           <tr class="text-left text-slate-400 border-b border-amber-100">
                              <th class="py-1.5 pr-3 font-medium"><?= T::date ?? 'Date' ?></th>
                              <th class="py-1.5 pr-3 font-medium"><?= T::activity ?? 'Activity' ?></th>
                              <th class="py-1.5 pr-3 font-medium text-right"><?= 'Points' ?></th>
                              <th class="py-1.5 font-medium text-right"><?= T::balance ?? 'Balance' ?></th>
                           </tr>
                        </thead>
                        <tbody>
                           <?php foreach ($dashboardData['loyalty_history'] as $lh):
                                    $isEarn = in_array(($lh['direction'] ?? ''), ['earn','adjust'], true); ?>
                           <tr class="border-b border-amber-50">
                              <td class="py-1.5 pr-3 text-slate-500 whitespace-nowrap"><?= htmlspecialchars(date('M d, Y', strtotime((string)$lh['created_at']))) ?></td>
                              <td class="py-1.5 pr-3 text-slate-600"><?= htmlspecialchars(ucwords(str_replace('_',' ',(string)($lh['reason'] ?? $lh['direction'])))) ?></td>
                              <td class="py-1.5 pr-3 text-right tabular-nums <?= $isEarn ? 'text-emerald-700' : 'text-slate-700' ?>">
                                 <?= $isEarn ? '+' : '−' ?><?= number_format((int)$lh['points']) ?>
                              </td>
                              <td class="py-1.5 text-right tabular-nums font-medium"><?= number_format((int)$lh['balance_after']) ?></td>
                           </tr>
                           <?php endforeach; ?>
                        </tbody>
                     </table>
                  </div>
               </details>
               <?php endif; ?>
            </div>
         </div>
         <?php endif; ?>

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