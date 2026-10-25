<?php
// app/views/agent/staff.php — team list + invite + roles summary.
// Vars: $staff, $roles, $canManage.
@$SECURE or die('Access Denied!');
?>
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-5">
        <div><h1 class="text-xl font-bold text-slate-800">Staff & Roles</h1>
             <p class="text-sm text-slate-500">Invite team members and control what each can do.</p></div>
        <a href="<?= root ?>agent/roles" class="text-sm px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Manage roles</a>
    </div>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <?php if (!empty($canManage)): ?>
    <div class="rounded-xl border border-slate-200 bg-white p-5 mb-6">
        <h2 class="font-semibold text-slate-800 mb-3">Invite a team member</h2>
        <form method="post" action="<?= root ?>agent/staff/invite" class="flex flex-wrap items-end gap-3">
            <?= CSRF::tokenField() ?>
            <div class="flex-1 min-w-[220px]">
                <label class="block text-[11px] uppercase text-slate-400 mb-1">Email</label>
                <input type="email" name="email" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="teammate@example.com">
            </div>
            <div class="min-w-[180px]">
                <label class="block text-[11px] uppercase text-slate-400 mb-1">Role</label>
                <select name="role_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="0">No role (no access until assigned)</option>
                    <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars((string)$r['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Send invite</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-800">Team (<?= count($staff) ?>)</h2></div>
        <?php if (empty($staff)): ?>
            <p class="px-5 py-6 text-sm text-slate-500">No team members yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Member</th><th class="text-left px-5 py-2">Role</th><th class="text-left px-5 py-2">Status</th><?php if (!empty($canManage)): ?><th class="px-5 py-2"></th><?php endif; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($staff as $s): ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 text-slate-700"><?= htmlspecialchars((string)($s['display']??'')) ?></td>
                        <td class="px-5 py-2 text-slate-600"><?= htmlspecialchars((string)($s['role_name']??'')) ?></td>
                        <td class="px-5 py-2"><span class="px-2 py-0.5 rounded-full text-[11px] <?= ($s['status']??'')==='active'?'bg-emerald-50 text-emerald-700':(($s['status']??'')==='invited'?'bg-amber-50 text-amber-700':'bg-slate-100 text-slate-500') ?>"><?= htmlspecialchars((string)($s['status']??'')) ?></span></td>
                        <?php if (!empty($canManage)): ?>
                        <td class="px-5 py-2 text-right">
                            <?php if (in_array(($s['status']??''), ['active','invited'], true)): ?>
                            <form method="post" action="<?= root ?>agent/staff/revoke" onsubmit="return confirm('Revoke this member\'s access?');">
                                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="text-xs text-red-600 hover:underline">Revoke</button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
