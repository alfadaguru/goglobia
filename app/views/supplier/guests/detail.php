<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Guest profile + history (inc S29). $data['profile'], $data['stays'], $meta (vip/note/tags),
// $propMap, $canEdit, $token (from the route path).
$data    = (isset($data) && is_array($data)) ? $data : ['profile' => null, 'stays' => []];
$profile = $data['profile'] ?? [];
$stays   = is_array($data['stays'] ?? null) ? $data['stays'] : [];
$meta    = (isset($meta) && is_array($meta)) ? $meta : ['vip' => 0, 'note' => '', 'tags' => ''];
$propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$canEdit = !empty($canEdit);
$token   = isset($profile['token']) ? (string) $profile['token'] : '';
$base = root . 'supplier/guests';
$stBadge = ['confirmed'=>'bg-green-50 text-green-700','pending'=>'bg-amber-50 text-amber-700','cancelled'=>'bg-red-50 text-red-700'];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <nav class="text-xs text-gray-500"><a href="<?= $base ?>" class="hover:underline">Guests</a> <span class="mx-1">/</span> <span class="text-gray-700"><?= htmlspecialchars($profile['name'] ?? $profile['email'] ?? '') ?></span></nav>

  <div class="flex items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
        <?= htmlspecialchars($profile['name'] ?: $profile['email']) ?>
        <?php if (!empty($meta['vip'])): ?><span class="px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-700">VIP</span><?php endif; ?>
      </h1>
      <p class="text-sm text-gray-600"><?= htmlspecialchars((string) $profile['email']) ?><?= !empty($profile['phone']) ? ' · ' . htmlspecialchars((string) $profile['phone']) : '' ?></p>
    </div>
  </div>

  <!-- Summary -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
    <div class="card p-3"><div class="text-xs text-gray-500">Stays</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= (int) ($profile['stays'] ?? 0) ?></div></div>
    <div class="card p-3"><div class="text-xs text-gray-500">Total spent</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= htmlspecialchars((string) ($profile['currency'] ?? '')) ?> <?= number_format((float) ($profile['total_spent'] ?? 0), 2) ?></div></div>
    <div class="card p-3"><div class="text-xs text-gray-500">First seen</div><div class="text-sm font-medium text-gray-700"><?= htmlspecialchars(substr((string) ($profile['first_seen'] ?? ''), 0, 10)) ?></div></div>
    <div class="card p-3"><div class="text-xs text-gray-500">Last seen</div><div class="text-sm font-medium text-gray-700"><?= htmlspecialchars(substr((string) ($profile['last_seen'] ?? ''), 0, 10)) ?></div></div>
  </div>

  <!-- Owner notes -->
  <?php if ($canEdit): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Internal profile</h2>
    <form action="<?= $base ?>/<?= htmlspecialchars($token) ?>/note" method="POST" class="space-y-3">
      <?= CSRF::tokenField() ?>
      <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="vip" value="1" class="checkbox-input" <?= !empty($meta['vip']) ? 'checked' : '' ?>> Mark as VIP</label>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Tags</label><input type="text" name="tags" class="input text-sm" value="<?= htmlspecialchars((string) $meta['tags']) ?>" placeholder="e.g. corporate, allergy:nuts"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Notes &amp; preferences</label><textarea name="note" rows="3" class="input text-sm"><?= htmlspecialchars((string) $meta['note']) ?></textarea></div>
      <button type="submit" class="btn secondary text-sm">Save profile</button>
    </form>
  </div>
  <?php elseif (!empty($meta['note']) || !empty($meta['vip']) || !empty($meta['tags'])): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-2">Internal profile</h2>
    <?php if (!empty($meta['tags'])): ?><p class="text-xs text-gray-500 mb-1">Tags: <?= htmlspecialchars((string) $meta['tags']) ?></p><?php endif; ?>
    <?php if (!empty($meta['note'])): ?><p class="text-sm text-gray-700 whitespace-pre-line"><?= htmlspecialchars((string) $meta['note']) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Loyalty (inc S30) -->
  <?php
    $loy = (isset($loyalty) && is_array($loyalty)) ? $loyalty : ['cfg' => ['enabled' => false], 'summary' => ['balance' => 0, 'lifetime_earned' => 0, 'tier' => 'Member'], 'ledger' => []];
    $lcfg = $loy['cfg'] ?? ['enabled' => false];
    $lsum = $loy['summary'] ?? ['balance' => 0, 'lifetime_earned' => 0, 'tier' => 'Member'];
    $lled = is_array($loy['ledger'] ?? null) ? $loy['ledger'] : [];
  ?>
  <?php if (!empty($lcfg['enabled']) || (int) ($lsum['balance'] ?? 0) !== 0 || !empty($lled)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">loyalty</span> Loyalty</h2>
    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="rounded-lg bg-violet-50 p-3"><div class="text-xs text-violet-700">Balance</div><div class="text-lg font-bold text-violet-800 tabular-nums"><?= (int) ($lsum['balance'] ?? 0) ?></div></div>
      <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Lifetime earned</div><div class="text-lg font-bold text-gray-700 tabular-nums"><?= (int) ($lsum['lifetime_earned'] ?? 0) ?></div></div>
      <div class="rounded-lg bg-amber-50 p-3"><div class="text-xs text-amber-700">Tier</div><div class="text-lg font-bold text-amber-800"><?= htmlspecialchars((string) ($lsum['tier'] ?? 'Member')) ?></div></div>
    </div>
    <?php if ($canEdit): ?>
      <form action="<?= $base ?>/<?= htmlspecialchars($token) ?>/loyalty" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Action</label>
          <select name="ltype" class="input text-sm"><option value="adjust">Adjust (±)</option><option value="redeem">Redeem (−)</option></select></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Points</label><input type="number" name="points" class="input text-sm w-24" required></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Reason</label><input type="text" name="reason" class="input text-sm" placeholder="optional"></div>
        <button type="submit" class="btn secondary text-sm">Apply</button>
        <p class="text-[11px] text-gray-400 w-full">Adjust accepts a signed value (e.g. 50 or −50). Redeem deducts the amount; it can't take the balance below zero.</p>
      </form>
    <?php endif; ?>
    <?php if (!empty($lled)): ?>
      <div class="overflow-x-auto mt-3"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">When</th><th class="px-2 py-1.5 font-medium">Type</th><th class="px-2 py-1.5 font-medium">Reason</th><th class="px-2 py-1.5 font-medium text-right">Points</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($lled as $l): $pts = (int) $l['points']; ?>
            <tr><td class="px-2 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) $l['created_at'], 0, 16)) ?></td><td class="px-2 py-1.5 text-gray-600 text-xs"><?= htmlspecialchars(ucfirst((string) $l['type'])) ?></td><td class="px-2 py-1.5 text-gray-600"><?= htmlspecialchars((string) ($l['reason'] ?? '')) ?></td><td class="px-2 py-1.5 text-right tabular-nums <?= $pts < 0 ? 'text-red-600' : 'text-green-700' ?>"><?= $pts > 0 ? '+' : '' ?><?= $pts ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Stay history -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Stay history</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Invoice</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Dates</th><th class="px-4 py-2 font-medium text-right">Total</th><th class="px-4 py-2 font-medium">Status</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($stays as $s): $st = strtolower((string) ($s['booking_status'] ?? '')); ?>
          <tr>
            <td class="px-4 py-2 font-mono text-xs"><a href="<?= root ?>supplier/reservations/<?= rawurlencode((string) $s['invoice_id']) ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars((string) $s['invoice_id']) ?></a></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $s['_hotel_id']] ?? ('#' . $s['_hotel_id'])) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars((string) $s['_checkin']) ?><?= $s['_checkout'] !== '' ? ' → ' . htmlspecialchars((string) $s['_checkout']) : '' ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= htmlspecialchars((string) ($s['currency_markup'] ?? '')) ?> <?= number_format((float) ($s['price_markup'] ?? 0), 2) ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stBadge[$st] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
