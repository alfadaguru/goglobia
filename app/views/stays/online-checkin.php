<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $ok = !empty($ok);
  $b = (isset($booking) && is_array($booking)) ? $booking : [];
  $bd = (isset($bd) && is_array($bd)) ? $bd : [];
  $inv = (string) ($invoiceId ?? ($b['invoice_id'] ?? ''));
  $done = !empty($bd['online_checkin']['done']);
  $guest = trim((string) ($b['first_name'] ?? '') . ' ' . (string) ($b['last_name'] ?? ''));
  $msg = $_SESSION['message'] ?? null; unset($_SESSION['message']);
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Online check-in</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#15181d;background:#f6f7f9;margin:0;padding:32px 16px;}
  .card{max-width:480px;margin:0 auto;background:#fff;border:1px solid #e3e6ec;border-radius:12px;padding:24px;}
  h1{font-size:20px;margin:0 0 4px;} .muted{color:#667085;font-size:13px;margin:0 0 16px;}
  label{display:block;font-size:13px;color:#344054;margin:12px 0 4px;}
  input,textarea{width:100%;box-sizing:border-box;border:1px solid #d0d5dd;border-radius:8px;padding:9px 11px;font-size:14px;font-family:inherit;}
  button{margin-top:18px;background:#059669;color:#fff;border:none;border-radius:8px;padding:11px 16px;font-size:15px;cursor:pointer;width:100%;}
  .ok{background:#e8f5ef;color:#1a7f5a;border:1px solid #bfe6d3;border-radius:8px;padding:12px;font-size:14px;margin-bottom:16px;}
  .err{background:#fbe6e4;color:#b3261e;border:1px solid #f2c4c0;border-radius:8px;padding:12px;font-size:14px;margin-bottom:16px;}
</style>
</head><body>
  <div class="card">
    <?php if (!$ok): ?>
      <h1>Booking not found</h1>
      <p class="muted">We couldn't find a check-in for that reference. Please check the link from your confirmation.</p>
    <?php else: ?>
      <h1>Online check-in</h1>
      <p class="muted"><?= htmlspecialchars($guest) ?> · booking <?= htmlspecialchars($inv) ?></p>
      <?php if ($msg): ?><div class="<?= ($msg['type'] ?? '') === 'error' ? 'err' : 'ok' ?>"><?= htmlspecialchars((string) ($msg['text'] ?? '')) ?></div><?php endif; ?>
      <?php if ($done && !$msg): ?><div class="ok">You've already checked in online. You can update your details below.</div><?php endif; ?>
      <form action="<?= root ?>stay-checkin/<?= rawurlencode($inv) ?>" method="POST">
        <?= CSRF::tokenField() ?>
        <label>Estimated arrival time</label>
        <input type="text" name="eta" value="<?= htmlspecialchars((string) ($bd['online_checkin']['eta'] ?? '')) ?>" placeholder="e.g. 3:00 PM">
        <label>ID / passport number</label>
        <input type="text" name="id_number" value="<?= htmlspecialchars((string) ($bd['online_checkin']['id_number'] ?? '')) ?>">
        <label>Home address</label>
        <input type="text" name="address" value="<?= htmlspecialchars((string) ($bd['online_checkin']['address'] ?? '')) ?>">
        <label>Special requests</label>
        <textarea name="special_requests" rows="3"><?= htmlspecialchars((string) ($bd['online_checkin']['special_requests'] ?? '')) ?></textarea>
        <button type="submit">Complete check-in</button>
      </form>
    <?php endif; ?>
  </div>
</body></html>
<?php exit; ?>
