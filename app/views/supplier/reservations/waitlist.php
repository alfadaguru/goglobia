<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $sid = (int) ($stay['id'] ?? 0);
  $rows = (isset($waitlist) && is_array($waitlist)) ? $waitlist : [];
  $canEdit = !empty($canEdit);
  $stColor = ['waiting' => 'bg-amber-50 text-amber-700', 'converted' => 'bg-green-50 text-green-700', 'cancelled' => 'bg-gray-100 text-gray-500'];
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

  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900">Waitlist</h1>
    <a href="<?= root ?>supplier/reservations" class="text-sm text-blue-600 hover:underline">&larr; Reservations</a>
  </div>
  <p class="text-sm text-gray-600"><?= htmlspecialchars((string) ($stay['name'] ?? '')) ?> — people waiting for space. No room is held; convert to a booking when space frees up.</p>

  <?php if ($canEdit): ?>
  <form action="<?= root ?>supplier/stays/<?= $sid ?>/waitlist" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <input type="text" name="guest_name" placeholder="Guest name *" required class="input">
    <input type="email" name="email" placeholder="Email" class="input">
    <input type="text" name="phone" placeholder="Phone" class="input">
    <input type="date" name="checkin" required class="input">
    <input type="date" name="checkout" required class="input">
    <input type="number" name="pax" min="1" value="1" placeholder="Guests" class="input">
    <input type="text" name="notes" placeholder="Notes" class="input md:col-span-2">
    <button type="submit" class="btn emerald"><span class="flex items-center gap-1.5 justify-center"><span class="material-symbols-outlined text-sm">add</span> Add to waitlist</span></button>
  </form>
  <?php endif; ?>

  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-100">
            <th class="px-4 py-2">Guest</th><th class="px-4 py-2">Dates</th><th class="px-4 py-2">Guests</th>
            <th class="px-4 py-2">Status</th><th class="px-4 py-2">Added</th><?php if ($canEdit): ?><th class="px-4 py-2 text-right">Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="<?= $canEdit ? 6 : 5 ?>" class="px-4 py-6 text-center text-gray-400">No one on the waitlist.</td></tr>
          <?php else: foreach ($rows as $w): $st = (string) ($w['status'] ?? 'waiting'); ?>
            <tr class="border-b border-gray-50 last:border-0">
              <td class="px-4 py-2">
                <div class="font-medium text-gray-900"><?= htmlspecialchars((string) ($w['guest_name'] ?? '')) ?></div>
                <?php if (!empty($w['email']) || !empty($w['phone'])): ?>
                  <div class="text-xs text-gray-400"><?= htmlspecialchars(trim((string) ($w['email'] ?? '') . ' ' . (string) ($w['phone'] ?? ''))) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) ($w['checkin'] ?? '')) ?> → <?= htmlspecialchars((string) ($w['checkout'] ?? '')) ?></td>
              <td class="px-4 py-2 text-gray-600"><?= (int) ($w['pax'] ?? 1) ?></td>
              <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? 'bg-gray-100 text-gray-500' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
              <td class="px-4 py-2 text-gray-400 text-xs"><?= htmlspecialchars(substr((string) ($w['created_at'] ?? ''), 0, 10)) ?></td>
              <?php if ($canEdit): ?>
              <td class="px-4 py-2 text-right">
                <?php if ($st === 'waiting'): ?>
                  <form action="<?= root ?>supplier/stays/<?= $sid ?>/waitlist/<?= (int) $w['id'] ?>/status" method="POST" class="inline">
                    <?= CSRF::tokenField() ?><input type="hidden" name="status" value="converted">
                    <button type="submit" class="btn secondary text-xs">Mark converted</button>
                  </form>
                  <form action="<?= root ?>supplier/stays/<?= $sid ?>/waitlist/<?= (int) $w['id'] ?>/status" method="POST" class="inline">
                    <?= CSRF::tokenField() ?><input type="hidden" name="status" value="cancelled">
                    <button type="submit" class="btn secondary text-xs">Cancel</button>
                  </form>
                <?php else: ?>
                  <span class="text-xs text-gray-300">—</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
