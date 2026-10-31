<?php
// app/views/admin/support/campaigns.php — GIA ad-campaign registry list.
// Mirrors app/views/admin/campaigns/campaigns.php.
@$SECURE or die('Access Denied!');

$campaigns = $db->select('gia_ad_campaigns', '*', ['ORDER' => ['id' => 'DESC']]) ?: [];

// Platform badge colours (meta/tiktok/google/other).
$platformBadge = function (string $p): string {
    $map = [
        'meta'   => 'background:#e0e7ff;color:#3730a3',
        'tiktok' => 'background:#1f2937;color:#fff',
        'google' => 'background:#fef3c7;color:#92400e',
        'other'  => 'background:#f1f5f9;color:#475569',
    ];
    $style = $map[$p] ?? $map['other'];
    return '<span style="' . $style . ';font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em">' . htmlspecialchars($p) . '</span>';
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
      <h1 class="text-xl font-bold text-gray-900">Ad Campaigns</h1>
      <p class="text-sm text-gray-500 mt-0.5">Register Meta / TikTok / Google offers so GIA greets ad visitors campaign-aware and tracks ad &rarr; chat &rarr; held &rarr; paid conversion.</p>
    </div>
    <div class="flex items-center gap-2">
      <button type="button" id="seedDecBtn" onclick="seedDecemberCampaigns()"
         class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
        <span class="material-symbols-outlined !text-[18px]">bolt</span> Seed December campaigns
      </button>
      <a href="<?= root.admin ?>/support/campaigns/create"
         class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
        <span class="material-symbols-outlined !text-[18px]">add</span> New campaign
      </a>
    </div>
  </div>
  <div id="seedDecResult" class="mb-4 text-sm"></div>
  <script>
  function seedDecemberCampaigns() {
    if (!confirm('Create the December ad campaigns (2 Umrah: 16th & 25th, and 8 domestic-flight routes) so GIA is aware of them? This registers CAMPAIGNS ONLY — it does NOT set Umrah prices or publish departures (do that in Admin → Umrah). Safe to run more than once.')) { return; }
    var btn = document.getElementById('seedDecBtn');
    var out = document.getElementById('seedDecResult');
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute('content') : '';
    btn.disabled = true;
    out.innerHTML = '<span class="text-gray-500">Creating campaigns…</span>';
    var body = new URLSearchParams(); body.append('csrf_token', token);
    fetch('<?= root.admin ?>/support/campaigns/seed-december', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': token },
      body: body.toString()
    })
    .then(function (r) { return r.text(); })
    .then(function (t) {
      var d; try { d = JSON.parse(t); } catch (e) { d = { ok: false, message: 'Unexpected server response.' }; }
      if (d.ok) {
        out.innerHTML = '<span class="text-green-600">' + (d.message || 'Done.') + '</span> <a href="" class="underline text-blue-600">Reload to see them</a>';
      } else {
        out.innerHTML = '<span class="text-red-600">' + (d.message || 'Failed') + '</span>';
      }
    })
    .catch(function () { out.innerHTML = '<span class="text-red-600">Request failed.</span>'; })
    .finally(function () { btn.disabled = false; });
  }
  </script>

  <?php if (empty($campaigns)): ?>
    <div class="text-center py-16 border border-dashed border-gray-200 rounded-xl bg-gray-50">
      <span class="material-symbols-outlined !text-[48px] text-gray-300">ads_click</span>
      <h3 class="mt-3 text-base font-semibold text-gray-700">No campaigns yet</h3>
      <p class="text-sm text-gray-500 mt-1">Register your first ad campaign so GIA can answer its landing visitors with the right offer.</p>
      <a href="<?= root.admin ?>/support/campaigns/create" class="inline-block mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">New campaign</a>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto border border-gray-100 rounded-xl">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 font-semibold">Platform</th>
            <th class="px-4 py-3 font-semibold">Campaign</th>
            <th class="px-4 py-3 font-semibold">Offer product</th>
            <th class="px-4 py-3 font-semibold">UTM campaign / match key</th>
            <th class="px-4 py-3 font-semibold">Active</th>
            <th class="px-4 py-3 font-semibold">Created</th>
            <th class="px-4 py-3 font-semibold text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($campaigns as $c): ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50">
              <td class="px-4 py-3"><?= $platformBadge((string) ($c['platform'] ?? 'other')) ?></td>
              <td class="px-4 py-3">
                <div class="font-semibold text-gray-900"><?= htmlspecialchars((string) $c['name']) ?></div>
                <?php if (!empty($c['offer_intent'])): ?>
                  <div class="text-xs text-gray-500 truncate" style="max-width:320px"><?= htmlspecialchars((string) $c['offer_intent']) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <?php if (!empty($c['offer_product'])): ?>
                  <span class="text-xs font-semibold text-gray-700 bg-gray-100 rounded px-2 py-0.5 capitalize"><?= htmlspecialchars((string) $c['offer_product']) ?></span>
                <?php else: ?>
                  <span class="text-xs text-gray-400">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs text-gray-600">
                <?php if (!empty($c['utm_campaign'])): ?><div><span class="text-gray-400">utm:</span> <?= htmlspecialchars((string) $c['utm_campaign']) ?></div><?php endif; ?>
                <?php if (!empty($c['match_key'])): ?><div><span class="text-gray-400">match:</span> <?= htmlspecialchars((string) $c['match_key']) ?></div><?php endif; ?>
                <?php if (empty($c['utm_campaign']) && empty($c['match_key'])): ?><span class="text-gray-400">&mdash;</span><?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <?php if ((int) $c['active'] === 1): ?>
                  <span style="background:#dcfce7;color:#166534;font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px">ACTIVE</span>
                <?php else: ?>
                  <span style="background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px">OFF</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) $c['created_at']) ?></td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <a href="<?= root.admin ?>/support/campaigns/edit/<?= (int) $c['id'] ?>" class="text-blue-600 hover:underline font-medium">Edit</a>
                <form method="POST" action="<?= root.admin ?>/support/campaigns/delete" class="inline ml-3" onsubmit="return confirm('Delete this campaign? This cannot be undone.');">
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
