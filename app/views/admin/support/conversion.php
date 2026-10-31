<?php
// app/views/admin/support/conversion.php — Ad-campaign CONVERSION ANALYTICS.
// Read-only report: one row per registered ad campaign (+ a "(none)" bucket for
// unattributed chats so totals reconcile), showing the funnel
//   ad -> chat (conversations) -> HELD booking -> PAID booking -> revenue.
// A date-range filter (GET from/to) scopes the window; defaults to last 30 days.
// Mirrors the Tailwind + material-symbols look of attendance.php / desk.php.
//
// Vars provided by the route: $report (array of funnel rows), $totals (array),
// $fromDate, $toDate (Y-m-d strings for the filter inputs).
@$SECURE or die('Access Denied!');

$base = root . admin . '/support';

// Platform label + accent — matches the LOCKED enum (meta/tiktok/google/other).
$platformMeta = [
    'meta'   => ['label' => 'Meta',   'cls' => 'bg-blue-50 text-blue-700'],
    'tiktok' => ['label' => 'TikTok', 'cls' => 'bg-gray-900/5 text-gray-800'],
    'google' => ['label' => 'Google', 'cls' => 'bg-amber-50 text-amber-700'],
    'other'  => ['label' => 'Other',  'cls' => 'bg-gray-100 text-gray-600'],
];

/** Format a rate (0..1 float) as a whole-ish percentage, e.g. 0.1234 -> "12.3%". */
if (!function_exists('support_fmt_rate')) {
    function support_fmt_rate(float $r): string
    {
        return number_format($r * 100, 1) . '%';
    }
}
/** Money with thousands separators; currency code prefixed when present. */
if (!function_exists('support_fmt_money')) {
    function support_fmt_money(float $amt, string $cur): string
    {
        $n = number_format($amt, 2);
        return $cur !== '' ? ($cur . ' ' . $n) : $n;
    }
}

// Overall held-rate on the summed totals (same held/conversations ratio as rows).
$overallRate = ($totals['conversations'] > 0)
    ? ($totals['held_bookings'] / $totals['conversations'])
    : 0.0;

// Dominant currency across the window (largest summed revenue) for the totals row.
$totalCur = '';
if (!empty($totals['currencies'])) {
    arsort($totals['currencies']);
    $totalCur = (string) array_key_first($totals['currencies']);
}
$mixedCurrency = count($totals['currencies']) > 1;
?>

<div class="container my-4">
  <!-- HEADER -->
  <div class="mb-4 flex items-center justify-between gap-3 flex-wrap">
    <div>
      <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
        <span class="material-symbols-outlined text-blue-600">insights</span>
        Conversion Analytics
      </h1>
      <p class="text-sm text-gray-500 mt-0.5">
        Ad&nbsp;&rarr;&nbsp;chat&nbsp;&rarr;&nbsp;held&nbsp;&rarr;&nbsp;paid, per ad campaign.
      </p>
    </div>
    <a href="<?= htmlspecialchars($base) ?>" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
      <span class="material-symbols-outlined !text-[18px]">arrow_back</span> Support home
    </a>
  </div>

  <!-- SUMMARY CARDS -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-5">
    <?php
    $cards = [
        ['label' => 'Conversations', 'icon' => 'forum',            'val' => number_format((int) $totals['conversations']), 'accent' => 'text-gray-900'],
        ['label' => 'Held bookings', 'icon' => 'pending_actions',  'val' => number_format((int) $totals['held_bookings']), 'accent' => 'text-amber-600'],
        ['label' => 'Paid bookings', 'icon' => 'task_alt',         'val' => number_format((int) $totals['paid_bookings']), 'accent' => 'text-emerald-600'],
        ['label' => 'Held rate',     'icon' => 'conversion_path',  'val' => support_fmt_rate($overallRate),                'accent' => 'text-blue-600'],
        ['label' => 'Revenue',       'icon' => 'payments',         'val' => support_fmt_money((float) $totals['revenue'], $totalCur), 'accent' => 'text-gray-900'],
    ];
    foreach ($cards as $c):
    ?>
      <div class="border border-gray-100 rounded-xl bg-white p-4">
        <div class="flex items-center gap-1.5 text-gray-400">
          <span class="material-symbols-outlined !text-[18px]"><?= $c['icon'] ?></span>
          <span class="text-[11px] font-medium uppercase tracking-wide"><?= htmlspecialchars($c['label']) ?></span>
        </div>
        <div class="mt-1.5 text-lg font-bold <?= $c['accent'] ?> break-words"><?= htmlspecialchars($c['val']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- TABLE + FILTER -->
  <div class="border border-gray-100 rounded-xl bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">
      <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
        <span class="material-symbols-outlined !text-[20px] text-blue-600">campaign</span>
        By campaign
      </h2>
      <form method="get" action="<?= htmlspecialchars($base . '/conversion') ?>" class="flex items-center gap-2 flex-wrap">
        <input type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>"
               class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white">
        <span class="text-gray-400 text-sm">&rarr;</span>
        <input type="date" name="to" value="<?= htmlspecialchars($toDate) ?>"
               class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white">
        <button type="submit" class="text-sm font-semibold px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700">Apply</button>
      </form>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500">
          <tr class="text-left">
            <th class="px-4 py-2 font-medium">Campaign</th>
            <th class="px-4 py-2 font-medium text-right">Conversations</th>
            <th class="px-4 py-2 font-medium text-right">Held</th>
            <th class="px-4 py-2 font-medium text-right">Paid</th>
            <th class="px-4 py-2 font-medium text-right">Held rate</th>
            <th class="px-4 py-2 font-medium text-right">Revenue</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($report)): ?>
            <tr><td class="px-4 py-6 text-center text-gray-400" colspan="6">No conversations in this window.</td></tr>
          <?php else: ?>
            <?php foreach ($report as $r):
              $isNone   = empty($r['campaign_id']);
              $platform = (string) ($r['platform'] ?? '');
              $pm       = $platformMeta[$platform] ?? null;
              $name     = (string) ($r['campaign_name'] ?? '');
              $product  = trim((string) ($r['offer_product'] ?? ''));
              $rate     = (float) ($r['conversion_rate'] ?? 0);
              $cur      = (string) ($r['revenue_currency'] ?? '');
            ?>
            <tr class="<?= $isNone ? 'bg-gray-50/40' : '' ?>">
              <td class="px-4 py-2.5">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-medium <?= $isNone ? 'text-gray-500 italic' : 'text-gray-900' ?>">
                    <?= htmlspecialchars($name) ?>
                  </span>
                  <?php if (!$isNone && $pm): ?>
                    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded <?= $pm['cls'] ?>">
                      <?= htmlspecialchars($pm['label']) ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($product !== ''): ?>
                    <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-600 uppercase tracking-wide">
                      <?= htmlspecialchars($product) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <?php if ($isNone): ?>
                  <div class="text-[11px] text-gray-400 mt-0.5">Chats not attributed to any campaign.</div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-2.5 text-right text-gray-700"><?= number_format((int) ($r['conversations'] ?? 0)) ?></td>
              <td class="px-4 py-2.5 text-right text-amber-600 font-medium"><?= number_format((int) ($r['held_bookings'] ?? 0)) ?></td>
              <td class="px-4 py-2.5 text-right text-emerald-600 font-semibold"><?= number_format((int) ($r['paid_bookings'] ?? 0)) ?></td>
              <td class="px-4 py-2.5 text-right text-gray-900 font-medium"><?= htmlspecialchars(support_fmt_rate($rate)) ?></td>
              <td class="px-4 py-2.5 text-right text-gray-900">
                <?= ((float) ($r['revenue'] ?? 0) != 0.0)
                      ? htmlspecialchars(support_fmt_money((float) $r['revenue'], $cur))
                      : '<span class="text-gray-300">&mdash;</span>' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php if (!empty($report)): ?>
        <tfoot class="bg-gray-50 border-t border-gray-100">
          <tr class="font-semibold text-gray-900">
            <td class="px-4 py-2.5">Total</td>
            <td class="px-4 py-2.5 text-right"><?= number_format((int) $totals['conversations']) ?></td>
            <td class="px-4 py-2.5 text-right text-amber-700"><?= number_format((int) $totals['held_bookings']) ?></td>
            <td class="px-4 py-2.5 text-right text-emerald-700"><?= number_format((int) $totals['paid_bookings']) ?></td>
            <td class="px-4 py-2.5 text-right text-blue-700"><?= htmlspecialchars(support_fmt_rate($overallRate)) ?></td>
            <td class="px-4 py-2.5 text-right"><?= htmlspecialchars(support_fmt_money((float) $totals['revenue'], $totalCur)) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <!-- PER-ROUTE FLIGHT FUNNEL (auto): which Nigerian routes convert. Directional. -->
  <div class="mt-6 rounded-xl border border-gray-100 bg-white shadow-sm">
    <div class="px-4 py-3 border-b border-gray-100">
      <h2 class="text-sm font-semibold text-gray-900">Flights — by route</h2>
      <p class="text-xs text-gray-500">Every flight chat is tracked by its searched route (origin&rarr;destination). Directional: LOS-ABV and ABV-LOS are separate. No campaign needed.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500">
          <tr class="text-left">
            <th class="px-4 py-2 font-medium">Route</th>
            <th class="px-4 py-2 font-medium text-right">Conversations</th>
            <th class="px-4 py-2 font-medium text-right">Held</th>
            <th class="px-4 py-2 font-medium text-right">Paid</th>
            <th class="px-4 py-2 font-medium text-right">Held rate</th>
            <th class="px-4 py-2 font-medium text-right">Revenue</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($routeReport)): ?>
            <tr><td class="px-4 py-6 text-center text-gray-400" colspan="6">No flight searches in this window.</td></tr>
          <?php else: ?>
            <?php foreach ($routeReport as $r):
              $rCur = trim((string) ($r['revenue_currency'] ?? '')); ?>
            <tr>
              <td class="px-4 py-2.5 font-medium text-gray-900"><?= htmlspecialchars((string) $r['route']) ?></td>
              <td class="px-4 py-2.5 text-right"><?= number_format((int) $r['conversations']) ?></td>
              <td class="px-4 py-2.5 text-right text-amber-700"><?= number_format((int) $r['held_bookings']) ?></td>
              <td class="px-4 py-2.5 text-right text-emerald-700"><?= number_format((int) $r['paid_bookings']) ?></td>
              <td class="px-4 py-2.5 text-right text-blue-700"><?= htmlspecialchars(support_fmt_rate((float) $r['conversion_rate'])) ?></td>
              <td class="px-4 py-2.5 text-right">
                <?= ((float) ($r['revenue'] ?? 0) != 0.0)
                      ? htmlspecialchars(support_fmt_money((float) $r['revenue'], $rCur))
                      : '<span class="text-gray-300">&mdash;</span>' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- NOTES: be honest about the proxies so the figures are read correctly. -->
  <div class="mt-3 text-[11px] leading-relaxed text-gray-400 space-y-0.5">
    <div><span class="font-semibold text-gray-500">Held</span> = conversations that produced at least one held (unpaid) booking. <span class="font-semibold text-gray-500">Held rate</span> = held ÷ conversations.</div>
    <div><span class="font-semibold text-gray-500">Paid</span> = GIA bookings (ai&nbsp;trip / umrah) linked to the conversation that reached <code>payment_status = paid</code>. <span class="font-semibold text-gray-500">Revenue</span> = realised revenue stamped on paid conversations.</div>
    <?php if ($mixedCurrency): ?>
      <div class="text-amber-500">Revenue spans multiple currencies — the totals row shows the dominant currency (<?= htmlspecialchars($totalCur) ?>) and is not an FX-converted sum.</div>
    <?php endif; ?>
  </div>
</div>
