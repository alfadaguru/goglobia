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
</div>
