<?php
// ADMIN — Credit Aging (AG-G3b). Which agents owe on their credit line + aging.
// Provided by app/routes/admin/moneyRoutes.php: $rows, $summary, $defaultCurrency
@$SECURE or die('Access Denied!');
$rows = $rows ?? [];
$summary = $summary ?? ['agents'=>0,'overdue'=>0,'owed'=>0];
$ccy = htmlspecialchars((string)($defaultCurrency ?? 'USD'), ENT_QUOTES, 'UTF-8');
$fmt = fn($n) => number_format((float)$n, 2);
?>
<div class="container my-4" style="max-width:1100px;margin:0 auto;padding:0 16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:6px;">
        <div>
            <h1 style="font-size:20px;font-weight:700;color:#1e2358;margin:0;">Credit Aging</h1>
            <p style="font-size:13px;color:#64748b;margin:2px 0 0;">Agents who have drawn on their credit line and not yet repaid. Overdue first.</p>
        </div>
        <a href="<?= root ?>admin/finance/credits" style="font-size:13px;color:#4f46e5;text-decoration:none;">Manage credits →</a>
    </div>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div style="margin:10px 0;padding:10px 14px;border-radius:8px;font-size:13px;background:<?= ($m['type']??'')==='success'?'#e7f6ef':'#fbe9ec' ?>;color:<?= ($m['type']??'')==='success'?'#0f6b4a':'#8f1d2b' ?>;"><?= htmlspecialchars((string)($m['text']??''), ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- summary tiles -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:16px 0 20px;">
        <div style="background:#fff;border:1px solid #e4e7f0;border-radius:12px;padding:14px 16px;">
            <div style="font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#94a3b8;">Agents owing</div>
            <div style="font-size:24px;font-weight:800;color:#1e2358;margin-top:4px;"><?= (int)$summary['agents'] ?></div>
        </div>
        <div style="background:#fff;border:1px solid #e4e7f0;border-radius:12px;padding:14px 16px;">
            <div style="font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#94a3b8;">Overdue</div>
            <div style="font-size:24px;font-weight:800;color:<?= $summary['overdue']>0?'#d1495b':'#1e2358' ?>;margin-top:4px;"><?= (int)$summary['overdue'] ?></div>
        </div>
        <div style="background:#fff;border:1px solid #e4e7f0;border-radius:12px;padding:14px 16px;">
            <div style="font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#94a3b8;">Total outstanding</div>
            <div style="font-size:24px;font-weight:800;color:#1e2358;margin-top:4px;"><?= $ccy ?> <?= $fmt($summary['owed']) ?></div>
        </div>
    </div>

    <div style="background:#fff;border:1px solid #e4e7f0;border-radius:12px;overflow:hidden;">
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13.5px;min-width:720px;">
            <thead>
                <tr style="background:#f3f4fa;color:#64748b;font-size:11px;text-transform:uppercase;">
                    <th style="text-align:left;padding:10px 14px;">Agent</th>
                    <th style="text-align:left;padding:10px 14px;">Email</th>
                    <th style="text-align:right;padding:10px 14px;">Used</th>
                    <th style="text-align:right;padding:10px 14px;">Limit</th>
                    <th style="text-align:left;padding:10px 14px;">Utilisation</th>
                    <th style="text-align:left;padding:10px 14px;">Due</th>
                    <th style="padding:10px 14px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" style="padding:26px;text-align:center;color:#94a3b8;">No agent currently owes on a credit line.</td></tr>
            <?php else: foreach ($rows as $r):
                $pct = max(0, min(100, (int)$r['pct']));
                $barColor = $r['is_overdue'] ? '#d1495b' : ($pct>=90 ? '#f59e0b' : '#4f46e5');
            ?>
                <tr style="border-top:1px solid #eef0f6;<?= $r['is_overdue']?'background:#fdf2f4;':'' ?>">
                    <td style="padding:10px 14px;color:#1e2358;font-weight:600;"><?= htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding:10px 14px;color:#64748b;"><?= htmlspecialchars((string)$r['email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding:10px 14px;text-align:right;color:#1e2358;font-weight:700;"><?= $ccy ?> <?= $fmt($r['used']) ?></td>
                    <td style="padding:10px 14px;text-align:right;color:#64748b;"><?= $r['limit']>0 ? $ccy.' '.$fmt($r['limit']) : '—' ?></td>
                    <td style="padding:10px 14px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="width:90px;height:7px;border-radius:999px;background:#eef0f6;overflow:hidden;">
                                <div style="height:100%;width:<?= $pct ?>%;background:<?= $barColor ?>;"></div>
                            </div>
                            <span style="font-size:12px;color:#64748b;"><?= $pct ?>%</span>
                        </div>
                    </td>
                    <td style="padding:10px 14px;">
                        <?php if (!empty($r['due_date'])): ?>
                            <span style="<?= $r['is_overdue']?'color:#d1495b;font-weight:700;':'color:#64748b;' ?>">
                                <?= htmlspecialchars((string)$r['due_date'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($r['is_overdue']): ?><br><span style="font-size:11px;"><?= (int)$r['days_overdue'] ?> days overdue</span><?php endif; ?>
                            </span>
                        <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                    </td>
                    <td style="padding:10px 14px;text-align:right;white-space:nowrap;">
                        <a href="<?= root ?>admin/finance/members/<?= rawurlencode((string)$r['user_id']) ?>" style="font-size:12px;color:#4f46e5;text-decoration:none;">View</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
