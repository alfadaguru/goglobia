<?php
// app/views/admin/support/campaigns-manage.php — create/edit a GIA ad campaign.
// Mirrors app/views/admin/campaigns/campaigns-manage.php (left content / right
// attribution panel). Posts to admin/support/campaigns/save.
@$SECURE or die('Access Denied!');

$cid = (int) ($_GET['id'] ?? 0);
$c = $cid > 0 ? $db->get('gia_ad_campaigns', '*', ['id' => $cid]) : null;
if ($cid > 0 && !$c) {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Campaign not found.'];
    redirect(root . admin . '/support/campaigns');
    return;
}

$products = function_exists('support_campaign_offer_products')
    ? support_campaign_offer_products()
    : ['flights', 'stays', 'umrah', 'tours', 'cars', 'visa', 'esim', 'ferries', 'rail', 'bus'];

$v = function ($k, $d = '') use ($c) { return htmlspecialchars((string) ($c[$k] ?? $d), ENT_QUOTES); };

// Preformat window values for the datetime-local inputs (YYYY-MM-DDTHH:MM).
$dtVal = function ($raw): string {
    $raw = (string) $raw;
    if ($raw === '') { return ''; }
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d\TH:i', $ts) : '';
};
$curPlatform = strtolower((string) ($c['platform'] ?? 'other'));
$curProduct  = strtolower((string) ($c['offer_product'] ?? ''));
?>

<div class="container my-4" style="max-width:1000px">
  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center gap-2 mb-5">
    <a href="<?= root.admin ?>/support/campaigns" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-xl font-bold text-gray-900"><?= $cid > 0 ? 'Edit campaign' : 'New campaign' ?></h1>
    <?php if ($c): ?>
      <span class="ml-2 text-xs font-bold uppercase tracking-wide px-2 py-0.5 rounded-full"
            style="<?= (int) $c['active'] === 1 ? 'background:#dcfce7;color:#166534' : 'background:#f1f5f9;color:#475569' ?>"><?= (int) $c['active'] === 1 ? 'active' : 'off' ?></span>
    <?php endif; ?>
  </div>

  <form method="POST" action="<?= root.admin ?>/support/campaigns/save">
    <?= CSRF::tokenField() ?>
    <input type="hidden" name="id" value="<?= $cid ?>">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Left: campaign + offer -->
      <div class="lg:col-span-2 space-y-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Campaign name</label>
          <input type="text" name="name" value="<?= $v('name') ?>" required
                 class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Platform</label>
          <select name="platform" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <?php foreach (['meta' => 'Meta (Facebook / Instagram)', 'tiktok' => 'TikTok', 'google' => 'Google', 'other' => 'Other'] as $pk => $plbl): ?>
              <option value="<?= $pk ?>" <?= $curPlatform === $pk ? 'selected' : '' ?>><?= $plbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Offer product <span class="text-gray-400 font-normal">(which service this ad promotes)</span></label>
          <select name="offer_product" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <option value="">— None / general —</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= $p ?>" <?= $curProduct === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Offer intent <span class="text-gray-400 font-normal">(what GIA should know the visitor came for)</span></label>
          <textarea name="offer_intent" rows="3"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="e.g. Umrah December packages from Kano, 14 nights, group rate"><?= htmlspecialchars((string) ($c['offer_intent'] ?? ''), ENT_QUOTES) ?></textarea>
          <p class="text-xs text-gray-500 mt-1">A short, plain-language note on the offer — GIA uses this to greet ad visitors with the right context.</p>
        </div>
      </div>

      <!-- Right: attribution + state -->
      <div class="space-y-4">
        <div class="rounded-xl border border-gray-100 p-4 bg-gray-50">
          <h3 class="text-sm font-bold text-gray-900 mb-1">Attribution</h3>
          <p class="text-xs text-gray-500 mb-3">How we recognise a visitor arriving from this ad. Match key is checked first, then the UTM fields.</p>

          <label class="block text-xs font-semibold text-gray-600 mb-1">Match key <span class="text-gray-400 font-normal">(usually the utm_campaign value)</span></label>
          <input type="text" name="match_key" value="<?= $v('match_key') ?>"
                 class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-3" placeholder="e.g. umrah-dec-kano">

          <label class="block text-xs font-semibold text-gray-600 mb-1">utm_source</label>
          <input type="text" name="utm_source" value="<?= $v('utm_source') ?>"
                 class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-3" placeholder="e.g. facebook">

          <label class="block text-xs font-semibold text-gray-600 mb-1">utm_campaign</label>
          <input type="text" name="utm_campaign" value="<?= $v('utm_campaign') ?>"
                 class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-3" placeholder="e.g. umrah-dec-kano">

          <label class="block text-xs font-semibold text-gray-600 mb-1">utm_medium</label>
          <input type="text" name="utm_medium" value="<?= $v('utm_medium') ?>"
                 class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="e.g. cpc">
        </div>

        <div class="rounded-xl border border-gray-100 p-4 bg-gray-50">
          <h3 class="text-sm font-bold text-gray-900 mb-3">Status &amp; window</h3>

          <label class="flex items-center gap-2 mb-3 cursor-pointer">
            <input type="checkbox" name="active" value="1" <?= ($c === null || (int) $c['active'] === 1) ? 'checked' : '' ?>>
            <span class="text-sm text-gray-800">Active <span class="block text-[11px] text-gray-400">only active campaigns are matched against visits</span></span>
          </label>

          <label class="block text-xs font-semibold text-gray-600 mb-1">Starts at <span class="text-gray-400 font-normal">(optional)</span></label>
          <input type="datetime-local" name="starts_at" value="<?= htmlspecialchars($dtVal($c['starts_at'] ?? ''), ENT_QUOTES) ?>"
                 class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-3">

          <label class="block text-xs font-semibold text-gray-600 mb-1">Ends at <span class="text-gray-400 font-normal">(optional)</span></label>
          <input type="datetime-local" name="ends_at" value="<?= htmlspecialchars($dtVal($c['ends_at'] ?? ''), ENT_QUOTES) ?>"
                 class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <p class="text-xs text-gray-500 mt-2">Leave both blank for an always-on campaign.</p>
        </div>

        <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-black transition">Save campaign</button>
      </div>
    </div>
  </form>
</div>
