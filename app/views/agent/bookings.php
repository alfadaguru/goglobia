<?php
// app/views/agent/bookings.php — the agency's bookings list.
// Vars: $bookings.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => number_format((float)$n, 2);
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-5">
        <div><h1 class="text-xl font-bold text-slate-800">Bookings</h1>
             <p class="text-sm text-slate-500">All bookings made by your agency.</p></div>
        <?php if (agent_can($db,'bookings','add')): ?>
        <a href="<?= root ?>agent/booking-centre" class="px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">New booking</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <?php if (empty($bookings)): ?>
            <p class="px-5 py-8 text-sm text-slate-500 text-center">No bookings yet. <a href="<?= root ?>agent/booking-centre" class="text-violet-600 hover:underline">Make your first booking.</a></p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr>
                        <th class="text-left px-4 py-2">Invoice</th>
                        <th class="text-left px-4 py-2">Service</th>
                        <th class="text-left px-4 py-2">Passenger</th>
                        <th class="text-left px-4 py-2">Status</th>
                        <th class="text-left px-4 py-2">Payment</th>
                        <th class="text-right px-4 py-2">You pay</th>
                        <th class="text-right px-4 py-2">Client price</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bookings as $b):
                    $ccy = htmlspecialchars((string)($b['currency_markup'] ?? 'NGN'));
                    $cost = (float)($b['price_markup'] ?? 0);
                    $clientPrice = (float)($b['agent_client_price'] ?? 0);
                    $unpaid = (($b['payment_status'] ?? '') !== 'paid');
                    $inv = (string)($b['invoice_id'] ?? '');
                    $pax = trim(((string)($b['first_name']??'')).' '.((string)($b['last_name']??''))) ?: '—';
                ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-4 py-2 font-mono text-slate-700"><?= htmlspecialchars($inv) ?></td>
                        <td class="px-4 py-2 capitalize text-slate-600"><?= htmlspecialchars((string)($b['module_type']??'')) ?></td>
                        <td class="px-4 py-2 text-slate-600"><?= htmlspecialchars($pax) ?></td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-[11px] <?= ($b['booking_status']??'')==='confirmed'?'bg-emerald-50 text-emerald-700':(($b['booking_status']??'')==='cancelled'?'bg-red-50 text-red-700':'bg-slate-100 text-slate-600') ?>"><?= htmlspecialchars((string)($b['booking_status']??'')) ?></span></td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-[11px] <?= !$unpaid?'bg-emerald-50 text-emerald-700':'bg-amber-50 text-amber-700' ?>"><?= htmlspecialchars((string)($b['payment_status']??'')) ?></span></td>
                        <td class="px-4 py-2 text-right text-slate-700"><?= $ccy ?> <?= $fmt($cost) ?></td>
                        <td class="px-4 py-2 text-right text-slate-500"><?= $clientPrice>0 ? $ccy.' '.$fmt($clientPrice) : '—' ?></td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <?php if ($unpaid && agent_can($db,'bookings','add')): ?>
                                <a href="<?= root ?>agent/booking/<?= urlencode($inv) ?>/pay" class="text-xs px-3 py-1.5 rounded-lg bg-violet-600 text-white font-semibold hover:bg-violet-700">Pay</a>
                            <?php else: ?>
                                <?php
                                // Paid but ticket not issued yet (pending) → one-click retry.
                                $__paidPending = (($b['payment_status']??'')==='paid')
                                    && !in_array(($b['booking_status']??''), ['confirmed','issued','cancelled'], true);
                                if ($__paidPending && agent_can($db,'bookings','add')): ?>
                                    <button type="button" onclick="agentBookingAction('<?= htmlspecialchars($inv, ENT_QUOTES) ?>','retry_issue')" class="text-xs px-2.5 py-1 rounded-lg bg-amber-500 text-white font-semibold hover:bg-amber-600 mr-2">Retry issue</button>
                                <?php endif; ?>
                                <?php if (agent_can($db,'bookings','view')): ?>
                                    <button type="button" onclick="agentBookingAction('<?= htmlspecialchars($inv, ENT_QUOTES) ?>','resend')" class="text-xs text-slate-600 hover:underline mr-2">Resend</button>
                                <?php endif; ?>
                                <?php if (($b['booking_status']??'') !== 'cancelled' && agent_can($db,'bookings','refund')): ?>
                                    <button type="button" onclick="agentBookingAction('<?= htmlspecialchars($inv, ENT_QUOTES) ?>','refund')" class="text-xs text-red-600 hover:underline">Cancel &amp; refund</button>
                                <?php elseif (($b['booking_status']??'') !== 'cancelled' && agent_can($db,'bookings','cancel')): ?>
                                    <button type="button" onclick="agentBookingAction('<?= htmlspecialchars($inv, ENT_QUOTES) ?>','cancel')" class="text-xs text-red-600 hover:underline">Cancel</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
function agentBookingAction(inv, action){
    var confirmMsg = action === 'resend'
        ? 'Re-send the booking voucher to the client?'
        : (action === 'refund' ? 'Cancel this booking and refund to your wallet?'
        : (action === 'retry_issue' ? 'Retry issuing this ticket? If it fails again, your payment is refunded to your wallet automatically.'
        : 'Cancel this booking?'));
    if (!confirm(confirmMsg)) return;
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', '<?= htmlspecialchars(CSRF::getToken(), ENT_QUOTES) ?>');
    fetch('<?= root ?>agent/booking/' + encodeURIComponent(inv) + '/action', { method:'POST', body: fd, headers: { 'X-Requested-With':'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(j){ alert(j.message || (j.status ? 'Done.' : 'Action failed.')); if (j.status) location.reload(); })
        .catch(function(){ alert('Could not complete the action.'); });
}
</script>
