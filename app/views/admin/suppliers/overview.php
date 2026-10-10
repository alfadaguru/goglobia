<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Admin suppliers overview (inc S34). $stats, $recent.
$stats  = (isset($stats) && is_array($stats)) ? $stats : [];
$recent = (isset($recent) && is_array($recent)) ? $recent : [];
$a = root . admin;
$stBadge = ['active'=>'bg-green-50 text-green-700','pending'=>'bg-amber-50 text-amber-700','rejected'=>'bg-red-50 text-red-700','inactive'=>'bg-gray-100 text-gray-500'];
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <h1 class="text-2xl font-bold text-gray-900">Suppliers</h1>
    <p class="text-sm text-gray-600">Overview of the supplier programme and the items awaiting your review.</p>
  </div>

  <!-- KPI cards -->
  <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
    <?php
      $cards = [
        ['Suppliers', $stats['suppliers_total'] ?? 0, 'storefront', $a . '/suppliers', 'gray'],
        ['Pending approval', $stats['suppliers_pending'] ?? 0, 'hourglass_top', $a . '/suppliers', 'amber'],
        ['Active', $stats['suppliers_active'] ?? 0, 'verified', $a . '/suppliers', 'green'],
        ['Service requests', $stats['service_requests_pending'] ?? 0, 'playlist_add_check', $a . '/supplier-service-requests', 'violet'],
        ['Listings to review', $stats['listings_submitted'] ?? 0, 'fact_check', $a . '/supplier-listings', 'blue'],
        ['Payout requests', $stats['payouts_requested'] ?? 0, 'account_balance', $a . '/supplier-payouts', 'indigo'],
      ];
      foreach ($cards as [$label, $val, $icon, $url, $color]):
    ?>
      <a href="<?= $url ?>" class="card p-4 flex items-center gap-3 hover:border-violet-300 transition">
        <span class="w-11 h-11 rounded-lg bg-<?= $color ?>-50 flex items-center justify-center flex-shrink-0"><span class="material-symbols-outlined text-<?= $color ?>-600"><?= $icon ?></span></span>
        <span>
          <span class="block text-2xl font-bold text-gray-900 tabular-nums"><?= (int) $val ?></span>
          <span class="block text-xs text-gray-500"><?= htmlspecialchars($label) ?></span>
        </span>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Recent suppliers -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Recent suppliers</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Name</th><th class="px-4 py-2 font-medium">Email</th><th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium">Joined</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($recent)): ?>
          <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400 text-sm">No suppliers yet.</td></tr>
        <?php else: foreach ($recent as $s): $st = (string) ($s['status'] ?? ''); ?>
          <tr>
            <td class="px-4 py-2 text-gray-900 font-medium"><?= htmlspecialchars(trim((string) ($s['title'] ?? '') ?: (($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')))) ?: '—' ?></td>
            <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) ($s['email'] ?? '')) ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stBadge[$st] ?? 'bg-gray-100 text-gray-500' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($s['created_at'] ?? ''), 0, 10)) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <div class="px-4 py-3"><a href="<?= $a ?>/suppliers" class="text-sm text-violet-600 hover:underline">All suppliers →</a></div>
  </div>
</div>
