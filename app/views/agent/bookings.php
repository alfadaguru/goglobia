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
                    // AG-G5b: a cancelled/refunded booking is "unpaid" but must NOT show Pay —
                    // it shows the credit note. Treat it separately from a genuinely-payable one.
                    $deadBooking = (($b['payment_status'] ?? '')==='refunded') || (($b['booking_status'] ?? '')==='cancelled');
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
                            <?php if ($unpaid && !$deadBooking && agent_can($db,'bookings','add')): ?>
                                <a href="<?= root ?>agent/booking/<?= urlencode($inv) ?>/pay" class="text-xs px-3 py-1.5 rounded-lg bg-violet-600 text-white font-semibold hover:bg-violet-700">Pay</a>
                            <?php elseif ($deadBooking): ?>
                                <?php if (agent_can($db,'bookings','view')): ?>
                                    <a href="<?= root ?>agent/booking/<?= urlencode($inv) ?>/client-credit-note" target="_blank" class="text-xs text-rose-600 hover:underline">Credit note</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php
                                // Paid but ticket not issued yet (pending) → one-click retry.
                                $__paidPending = (($b['payment_status']??'')==='paid')
                                    && !in_array(($b['booking_status']??''), ['confirmed','issued','cancelled'], true);
                                if ($__paidPending && agent_can($db,'bookings','add')): ?>
                                    <button type="button" onclick="agentBookingAction('<?= htmlspecialchars($inv, ENT_QUOTES) ?>','retry_issue')" class="text-xs px-2.5 py-1 rounded-lg bg-amber-500 text-white font-semibold hover:bg-amber-600 mr-2">Retry issue</button>
                                <?php endif; ?>
                                <?php if (agent_can($db,'bookings','view')):
                                    // View the invoice/voucher (where eSIM QR/ICCID + other
                                    // credentials are shown) so the agent can read/relay them
                                    // to the client on-screen, not only via Resend email.
                                    $__mt = strtolower((string)($b['module_type'] ?? '')); ?>
                                    <?php if ($__mt !== ''): ?>
                                        <a href="<?= root ?>invoice/<?= urlencode($__mt) ?>/<?= urlencode($inv) ?>" target="_blank" class="text-xs text-violet-600 hover:underline mr-2">View</a>
                                    <?php endif; ?>
                                    <?php // AG-G5: branded client invoice at the CLIENT price (paid bookings only).
                                    if (($b['payment_status']??'')==='paid'): ?>
                                        <a href="<?= root ?>agent/booking/<?= urlencode($inv) ?>/client-invoice" target="_blank" class="text-xs text-violet-600 hover:underline mr-2">Client invoice</a>
                                    <?php endif; ?>
                                    <button type="button" onclick="agentBookingAction('<?= htmlspecialchars($inv, ENT_QUOTES) ?>','resend')" class="text-xs text-slate-600 hover:underline mr-2">Resend</button>
                                <?php endif; ?>
                                <?php
                                // CHANGE / NO-SHOW (bookings.edit). A paid, live booking can be amended
                                // (fare diff +/- change fee) or flagged no-show. Hidden for terminal states.
                                $__liveForChange = !in_array(strtolower((string)($b['booking_status']??'')), ['cancelled','voided','refunded'], true);
                                if ($__liveForChange && agent_can($db,'bookings','edit')): ?>
                                    <button type="button" onclick="agentBookingChange('<?= htmlspecialchars($inv, ENT_QUOTES) ?>')" class="text-xs text-indigo-600 hover:underline mr-2">Change</button>
                                    <?php if (strtolower((string)($b['booking_status']??'')) !== 'no_show'): ?>
                                        <button type="button" onclick="agentBookingAction('<?= htmlspecialchars($inv, ENT_QUOTES) ?>','mark_no_show')" class="text-xs text-amber-600 hover:underline mr-2">No-show</button>
                                    <?php endif; ?>
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
var AGENT_CSRF = '<?= htmlspecialchars(CSRF::getToken(), ENT_QUOTES) ?>';

function agentBookingAction(inv, action){
    var confirmMsg = action === 'resend'
        ? 'Re-send the booking voucher to the client?'
        : (action === 'refund' ? 'Cancel this booking and refund to your wallet?'
        : (action === 'retry_issue' ? 'Retry issuing this ticket? If it fails again, your payment is refunded to your wallet automatically.'
        : (action === 'mark_no_show' ? 'Mark this booking as a no-show? (No money is moved — you can start a rebook afterwards via Change.)'
        : 'Cancel this booking?')));
    if (!confirm(confirmMsg)) return;
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', AGENT_CSRF);
    fetch('<?= root ?>agent/booking/' + encodeURIComponent(inv) + '/action', { method:'POST', body: fd, headers: { 'X-Requested-With':'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(j){ alert(j.message || (j.status ? 'Done.' : 'Action failed.')); if (j.status) location.reload(); })
        .catch(function(){ alert('Could not complete the action.'); });
}

// CHANGE / RESCHEDULE: collect the fare difference (new − old) and an optional change
// fee, then post to the same /action endpoint. The SERVER is the money authority —
// a fare increase becomes an amount due (pay from wallet); a decrease (net of the
// retained fee) is credited back to the wallet.
function agentBookingChange(inv){
    var diffStr = prompt('Fare difference (new price − old price).\n\nPositive = customer owes more; negative = refund due.\nLeave 0 for a fee-only change.');
    if (diffStr === null) return;                      // cancelled
    var fareDiff = parseFloat(diffStr);
    if (isNaN(fareDiff)) { alert('Please enter a number for the fare difference.'); return; }
    var feeStr = prompt('Change fee to charge (retained by you). Enter 0 for none.', '0');
    if (feeStr === null) return;
    var changeFee = parseFloat(feeStr); if (isNaN(changeFee) || changeFee < 0) changeFee = 0;
    if (Math.abs(fareDiff) < 0.001 && changeFee <= 0) { alert('Enter a fare difference and/or a change fee.'); return; }
    var reason = prompt('Reason / note for this change (optional):', 'Reschedule') || '';
    var net = fareDiff + (fareDiff === 0 ? changeFee : changeFee);
    var human = net > 0 ? ('Customer will owe ' + net.toFixed(2))
              : (net < 0 ? ('Refund of ' + Math.abs(net).toFixed(2) + ' to your wallet')
              : 'No net money movement');
    if (!confirm('Apply this change?\n\n' + human + '.')) return;
    var fd = new FormData();
    fd.append('action', 'change');
    fd.append('fare_difference', fareDiff);
    fd.append('change_fee', changeFee);
    fd.append('reason', reason);
    fd.append('csrf_token', AGENT_CSRF);
    fetch('<?= root ?>agent/booking/' + encodeURIComponent(inv) + '/action', { method:'POST', body: fd, headers: { 'X-Requested-With':'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(j){ alert(j.message || (j.status ? 'Change applied.' : 'Change failed.')); if (j.status) location.reload(); })
        .catch(function(){ alert('Could not apply the change.'); });
}
</script>
