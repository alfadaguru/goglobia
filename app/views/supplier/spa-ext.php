<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $services     = (isset($services) && is_array($services)) ? $services : [];
  $resources    = (isset($resources) && is_array($resources)) ? $resources : [];
  $appointments = (isset($appointments) && is_array($appointments)) ? $appointments : [];
  $memberships  = (isset($memberships) && is_array($memberships)) ? $memberships : [];
  $propMap      = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList     = (isset($propList) && is_array($propList)) ? $propList : [];
  $svcMap       = (isset($svcMap) && is_array($svcMap)) ? $svcMap : [];
  $resMap       = (isset($resMap) && is_array($resMap)) ? $resMap : [];
  $memMap       = (isset($memMap) && is_array($memMap)) ? $memMap : [];
  $canEdit      = !empty($canEdit);
  $from         = isset($from) ? (string) $from : date('Y-m-d');
  $to           = isset($to) ? (string) $to : date('Y-m-d', strtotime('+13 days'));
  $base         = root . 'supplier/spa';
  $resKinds     = function_exists('spa_resource_kinds') ? spa_resource_kinds() : [];
  // Group services/resources by stay for the dependent selects.
  $svcByStay = []; foreach ($services as $s) { $svcByStay[(int) $s['stay_id']][] = $s; }
  $resByStay = []; foreach ($resources as $r) { if ((int) $r['active'] === 1) { $resByStay[(int) $r['stay_id']][] = $r; } }
  $apStatusBadge = function (string $st): string {
      switch ($st) {
          case 'completed': return 'bg-emerald-100 text-emerald-700';
          case 'cancelled': return 'bg-gray-100 text-gray-500';
          case 'no_show':   return 'bg-amber-100 text-amber-700';
          default:          return 'bg-violet-100 text-violet-700';
      }
  };
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between flex-wrap gap-2">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Spa &amp; wellness</h1>
      <p class="text-sm text-gray-600">Therapist &amp; room roster, appointment calendar with double-book protection, session-pass memberships, and charge-to-room.</p>
    </div>
    <a href="<?= root ?>supplier/ancillary-services" class="btn ghost text-sm">Service price list</a>
  </div>

  <?php if (empty($services)): ?>
    <div class="card p-6 text-center text-gray-500 text-sm">Add spa services on the <a href="<?= root ?>supplier/ancillary-services" class="text-violet-600 hover:underline">service price list</a> first (name, duration, price). Then set up resources and book appointments here.</div>
  <?php else: ?>

  <!-- RESOURCES -->
  <div class="card p-5 space-y-3">
    <h2 class="font-semibold text-gray-900">Resources (therapists &amp; rooms)</h2>
    <p class="text-xs text-gray-500">A resource is a calendar lane. An appointment cannot overlap another on the same resource unless its capacity allows it.</p>
    <?php if ($canEdit): ?>
    <form action="<?= $base ?>/resource/add" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-sm" required><option value="">Property *</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="kind" class="input text-sm"><?php foreach ($resKinds as $k => $lbl): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?></select>
      <input type="text" name="name" class="input text-sm w-48" placeholder="Name (e.g. Amina / Room 2) *" required>
      <input type="number" name="capacity" step="1" min="1" value="1" class="input text-sm w-24" placeholder="capacity">
      <button type="submit" class="btn emerald text-sm">Add resource</button>
    </form>
    <?php endif; ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b"><th class="py-2 pr-3">Resource</th><th class="py-2 pr-3">Type</th><th class="py-2 pr-3">Property</th><th class="py-2 pr-3">Cap.</th><th class="py-2 pr-3">Status</th><?php if ($canEdit): ?><th class="py-2 pr-3"></th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (empty($resources)): ?>
          <tr><td colspan="6" class="py-4 text-center text-gray-400">No resources yet.</td></tr>
        <?php else: foreach ($resources as $r): ?>
          <tr class="border-b last:border-0">
            <td class="py-2 pr-3 font-medium text-gray-900"><?= htmlspecialchars((string) $r['name']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars($resKinds[$r['kind']] ?? (string) $r['kind']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($propMap[(int) $r['stay_id']] ?? ('#' . $r['stay_id']))) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= (int) $r['capacity'] ?></td>
            <td class="py-2 pr-3"><?php if ((int) $r['active'] === 1): ?><span class="px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">Active</span><?php else: ?><span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">Off</span><?php endif; ?></td>
            <?php if ($canEdit): ?>
            <td class="py-2 pr-3">
              <form action="<?= $base ?>/resource/toggle" method="POST" class="inline">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= (int) $r['stay_id'] ?>">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="active" value="<?= (int) $r['active'] === 1 ? '0' : '1' ?>">
                <button type="submit" class="text-xs text-violet-600 hover:underline"><?= (int) $r['active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- BOOK AN APPOINTMENT -->
  <?php if ($canEdit): ?>
  <div class="card p-5 space-y-3">
    <h2 class="font-semibold text-gray-900">Book an appointment</h2>
    <p class="text-xs text-gray-500">Duration and price come from the service — never entered here. A clashing resource/time is refused.</p>
    <form action="<?= $base ?>/appointment/book" method="POST" class="grid gap-2 md:grid-cols-6 items-end">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-sm md:col-span-1" required><option value="">Property *</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="service_id" class="input text-sm md:col-span-1" required><option value="">Service *</option><?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) $s['name']) ?><?php if ($s['duration_min'] !== null): ?> (<?= (int) $s['duration_min'] ?>m)<?php endif; ?> — <?= number_format((float) $s['price'], 2) ?></option><?php endforeach; ?></select>
      <select name="resource_id" class="input text-sm md:col-span-1" required><option value="">Resource *</option><?php foreach ($resources as $r): if ((int) $r['active'] !== 1) { continue; } ?><option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars((string) $r['name']) ?></option><?php endforeach; ?></select>
      <input type="datetime-local" name="start_at" class="input text-sm md:col-span-1" required>
      <input type="text" name="guest_name" class="input text-sm md:col-span-1" placeholder="Guest">
      <input type="text" name="invoice_id" class="input text-sm md:col-span-1" placeholder="Reservation / invoice ID">
      <div class="md:col-span-6"><button type="submit" class="btn emerald text-sm">Book appointment</button></div>
    </form>
  </div>
  <?php endif; ?>

  <!-- CALENDAR / APPOINTMENTS -->
  <div class="card p-5 space-y-3">
    <div class="flex items-center justify-between flex-wrap gap-2">
      <h2 class="font-semibold text-gray-900">Appointments</h2>
      <form method="GET" action="<?= $base ?>" class="flex items-end gap-2">
        <label class="text-xs text-gray-500">From <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input text-sm"></label>
        <label class="text-xs text-gray-500">To <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input text-sm"></label>
        <button type="submit" class="btn ghost text-sm">Filter</button>
      </form>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b"><th class="py-2 pr-3">When</th><th class="py-2 pr-3">Service</th><th class="py-2 pr-3">Resource</th><th class="py-2 pr-3">Guest</th><th class="py-2 pr-3">Folio</th><th class="py-2 pr-3 text-right">Price</th><th class="py-2 pr-3">Status</th><?php if ($canEdit): ?><th class="py-2 pr-3"></th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (empty($appointments)): ?>
          <tr><td colspan="8" class="py-4 text-center text-gray-400">No appointments in this window.</td></tr>
        <?php else: foreach ($appointments as $a):
          $svc = $svcMap[(int) $a['service_id']] ?? null;
          $res = $resMap[(int) $a['resource_id']] ?? null;
          $st  = (string) ($a['status'] ?? 'booked');
          $onPass = (int) ($a['membership_id'] ?? 0) > 0;
        ?>
          <tr class="border-b last:border-0 align-top">
            <td class="py-2 pr-3 text-gray-700 whitespace-nowrap"><?= htmlspecialchars(date('D j M, H:i', strtotime((string) $a['start_at']))) ?><div class="text-xs text-gray-400"><?= (int) $a['duration_min'] ?> min</div></td>
            <td class="py-2 pr-3 text-gray-900"><?= htmlspecialchars($svc['name'] ?? ('#' . $a['service_id'])) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars($res['name'] ?? ('#' . $a['resource_id'])) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($a['guest_name'] ?? '—')) ?></td>
            <td class="py-2 pr-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($a['invoice_id'] ?? '—')) ?></td>
            <td class="py-2 pr-3 text-right text-gray-900"><?= number_format((float) $a['price'], 2) ?><?php if ($onPass): ?><div class="text-xs text-emerald-600">on pass</div><?php endif; ?></td>
            <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded-full text-xs <?= $apStatusBadge($st) ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $st))) ?></span><?php if ((int) ($a['charged'] ?? 0) === 1): ?> <span class="px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-700">Charged</span><?php endif; ?></td>
            <?php if ($canEdit): ?>
            <td class="py-2 pr-3 whitespace-nowrap space-y-1">
              <?php if ($st !== 'cancelled'): ?>
              <div class="flex flex-wrap gap-1">
                <?php foreach (['completed' => 'Done', 'no_show' => 'No-show', 'cancelled' => 'Cancel'] as $ns => $lbl): ?>
                <?php if ($ns === $st) { continue; } ?>
                <form action="<?= $base ?>/appointment/status" method="POST" class="inline">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="stay_id" value="<?= (int) $a['stay_id'] ?>">
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <input type="hidden" name="status" value="<?= htmlspecialchars($ns) ?>">
                  <button type="submit" class="text-xs text-violet-600 hover:underline"><?= htmlspecialchars($lbl) ?></button>
                </form>
                <?php endforeach; ?>
              </div>
              <?php if ((int) ($a['charged'] ?? 0) !== 1 && $st !== 'cancelled' && !$onPass): ?>
              <form action="<?= $base ?>/appointment/charge" method="POST" class="inline">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= (int) $a['stay_id'] ?>">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button type="submit" class="text-xs text-emerald-600 hover:underline">Charge to room</button>
              </form>
              <?php endif; ?>
              <?php endif; /* $st !== 'cancelled' */ ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- MEMBERSHIPS -->
  <div class="card p-5 space-y-3">
    <h2 class="font-semibold text-gray-900">Memberships &amp; session passes</h2>
    <p class="text-xs text-gray-500">Sell a pass of N sessions. Redeeming a pass against an appointment decrements the balance and zeroes that appointment's charge.</p>
    <?php if ($canEdit): ?>
    <form action="<?= $base ?>/membership/add" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-sm" required><option value="">Property *</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <input type="text" name="holder_name" class="input text-sm w-40" placeholder="Holder *" required>
      <input type="email" name="holder_email" class="input text-sm w-48" placeholder="Email">
      <input type="text" name="plan_name" class="input text-sm w-40" placeholder="Plan name">
      <input type="number" name="sessions_total" step="1" min="1" value="10" class="input text-sm w-24" placeholder="sessions">
      <input type="number" name="price" step="0.01" min="0" class="input text-sm w-28" placeholder="price">
      <input type="date" name="expires_on" class="input text-sm" title="Expiry (optional)">
      <button type="submit" class="btn emerald text-sm">Sell pass</button>
    </form>
    <?php endif; ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b"><th class="py-2 pr-3">Holder</th><th class="py-2 pr-3">Plan</th><th class="py-2 pr-3">Property</th><th class="py-2 pr-3">Remaining</th><th class="py-2 pr-3">Expires</th><th class="py-2 pr-3">Status</th><?php if ($canEdit): ?><th class="py-2 pr-3">Redeem against appointment</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (empty($memberships)): ?>
          <tr><td colspan="7" class="py-4 text-center text-gray-400">No memberships sold yet.</td></tr>
        <?php else: foreach ($memberships as $m):
          $remaining = function_exists('spa_membership_remaining') ? spa_membership_remaining($m) : (max(0, (int) $m['sessions_total'] - (int) $m['sessions_used']));
          $mst = (string) ($m['status'] ?? 'active');
          // Open appointments on this membership's property that aren't yet on a pass.
          $redeemables = [];
          foreach ($appointments as $a) {
              if ((int) $a['stay_id'] !== (int) $m['stay_id']) { continue; }
              if ((int) ($a['membership_id'] ?? 0) > 0 || (int) ($a['charged'] ?? 0) === 1) { continue; }
              if (in_array((string) $a['status'], ['cancelled'], true)) { continue; }
              $redeemables[] = $a;
          }
        ?>
          <tr class="border-b last:border-0 align-top">
            <td class="py-2 pr-3 font-medium text-gray-900"><?= htmlspecialchars((string) $m['holder_name']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) $m['plan_name']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($propMap[(int) $m['stay_id']] ?? ('#' . $m['stay_id']))) ?></td>
            <td class="py-2 pr-3 text-gray-900"><?= (int) $remaining ?> / <?= (int) $m['sessions_total'] ?></td>
            <td class="py-2 pr-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($m['expires_on'] ?? '—')) ?></td>
            <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded-full text-xs <?= $mst === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' ?>"><?= htmlspecialchars(ucfirst($mst)) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="py-2 pr-3">
              <?php if ($mst === 'active' && $remaining > 0 && !empty($redeemables)): ?>
              <form action="<?= $base ?>/membership/redeem" method="POST" class="flex items-center gap-1">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= (int) $m['stay_id'] ?>">
                <input type="hidden" name="membership_id" value="<?= (int) $m['id'] ?>">
                <select name="appointment_id" class="input text-xs" required>
                  <option value="">Choose…</option>
                  <?php foreach ($redeemables as $a): $svc = $svcMap[(int) $a['service_id']] ?? null; ?>
                  <option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars((string) ($svc['name'] ?? ('#' . $a['service_id']))) ?> · <?= htmlspecialchars(date('j M H:i', strtotime((string) $a['start_at']))) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="text-xs text-violet-600 hover:underline">Apply</button>
              </form>
              <?php else: ?>
              <span class="text-xs text-gray-400">—</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php endif; ?>
</div>
