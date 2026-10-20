<?php
// app/views/admin/campaigns/subscribers.php — newsletter subscriber management.
// List + status filter + email search + CSV export + per-row actions.
@$SECURE or die('Access Denied!');

// ---- Filters (from query string) ----
$status = (string) ($_GET['status'] ?? '');
$status = in_array($status, ['pending','subscribed','unsubscribed','bounced'], true) ? $status : '';
$q      = trim((string) ($_GET['q'] ?? ''));

$where = [];
if ($status !== '') { $where['status'] = $status; }
if ($q !== '')      { $where['email[~]'] = $q; }

// ---- Pagination ----
$perPage = 50;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$countWhere = $where; // without ORDER/LIMIT
$totalRows  = (int) $db->count('newsletter_subscribers', $countWhere ?: ['id[>]' => 0]);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; }

$listWhere = $where;
$listWhere['ORDER'] = ['id' => 'DESC'];
$listWhere['LIMIT'] = [($page - 1) * $perPage, $perPage];
$subs = $db->select('newsletter_subscribers',
    ['id','email','first_name','status','source','country','language','confirmed_at','unsubscribed_at','created_at'],
    $listWhere) ?: [];

// ---- Status counts for the filter chips ----
$counts = [
    'all'          => (int) $db->count('newsletter_subscribers', ['id[>]' => 0]),
    'subscribed'   => (int) $db->count('newsletter_subscribers', ['status' => 'subscribed']),
    'pending'      => (int) $db->count('newsletter_subscribers', ['status' => 'pending']),
    'unsubscribed' => (int) $db->count('newsletter_subscribers', ['status' => 'unsubscribed']),
    'bounced'      => (int) $db->count('newsletter_subscribers', ['status' => 'bounced']),
];

$statusStyle = function (string $s): string {
    $map = [
        'subscribed'   => 'background:#dcfce7;color:#166534',
        'pending'      => 'background:#fef9c3;color:#854d0e',
        'unsubscribed' => 'background:#f1f5f9;color:#475569',
        'bounced'      => 'background:#fee2e2;color:#991b1b',
    ];
    return $map[$s] ?? $map['pending'];
};

// Preserve the current filter in action/export/pagination links.
$qs = function (array $override = []) use ($status, $q, $page): string {
    $p = array_merge(['status' => $status, 'q' => $q, 'page' => $page], $override);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return $p ? ('?' . http_build_query($p)) : '';
};
?>

<div class="container my-4" style="max-width:1100px">
  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Subscribers</h1>
      <p class="text-sm text-gray-500 mt-0.5"><?= number_format($counts['all']) ?> total &middot; <?= number_format($counts['subscribed']) ?> confirmed</p>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= root.admin ?>/campaigns/subscribers/export<?= $qs(['page' => '']) ?>"
         class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:border-gray-400 transition">
        <span class="material-symbols-outlined !text-[18px]">download</span> Export CSV
      </a>
      <a href="<?= root.admin ?>/campaigns"
         class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
        <span class="material-symbols-outlined !text-[18px]">campaign</span> Campaigns
      </a>
    </div>
  </div>

  <!-- Filter chips + search -->
  <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
    <div class="flex items-center gap-1.5 flex-wrap">
      <?php
      $chips = [
        ''             => ['All', $counts['all']],
        'subscribed'   => ['Confirmed', $counts['subscribed']],
        'pending'      => ['Pending', $counts['pending']],
        'unsubscribed' => ['Unsubscribed', $counts['unsubscribed']],
        'bounced'      => ['Bounced', $counts['bounced']],
      ];
      foreach ($chips as $key => [$label, $n]):
        $active = ($status === $key);
      ?>
        <a href="<?= root.admin ?>/campaigns/subscribers<?= ($key !== '' || $q !== '') ? ('?' . http_build_query(array_filter(['status' => $key, 'q' => $q]))) : '' ?>"
           class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition <?= $active ? 'text-white' : 'text-gray-600 border border-gray-300 hover:border-gray-400' ?>"
           style="<?= $active ? 'background:#111827' : '' ?>">
          <?= htmlspecialchars($label) ?>
          <span class="<?= $active ? 'opacity-80' : 'text-gray-400' ?>"><?= number_format($n) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <form method="GET" action="<?= root.admin ?>/campaigns/subscribers" class="flex items-center gap-2">
      <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES) ?>"><?php endif; ?>
      <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Search email…"
             class="rounded-lg border border-gray-300 px-3 py-2 text-sm" style="min-width:220px">
      <button type="submit" class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-black transition">Search</button>
    </form>
  </div>

  <?php if (empty($subs)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">group</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No subscribers found</h3>
      <p class="text-sm text-gray-500 mt-1"><?= ($status !== '' || $q !== '') ? 'Try clearing the filter or search.' : 'Subscribers appear here once people sign up and confirm.' ?></p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto border border-gray-100 rounded-xl">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 font-semibold">Email</th>
            <th class="px-4 py-3 font-semibold">Name</th>
            <th class="px-4 py-3 font-semibold">Status</th>
            <th class="px-4 py-3 font-semibold">Source</th>
            <th class="px-4 py-3 font-semibold">Joined</th>
            <th class="px-4 py-3 font-semibold text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subs as $s): $st = (string) $s['status']; ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50">
              <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars((string) $s['email']) ?></td>
              <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars((string) ($s['first_name'] ?? '')) ?: '<span class="text-gray-300">—</span>' ?></td>
              <td class="px-4 py-3">
                <span style="<?= $statusStyle($st) ?>;font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em"><?= htmlspecialchars($st) ?></span>
              </td>
              <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($s['source'] ?? '')) ?></td>
              <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($s['created_at'] ?? '')) ?></td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <?php if ($st !== 'unsubscribed'): ?>
                  <form method="POST" action="<?= root.admin ?>/campaigns/subscribers/action" class="inline">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="action" value="unsubscribe">
                    <button type="submit" class="text-gray-600 hover:underline font-medium">Unsubscribe</button>
                  </form>
                <?php else: ?>
                  <form method="POST" action="<?= root.admin ?>/campaigns/subscribers/action" class="inline">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="action" value="resubscribe">
                    <button type="submit" class="text-green-600 hover:underline font-medium">Re-subscribe</button>
                  </form>
                <?php endif; ?>
                <form method="POST" action="<?= root.admin ?>/campaigns/subscribers/action" class="inline ml-3" onsubmit="return confirm('Permanently delete this subscriber?');">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button type="submit" class="text-red-600 hover:underline font-medium">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="flex items-center justify-between mt-4 text-sm text-gray-500">
        <div>Page <?= $page ?> of <?= $totalPages ?> &middot; <?= number_format($totalRows) ?> result<?= $totalRows === 1 ? '' : 's' ?></div>
        <div class="flex items-center gap-2">
          <?php if ($page > 1): ?>
            <a href="<?= root.admin ?>/campaigns/subscribers<?= $qs(['page' => $page - 1]) ?>" class="rounded-lg border border-gray-300 px-3 py-1.5 hover:border-gray-400">Previous</a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <a href="<?= root.admin ?>/campaigns/subscribers<?= $qs(['page' => $page + 1]) ?>" class="rounded-lg border border-gray-300 px-3 py-1.5 hover:border-gray-400">Next</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
