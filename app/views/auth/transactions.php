<?php
// ============================================================================
// CUSTOMER TRANSACTIONS PAGE — read-only wallet/payment statement
// Provided by route: $txnData, $dashboardData, $user, $db
// ============================================================================
@$SECURE or die('Access Denied!');

// Human labels for the ledger `reason` codes (fall back to a title-cased slug).
$reasonLabels = [
    'topup'             => 'Wallet top-up',
    'wallet_topup'      => 'Wallet top-up',
    'booking'           => 'Booking payment',
    'booking_spend'     => 'Booking payment',
    'spend'             => 'Booking payment',
    'refund'            => 'Refund',
    'gateway_refund'    => 'Refund',
    'loyalty'           => 'Loyalty',
    'convert_to_wallet' => 'Loyalty → wallet',
    'referral'          => 'Referral reward',
    'credit'            => 'Credit',
    'debit'             => 'Debit',
    'adjustment'        => 'Adjustment',
];
$prettyReason = static function ($reason) use ($reasonLabels) {
    $r = strtolower(trim((string) $reason));
    if ($r === '') { return 'Transaction'; }
    return $reasonLabels[$r] ?? ucwords(str_replace(['_', '-'], ' ', $r));
};
$statusClass = static function ($status) {
    $s = strtolower(trim((string) $status));
    if (in_array($s, ['success', 'completed', 'paid', 'confirmed'], true)) return 'badge-success';
    if (in_array($s, ['pending', 'processing'], true)) return 'badge-warning';
    if (in_array($s, ['failed', 'error', 'cancelled', 'reversed'], true)) return 'badge-error';
    return 'badge-neutral';
};
?>

<div class="bg" x-data="{ sidebarCollapsed: window.innerWidth < 1024 }">
   <div class="flex gap-5 container mb-8">

      <div class="flex-shrink-0">
         <?php include views . 'auth/sidebar.php'; ?>
      </div>

      <div class="flex-1 min-w-0 pt-6 bg-white rounded-[8px] ml-0">

         <!-- Header -->
         <div class="flex items-center justify-between mb-6 px-4 lg:px-6">
            <div class="flex items-center gap-4">
               <button @click="sidebarCollapsed = false" class="lg:hidden flex items-center justify-center w-12 h-12 bg-slate-100 rounded-lg text-slate-500 hover:bg-slate-200 transition-colors">
                  <span class="material-symbols-outlined text-2xl">menu</span>
               </button>
               <div class="hidden lg:flex items-center justify-center w-12 h-12 bg-slate-100 rounded-lg">
                  <span class="material-symbols-outlined text-2xl text-slate-500">receipt_long</span>
               </div>
               <div>
                  <h1 class="font-bold text-2xl text-gray-900"><?= T::transactions ?? 'Transactions' ?></h1>
                  <p class="text-gray-600 text-sm"><?= 'Your wallet and payment history' ?></p>
               </div>
            </div>
         </div>

         <!-- Wallet balance strip -->
         <div class="px-4 lg:px-6 mb-5">
            <div class="rounded-xl border border-slate-200 bg-gradient-to-br from-slate-50 to-white p-5 flex flex-wrap items-center justify-between gap-4">
               <div>
                  <div class="text-xs font-medium text-slate-500 mb-1 flex items-center gap-1">
                     <span class="material-symbols-outlined text-base">account_balance_wallet</span> <?= T::wallet ?? 'Wallet' ?> <?= T::balance ?? 'Balance' ?>
                  </div>
                  <div class="text-2xl font-bold text-slate-800 tabular-nums">
                     <?= htmlspecialchars($txnData['currency']) ?> <?= htmlspecialchars($txnData['wallet']) ?>
                  </div>
               </div>
               <a href="<?= root ?>dashboard" class="btn light inline-flex items-center gap-2">
                  <span class="material-symbols-outlined text-xl">add</span> <?= T::add_funds ?? 'Add Funds' ?>
               </a>
            </div>
         </div>

         <!-- Filter tabs -->
         <?php
            $dir = $txnData['dir'];
            $tabs = ['all' => (T::all ?? 'All'), 'credit' => (T::credits ?? 'Credits'), 'debit' => ('Debits')];
         ?>
         <div class="px-4 lg:px-6 mb-4 flex gap-2 flex-wrap">
            <?php foreach ($tabs as $key => $label): ?>
               <a href="<?= root ?>transactions?dir=<?= urlencode($key) ?>"
                  class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                  <?= $dir === $key ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-300' ?>">
                  <?= htmlspecialchars($label) ?>
               </a>
            <?php endforeach; ?>
         </div>

         <!-- Ledger -->
         <div class="px-4 lg:px-6 pb-8">
            <?php if (empty($txnData['rows'])): ?>
               <div class="text-center py-16 text-slate-400">
                  <span class="material-symbols-outlined text-5xl mb-3 block">receipt_long</span>
                  <p class="font-medium text-slate-500"><?= 'No transactions yet' ?></p>
                  <p class="text-sm mt-1"><?= 'Your payments, top-ups and refunds will appear here.' ?></p>
               </div>
            <?php else: ?>
               <div class="overflow-x-auto border border-slate-200 rounded-lg">
                  <table class="w-full text-sm" style="min-width:640px">
                     <thead>
                        <tr class="bg-slate-50 text-left text-slate-500 text-xs uppercase tracking-wide">
                           <th class="py-3 px-4 font-semibold"><?= T::date ?? 'Date' ?></th>
                           <th class="py-3 px-4 font-semibold"><?= T::activity ?? 'Activity' ?></th>
                           <th class="py-3 px-4 font-semibold"><?= T::reference ?? 'Reference' ?></th>
                           <th class="py-3 px-4 font-semibold"><?= T::status ?? 'Status' ?></th>
                           <th class="py-3 px-4 font-semibold text-right"><?= T::amount ?? 'Amount' ?></th>
                        </tr>
                     </thead>
                     <tbody class="divide-y divide-slate-100">
                        <?php foreach ($txnData['rows'] as $t):
                           $isCredit = strtolower((string) ($t['direction'] ?? '')) === 'credit';
                           $amt = number_format((float) ($t['amount'] ?? 0), 2);
                           $cur = htmlspecialchars((string) ($t['currency'] ?? $txnData['currency']));
                           $inv = trim((string) ($t['invoice_id'] ?? ''));
                        ?>
                        <tr class="hover:bg-slate-50/60">
                           <td class="py-3 px-4 text-slate-600 whitespace-nowrap tabular-nums">
                              <?= htmlspecialchars(date('d M Y, H:i', strtotime((string) ($t['created_at'] ?? 'now')))) ?>
                           </td>
                           <td class="py-3 px-4">
                              <div class="font-medium text-slate-800"><?= htmlspecialchars($prettyReason($t['reason'] ?? '')) ?></div>
                              <?php if (!empty($t['description'])): ?>
                                 <div class="text-xs text-slate-400"><?= htmlspecialchars((string) $t['description']) ?></div>
                              <?php endif; ?>
                           </td>
                           <td class="py-3 px-4 text-slate-500 font-mono text-xs">
                              <?php if ($inv !== ''): ?>
                                 <a href="<?= root ?>invoice/<?= urlencode($inv) ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars($inv) ?></a>
                              <?php else: ?>
                                 <?= htmlspecialchars((string) ($t['txn_ref'] ?? '—')) ?>
                              <?php endif; ?>
                           </td>
                           <td class="py-3 px-4">
                              <span class="badge <?= $statusClass($t['status'] ?? '') ?> badge-sm"><?= htmlspecialchars(ucfirst((string) ($t['status'] ?? 'n/a'))) ?></span>
                           </td>
                           <td class="py-3 px-4 text-right tabular-nums font-semibold <?= $isCredit ? 'text-emerald-600' : 'text-slate-700' ?>">
                              <?= $isCredit ? '+' : '−' ?><?= $cur ?> <?= $amt ?>
                           </td>
                        </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               </div>

               <!-- Pagination -->
               <?php if ($txnData['total_pages'] > 1): ?>
                  <div class="flex items-center justify-between mt-4 text-sm">
                     <span class="text-slate-500">
                        <?= T::page ?? 'Page' ?> <?= (int) $txnData['page'] ?> / <?= (int) $txnData['total_pages'] ?>
                        · <?= (int) $txnData['total'] ?> <?= T::total ?? 'total' ?>
                     </span>
                     <div class="flex gap-2">
                        <?php $q = $dir !== 'all' ? ('&dir=' . urlencode($dir)) : ''; ?>
                        <?php if ($txnData['page'] > 1): ?>
                           <a href="<?= root ?>transactions?page=<?= $txnData['page'] - 1 ?><?= $q ?>" class="btn light btn-sm"><?= T::previous ?? 'Previous' ?></a>
                        <?php endif; ?>
                        <?php if ($txnData['page'] < $txnData['total_pages']): ?>
                           <a href="<?= root ?>transactions?page=<?= $txnData['page'] + 1 ?><?= $q ?>" class="btn light btn-sm"><?= T::next ?? 'Next' ?></a>
                        <?php endif; ?>
                     </div>
                  </div>
               <?php endif; ?>
            <?php endif; ?>
         </div>

      </div>
   </div>
</div>
