<?php
// app/views/agent/roles.php — roles list + permission builder.
// Vars: $roles, $catalogue (module=>[actions]), $canManage.
@$SECURE or die('Access Denied!');
?>
<div class="max-w-5xl mx-auto px-4 py-6" x-data="{ editing: null }">
    <div class="flex items-center justify-between mb-5">
        <div><h1 class="text-xl font-bold text-slate-800">Roles & Permissions</h1>
             <p class="text-sm text-slate-500">Default roles are locked. Create custom roles for your team.</p></div>
        <a href="<?= root ?>agent/staff" class="text-sm px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Back to staff</a>
    </div>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <!-- Existing roles -->
    <div class="space-y-3 mb-8">
        <?php foreach ($roles as $r): $perms = json_decode((string)($r['permissions']??'{}'), true) ?: []; ?>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-slate-800"><?= htmlspecialchars((string)$r['name']) ?></span>
                    <?php if ((int)($r['is_system']??0)===1): ?><span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 text-[11px]">Default</span><?php endif; ?>
                </div>
                <?php if (!empty($canManage) && (int)($r['is_system']??0)===0): ?>
                <form method="post" action="<?= root ?>agent/roles/delete" onsubmit="return confirm('Delete this role?');">
                    <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="text-xs text-red-600 hover:underline">Delete</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-1.5 mt-2">
                <?php foreach ($perms as $mod => $acts): foreach ((array)$acts as $act => $_): ?>
                    <span class="px-2 py-0.5 rounded bg-violet-50 text-violet-700 text-[11px]"><?= htmlspecialchars((string)$mod) ?>.<?= htmlspecialchars((string)$act) ?></span>
                <?php endforeach; endforeach; ?>
                <?php if (empty($perms)): ?><span class="text-[11px] text-slate-400">No permissions</span><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Create custom role -->
    <?php if (!empty($canManage)): ?>
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-800 mb-3">Create a custom role</h2>
        <form method="post" action="<?= root ?>agent/roles/save">
            <?= CSRF::tokenField() ?>
            <div class="mb-4 max-w-sm">
                <label class="block text-[11px] uppercase text-slate-400 mb-1">Role name</label>
                <input name="name" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="e.g. Senior Consultant">
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($catalogue as $mod => $actions): ?>
                <div class="rounded-lg border border-slate-100 p-3">
                    <p class="text-sm font-semibold text-slate-700 capitalize mb-2"><?= htmlspecialchars((string)$mod) ?></p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($actions as $act): ?>
                        <label class="inline-flex items-center gap-1 text-xs text-slate-600">
                            <input type="checkbox" name="perms[<?= htmlspecialchars((string)$mod) ?>][<?= htmlspecialchars((string)$act) ?>]" value="1" class="rounded border-slate-300">
                            <?= htmlspecialchars((string)$act) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button class="mt-4 px-4 py-2.5 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Create role</button>
        </form>
    </div>
    <?php endif; ?>
</div>
