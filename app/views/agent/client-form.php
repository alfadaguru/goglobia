<?php
// app/views/agent/client-form.php — add/edit a client.
// Vars: $client (null on add).
@$SECURE or die('Access Denied!');
$v = fn($k) => htmlspecialchars((string)($client[$k] ?? ''));
$isEdit = !empty($client);
?>
<div class="max-w-2xl mx-auto px-4 py-6">
    <nav class="text-xs text-slate-400 mb-3"><a href="<?= root ?>agent/clients" class="hover:underline">Clients</a> / <?= $isEdit ? 'Edit' : 'Add' ?></nav>
    <h1 class="text-xl font-bold text-slate-800 mb-5"><?= $isEdit ? 'Edit client' : 'Add client' ?></h1>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= root ?>agent/clients/save" class="rounded-xl border border-slate-200 bg-white p-5 space-y-4">
        <?= CSRF::tokenField() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$client['id'] ?>"><?php endif; ?>

        <div class="grid sm:grid-cols-4 gap-3">
            <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Title</label><input name="title" value="<?= $v('title') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Mr/Ms"></div>
            <div class="sm:col-span-2"><label class="block text-[11px] uppercase text-slate-400 mb-1">First name</label><input name="first_name" value="<?= $v('first_name') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <div class="sm:col-span-1"><label class="block text-[11px] uppercase text-slate-400 mb-1">Last name</label><input name="last_name" value="<?= $v('last_name') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
        </div>
        <div class="grid sm:grid-cols-3 gap-3">
            <div class="sm:col-span-2"><label class="block text-[11px] uppercase text-slate-400 mb-1">Email</label><input type="email" name="email" value="<?= $v('email') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Phone</label><input name="phone" value="<?= $v('phone') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
        </div>
        <div class="grid sm:grid-cols-4 gap-3">
            <div><label class="block text-[11px] uppercase text-slate-400 mb-1">DOB</label><input type="date" name="dob" value="<?= $v('dob') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Nationality (ISO)</label><input name="nationality" maxlength="2" value="<?= $v('nationality') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="NG"></div>
            <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Passport #</label><input name="passport_number" value="<?= $v('passport_number') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Passport expiry</label><input type="date" name="passport_expiry" value="<?= $v('passport_expiry') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
        </div>
        <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Tags (comma-separated)</label><input name="tags" value="<?= $v('tags') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="vip, corporate"></div>
        <div><label class="block text-[11px] uppercase text-slate-400 mb-1">Notes</label><textarea name="notes" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= $v('notes') ?></textarea></div>

        <div class="flex gap-3">
            <button class="px-5 py-2.5 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700"><?= $isEdit ? 'Save changes' : 'Add client' ?></button>
            <a href="<?= root ?>agent/clients" class="px-5 py-2.5 rounded-lg border border-slate-300 text-slate-700 text-sm font-semibold hover:bg-slate-50">Cancel</a>
        </div>
    </form>
</div>
