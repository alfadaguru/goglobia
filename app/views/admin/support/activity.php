<?php
// app/views/admin/support/activity.php — employee activity log (S7).
// Renders admin_activity_log newest-first with a per-employee filter + paging.
// Vars from the route: $rows, $employees, $filterUser, $page, $pages, $total.
@$SECURE or die('Access Denie'.'d!');

$nameOf = function (array $r): string {
    $n = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
    return $n !== '' ? $n : (string) $r['user_id'];
};
?>

<div class="container my-4">
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Employee Activity</h1>
      <p class="text-sm text-gray-500 mt-0.5"><?= number_format((int) $total) ?> logged request<?= (int) $total === 1 ? '' : 's' ?> &middot; every admin-panel action &amp; page view</p>
    </div>
    <form method="GET" action="<?= root.admin ?>/employees/activity" class="flex items-center gap-2">
      <select name="user_id" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" onchange="this.form.submit()">
        <option value="">All employees</option>
        <?php foreach ($employees as $e): ?>
          <?php $uid = (string) $e['user_id']; $nm = trim(((string)($e['first_name']??'')).' '.((string)($e['last_name']??''))); ?>
          <option value="<?= htmlspecialchars($uid, ENT_QUOTES) ?>" <?= $filterUser === $uid ? 'selected' : '' ?>>
            <?= htmlspecialchars($nm !== '' ? $nm : $uid) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (empty($rows)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">history</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No activity yet</h3>
      <p class="text-sm text-gray-500 mt-1">Admin-panel actions by employees will appear here.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto border border-gray-100 rounded-xl">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 font-semibold">When</th>
            <th class="px-4 py-3 font-semibold">Employee</th>
            <th class="px-4 py-3 font-semibold">Role</th>
            <th class="px-4 py-3 font-semibold">Area</th>
            <th class="px-4 py-3 font-semibold">Action</th>
            <th class="px-4 py-3 font-semibold">Result</th>
            <th class="px-4 py-3 font-semibold">Request</th>
            <th class="px-4 py-3 font-semibold">IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50">
              <td class="px-4 py-2.5 text-gray-500 whitespace-nowrap"><?= htmlspecialchars((string) ($r['created_at'] ?? '')) ?></td>
              <td class="px-4 py-2.5 font-medium text-gray-900"><?= htmlspecialchars($nameOf($r)) ?></td>
              <td class="px-4 py-2.5 text-gray-600"><?= htmlspecialchars((string) ($r['role_name'] ?: '—')) ?></td>
              <td class="px-4 py-2.5"><code class="text-xs text-gray-700"><?= htmlspecialchars((string) $r['area']) ?></code></td>
              <td class="px-4 py-2.5"><code class="text-xs text-gray-700"><?= htmlspecialchars((string) $r['action']) ?></code></td>
              <td class="px-4 py-2.5">
                <?php if ((int) ($r['allowed'] ?? 1) === 1): ?>
                  <span style="background:#dcfce7;color:#166534;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px">ALLOWED</span>
                <?php else: ?>
                  <span style="background:#fee2e2;color:#991b1b;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px">DENIED</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-2.5 text-gray-500">
                <span class="font-mono text-xs"><?= htmlspecialchars((string) ($r['method'] ?? '')) ?></span>
                <span class="text-xs truncate inline-block align-bottom" style="max-width:280px"><?= htmlspecialchars((string) ($r['path'] ?? '')) ?></span>
              </td>
              <td class="px-4 py-2.5 text-gray-400 text-xs"><?= htmlspecialchars((string) ($r['ip'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ((int) $pages > 1): ?>
      <div class="flex items-center justify-center gap-2 mt-4 text-sm">
        <?php $qs = $filterUser !== '' ? ('&user_id=' . urlencode($filterUser)) : ''; ?>
        <?php if ((int) $page > 1): ?>
          <a href="<?= root.admin ?>/employees/activity?page=<?= (int)$page - 1 ?><?= $qs ?>" class="px-3 py-1.5 rounded border border-gray-200 hover:bg-gray-50">Prev</a>
        <?php endif; ?>
        <span class="text-gray-500">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
        <?php if ((int) $page < (int) $pages): ?>
          <a href="<?= root.admin ?>/employees/activity?page=<?= (int)$page + 1 ?><?= $qs ?>" class="px-3 py-1.5 rounded border border-gray-200 hover:bg-gray-50">Next</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
