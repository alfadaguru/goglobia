<?php
// app/views/agent/trip-proposal.php — PUBLIC client proposal (token-gated),
// standalone page rendered in the AGENCY brand. Vars: $trip (nullable), $brand
// (nullable), $items, $totals.
@$SECURE or die('Access Denied!');
if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
$ok = !empty($trip);
$primary = '#7c3aed'; $accent = '#f59e0b';
if ($ok && !empty($brand['colorPrimary']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$brand['colorPrimary'])) { $primary = (string)$brand['colorPrimary']; }
if ($ok && !empty($brand['colorAccent']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$brand['colorAccent'])) { $accent = (string)$brand['colorAccent']; }
$company = $ok ? ((string)($brand['companyName'] ?? 'Travel Proposal')) : 'Travel Proposal';
$ccy = $ok ? htmlspecialchars((string)($trip['currency'] ?? '')) : '';
$fmt = fn($n) => number_format((float)$n, 2);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $ok ? htmlspecialchars((string)$trip['title']).' — '.htmlspecialchars($company) : 'Proposal not found' ?></title>
<style>
  :root { --p: <?= htmlspecialchars($primary) ?>; --a: <?= htmlspecialchars($accent) ?>; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7f9; color:#1f2937; line-height:1.5; }
  .wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px; }
  .card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden; }
  .head { background: var(--p); color:#fff; padding:22px 24px; }
  .head .co { font-size:20px; font-weight:800; }
  .head .ti { opacity:.9; margin-top:2px; }
  .logo { height:40px; margin-bottom:8px; }
  .body { padding:22px 24px; }
  .msg { background:#f9fafb; border-left:3px solid var(--a); padding:10px 14px; border-radius:6px; color:#374151; margin-bottom:18px; font-size:14px; }
  table { width:100%; border-collapse:collapse; }
  th { text-align:left; font-size:11px; text-transform:uppercase; color:#6b7280; padding:8px; border-bottom:2px solid #eef0f3; }
  td { padding:10px 8px; border-bottom:1px solid #f1f3f5; font-size:14px; }
  .svc { display:inline-block; font-size:11px; padding:2px 8px; border-radius:10px; background:#eef2ff; color:#4338ca; text-transform:capitalize; }
  .total { text-align:right; font-size:20px; font-weight:800; color:var(--p); padding:14px 8px 0; }
  .foot { padding:16px 24px; border-top:1px solid #eef0f3; color:#6b7280; font-size:13px; text-align:center; }
  .err { text-align:center; padding:60px 20px; color:#6b7280; }
  .actions { padding:18px 24px 4px; display:flex; gap:12px; flex-wrap:wrap; }
  .btn { flex:1; min-width:140px; text-align:center; padding:12px 16px; border-radius:10px; font-weight:700; font-size:15px; border:0; cursor:pointer; }
  .btn-accept { background:var(--p); color:#fff; }
  .btn-decline { background:#fff; color:#6b7280; border:1px solid #d1d5db; }
  .flash { margin:0 24px 4px; padding:10px 14px; border-radius:8px; font-size:14px; }
  .flash-success { background:#ecfdf5; color:#065f46; }
  .flash-error { background:#fef2f2; color:#991b1b; }
  .flash-info { background:#eff6ff; color:#1e40af; }
  .status-note { padding:16px 24px; text-align:center; font-size:14px; color:#374151; }
</style>
</head>
<body>
<div class="wrap">
<?php if (!$ok): ?>
    <div class="card"><div class="err"><h1 style="font-size:20px;color:#111">Proposal not found</h1><p>This link is invalid or has expired. Please contact your travel agent.</p></div></div>
<?php else: ?>
    <div class="card">
        <div class="head">
            <?php if (!empty($brand['logoUrl'])): ?><img class="logo" src="<?= htmlspecialchars((string)$brand['logoUrl']) ?>" alt="<?= htmlspecialchars($company) ?>"><?php endif; ?>
            <div class="co"><?= htmlspecialchars($company) ?></div>
            <div class="ti"><?= htmlspecialchars((string)$trip['title']) ?></div>
        </div>
        <div class="body">
            <?php if (!empty($trip['client_name'])): ?><p style="margin-bottom:12px;color:#374151">Prepared for <strong><?= htmlspecialchars((string)$trip['client_name']) ?></strong></p><?php endif; ?>
            <?php if (!empty($trip['client_message'])): ?><div class="msg"><?= nl2br(htmlspecialchars((string)$trip['client_message'])) ?></div><?php endif; ?>

            <?php if (!empty($items)): ?>
            <table>
                <thead><tr><th>Service</th><th>Item</th><th style="text-align:center">Qty</th><th style="text-align:right">Price</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): $qty=max(1,(int)($it['qty']??1)); $line=round((float)($it['price']??0)*$qty,2); ?>
                    <tr>
                        <td><?php if (!empty($it['service'])): ?><span class="svc"><?= htmlspecialchars((string)$it['service']) ?></span><?php endif; ?></td>
                        <td><?= htmlspecialchars((string)($it['title']??'')) ?></td>
                        <td style="text-align:center"><?= $qty ?></td>
                        <td style="text-align:right"><?= $ccy ?> <?= $fmt($line) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="total">Total: <?= $ccy ?> <?= $fmt($totals['total'] ?? 0) ?></div>
            <?php else: ?>
            <p style="color:#6b7280">Your agent is still preparing the details of this trip.</p>
            <?php endif; ?>
        </div>

        <?php // Flash message (set by the respond POST). ?>
        <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']);
            $cls = ($m['type']??'')==='success'?'flash-success':((($m['type']??'')==='info')?'flash-info':'flash-error'); ?>
        <div class="flash <?= $cls ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
        <?php endif; ?>

        <?php
        // Decision controls / state. $canRespond is set by the route (only 'sent'/
        // 'quoted' and not expired). Accepting records intent + notifies the agency;
        // the client never pays here (free-text lines have no live checkout).
        $st = (string)($trip['status'] ?? '');
        if (!empty($canRespond) && !empty($items)): ?>
        <form method="post" action="<?= root ?>trip/<?= htmlspecialchars((string)$trip['ref']) ?>/respond" class="actions">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars((string)($_GET['token'] ?? '')) ?>">
            <button type="submit" name="choice" value="accept" class="btn btn-accept">Accept proposal</button>
            <button type="submit" name="choice" value="decline" class="btn btn-decline" onclick="return confirm('Decline this proposal?');">Decline</button>
        </form>
        <p class="status-note" style="padding-top:8px;color:#6b7280;font-size:12px">Accepting lets your agent finalise the booking and arrange payment.</p>
        <?php elseif ($st === 'accepted'): ?>
        <div class="status-note" style="color:#065f46">✓ You accepted this proposal. Your agent is finalising your booking.</div>
        <?php elseif ($st === 'declined'): ?>
        <div class="status-note">You declined this proposal. Contact your agent if this was a mistake.</div>
        <?php elseif ($st === 'booked'): ?>
        <div class="status-note" style="color:#065f46">✓ This trip has been booked. Your agent will share your travel documents.</div>
        <?php endif; ?>

        <div class="foot">
            <?= htmlspecialchars($company) ?>
            <?php if (!empty($brand['supportEmail'])): ?> · <?= htmlspecialchars((string)$brand['supportEmail']) ?><?php endif; ?>
            <?php if (!empty($brand['supportPhone'])): ?> · <?= htmlspecialchars((string)$brand['supportPhone']) ?><?php endif; ?>
            <div style="margin-top:6px;font-size:11px">Questions? Reply to your travel agent.</div>
        </div>
    </div>
<?php endif; ?>
</div>
</body>
</html>
