<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $b = $booking ?? [];
  $bd = $b['_bd'] ?? [];
  $p = $property ?? [];
  $inv = (string) ($b['invoice_id'] ?? '');
  $items = (isset($folioItems) && is_array($folioItems)) ? $folioItems : [];
  $tot = (isset($folioTotals) && is_array($folioTotals)) ? $folioTotals : ['charges'=>0,'payments'=>0,'balance'=>0];
  $cur = htmlspecialchars((string) (($folio['currency'] ?? null) ?: ($b['currency_markup'] ?? ($p['currency'] ?? ''))));
  $guest = trim((string) ($b['first_name'] ?? '') . ' ' . (string) ($b['last_name'] ?? ''));
  $isPayType = function($t){ return in_array($t, ['payment','refund'], true); };
  $typeLabel = ['room'=>'Room','tax'=>'Taxes & fees','extra'=>'Extra','charge'=>'Charge','payment'=>'Payment','refund'=>'Refund'];
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice <?= htmlspecialchars($inv) ?></title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#15181d;max-width:720px;margin:0 auto;padding:32px;}
  h1{font-size:20px;margin:0;} .muted{color:#667085;font-size:13px;}
  .head{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:20px;}
  table{width:100%;border-collapse:collapse;margin-top:14px;font-size:14px;}
  th,td{text-align:left;padding:8px 6px;border-bottom:1px solid #eceef2;}
  td.amt,th.amt{text-align:right;font-variant-numeric:tabular-nums;}
  tfoot td{border-bottom:none;padding-top:6px;}
  tfoot .total{font-weight:700;font-size:15px;border-top:2px solid #15181d;}
  .pay{color:#1a7f5a;}
  @media print{.noprint{display:none;} body{padding:0;}}
  .btn{display:inline-block;background:#059669;color:#fff;border:none;border-radius:6px;padding:8px 14px;font-size:14px;cursor:pointer;text-decoration:none;}
</style>
</head><body>
  <div class="noprint" style="margin-bottom:18px;display:flex;gap:10px;">
    <button class="btn" onclick="window.print()">Print / Save PDF</button>
    <a class="btn" style="background:#64748b" href="<?= root ?>supplier/reservations/<?= rawurlencode($inv) ?>">Back</a>
  </div>

  <div class="head">
    <div>
      <h1><?= htmlspecialchars((string) ($p['name'] ?? 'Property')) ?></h1>
      <div class="muted"><?= htmlspecialchars((string) ($p['location'] ?? '')) ?><?= !empty($p['address']) ? ' · ' . htmlspecialchars((string) $p['address']) : '' ?></div>
      <?php if (!empty($p['email']) || !empty($p['phone'])): ?><div class="muted"><?= htmlspecialchars(trim((string) ($p['email'] ?? '') . '  ' . (string) ($p['phone'] ?? ''))) ?></div><?php endif; ?>
    </div>
    <div style="text-align:right;">
      <div style="font-size:16px;font-weight:700;">INVOICE</div>
      <div class="muted"><?= htmlspecialchars($inv) ?></div>
      <div class="muted"><?= htmlspecialchars(substr((string) ($b['paid_at'] ?? $b['booking_date'] ?? ''), 0, 10)) ?></div>
    </div>
  </div>

  <div class="muted">Billed to: <strong style="color:#15181d;"><?= htmlspecialchars($guest) ?></strong>
    <?= !empty($b['email']) ? ' · ' . htmlspecialchars((string) $b['email']) : '' ?></div>
  <div class="muted"><?= htmlspecialchars((string) ($bd['checkin'] ?? '')) ?> → <?= htmlspecialchars((string) ($bd['checkout'] ?? '')) ?></div>

  <table>
    <thead><tr><th>Item</th><th>Description</th><th class="amt">Amount</th></tr></thead>
    <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="3" class="muted" style="padding:16px 6px;">No folio lines.</td></tr>
      <?php else: foreach ($items as $it): $t = (string) ($it['type'] ?? ''); $pay = $isPayType($t); ?>
        <tr>
          <td><?= htmlspecialchars($typeLabel[$t] ?? ucfirst($t)) ?></td>
          <td class="muted"><?= htmlspecialchars((string) ($it['description'] ?? '')) ?></td>
          <td class="amt <?= $pay ? 'pay' : '' ?>"><?= $pay ? '−' : '' ?><?= $cur ?> <?= number_format((float) ($it['amount'] ?? 0), 2) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
      <tr><td></td><td class="amt muted">Charges</td><td class="amt"><?= $cur ?> <?= number_format((float) $tot['charges'], 2) ?></td></tr>
      <tr><td></td><td class="amt muted">Paid</td><td class="amt pay">−<?= $cur ?> <?= number_format((float) $tot['payments'], 2) ?></td></tr>
      <tr class="total"><td></td><td class="amt">Balance due</td><td class="amt"><?= $cur ?> <?= number_format((float) $tot['balance'], 2) ?></td></tr>
    </tfoot>
  </table>

  <p class="muted" style="margin-top:24px;">Thank you for your stay.</p>
</body></html>
<?php exit; ?>
