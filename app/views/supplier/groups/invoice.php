<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $g = $inv['group'] ?? [];
  $blocks = $inv['blocks'] ?? [];
  $items = $inv['items'] ?? [];
  $totals = $inv['totals'] ?? ['charges' => 0, 'payments' => 0, 'balance' => 0];
  $cur = (string) ($stay['currency'] ?? ($g['currency'] ?? ''));
  $money = fn($n) => htmlspecialchars($cur) . ' ' . number_format((float) $n, 2);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Group invoice — <?= htmlspecialchars((string) ($g['reference'] ?? '')) ?></title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1f2937;max-width:800px;margin:24px auto;padding:0 20px;font-size:14px}
  h1{font-size:20px;margin:0 0 2px} .muted{color:#6b7280;font-size:12px}
  table{width:100%;border-collapse:collapse;margin:14px 0} th,td{text-align:left;padding:7px 8px;border-bottom:1px solid #eee}
  th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280} td.r,th.r{text-align:right}
  .tot{display:flex;justify-content:space-between;padding:4px 0} .tot.bal{font-weight:700;border-top:2px solid #111;margin-top:6px;padding-top:8px}
  .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:10px}
  @media print{.noprint{display:none}}
</style></head><body>
<div class="head">
  <div>
    <h1><?= htmlspecialchars((string) ($stay['name'] ?? 'Property')) ?></h1>
    <div class="muted"><?= htmlspecialchars((string) ($stay['address'] ?? '')) ?></div>
  </div>
  <div style="text-align:right">
    <div style="font-weight:700">GROUP INVOICE</div>
    <div class="muted">Ref <?= htmlspecialchars((string) ($g['reference'] ?? '')) ?></div>
    <div class="muted"><?= htmlspecialchars((string) ($g['arrival'] ?? '')) ?> → <?= htmlspecialchars((string) ($g['departure'] ?? '')) ?></div>
  </div>
</div>

<p class="muted" style="margin-top:12px">
  <strong><?= htmlspecialchars((string) ($g['name'] ?? '')) ?></strong>
  <?= !empty($g['company']) ? ' · ' . htmlspecialchars((string) $g['company']) : '' ?>
  <?= !empty($g['contact_email']) ? ' · ' . htmlspecialchars((string) $g['contact_email']) : '' ?>
</p>

<?php if (!empty($blocks)): ?>
<table>
  <thead><tr><th>Room block</th><th>Dates</th><th class="r">Rooms</th><th class="r">Rate/night</th><th>State</th></tr></thead>
  <tbody>
    <?php foreach ($blocks as $b): ?>
      <tr>
        <td>#<?= (int) $b['id'] ?></td>
        <td><?= htmlspecialchars((string) $b['date_from']) ?> → <?= htmlspecialchars((string) $b['date_to']) ?></td>
        <td class="r"><?= (int) $b['qty'] ?></td>
        <td class="r"><?= $b['rate'] !== null ? $money($b['rate']) : '—' ?></td>
        <td><?= htmlspecialchars((string) $b['state']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<table>
  <thead><tr><th>Folio item</th><th>Type</th><th class="r">Amount</th></tr></thead>
  <tbody>
    <?php if (empty($items)): ?><tr><td colspan="3" class="muted">No folio items.</td></tr>
    <?php else: foreach ($items as $it): ?>
      <tr><td><?= htmlspecialchars((string) ($it['description'] ?? '')) ?></td><td><?= htmlspecialchars((string) $it['type']) ?></td><td class="r"><?= $money($it['amount']) ?></td></tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<div style="max-width:280px;margin-left:auto">
  <div class="tot"><span>Charges</span><span><?= $money($totals['charges']) ?></span></div>
  <div class="tot"><span>Payments</span><span>− <?= $money($totals['payments']) ?></span></div>
  <div class="tot bal"><span>Balance due</span><span><?= $money($totals['balance']) ?></span></div>
</div>

<p class="noprint" style="margin-top:20px"><button onclick="window.print()">Print</button></p>
</body></html>
