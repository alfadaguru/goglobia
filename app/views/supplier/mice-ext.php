<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Events / MICE extension — one event's quotations, catering, equipment, staffing,
// contracts. Vars: $event, $stayId, $eventId, $totals, $quotes, $quoteItems[qid],
// $quoteTotals[qid], $menus, $equipment, $assignEquip, $assignStaff, $contracts, $canEdit.
$event       = (isset($event) && is_array($event)) ? $event : [];
$stayId      = (int) ($stayId ?? 0);
$eventId     = (int) ($eventId ?? 0);
$totals      = (isset($totals) && is_array($totals)) ? $totals : ['charges' => 0, 'payments' => 0, 'balance' => 0];
$quotes      = (isset($quotes) && is_array($quotes)) ? $quotes : [];
$quoteItems  = (isset($quoteItems) && is_array($quoteItems)) ? $quoteItems : [];
$quoteTotals = (isset($quoteTotals) && is_array($quoteTotals)) ? $quoteTotals : [];
$menus       = (isset($menus) && is_array($menus)) ? $menus : [];
$equipment   = (isset($equipment) && is_array($equipment)) ? $equipment : [];
$assignEquip = (isset($assignEquip) && is_array($assignEquip)) ? $assignEquip : [];
$assignStaff = (isset($assignStaff) && is_array($assignStaff)) ? $assignStaff : [];
$contracts   = (isset($contracts) && is_array($contracts)) ? $contracts : [];
$canEdit     = !empty($canEdit);
$cur         = htmlspecialchars((string) ($event['currency'] ?? 'USD'));
$open        = in_array((string) ($event['status'] ?? ''), ['enquiry', 'confirmed'], true);
$base        = root . 'supplier/events';
$qsEvent     = '?stay_id=' . $stayId . '&event_id=' . $eventId;
$qColor = ['draft' => 'bg-gray-100 text-gray-600', 'sent' => 'bg-blue-50 text-blue-700', 'accepted' => 'bg-green-50 text-green-700', 'declined' => 'bg-red-50 text-red-700'];
$cColor = ['draft' => 'bg-gray-100 text-gray-600', 'sent' => 'bg-blue-50 text-blue-700', 'signed' => 'bg-green-50 text-green-700', 'cancelled' => 'bg-red-50 text-red-700'];
$qNext  = ['draft' => [['sent', 'Send']], 'sent' => [['accepted', 'Accept'], ['declined', 'Decline']], 'accepted' => [], 'declined' => []];
$cNext  = ['draft' => [['sent', 'Send']], 'sent' => [['signed', 'Mark signed'], ['cancelled', 'Cancel']], 'signed' => [], 'cancelled' => []];
function _mice_tf() { return CSRF::tokenField(); }
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

  <!-- Header -->
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <a href="<?= $base ?>?stay_id=<?= $stayId ?>" class="text-xs text-gray-500 hover:text-gray-700 inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">arrow_back</span> All events</a>
      <h1 class="text-2xl font-bold text-gray-900 mt-1"><?= htmlspecialchars((string) ($event['title'] ?? 'Event')) ?> <span class="font-mono text-sm text-gray-400"><?= htmlspecialchars((string) ($event['reference'] ?? '')) ?></span></h1>
      <p class="text-sm text-gray-600"><?= htmlspecialchars((string) ($event['event_date'] ?? '')) ?> · <?= (int) ($event['pax'] ?? 0) ?> pax · <?= htmlspecialchars(ucfirst((string) ($event['status'] ?? ''))) ?></p>
    </div>
    <div class="text-right">
      <div class="text-sm text-gray-500">Folio charges</div>
      <div class="text-xl font-semibold tabular-nums"><?= $cur ?> <?= number_format((float) $totals['charges'], 2) ?></div>
      <?php if ((float) $totals['balance'] > 0): ?><div class="text-xs text-red-500">balance <?= number_format((float) $totals['balance'], 2) ?></div><?php endif; ?>
      <a href="<?= $base ?>/beo<?= $qsEvent ?>" class="btn secondary text-xs mt-2 inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">description</span> BEO</a>
    </div>
  </div>

  <?php if (!$open): ?>
    <div class="card p-3 text-sm text-gray-500">This event is <?= htmlspecialchars((string) $event['status']) ?> — quotations, catering, equipment and deposits are locked.</div>
  <?php endif; ?>

  <!-- QUOTATIONS -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900">Quotations</h2>
    </div>
    <?php if (empty($quotes)): ?>
      <p class="text-sm text-gray-500 mb-3">No quotations yet.</p>
    <?php else: foreach ($quotes as $q): $qid = (int) $q['id']; $qst = (string) $q['status']; $qis = $quoteItems[$qid] ?? []; ?>
      <div class="border border-gray-100 rounded-lg p-3 mb-3">
        <div class="flex items-start justify-between gap-2">
          <div>
            <div class="font-medium text-gray-900 text-sm"><?= htmlspecialchars((string) $q['title']) ?> <span class="font-mono text-xs text-gray-400"><?= htmlspecialchars((string) $q['reference']) ?></span></div>
            <div class="text-xs text-gray-500"><?= htmlspecialchars((string) $q['currency']) ?> <?= number_format((float) ($quoteTotals[$qid] ?? 0), 2) ?><?= !empty($q['valid_until']) ? ' · valid to ' . htmlspecialchars((string) $q['valid_until']) : '' ?><?= (int) ($q['folio_posted'] ?? 0) === 1 ? ' · posted to folio' : '' ?></div>
          </div>
          <span class="px-2 py-0.5 rounded text-xs font-medium <?= $qColor[$qst] ?? '' ?>"><?= htmlspecialchars(ucfirst($qst)) ?></span>
        </div>
        <?php if (!empty($qis)): ?>
          <ul class="text-xs text-gray-600 mt-2 space-y-0.5">
            <?php foreach ($qis as $it): ?><li class="flex justify-between"><span><?= htmlspecialchars((string) ($it['description'] ?? '')) ?> <span class="text-gray-400">(<?= htmlspecialchars((string) $it['type']) ?><?= (int) $it['qty'] > 1 ? ' ×' . (int) $it['qty'] : '' ?>)</span></span><span class="tabular-nums"><?= number_format((float) $it['amount'], 2) ?></span></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($canEdit && $qst === 'draft' && $open): ?>
          <form action="<?= $base ?>/quote/line" method="POST" class="flex flex-wrap items-end gap-2 mt-2 border-t border-gray-100 pt-2">
            <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="quote_id" value="<?= $qid ?>">
            <select name="ltype" class="input text-sm"><option value="venue">Venue</option><option value="catering">Catering</option><option value="extra">Extra</option></select>
            <input type="text" name="description" class="input text-sm w-32" placeholder="Description">
            <input type="number" name="unit" step="0.01" min="0" class="input text-sm w-24" placeholder="unit" required>
            <input type="number" name="qty" min="1" value="1" class="input text-sm w-16" placeholder="qty">
            <button type="submit" class="btn secondary text-xs">Add line</button>
          </form>
        <?php endif; ?>
        <?php if ($canEdit && !empty($qNext[$qst]) && $open): ?>
          <div class="flex items-center gap-2 mt-2">
            <?php foreach ($qNext[$qst] as $opt): ?>
              <form action="<?= $base ?>/quote/status" method="POST" class="inline" <?= $opt[0] === 'accepted' ? "onsubmit=\"return confirm('Accept this quotation? Its lines will be posted to the event folio.');\"" : '' ?>>
                <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="quote_id" value="<?= $qid ?>"><input type="hidden" name="to" value="<?= $opt[0] ?>">
                <button type="submit" class="btn <?= $opt[0] === 'declined' ? 'rose' : 'emerald' ?> text-xs"><?= $opt[1] ?></button>
              </form>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
    <?php if ($canEdit && $open): ?>
      <form action="<?= $base ?>/quote/create" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>">
        <input type="text" name="title" class="input text-sm" placeholder="Quotation title">
        <input type="date" name="valid_until" class="input text-sm" title="Valid until">
        <button type="submit" class="btn secondary text-sm">New quotation</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- CATERING MENUS -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Catering packages</h2>
    <?php if (!empty($menus)): ?>
      <ul class="text-sm divide-y divide-gray-50 mb-3">
        <?php foreach ($menus as $mn): ?>
          <li class="py-2 flex items-center justify-between gap-2">
            <span class="text-gray-800"><?= htmlspecialchars((string) $mn['name']) ?> <span class="text-xs text-gray-400"><?= $cur ?> <?= number_format((float) $mn['price_per_pax'], 2) ?>/pax</span></span>
            <?php if ($canEdit && $open): ?>
              <form action="<?= $base ?>/menu/apply" method="POST" class="flex items-end gap-1">
                <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="menu_id" value="<?= (int) $mn['id'] ?>">
                <input type="number" name="pax" min="1" class="input text-xs w-20" placeholder="pax (<?= (int) ($event['pax'] ?? 0) ?>)">
                <button type="submit" class="btn secondary text-xs">Add to folio</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?><p class="text-sm text-gray-500 mb-3">No catering packages for this property yet.</p><?php endif; ?>
    <?php if ($canEdit): ?>
      <form action="<?= $base ?>/menu/create" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>">
        <input type="text" name="name" class="input text-sm" placeholder="Package name *" required>
        <input type="number" name="price_per_pax" step="0.01" min="0" class="input text-sm w-28" placeholder="per pax *" required>
        <button type="submit" class="btn secondary text-sm">Save package</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- EQUIPMENT -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Equipment</h2>
    <?php if (!empty($assignEquip)): ?>
      <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Assigned</div>
      <ul class="text-xs text-gray-600 mb-3 space-y-0.5"><?php foreach ($assignEquip as $a): ?><li class="flex justify-between"><span><?= htmlspecialchars((string) $a['label']) ?> <span class="text-gray-400">×<?= (int) $a['qty'] ?></span></span><span class="tabular-nums"><?= number_format((float) $a['amount'], 2) ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if (!empty($equipment)): ?>
      <ul class="text-sm divide-y divide-gray-50 mb-3">
        <?php foreach ($equipment as $eq): ?>
          <li class="py-2 flex items-center justify-between gap-2">
            <span class="text-gray-800"><?= htmlspecialchars((string) $eq['name']) ?> <span class="text-xs text-gray-400"><?= $cur ?> <?= number_format((float) $eq['unit_price'], 2) ?>/unit</span></span>
            <?php if ($canEdit && $open): ?>
              <form action="<?= $base ?>/equipment/assign" method="POST" class="flex items-end gap-1">
                <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="equipment_id" value="<?= (int) $eq['id'] ?>">
                <input type="number" name="qty" min="1" value="1" class="input text-xs w-16">
                <button type="submit" class="btn secondary text-xs">Assign</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?><p class="text-sm text-gray-500 mb-3">No equipment catalogue for this property yet.</p><?php endif; ?>
    <?php if ($canEdit): ?>
      <form action="<?= $base ?>/equipment/create" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>">
        <input type="text" name="name" class="input text-sm" placeholder="Equipment name *" required>
        <input type="number" name="unit_price" step="0.01" min="0" class="input text-sm w-28" placeholder="unit price">
        <input type="number" name="stock_qty" min="0" class="input text-sm w-20" placeholder="stock">
        <button type="submit" class="btn secondary text-sm">Add item</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- STAFFING -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Staffing</h2>
    <?php if (!empty($assignStaff)): ?>
      <ul class="text-xs text-gray-600 mb-3 space-y-0.5"><?php foreach ($assignStaff as $a): ?><li class="flex justify-between"><span><?= htmlspecialchars((string) $a['label']) ?></span><span class="tabular-nums"><?= (float) $a['amount'] > 0 ? number_format((float) $a['amount'], 2) : '—' ?></span></li><?php endforeach; ?></ul>
    <?php else: ?><p class="text-sm text-gray-500 mb-3">No staff assigned yet.</p><?php endif; ?>
    <?php if ($canEdit && $open): ?>
      <form action="<?= $base ?>/staff/assign" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>">
        <input type="text" name="role" class="input text-sm w-28" placeholder="Role *" required>
        <input type="text" name="name" class="input text-sm w-28" placeholder="Name">
        <input type="text" name="shift" class="input text-sm w-28" placeholder="Shift">
        <input type="number" name="cost" step="0.01" min="0" class="input text-sm w-24" placeholder="cost">
        <button type="submit" class="btn secondary text-sm">Assign</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- CONTRACTS -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Contracts</h2>
    <?php if (empty($contracts)): ?>
      <p class="text-sm text-gray-500 mb-3">No contracts yet.</p>
    <?php else: foreach ($contracts as $c): $cid = (int) $c['id']; $cst = (string) $c['status']; ?>
      <div class="border border-gray-100 rounded-lg p-3 mb-3">
        <div class="flex items-start justify-between gap-2">
          <div>
            <div class="font-medium text-gray-900 text-sm font-mono"><?= htmlspecialchars((string) $c['reference']) ?></div>
            <div class="text-xs text-gray-500">Deposit <?= htmlspecialchars((string) $c['currency']) ?> <?= number_format((float) $c['deposit_amount'], 2) ?><?= !empty($c['deposit_due']) ? ' · due ' . htmlspecialchars((string) $c['deposit_due']) : '' ?><?= (int) ($c['deposit_posted'] ?? 0) === 1 ? ' · recorded' : '' ?></div>
          </div>
          <span class="px-2 py-0.5 rounded text-xs font-medium <?= $cColor[$cst] ?? '' ?>"><?= htmlspecialchars(ucfirst($cst)) ?></span>
        </div>
        <?php if ($canEdit): ?>
          <div class="flex flex-wrap items-center gap-2 mt-2">
            <?php foreach (($cNext[$cst] ?? []) as $opt): ?>
              <form action="<?= $base ?>/contract/status" method="POST" class="inline">
                <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="contract_id" value="<?= $cid ?>"><input type="hidden" name="to" value="<?= $opt[0] ?>">
                <button type="submit" class="btn <?= $opt[0] === 'cancelled' ? 'rose' : 'emerald' ?> text-xs"><?= $opt[1] ?></button>
              </form>
            <?php endforeach; ?>
            <?php if ($open && (float) $c['deposit_amount'] > 0 && (int) ($c['deposit_posted'] ?? 0) !== 1): ?>
              <form action="<?= $base ?>/contract/deposit" method="POST" class="inline" onsubmit="return confirm('Record the deposit as a payment on the event folio?');">
                <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="contract_id" value="<?= $cid ?>">
                <button type="submit" class="btn secondary text-xs">Record deposit</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
    <?php if ($canEdit): ?>
      <form action="<?= $base ?>/contract/create" method="POST" class="space-y-2 border-t border-gray-100 pt-3">
        <?= _mice_tf() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>">
        <div class="flex flex-wrap gap-2">
          <select name="quote_id" class="input text-sm">
            <option value="0">No linked quote</option>
            <?php foreach ($quotes as $q): if ((string) $q['status'] === 'accepted'): ?><option value="<?= (int) $q['id'] ?>">Accepted: <?= htmlspecialchars((string) $q['reference']) ?></option><?php endif; endforeach; ?>
          </select>
          <input type="number" name="deposit_amount" step="0.01" min="0" class="input text-sm w-28" placeholder="deposit">
          <input type="date" name="deposit_due" class="input text-sm" title="Deposit due">
        </div>
        <textarea name="terms" class="input text-sm w-full" rows="2" placeholder="Terms &amp; conditions"></textarea>
        <button type="submit" class="btn secondary text-sm">Draft contract</button>
      </form>
    <?php endif; ?>
  </div>
</div>
