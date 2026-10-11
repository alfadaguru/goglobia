<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $b = $booking ?? [];
  $bd = $b['_bd'] ?? [];
  $p = $property ?? [];
  $inv = (string) ($b['invoice_id'] ?? '');
  $cur = htmlspecialchars((string) ($b['currency_markup'] ?? ($p['currency'] ?? '')));
  $guest = trim((string) ($b['first_name'] ?? '') . ' ' . (string) ($b['last_name'] ?? ''));
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registration card — <?= htmlspecialchars($inv) ?></title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#15181d;max-width:720px;margin:0 auto;padding:32px;}
  h1{font-size:20px;margin:0 0 2px;} .muted{color:#667085;font-size:13px;}
  .row{display:flex;gap:24px;flex-wrap:wrap;margin-top:18px;}
  .box{flex:1 1 220px;border:1px solid #e3e6ec;border-radius:8px;padding:14px;}
  .box h3{margin:0 0 8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#667085;}
  dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:14px;}
  dt{color:#667085;} dd{margin:0;text-align:right;font-variant-numeric:tabular-nums;}
  .sign{margin-top:40px;border-top:1px solid #15181d;padding-top:6px;width:260px;font-size:12px;color:#667085;}
  .vip{display:inline-block;background:#fbe6e4;color:#b3261e;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:8px;}
  @media print{.noprint{display:none;} body{padding:0;}}
  .btn{display:inline-block;background:#059669;color:#fff;border:none;border-radius:6px;padding:8px 14px;font-size:14px;cursor:pointer;text-decoration:none;}
</style>
</head><body>
  <div class="noprint" style="margin-bottom:18px;display:flex;gap:10px;">
    <button class="btn" onclick="window.print()">Print</button>
    <a class="btn" style="background:#64748b" href="<?= root ?>supplier/reservations/<?= rawurlencode($inv) ?>">Back</a>
  </div>

  <h1><?= htmlspecialchars((string) ($p['name'] ?? 'Property')) ?><?= !empty($bd['vip']) ? '<span class="vip">VIP</span>' : '' ?></h1>
  <div class="muted"><?= htmlspecialchars((string) ($p['location'] ?? '')) ?><?= !empty($p['address']) ? ' · ' . htmlspecialchars((string) $p['address']) : '' ?></div>
  <div class="muted">Registration card · Invoice <?= htmlspecialchars($inv) ?></div>

  <div class="row">
    <div class="box">
      <h3>Guest</h3>
      <dl>
        <dt>Name</dt><dd><?= htmlspecialchars($guest) ?></dd>
        <dt>Email</dt><dd><?= htmlspecialchars((string) ($b['email'] ?? '')) ?></dd>
        <dt>Phone</dt><dd><?= htmlspecialchars((string) ($b['phone'] ?? '')) ?></dd>
        <dt>Guests</dt><dd><?= (int) ($b['adults'] ?? 0) ?> adult(s), <?= (int) ($b['childs'] ?? 0) ?> child(ren)</dd>
      </dl>
    </div>
    <div class="box">
      <h3>Stay</h3>
      <dl>
        <dt>Check-in</dt><dd><?= htmlspecialchars((string) ($bd['checkin'] ?? '')) ?> <?= htmlspecialchars(substr((string) ($p['checkin_time'] ?? ''), 0, 5)) ?></dd>
        <dt>Check-out</dt><dd><?= htmlspecialchars((string) ($bd['checkout'] ?? '')) ?> <?= htmlspecialchars(substr((string) ($p['checkout_time'] ?? ''), 0, 5)) ?></dd>
        <dt>Source</dt><dd><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($bd['booking_source'] ?? '')))) ?></dd>
        <dt>Total</dt><dd><?= $cur ?> <?= number_format((float) ($b['price_markup'] ?? 0), 2) ?></dd>
        <dt>Status</dt><dd><?= htmlspecialchars(ucfirst((string) ($b['payment_status'] ?? ''))) ?></dd>
      </dl>
    </div>
  </div>

  <p class="muted" style="margin-top:20px;">The guest confirms the above details are correct and agrees to the property's terms, cancellation policy and house rules.</p>
  <div class="sign">Guest signature &amp; date</div>
</body></html>
<?php exit; ?>
