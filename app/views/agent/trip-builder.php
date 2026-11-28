<?php
// app/views/agent/trip-builder.php — add/remove service lines + send proposal.
// Vars: $trip, $items, $totals, $canEdit, $canSend, $shareUrl, $services.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => number_format((float)$n, 2);
$ccy = htmlspecialchars((string)($trip['currency'] ?? 'NGN'));
$ref = htmlspecialchars((string)$trip['ref']);
$itemsJson = htmlspecialchars(json_encode($items ?: []), ENT_QUOTES);
?>
<div class="max-w-4xl mx-auto px-4 py-6"
     x-data='{
        lines: <?= $itemsJson ?: "[]" ?>,
        services: <?= htmlspecialchars(json_encode($services), ENT_QUOTES) ?>,
        add(){ this.lines.push({service:"",title:"",qty:1,price:0}); },
        rm(i){ this.lines.splice(i,1); },
        get total(){ return this.lines.reduce((s,l)=> s + (Number(l.price||0)*Math.max(1,Number(l.qty||1))), 0); }
     }'>
    <nav class="text-xs text-slate-400 mb-3"><a href="<?= root ?>agent/trips" class="hover:underline">Trip Plans</a> / <?= htmlspecialchars((string)$trip['title']) ?></nav>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold text-slate-800"><?= htmlspecialchars((string)$trip['title']) ?></h1>
            <p class="text-sm text-slate-500"><?= htmlspecialchars((string)($trip['client_name'] ?: 'No client')) ?> · <span class="font-mono"><?= $ref ?></span> · <?= htmlspecialchars((string)$trip['status']) ?></p>
        </div>
    </div>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <!-- Line editor -->
    <form method="post" action="<?= root ?>agent/trips/<?= $ref ?>/lines" class="rounded-xl border border-slate-200 bg-white p-5 mb-5">
        <?= CSRF::tokenField() ?>
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-slate-800">Itinerary items</h2>
            <?php if (!empty($canEdit)): ?><button type="button" @click="add()" class="text-sm px-3 py-1.5 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">+ Add item</button><?php endif; ?>
        </div>
        <div class="space-y-2">
            <template x-for="(l,i) in lines" :key="i">
                <div class="grid grid-cols-12 gap-2 items-center">
                    <select :name="'line_service['+i+']'" x-model="l.service" class="col-span-3 rounded-lg border border-slate-300 px-2 py-2 text-sm" <?= empty($canEdit)?'disabled':'' ?>>
                        <option value="">Service…</option>
                        <template x-for="s in services" :key="s"><option :value="s" x-text="s"></option></template>
                    </select>
                    <input :name="'line_title['+i+']'" x-model="l.title" placeholder="Description" class="col-span-5 rounded-lg border border-slate-300 px-2 py-2 text-sm" <?= empty($canEdit)?'readonly':'' ?>>
                    <input :name="'line_qty['+i+']'" type="number" min="1" x-model="l.qty" class="col-span-1 rounded-lg border border-slate-300 px-2 py-2 text-sm" <?= empty($canEdit)?'readonly':'' ?>>
                    <input :name="'line_price['+i+']'" type="number" min="0" step="0.01" x-model="l.price" placeholder="Price" class="col-span-2 rounded-lg border border-slate-300 px-2 py-2 text-sm" <?= empty($canEdit)?'readonly':'' ?>>
                    <?php if (!empty($canEdit)): ?><button type="button" @click="rm(i)" class="col-span-1 text-red-500 hover:text-red-700"><span class="material-symbols-outlined text-[18px]">delete</span></button><?php endif; ?>
                </div>
            </template>
            <p x-show="lines.length===0" class="text-sm text-slate-400">No items yet. Add flights, hotels, tours, etc.</p>
        </div>
        <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100">
            <span class="text-sm text-slate-500">Total</span>
            <span class="text-lg font-bold text-slate-800"><?= $ccy ?> <span x-text="total.toLocaleString()"></span></span>
        </div>
        <?php if (!empty($canEdit)): ?><button class="mt-3 px-5 py-2.5 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Save itinerary</button><?php endif; ?>
    </form>

    <!-- GAP-1: Client decision + convert-to-booking. Surfaces the next action once
         the client has responded on the public proposal page. -->
    <?php $tStatus = (string)($trip['status'] ?? ''); ?>
    <?php if ($tStatus === 'accepted'): ?>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 mb-4">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-emerald-600">verified</span>
            <div class="flex-1">
                <h2 class="font-semibold text-emerald-900">Your client accepted this proposal</h2>
                <p class="text-sm text-emerald-800 mt-0.5 mb-3">Convert it to a booking at the quoted total, then pay from your wallet to confirm. You'll fulfil each line through the normal service flows.</p>
                <?php if (agent_can($db,'bookings','add')): ?>
                <form method="post" action="<?= root ?>agent/trips/<?= $ref ?>/convert" onsubmit="return confirm('Create a booking for this trip at the quoted total?');">
                    <?= CSRF::tokenField() ?>
                    <button class="px-5 py-2.5 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700">Convert to booking &amp; pay</button>
                </form>
                <?php else: ?>
                <p class="text-[12px] text-emerald-700">An owner or a team member with booking permission can convert this.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php elseif ($tStatus === 'declined'): ?>
    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 mb-4 text-sm text-slate-600">
        <span class="material-symbols-outlined align-middle text-[18px]">do_not_disturb_on</span>
        Your client declined this proposal. You can edit the lines and re-send it.
    </div>
    <?php elseif ($tStatus === 'booked'): ?>
    <div class="rounded-xl border border-violet-200 bg-violet-50 p-4 mb-4 text-sm">
        <span class="material-symbols-outlined align-middle text-[18px] text-violet-600">confirmation_number</span>
        This trip was converted to a booking.
        <?php if (!empty($trip['booking_invoice_id'])): ?>
        <a href="<?= root ?>agent/booking/<?= htmlspecialchars((string)$trip['booking_invoice_id']) ?>/pay" class="text-violet-700 font-semibold hover:underline ml-1">Open booking <?= htmlspecialchars((string)$trip['booking_invoice_id']) ?></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Proposal / share -->
    <?php if (!empty($canSend)): ?>
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-800 mb-2">Send branded proposal</h2>
        <p class="text-sm text-slate-500 mb-3">Share a client-facing page in your agency brand. The client sees your logo, colours and prices — never GoGlobia or any supplier.</p>
        <?php if (!empty($shareUrl)): ?>
        <div class="mb-3 px-3 py-2 rounded-lg bg-slate-50 text-sm break-all">
            <span class="text-slate-400 text-[11px] uppercase block mb-1">Share link</span>
            <a href="<?= htmlspecialchars($shareUrl) ?>" target="_blank" class="text-violet-600 hover:underline"><?= htmlspecialchars($shareUrl) ?></a>
        </div>
        <?php endif; ?>
        <form method="post" action="<?= root ?>agent/trips/<?= $ref ?>/send" class="space-y-3">
            <?= CSRF::tokenField() ?>
            <textarea name="client_message" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Optional message to your client…"><?= htmlspecialchars((string)($trip['client_message'] ?? '')) ?></textarea>
            <button class="px-5 py-2.5 rounded-lg bg-slate-800 text-white text-sm font-semibold hover:bg-slate-900"><?= !empty($shareUrl) ? 'Re-send / refresh link' : 'Generate proposal link' ?></button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Gap #5: Fulfilment — the real per-service bookings that fulfil this trip quote. -->
    <div class="rounded-xl border border-slate-200 bg-white p-5 mt-6">
        <h2 class="font-semibold text-slate-800 mb-1">Fulfilment</h2>
        <p class="text-sm text-slate-500 mb-3">Link the actual service bookings you make to fulfil this trip, so you have a record of what's booked.</p>

        <?php if (empty($linkedBookings)): ?>
            <p class="text-sm text-slate-400 mb-3">No bookings linked yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto mb-3">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-3 py-2">Invoice</th><th class="text-left px-3 py-2">Service</th><th class="text-right px-3 py-2">Amount</th><th class="text-left px-3 py-2">Status</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($linkedBookings as $lb):
                    $ps = strtolower((string)($lb['payment_status'] ?? ''));
                    $pill = $ps==='paid' ? 'bg-emerald-50 text-emerald-700' : ($ps==='refunded' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700');
                ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-3 py-2 font-mono text-[12px] text-slate-700"><?= htmlspecialchars((string)$lb['invoice_id']) ?></td>
                        <td class="px-3 py-2 capitalize text-slate-600"><?= htmlspecialchars((string)($lb['module_type'] ?? '')) ?></td>
                        <td class="px-3 py-2 text-right text-slate-600"><?= htmlspecialchars((string)($lb['currency_markup'] ?? '')) ?> <?= number_format((float)($lb['price_markup'] ?? 0), 2) ?></td>
                        <td class="px-3 py-2"><span class="inline-block px-2 py-0.5 rounded text-[11px] <?= $pill ?>"><?= htmlspecialchars(str_replace('_',' ',(string)($lb['payment_status'] ?? ''))) ?></span></td>
                        <td class="px-3 py-2 text-right">
                            <?php if (!empty($canEdit)): ?>
                            <form method="post" action="<?= root ?>agent/trips/<?= $ref ?>/unlink-booking" class="inline" onsubmit="return confirm('Unlink this booking from the trip?');">
                                <?= CSRF::tokenField() ?>
                                <input type="hidden" name="invoice_id" value="<?= htmlspecialchars((string)$lb['invoice_id']) ?>">
                                <button class="text-xs text-red-600 hover:underline">Unlink</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($canEdit)): ?>
            <?php if (!empty($linkCandidates)): ?>
            <form method="post" action="<?= root ?>agent/trips/<?= $ref ?>/link-booking" class="flex flex-wrap items-end gap-2">
                <?= CSRF::tokenField() ?>
                <div>
                    <label class="block text-[11px] uppercase text-slate-400 mb-1">Link a booking</label>
                    <select name="invoice_id" required class="rounded-lg border border-slate-300 px-3 py-2 text-sm min-w-[260px]">
                        <option value="">Choose one of your bookings…</option>
                        <?php foreach ($linkCandidates as $c): ?>
                        <option value="<?= htmlspecialchars((string)$c['invoice_id']) ?>"><?= htmlspecialchars((string)$c['invoice_id']) ?> · <?= htmlspecialchars((string)($c['module_type'] ?? '')) ?> · <?= htmlspecialchars((string)($c['currency_markup'] ?? '')) ?> <?= number_format((float)($c['price_markup'] ?? 0), 2) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Link</button>
            </form>
            <?php else: ?>
            <p class="text-[12px] text-slate-400">No unlinked agency bookings to attach. Bookings you make from the Booking Centre will appear here.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
