<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $summary = (isset($summary) && is_array($summary)) ? $summary : [];
  $properties = (isset($properties) && is_array($properties)) ? $properties : [];
  $guests = (isset($guests) && is_array($guests)) ? $guests : [];
  $availability = (isset($availability) && is_array($availability)) ? $availability : [];
  $availDate = (string) ($availDate ?? '');
  $revByCur = $summary['revenue_by_currency'] ?? [];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Portfolio / chain</h1>
    <p class="text-sm text-gray-600">A consolidated view across every property in your group — brands, regions, revenue, cross-property availability and your chain-wide guests.</p>
  </div>

  <!-- CONSOLIDATED KPIs -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="card p-4"><div class="text-xs text-gray-500">Properties</div><div class="text-2xl font-bold tabular-nums"><?= (int) ($summary['properties'] ?? 0) ?></div><div class="text-xs text-gray-400"><?= (int) ($summary['live'] ?? 0) ?> live · <?= (int) ($summary['draft'] ?? 0) ?> draft</div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Bookings (paid)</div><div class="text-2xl font-bold tabular-nums"><?= (int) ($summary['bookings'] ?? 0) ?></div></div>
    <div class="card p-4 col-span-2"><div class="text-xs text-gray-500 mb-1">Net revenue</div>
      <?php if (empty($revByCur)): ?><div class="text-sm text-gray-400">—</div><?php else: foreach ($revByCur as $cur => $amt): ?>
        <div class="flex justify-between text-sm"><span class="text-gray-600"><?= htmlspecialchars((string) $cur) ?></span><span class="tabular-nums font-semibold"><?= number_format((float) $amt, 2) ?></span></div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- BRAND / REGION / TYPE breakdown -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <?php
      $blocks = [['By brand', $summary['by_brand'] ?? []], ['By region', $summary['by_region'] ?? []], ['By type', $summary['by_type'] ?? []]];
      foreach ($blocks as [$label, $map]):
    ?>
    <div class="card p-4">
      <h2 class="text-sm font-semibold text-gray-900 mb-2"><?= htmlspecialchars($label) ?></h2>
      <?php if (empty($map)): ?><p class="text-xs text-gray-400">—</p><?php else: ?>
        <ul class="text-sm space-y-1"><?php foreach ($map as $k => $n): ?>
          <li class="flex justify-between"><span class="text-gray-600"><?= htmlspecialchars((string) $k) ?></span><span class="tabular-nums"><?= (int) $n ?></span></li>
        <?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- PROPERTIES table -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Properties</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Brand</th>
        <th class="px-4 py-2 font-medium">Region</th><th class="px-4 py-2 font-medium">Type</th><th class="px-4 py-2 font-medium">Status</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($properties)): ?><tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No properties yet.</td></tr>
        <?php else: foreach ($properties as $p): $live = (int) $p['status'] === 1 && (string) $p['listing_status'] === 'approved'; ?>
          <tr>
            <td class="px-4 py-2 text-gray-800"><a href="<?= root ?>supplier/stays/<?= (int) $p['id'] ?>" class="text-violet-600 hover:underline"><?= htmlspecialchars((string) $p['name']) ?></a></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($p['brand_name'] ?? '—')) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($p['region'] ?? '—')) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(ucfirst((string) ($p['accommodation_type'] ?? 'hotel'))) ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $live ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $live ? 'Live' : htmlspecialchars((string) $p['listing_status']) ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- CROSS-PROPERTY AVAILABILITY -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-2">Cross-property availability</h2>
    <form method="GET" action="<?= root ?>supplier/chain" class="flex items-end gap-2 mb-3">
      <div><label class="block text-xs text-gray-500 mb-1">Night</label><input type="date" name="date" value="<?= htmlspecialchars($availDate) ?>" class="input text-sm"></div>
      <button type="submit" class="btn secondary text-sm">Check</button>
    </form>
    <?php if ($availDate !== ''): ?>
      <?php if (empty($availability)): ?><p class="text-xs text-gray-400">No live properties / no data for that night.</p>
      <?php else: ?>
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-3 py-2 font-medium">Property</th><th class="px-3 py-2 font-medium text-right">Rooms</th><th class="px-3 py-2 font-medium text-right">Held</th><th class="px-3 py-2 font-medium text-right">Free</th></tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($availability as $a): ?>
              <tr><td class="px-3 py-2 text-gray-800"><?= htmlspecialchars((string) $a['name']) ?></td>
                <td class="px-3 py-2 text-right tabular-nums"><?= (int) $a['physical_rooms'] ?></td>
                <td class="px-3 py-2 text-right tabular-nums text-gray-500"><?= (int) $a['held'] ?></td>
                <td class="px-3 py-2 text-right tabular-nums font-semibold <?= (int) $a['free'] > 0 ? 'text-green-700' : 'text-red-600' ?>"><?= (int) $a['free'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php else: ?><p class="text-xs text-gray-400">Pick a night to see how many rooms each property has free.</p><?php endif; ?>
  </div>

  <!-- CENTRALIZED GUEST DB -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Chain guests</h2>
    <p class="text-xs text-gray-500 px-4">Unique guests across all your properties. Multi-property guests are your most valuable.</p>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Guest</th><th class="px-4 py-2 font-medium text-right">Stays</th><th class="px-4 py-2 font-medium text-right">Properties</th><th class="px-4 py-2 font-medium text-right">Spent</th><th class="px-4 py-2 font-medium">Last seen</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($guests)): ?><tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No guests yet.</td></tr>
        <?php else: foreach ($guests as $g): $np = is_array($g['properties'] ?? null) ? count($g['properties']) : (int) ($g['properties'] ?? 1); ?>
          <tr class="<?= $np > 1 ? 'bg-violet-50/40' : '' ?>">
            <td class="px-4 py-2 text-gray-800"><?= htmlspecialchars((string) ($g['name'] ?: $g['email'])) ?><div class="text-xs text-gray-400"><?= htmlspecialchars((string) $g['email']) ?></div></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= (int) ($g['stays'] ?? 0) ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= $np > 1 ? 'font-semibold text-violet-700' : '' ?>"><?= $np ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= htmlspecialchars((string) ($g['currency'] ?? '')) ?> <?= number_format((float) ($g['total_spent'] ?? 0), 2) ?></td>
            <td class="px-4 py-2 text-gray-400 text-xs"><?= htmlspecialchars(substr((string) ($g['last_seen'] ?? ''), 0, 10)) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
