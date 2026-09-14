<?php
// ADMIN — Finance Report (docs/MONEY-WALLET-AUDIT.md)
// Provided by app/routes/admin/reportsRoutes.php:
//   $from, $to, $moduleFilter, $byModule, $tot, $spine, $spineAvailable,
//   $moduleTypes, $defaultCurrency
@$SECURE or die('Access Denied!');

$byModule = $byModule ?? [];
$tot = $tot ?? ['revenue'=>0,'cost'=>0,'commission'=>0,'agent_earning'=>0,'tax'=>0,'count'=>0,'net_profit'=>0];
$spine = $spine ?? ['topups'=>0,'wallet_payments'=>0,'refunds'=>0,'gateway_payments'=>0];
$spineAvailable = $spineAvailable ?? false;
$moduleTypes = $moduleTypes ?? [];
$cur = htmlspecialchars($defaultCurrency ?? 'USD');
$from = $from ?? date('Y-m-d'); $to = $to ?? date('Y-m-d'); $moduleFilter = $moduleFilter ?? '';
$m = fn($v) => $cur . ' ' . number_format((float)$v, 2);
$marginPct = $tot['revenue'] > 0 ? round(($tot['net_profit'] / $tot['revenue']) * 100, 1) : 0;
?>

<div class="container my-4">

    <!-- Header + range -->
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Finance Report</h1>
            <p class="text-sm text-slate-600 mt-1">Revenue, supplier cost, profit and agent earnings by module — plus wallet cash-flow — for the selected period.</p>
        </div>
        <form method="GET" action="<?= root . admin ?>/reports/finance" class="flex flex-wrap items-end gap-2">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-slate-600">From</label>
                <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-slate-600">To</label>
                <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-slate-600">Module</label>
                <select name="module" class="select input">
                    <option value="">All</option>
                    <?php foreach ($moduleTypes as $mt): ?>
                        <option value="<?= htmlspecialchars($mt) ?>" <?= $moduleFilter === $mt ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($mt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn primary">Run</button>
            <a href="<?= root . admin ?>/reports/finance?<?= htmlspecialchars(http_build_query(['from' => $from, 'to' => $to, 'module' => $moduleFilter, 'format' => 'csv'])) ?>" class="btn secondary inline-flex items-center gap-1">
                <span class="material-symbols-outlined text-[18px]">download</span> CSV
            </a>
        </form>
    </div>

    <p class="text-xs text-slate-400 mb-4">Paid bookings from <strong><?= htmlspecialchars($from) ?></strong> to <strong><?= htmlspecialchars($to) ?></strong><?= $moduleFilter !== '' ? ' · module: ' . htmlspecialchars($moduleFilter) : '' ?>.</p>

    <!-- Headline KPIs -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium text-slate-500 mb-1">Paid bookings</div>
            <div class="text-2xl font-bold text-slate-800 tabular-nums"><?= number_format((int)$tot['count']) ?></div>
        </div>
        <div class="rounded-xl border border-blue-100 bg-gradient-to-br from-blue-50 to-white p-4">
            <div class="text-xs font-medium text-blue-700 mb-1">Gross revenue</div>
            <div class="text-lg font-bold text-blue-800 tabular-nums"><?= $m($tot['revenue']) ?></div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium text-slate-500 mb-1">Supplier cost</div>
            <div class="text-lg font-bold text-slate-700 tabular-nums"><?= $m($tot['cost']) ?></div>
        </div>
        <div class="rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-4">
            <div class="text-xs font-medium text-emerald-700 mb-1">Net profit</div>
            <div class="text-lg font-bold text-emerald-800 tabular-nums"><?= $m($tot['net_profit']) ?></div>
            <div class="text-[11px] text-emerald-600 mt-0.5"><?= $marginPct ?>% margin</div>
        </div>
        <div class="rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-white p-4">
            <div class="text-xs font-medium text-indigo-700 mb-1">Agent earnings</div>
            <div class="text-lg font-bold text-indigo-800 tabular-nums"><?= $m($tot['agent_earning']) ?></div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium text-slate-500 mb-1">Tax collected</div>
            <div class="text-lg font-bold text-slate-700 tabular-nums"><?= $m($tot['tax']) ?></div>
        </div>
    </div>

    <!-- Daily revenue trend -->
    <?php $trend = $trend ?? []; $peakRevenue = (float)($peakRevenue ?? 0); ?>
    <div class="card p-0 mb-6">
        <div class="card-header">
            <div><span class="card-header-icon text-[18px]">show_chart</span><h3>Daily revenue trend</h3></div>
            <div class="flex items-center gap-4 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-1.5 rounded-full" style="background:#3b82f6"></span>Revenue</span>
                <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-1.5 rounded-full" style="background:#10b981"></span>Net profit</span>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($trend) || $peakRevenue <= 0): ?>
                <p class="text-slate-500 text-sm py-8 text-center">No revenue in this period to chart.</p>
            <?php else: ?>
                <div class="relative w-full" style="height:240px">
                    <canvas id="revTrend" class="w-full h-full block"></canvas>
                    <div id="revTip" class="pointer-events-none absolute hidden bg-slate-800 text-white text-xs rounded-md px-2 py-1 shadow-lg" style="transform:translate(-50%,-115%);white-space:nowrap;z-index:10"></div>
                </div>
                <script type="application/json" id="revTrendData"><?= json_encode(['currency' => $defaultCurrency, 'points' => $trend], JSON_UNESCAPED_SLASHES) ?></script>
                <script>
                (function(){
                    var el = document.getElementById('revTrend');
                    var raw = document.getElementById('revTrendData');
                    if (!el || !raw) return;
                    var cfg = JSON.parse(raw.textContent);
                    var pts = cfg.points || [];
                    var tip = document.getElementById('revTip');
                    var dpr = window.devicePixelRatio || 1;
                    var padL = 8, padR = 8, padT = 12, padB = 22;

                    function fmt(n){ return cfg.currency + ' ' + Number(n).toLocaleString(undefined,{maximumFractionDigits:0}); }

                    function draw(){
                        var cssW = el.clientWidth, cssH = el.clientHeight;
                        el.width = cssW * dpr; el.height = cssH * dpr;
                        var ctx = el.getContext('2d'); ctx.setTransform(dpr,0,0,dpr,0,0);
                        ctx.clearRect(0,0,cssW,cssH);
                        var W = cssW - padL - padR, H = cssH - padT - padB;
                        var n = pts.length;
                        var maxV = 0;
                        pts.forEach(function(p){ maxV = Math.max(maxV, +p.revenue, +p.net_profit); });
                        if (maxV <= 0) maxV = 1;
                        var niceMax = Math.pow(10, Math.floor(Math.log10(maxV)));
                        niceMax = Math.ceil(maxV / niceMax) * niceMax;

                        var x = function(i){ return padL + (n <= 1 ? W/2 : (i/(n-1))*W); };
                        var y = function(v){ return padT + H - (v/niceMax)*H; };

                        // faint horizontal grid + y labels (0, mid, max)
                        ctx.strokeStyle = '#eef2f7'; ctx.fillStyle = '#94a3b8'; ctx.lineWidth = 1;
                        ctx.font = '10px -apple-system,Segoe UI,Roboto,sans-serif'; ctx.textBaseline = 'middle';
                        [0, 0.5, 1].forEach(function(f){
                            var yy = padT + H - f*H;
                            ctx.beginPath(); ctx.moveTo(padL, yy); ctx.lineTo(padL+W, yy); ctx.stroke();
                            ctx.fillText(fmt(niceMax*f), padL+2, yy-6);
                        });

                        // area under revenue
                        function series(key, stroke, fill){
                            ctx.beginPath();
                            pts.forEach(function(p,i){ var xx=x(i), yy=y(+p[key]); i?ctx.lineTo(xx,yy):ctx.moveTo(xx,yy); });
                            if (fill){
                                ctx.lineTo(x(n-1), padT+H); ctx.lineTo(x(0), padT+H); ctx.closePath();
                                ctx.fillStyle = fill; ctx.fill();
                                ctx.beginPath();
                                pts.forEach(function(p,i){ var xx=x(i), yy=y(+p[key]); i?ctx.lineTo(xx,yy):ctx.moveTo(xx,yy); });
                            }
                            ctx.strokeStyle = stroke; ctx.lineWidth = 2; ctx.lineJoin='round'; ctx.stroke();
                        }
                        series('revenue', '#3b82f6', 'rgba(59,130,246,0.10)');
                        series('net_profit', '#10b981', null);

                        // emphasize the peak revenue point
                        var peakI = 0, peakV = -1;
                        pts.forEach(function(p,i){ if(+p.revenue > peakV){ peakV = +p.revenue; peakI = i; } });
                        if (peakV > 0){
                            ctx.fillStyle = '#3b82f6'; ctx.beginPath(); ctx.arc(x(peakI), y(peakV), 3.5, 0, Math.PI*2); ctx.fill();
                            ctx.strokeStyle = '#fff'; ctx.lineWidth = 1.5; ctx.stroke();
                        }

                        // x labels: first, middle, last
                        ctx.fillStyle = '#94a3b8'; ctx.textBaseline='alphabetic';
                        function shortDate(d){ var m=(d||'').slice(5); return m; }
                        [0, Math.floor((n-1)/2), n-1].filter(function(v,i,a){return a.indexOf(v)===i;}).forEach(function(i){
                            var lbl = shortDate(pts[i] && pts[i].date);
                            var tw = ctx.measureText(lbl).width;
                            var xx = Math.min(Math.max(x(i)-tw/2, padL), padL+W-tw);
                            ctx.fillText(lbl, xx, cssH-6);
                        });

                        el._geom = { x:x, y:y, n:n, padT:padT, H:H };
                    }

                    function onMove(ev){
                        var g = el._geom; if(!g) return;
                        var rect = el.getBoundingClientRect();
                        var mx = ev.clientX - rect.left;
                        var best = 0, bestD = 1e9;
                        for (var i=0;i<g.n;i++){ var d=Math.abs(g.x(i)-mx); if(d<bestD){bestD=d;best=i;} }
                        var p = pts[best]; if(!p){ tip.classList.add('hidden'); return; }
                        tip.innerHTML = '<strong>'+p.date+'</strong><br>Rev '+fmt(p.revenue)+' · Profit '+fmt(p.net_profit)+' · '+p.count+' bookings';
                        tip.style.left = g.x(best) + 'px';
                        tip.style.top = g.y(+p.revenue) + 'px';
                        tip.classList.remove('hidden');
                    }
                    el.addEventListener('mousemove', onMove);
                    el.addEventListener('mouseleave', function(){ tip.classList.add('hidden'); });
                    draw();
                    var rt; window.addEventListener('resize', function(){ clearTimeout(rt); rt=setTimeout(draw,150); });
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>

    <!-- Revenue by module -->
    <div class="card p-0 mb-6">
        <div class="card-header"><div><span class="card-header-icon text-[18px]">bar_chart</span><h3>By module</h3></div></div>
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200">
                            <th class="px-4 py-3 font-medium">Module</th>
                            <th class="px-4 py-3 font-medium text-right">Bookings</th>
                            <th class="px-4 py-3 font-medium text-right">Revenue</th>
                            <th class="px-4 py-3 font-medium text-right">Cost</th>
                            <th class="px-4 py-3 font-medium text-right">Agent earnings</th>
                            <th class="px-4 py-3 font-medium text-right">Tax</th>
                            <th class="px-4 py-3 font-medium text-right">Net profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($byModule)): ?>
                            <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">
                                <span class="material-symbols-outlined text-3xl mb-1 block">bar_chart</span>
                                No paid bookings in this period.
                            </td></tr>
                        <?php else: foreach ($byModule as $row): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium text-slate-800 capitalize"><?= htmlspecialchars((string)$row['module']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int)$row['count']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= $m($row['revenue']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-500"><?= $m($row['cost']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums text-indigo-700"><?= $m($row['agent_earning']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-500"><?= $m($row['tax']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold text-emerald-700"><?= $m($row['net_profit']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <?php if (!empty($byModule)): ?>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                            <td class="px-4 py-3">Total</td>
                            <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int)$tot['count']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums"><?= $m($tot['revenue']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600"><?= $m($tot['cost']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums text-indigo-700"><?= $m($tot['agent_earning']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600"><?= $m($tot['tax']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-700"><?= $m($tot['net_profit']) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Wallet cash flow (spine) -->
    <div class="card p-0">
        <div class="card-header"><div><span class="card-header-icon text-[18px]">account_balance_wallet</span><h3>Wallet cash flow</h3></div></div>
        <div class="card-body">
            <?php if (!$spineAvailable): ?>
                <p class="text-slate-500 text-sm">Wallet spine not available.</p>
            <?php else: ?>
                <p class="text-xs text-slate-400 mb-3">Successful money-spine movements in the selected period (independent of booking payment status).</p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-4">
                        <div class="text-xs font-medium text-emerald-700 mb-1">Wallet top-ups</div>
                        <div class="text-lg font-bold text-emerald-800 tabular-nums"><?= $m($spine['topups']) ?></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="text-xs font-medium text-slate-500 mb-1">Paid from wallet</div>
                        <div class="text-lg font-bold text-slate-700 tabular-nums"><?= $m($spine['wallet_payments']) ?></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="text-xs font-medium text-slate-500 mb-1">Paid by gateway</div>
                        <div class="text-lg font-bold text-slate-700 tabular-nums"><?= $m($spine['gateway_payments']) ?></div>
                    </div>
                    <div class="rounded-xl border border-sky-100 bg-gradient-to-br from-sky-50 to-white p-4">
                        <div class="text-xs font-medium text-sky-700 mb-1">Refunds</div>
                        <div class="text-lg font-bold text-sky-800 tabular-nums"><?= $m($spine['refunds']) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
