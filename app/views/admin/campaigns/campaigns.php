<?php
// app/views/admin/campaigns/campaigns.php — Email campaigns list.
@$SECURE or die('Access Denied!');

$campaigns = $db->select('email_campaigns', '*', ['ORDER' => ['id' => 'DESC']]) ?: [];
$subCount  = (int) $db->count('newsletter_subscribers', ['status' => 'subscribed']);

$statusBadge = function (string $s): string {
    $map = [
        'draft'     => 'background:#f1f5f9;color:#475569',
        'scheduled' => 'background:#fef9c3;color:#854d0e',
        'sending'   => 'background:#dbeafe;color:#1e40af',
        'sent'      => 'background:#dcfce7;color:#166534',
        'failed'    => 'background:#fee2e2;color:#991b1b',
    ];
    $style = $map[$s] ?? $map['draft'];
    return '<span style="' . $style . ';font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em">' . htmlspecialchars($s) . '</span>';
};
?>

<div class="container my-4">
  <?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Email Campaigns</h1>
      <p class="text-sm text-gray-500 mt-0.5"><?= number_format($subCount) ?> confirmed subscriber<?= $subCount === 1 ? '' : 's' ?> &middot; sends via your active email provider</p>
    </div>
    <a href="<?= root.admin ?>/campaigns/create"
       class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
      <span class="material-symbols-outlined !text-[18px]">add</span> New campaign
    </a>
  </div>

  <?php if (empty($campaigns)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">campaign</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No campaigns yet</h3>
      <p class="text-sm text-gray-500 mt-1">Create your first newsletter campaign to reach your subscribers.</p>
      <a href="<?= root.admin ?>/campaigns/create" class="inline-block mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">New campaign</a>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto border border-gray-100 rounded-xl">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 font-semibold">Campaign</th>
            <th class="px-4 py-3 font-semibold">Status</th>
            <th class="px-4 py-3 font-semibold text-right">Recipients</th>
            <th class="px-4 py-3 font-semibold text-right">Sent</th>
            <th class="px-4 py-3 font-semibold">Date</th>
            <th class="px-4 py-3 font-semibold text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($campaigns as $c): ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-gray-900"><?= htmlspecialchars($c['name']) ?></div>
                <div class="text-xs text-gray-500 truncate" style="max-width:320px"><?= htmlspecialchars($c['subject']) ?></div>
              </td>
              <td class="px-4 py-3"><?= $statusBadge((string) $c['status']) ?></td>
              <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int) $c['total_recipients']) ?></td>
              <td class="px-4 py-3 text-right tabular-nums">
                <?= number_format((int) $c['sent_count']) ?><?php if ((int)$c['failed_count'] > 0): ?><span class="text-red-600"> / <?= number_format((int)$c['failed_count']) ?> failed</span><?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-500 text-xs">
                <?php if (($c['status'] ?? '') === 'scheduled' && !empty($c['scheduled_at'])): ?>
                  <span class="material-symbols-outlined !text-[13px] align-middle" style="color:#854d0e">schedule</span>
                  <?= htmlspecialchars((string) $c['scheduled_at']) ?>
                <?php else: ?>
                  <?= htmlspecialchars((string) ($c['sent_at'] ?: $c['created_at'])) ?>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <a href="<?= root.admin ?>/campaigns/edit/<?= (int) $c['id'] ?>" class="text-blue-600 hover:underline font-medium"><?= in_array($c['status'], ['sent','sending'], true) ? 'View' : 'Edit' ?></a>
                <form method="POST" action="<?= root.admin ?>/campaigns/delete" class="inline ml-3" onsubmit="return confirm('Delete this campaign? This cannot be undone.');">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button type="submit" class="text-red-600 hover:underline font-medium">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
