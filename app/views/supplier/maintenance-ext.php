<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Maintenance & engineering (PMS #27 ext). Data from suppliermaintExtRoutes.php:
// $assets, $contractors, $schedules, $openOrders, $costLines[woId][], $analytics,
// $propMap, $physRooms[stayId][], $canEdit, $stayFilter.
$assets      = (isset($assets) && is_array($assets)) ? $assets : [];
$contractors = (isset($contractors) && is_array($contractors)) ? $contractors : [];
$schedules   = (isset($schedules) && is_array($schedules)) ? $schedules : [];
$openOrders  = (isset($openOrders) && is_array($openOrders)) ? $openOrders : [];
$costLines   = (isset($costLines) && is_array($costLines)) ? $costLines : [];
$analytics   = (isset($analytics) && is_array($analytics)) ? $analytics : [];
$propMap     = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$physRooms   = (isset($physRooms) && is_array($physRooms)) ? $physRooms : [];
$canEdit     = !empty($canEdit);
$stayFilter  = (int) ($stayFilter ?? 0);
$base = root . 'supplier/maintenance/engineering';
$pickRooms = $stayFilter > 0 ? ($physRooms[$stayFilter] ?? []) : [];
$prColor = ['low'=>'bg-gray-100 text-gray-600','normal'=>'bg-blue-50 text-blue-700','high'=>'bg-amber-50 text-amber-700','urgent'=>'bg-red-50 text-red-700'];
$asColor = ['active'=>'bg-green-50 text-green-700','repair'=>'bg-amber-50 text-amber-700','retired'=>'bg-gray-100 text-gray-500'];
$costLabel = ['part'=>'Part','material'=>'Material','labour'=>'Labour','contractor'=>'Contractor'];
// Build an asset/contractor name lookup for the pickers (within scope).
$assetOpts = array_filter($assets, fn($a) => $stayFilter <= 0 || (int)$a['stay_id'] === $stayFilter);
$conOpts   = array_filter($contractors, fn($c) => (!empty($c['active'])) && ($stayFilter <= 0 || (int)$c['stay_id'] === $stayFilter));
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

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Maintenance &amp; engineering</h1>
      <p class="text-sm text-gray-600">Assets &amp; warranties, contractors, preventive schedules, repair costing and SLA analytics. <a href="<?= root ?>supplier/maintenance<?= $stayFilter>0?'?stay_id='.$stayFilter:'' ?>" class="text-violet-600 hover:underline">Work orders &amp; out-of-order &rarr;</a></p>
    </div>
    <form method="GET" action="<?= $base ?>">
      <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
        <option value="0">All properties</option>
        <?php foreach ($propMap as $sid => $pname): ?>
          <option value="<?= (int) $sid ?>" <?= $stayFilter === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars($pname ?? ('#' . $sid)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- ANALYTICS -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
    <?php
      $kpis = [
        ['Open', (int)($analytics['open'] ?? 0), 'bg-amber-50 text-amber-700'],
        ['In progress', (int)($analytics['in_progress'] ?? 0), 'bg-indigo-50 text-indigo-700'],
        ['SLA overdue', (int)($analytics['overdue'] ?? 0), 'bg-red-50 text-red-700'],
        ['MTTR (hrs)', (float)($analytics['mttr_hours'] ?? 0), 'bg-blue-50 text-blue-700'],
        ['SLA breach %', (float)($analytics['sla_breach_pct'] ?? 0), 'bg-rose-50 text-rose-700'],
        ['Warranty soon', (int)($analytics['warranty_expiring'] ?? 0), 'bg-purple-50 text-purple-700'],
      ];
      foreach ($kpis as $k): ?>
      <div class="card p-4">
        <div class="text-xs text-gray-500"><?= htmlspecialchars($k[0]) ?></div>
        <div class="mt-1 inline-flex px-2 py-0.5 rounded text-lg font-bold <?= $k[2] ?>"><?= htmlspecialchars((string) $k[1]) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card p-4 flex items-center justify-between">
    <span class="text-sm text-gray-600">Repair spend month-to-date</span>
    <span class="text-lg font-bold text-gray-900"><?= htmlspecialchars(number_format((float)($analytics['cost_mtd'] ?? 0), 2)) ?></span>
  </div>

  <?php if ($stayFilter <= 0): ?>
    <div class="card p-4 text-sm text-gray-500">Select a single property above to register assets, add contractors, create preventive schedules or add repair costs.</div>
  <?php endif; ?>

  <!-- CREATE FORMS (single-property only, so pickers are unambiguous) -->
  <?php if ($canEdit && $stayFilter > 0): ?>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- Asset -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Register an asset</h2>
      <form action="<?= $base ?>/asset" method="POST" class="space-y-3" style="display:block">
        <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
        <input type="text" name="name" class="input text-sm" placeholder="Asset name * (e.g. Boiler #1)" required>
        <div class="flex flex-wrap gap-2">
          <input type="text" name="category" class="input text-sm w-36" placeholder="Category (HVAC)">
          <input type="text" name="serial_no" class="input text-sm w-36" placeholder="Serial no">
          <select name="physical_room_id" class="input text-sm">
            <option value="0">— no room —</option>
            <?php foreach ($pickRooms as $r): ?><option value="<?= (int) $r['id'] ?>">#<?= htmlspecialchars((string) $r['room_number']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="flex flex-wrap gap-2">
          <div><label class="block text-xs text-gray-600 mb-1">Purchase cost</label><input type="number" step="0.01" min="0" name="purchase_cost" class="input text-sm w-28"></div>
          <div><label class="block text-xs text-gray-600 mb-1">Purchased</label><input type="date" name="purchase_date" class="input text-sm"></div>
          <div><label class="block text-xs text-gray-600 mb-1">Warranty expires</label><input type="date" name="warranty_expires" class="input text-sm"></div>
        </div>
        <input type="text" name="location" class="input text-sm" placeholder="Location (e.g. plant room)">
        <button type="submit" class="btn secondary text-sm">Register asset</button>
      </form>
    </div>
    <!-- Contractor -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Add a contractor</h2>
      <form action="<?= $base ?>/contractor" method="POST" class="space-y-3" style="display:block">
        <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
        <input type="text" name="name" class="input text-sm" placeholder="Company / contractor name *" required>
        <div class="flex flex-wrap gap-2">
          <input type="text" name="trade" class="input text-sm w-36" placeholder="Trade (plumber)">
          <input type="text" name="phone" class="input text-sm w-36" placeholder="Phone">
          <input type="email" name="email" class="input text-sm w-44" placeholder="Email">
        </div>
        <button type="submit" class="btn secondary text-sm">Add contractor</button>
      </form>
    </div>
    <!-- Preventive schedule -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Preventive / recurring schedule</h2>
      <p class="text-xs text-gray-500 mb-2">A work order is generated automatically each time the schedule falls due.</p>
      <form action="<?= $base ?>/schedule" method="POST" class="space-y-3" style="display:block">
        <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
        <input type="text" name="title" class="input text-sm" placeholder="Inspection title * (e.g. Quarterly HVAC check)" required>
        <div class="flex flex-wrap gap-2">
          <input type="text" name="area" class="input text-sm w-32" placeholder="Area">
          <select name="priority" class="input text-sm"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select>
          <div><label class="block text-xs text-gray-600 mb-1">Every N days</label><input type="number" min="1" name="interval_days" value="90" class="input text-sm w-24"></div>
          <div><label class="block text-xs text-gray-600 mb-1">Next due</label><input type="date" name="next_due_date" class="input text-sm" required></div>
        </div>
        <div class="flex flex-wrap gap-2">
          <select name="asset_id" class="input text-sm">
            <option value="0">— no asset —</option>
            <?php foreach ($assetOpts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars((string) $a['name']) ?></option><?php endforeach; ?>
          </select>
          <select name="physical_room_id" class="input text-sm">
            <option value="0">— no room —</option>
            <?php foreach ($pickRooms as $r): ?><option value="<?= (int) $r['id'] ?>">#<?= htmlspecialchars((string) $r['room_number']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn secondary text-sm">Create schedule</button>
      </form>
    </div>
    <!-- Add repair cost to a work order -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Add repair cost to a work order</h2>
      <?php if (empty($openOrders)): ?>
        <p class="text-sm text-gray-500">No open work orders. Raise one on the <a href="<?= root ?>supplier/maintenance?stay_id=<?= $stayFilter ?>" class="text-violet-600 hover:underline">work orders</a> page.</p>
      <?php else: ?>
      <form action="<?= $base ?>/cost" method="POST" class="space-y-3" style="display:block">
        <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
        <select name="work_order_id" class="input text-sm w-full">
          <?php foreach ($openOrders as $o): if ((int)$o['stay_id'] !== $stayFilter) continue; ?>
            <option value="<?= (int) $o['id'] ?>">#<?= (int) $o['id'] ?> — <?= htmlspecialchars((string) $o['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="flex flex-wrap gap-2">
          <select name="cost_type" class="input text-sm"><option value="part">Part</option><option value="material">Material</option><option value="labour">Labour</option><option value="contractor">Contractor</option></select>
          <input type="text" name="description" class="input text-sm flex-1" placeholder="Description *" required>
        </div>
        <div class="flex flex-wrap gap-2">
          <div><label class="block text-xs text-gray-600 mb-1">Qty / hours</label><input type="number" step="0.01" min="0.01" name="qty" value="1" class="input text-sm w-24"></div>
          <div><label class="block text-xs text-gray-600 mb-1">Unit cost</label><input type="number" step="0.01" min="0" name="unit_cost" value="0" class="input text-sm w-28"></div>
        </div>
        <button type="submit" class="btn secondary text-sm">Add cost line</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- OPEN WORK ORDERS with costs / SLA / asset-contractor association -->
  <?php if (!empty($openOrders)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Open work orders — costing, SLA &amp; association</h2>
    <div class="space-y-3">
      <?php foreach ($openOrders as $o): $wid = (int) $o['id']; $breach = function_exists('maint_wo_is_breached') && maint_wo_is_breached($o); ?>
        <div class="border border-gray-100 rounded-lg p-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium text-gray-900">#<?= $wid ?> <?= htmlspecialchars((string) $o['title']) ?></span>
              <span class="px-2 py-0.5 rounded text-xs font-medium <?= $prColor[(string) $o['priority']] ?? '' ?>"><?= htmlspecialchars(ucfirst((string) $o['priority'])) ?></span>
              <?php if (!empty($o['due_at']) && $o['due_at'] !== '0000-00-00 00:00:00'): ?>
                <span class="px-2 py-0.5 rounded text-xs font-medium <?= $breach ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600' ?>">SLA <?= $breach ? 'BREACHED' : 'by' ?> <?= htmlspecialchars((string) $o['due_at']) ?></span>
              <?php endif; ?>
            </div>
            <span class="text-sm font-semibold text-gray-900">Cost: <?= htmlspecialchars(number_format((float)($o['cost_total'] ?? 0), 2)) ?></span>
          </div>

          <!-- Cost lines -->
          <?php $lines = $costLines[$wid] ?? []; ?>
          <?php if (!empty($lines)): ?>
            <div class="mt-2 overflow-x-auto"><table class="w-full text-xs">
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($lines as $cl): ?>
                  <tr>
                    <td class="px-2 py-1 text-gray-500 w-24"><?= htmlspecialchars($costLabel[(string)$cl['cost_type']] ?? (string)$cl['cost_type']) ?></td>
                    <td class="px-2 py-1 text-gray-700"><?= htmlspecialchars((string) $cl['description']) ?></td>
                    <td class="px-2 py-1 text-gray-500 text-right"><?= htmlspecialchars(rtrim(rtrim((string)$cl['qty'],'0'),'.')) ?> &times; <?= htmlspecialchars(number_format((float)$cl['unit_cost'],2)) ?></td>
                    <td class="px-2 py-1 text-gray-900 text-right font-medium"><?= htmlspecialchars(number_format((float)$cl['line_total'],2)) ?></td>
                    <td class="px-2 py-1 text-right">
                      <?php if ($canEdit): ?>
                      <form action="<?= $base ?>/cost/delete" method="POST" class="inline" onsubmit="return confirm('Remove this cost line?');">
                        <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $o['stay_id'] ?>"><input type="hidden" name="cost_id" value="<?= (int) $cl['id'] ?>">
                        <button type="submit" class="text-xs text-rose-600 hover:underline">remove</button>
                      </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php endif; ?>

          <!-- Associate asset/contractor (+ stamp SLA) -->
          <?php if ($canEdit && (int)$o['stay_id'] === $stayFilter): ?>
          <form action="<?= $base ?>/wo/associate" method="POST" class="mt-2 flex flex-wrap items-center gap-2" style="display:flex">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $o['stay_id'] ?>"><input type="hidden" name="work_order_id" value="<?= $wid ?>">
            <select name="asset_id" class="input text-xs">
              <option value="0">— link asset —</option>
              <?php foreach ($assetOpts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int)($o['asset_id']??0)===(int)$a['id']?'selected':'' ?>><?= htmlspecialchars((string) $a['name']) ?></option><?php endforeach; ?>
            </select>
            <select name="contractor_id" class="input text-xs">
              <option value="0">— assign contractor —</option>
              <?php foreach ($conOpts as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int)($o['contractor_id']??0)===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars((string) $c['name']) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="text-xs text-violet-600 hover:underline">Save &amp; stamp SLA</button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- PREVENTIVE SCHEDULES -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4 text-sm font-semibold text-gray-900">Preventive schedules</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-3 font-medium">Property</th><th class="px-4 py-3 font-medium">Title</th><th class="px-4 py-3 font-medium">Priority</th><th class="px-4 py-3 font-medium">Every</th><th class="px-4 py-3 font-medium">Next due</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium"></th></tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($schedules)): ?>
          <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400 text-sm">No preventive schedules.</td></tr>
        <?php else: foreach ($schedules as $s): $on = !empty($s['active']); ?>
          <tr>
            <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $s['stay_id']] ?? ('#' . $s['stay_id'])) ?></td>
            <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars((string) $s['title']) ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $prColor[(string) $s['priority']] ?? '' ?>"><?= htmlspecialchars(ucfirst((string) $s['priority'])) ?></span></td>
            <td class="px-4 py-3 text-gray-600 text-xs"><?= (int) $s['interval_days'] ?> days</td>
            <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars((string) $s['next_due_date']) ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $on ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $on ? 'Active' : 'Paused' ?></span></td>
            <td class="px-4 py-3">
              <?php if ($canEdit): ?>
              <form action="<?= $base ?>/schedule/toggle" method="POST" class="inline">
                <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $s['stay_id'] ?>"><input type="hidden" name="schedule_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="active" value="<?= $on ? '0' : '1' ?>">
                <button type="submit" class="text-xs text-violet-600 hover:underline"><?= $on ? 'Pause' : 'Resume' ?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ASSET REGISTER -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4 text-sm font-semibold text-gray-900">Asset register</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-3 font-medium">Property</th><th class="px-4 py-3 font-medium">Asset</th><th class="px-4 py-3 font-medium">Category</th><th class="px-4 py-3 font-medium">Serial</th><th class="px-4 py-3 font-medium">Warranty</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium"></th></tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($assets)): ?>
          <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400 text-sm">No assets registered.</td></tr>
        <?php else: foreach ($assets as $a): $st = (string)($a['status'] ?? 'active');
          $wexp = (string)($a['warranty_expires'] ?? '');
          $wsoon = $wexp !== '' && strtotime($wexp) !== false && strtotime($wexp) <= strtotime('+60 days') && strtotime($wexp) >= strtotime('today'); ?>
          <tr>
            <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $a['stay_id']] ?? ('#' . $a['stay_id'])) ?></td>
            <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars((string) $a['name']) ?></td>
            <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars((string) ($a['category'] ?? '')) ?: '—' ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($a['serial_no'] ?? '')) ?: '—' ?></td>
            <td class="px-4 py-3 text-xs <?= $wsoon ? 'text-amber-700 font-medium' : 'text-gray-500' ?>"><?= $wexp !== '' ? htmlspecialchars($wexp) : '—' ?><?= $wsoon ? ' (soon)' : '' ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $asColor[$st] ?? '' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
            <td class="px-4 py-3">
              <?php if ($canEdit): ?>
              <div class="flex items-center gap-1.5">
                <?php foreach (['active'=>'Active','repair'=>'Repair','retired'=>'Retire'] as $k=>$lbl): if ($k===$st) continue; ?>
                  <form action="<?= $base ?>/asset/status" method="POST" class="inline">
                    <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $a['stay_id'] ?>"><input type="hidden" name="asset_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="to" value="<?= htmlspecialchars($k) ?>">
                    <button type="submit" class="text-xs text-violet-600 hover:underline"><?= htmlspecialchars($lbl) ?></button>
                  </form>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- CONTRACTORS -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4 text-sm font-semibold text-gray-900">Contractors</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-3 font-medium">Property</th><th class="px-4 py-3 font-medium">Name</th><th class="px-4 py-3 font-medium">Trade</th><th class="px-4 py-3 font-medium">Phone</th><th class="px-4 py-3 font-medium">Email</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium"></th></tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($contractors)): ?>
          <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400 text-sm">No contractors.</td></tr>
        <?php else: foreach ($contractors as $c): $on = !empty($c['active']); ?>
          <tr>
            <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $c['stay_id']] ?? ('#' . $c['stay_id'])) ?></td>
            <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars((string) $c['name']) ?></td>
            <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars((string) ($c['trade'] ?? '')) ?: '—' ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($c['phone'] ?? '')) ?: '—' ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($c['email'] ?? '')) ?: '—' ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $on ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $on ? 'Active' : 'Inactive' ?></span></td>
            <td class="px-4 py-3">
              <?php if ($canEdit): ?>
              <form action="<?= $base ?>/contractor/toggle" method="POST" class="inline">
                <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $c['stay_id'] ?>"><input type="hidden" name="contractor_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="active" value="<?= $on ? '0' : '1' ?>">
                <button type="submit" class="text-xs text-violet-600 hover:underline"><?= $on ? 'Deactivate' : 'Activate' ?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
