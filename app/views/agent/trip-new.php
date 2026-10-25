<?php
// app/views/agent/trip-new.php — create a trip plan. Vars: $clients, $defaultCurrency.
@$SECURE or die('Access Denied!');
?>
<div class="max-w-xl mx-auto px-4 py-6">
    <nav class="text-xs text-slate-400 mb-3"><a href="<?= root ?>agent/trips" class="hover:underline">Trip Plans</a> / New</nav>
    <h1 class="text-xl font-bold text-slate-800 mb-5">New trip plan</h1>

    <form method="post" action="<?= root ?>agent/trips/save" class="rounded-xl border border-slate-200 bg-white p-5 space-y-4" x-data="{ mode: 'existing' }">
        <?= CSRF::tokenField() ?>
        <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Trip title</label>
             <input name="title" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="e.g. Dubai family holiday, Dec 2026"></div>

        <div>
            <label class="block text-[11px] uppercase text-slate-400 mb-1">Client</label>
            <div class="flex gap-3 mb-2 text-sm">
                <label class="inline-flex items-center gap-1"><input type="radio" name="__m" value="existing" x-model="mode" checked> Existing</label>
                <label class="inline-flex items-center gap-1"><input type="radio" name="__m" value="new" x-model="mode"> New / ad-hoc</label>
            </div>
            <select name="client_id" x-show="mode==='existing'" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="0">— select a client —</option>
                <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars(trim(($c['first_name']??'').' '.($c['last_name']??'')).' '.(!empty($c['email'])?'('.$c['email'].')':'')) ?></option><?php endforeach; ?>
            </select>
            <div x-show="mode==='new'" class="grid sm:grid-cols-2 gap-3">
                <input name="client_name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Client name">
                <input name="client_email" type="email" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Client email">
            </div>
        </div>

        <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Internal notes</label>
             <textarea name="notes" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea></div>

        <div class="flex gap-3">
            <button class="px-5 py-2.5 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Create &amp; add services</button>
            <a href="<?= root ?>agent/trips" class="px-5 py-2.5 rounded-lg border border-slate-300 text-slate-700 text-sm font-semibold hover:bg-slate-50">Cancel</a>
        </div>
    </form>
</div>
