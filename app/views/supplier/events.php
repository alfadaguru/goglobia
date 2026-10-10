<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Events / MICE (inc S33). $spaces, $events, $eventTotals[eid], $eventItems[eid][],
// $propMap, $spaceNames, $canEdit, $stayFilter.
$spaces      = (isset($spaces) && is_array($spaces)) ? $spaces : [];
$events      = (isset($events) && is_array($events)) ? $events : [];
$eventTotals = (isset($eventTotals) && is_array($eventTotals)) ? $eventTotals : [];
$eventItems  = (isset($eventItems) && is_array($eventItems)) ? $eventItems : [];
$propMap     = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$spaceNames  = (isset($spaceNames) && is_array($spaceNames)) ? $spaceNames : [];
$canEdit     = !empty($canEdit);
$stayFilter  = (int) ($stayFilter ?? 0);
$base = root . 'supplier/events';
$stColor = ['enquiry'=>'bg-amber-50 text-amber-700','confirmed'=>'bg-blue-50 text-blue-700','completed'=>'bg-green-50 text-green-700','cancelled'=>'bg-red-50 text-red-700'];
$stNext  = ['enquiry'=>[['confirmed','Confirm'],['cancelled','Cancel']],'confirmed'=>[['completed','Complete'],['cancelled','Cancel']],'completed'=>[],'cancelled'=>[]];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Events &amp; banquets</h1>
      <p class="text-sm text-gray-600">Function spaces and event bookings. Completing an event posts its charges to your books.</p>
    </div>
    <form method="GET" action="<?= $base ?>">
      <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
        <option value="0">All properties</option>
        <?php foreach ($propMap as $sid => $pname): ?><option value="<?= (int) $sid ?>" <?= $stayFilter === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars($pname ?? ('#' . $sid)) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- Spaces + create event (requires a single property) -->
  <?php if ($canEdit): ?>
    <?php if ($stayFilter <= 0): ?>
      <div class="card p-4 text-sm text-gray-500">Select a single property above to add a function space or create an event.</div>
    <?php else: ?>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="card p-5">
          <h2 class="text-sm font-semibold text-gray-900 mb-3">Function spaces</h2>
          <?php if (!empty($spaces)): ?>
            <ul class="text-sm divide-y divide-gray-50 mb-3"><?php foreach ($spaces as $s): ?><li class="py-1.5 flex justify-between"><span class="text-gray-800"><?= htmlspecialchars((string) $s['name']) ?></span><span class="text-xs text-gray-400">cap <?= (int) $s['capacity'] ?></span></li><?php endforeach; ?></ul>
          <?php endif; ?>
          <form action="<?= $base ?>/space" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
            <input type="text" name="name" class="input text-sm" placeholder="Space name *" required>
            <input type="number" name="capacity" min="0" class="input text-sm w-24" placeholder="capacity">
            <button type="submit" class="btn secondary text-sm">Add space</button>
          </form>
        </div>
        <div class="card p-5">
          <h2 class="text-sm font-semibold text-gray-900 mb-3">New event enquiry</h2>
          <?php if (empty($spaces)): ?>
            <p class="text-sm text-gray-500">Add a function space first.</p>
          <?php else: ?>
          <form action="<?= $base ?>/create" method="POST" class="space-y-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
            <input type="text" name="title" class="input text-sm w-full" placeholder="Event title" required>
            <div class="flex flex-wrap gap-2">
              <select name="space_id" class="input text-sm"><?php foreach ($spaces as $s): ?><option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) $s['name']) ?></option><?php endforeach; ?></select>
              <input type="date" name="event_date" class="input text-sm" required>
              <input type="number" name="pax" min="0" class="input text-sm w-20" placeholder="pax">
            </div>
            <div class="flex flex-wrap gap-2">
              <input type="text" name="client_name" class="input text-sm" placeholder="Client name">
              <input type="email" name="client_email" class="input text-sm" placeholder="Client email">
            </div>
            <button type="submit" class="btn secondary text-sm">Create enquiry</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Events -->
  <?php if (empty($events)): ?>
    <div class="card p-8 text-center text-gray-500"><span class="material-symbols-outlined text-5xl text-gray-300">celebration</span><p class="mt-2 text-sm">No events yet.</p></div>
  <?php else: foreach ($events as $ev): $eid = (int) $ev['id']; $st = (string) $ev['status']; $tot = $eventTotals[$eid] ?? ['charges'=>0,'payments'=>0,'balance'=>0]; $items = $eventItems[$eid] ?? []; ?>
    <div class="card p-4">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="font-medium text-gray-900"><?= htmlspecialchars((string) $ev['title']) ?> <span class="font-mono text-xs text-gray-400"><?= htmlspecialchars((string) $ev['reference']) ?></span></div>
          <div class="text-xs text-gray-500"><?= htmlspecialchars($propMap[(int) $ev['stay_id']] ?? ('#' . $ev['stay_id'])) ?> · <?= htmlspecialchars($spaceNames[(int) $ev['space_id']] ?? ('space #' . $ev['space_id'])) ?> · <?= htmlspecialchars((string) $ev['event_date']) ?> · <?= (int) $ev['pax'] ?> pax<?= !empty($ev['client_name']) ? ' · ' . htmlspecialchars((string) $ev['client_name']) : '' ?></div>
        </div>
        <div class="text-right">
          <span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span>
          <div class="text-sm font-semibold tabular-nums mt-1"><?= htmlspecialchars((string) $ev['currency']) ?> <?= number_format((float) $tot['charges'], 2) ?></div>
          <?php if ((float) $tot['balance'] > 0): ?><div class="text-[11px] text-red-500">bal <?= number_format((float) $tot['balance'], 2) ?></div><?php endif; ?>
        </div>
      </div>
      <?php if (!empty($items)): ?>
        <ul class="text-xs text-gray-600 mt-2 space-y-0.5"><?php foreach ($items as $it): $isPay = in_array((string) $it['type'], ['payment','refund'], true); ?><li class="flex justify-between"><span><?= htmlspecialchars((string) ($it['description'] ?? '')) ?> <span class="text-gray-400">(<?= htmlspecialchars((string) $it['type']) ?>)</span></span><span class="tabular-nums <?= $isPay ? 'text-green-700' : '' ?>"><?= $isPay ? '−' : '' ?><?= number_format((float) $it['amount'], 2) ?></span></li><?php endforeach; ?></ul>
      <?php endif; ?>

      <?php if ($canEdit && in_array($st, ['enquiry','confirmed'], true)): ?>
        <form action="<?= $base ?>/line" method="POST" class="flex flex-wrap items-end gap-2 mt-3 border-t border-gray-100 pt-3">
          <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $ev['stay_id'] ?>"><input type="hidden" name="event_id" value="<?= $eid ?>">
          <select name="ltype" class="input text-sm"><option value="venue">Venue hire</option><option value="catering">Catering</option><option value="extra">Extra</option><option value="payment">Payment</option><option value="refund">Refund</option></select>
          <input type="text" name="description" class="input text-sm w-32" placeholder="Description">
          <input type="number" name="amount" step="0.01" min="0" class="input text-sm w-24" placeholder="amount" required>
          <button type="submit" class="btn secondary text-xs">Add line</button>
        </form>
      <?php endif; ?>

      <?php if ($canEdit && !empty($stNext[$st])): ?>
        <div class="flex items-center gap-2 mt-3">
          <?php foreach ($stNext[$st] as $opt): ?>
            <form action="<?= $base ?>/status" method="POST" class="inline"
                  <?= $opt[0] === 'completed' ? "onsubmit=\"return confirm('Complete this event? Its charges will post to your books.');\"" : '' ?>>
              <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $ev['stay_id'] ?>"><input type="hidden" name="event_id" value="<?= $eid ?>"><input type="hidden" name="to" value="<?= $opt[0] ?>">
              <button type="submit" class="btn <?= $opt[0] === 'cancelled' ? 'rose' : 'emerald' ?> text-xs"><?= $opt[1] ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
