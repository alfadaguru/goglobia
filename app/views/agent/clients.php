<?php
// app/views/agent/clients.php — client book list + search.
// Vars: $clients, $canEdit, $q (from GET).
@$SECURE or die('Access Denied!');
$q = trim((string)($_GET['q'] ?? ''));
$name = fn($c) => trim(((string)($c['title']??'')).' '.((string)($c['first_name']??'')).' '.((string)($c['last_name']??''))) ?: '—';
?>
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-5">
        <div><h1 class="text-xl font-bold text-slate-800">Clients</h1>
             <p class="text-sm text-slate-500">Your agency's own client book — reuse details when booking.</p></div>
        <?php if (!empty($canEdit) && agent_can($db,'clients','add')): ?>
        <a href="<?= root ?>agent/clients/new" class="px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Add client</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= root ?>agent/clients" class="mb-4">
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name, email, phone, tag…" class="w-full max-w-sm rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </form>

    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <?php if (empty($clients)): ?>
            <p class="px-5 py-8 text-sm text-slate-500 text-center"><?= $q!=='' ? 'No clients match your search.' : 'No clients yet.' ?></p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Name</th><th class="text-left px-5 py-2">Email</th><th class="text-left px-5 py-2">Phone</th><th class="text-left px-5 py-2">Tags</th><?php if (!empty($canEdit)): ?><th class="px-5 py-2"></th><?php endif; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c): ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 text-slate-700"><?= htmlspecialchars($name($c)) ?></td>
                        <td class="px-5 py-2 text-slate-500"><?= htmlspecialchars((string)($c['email']??'')) ?></td>
                        <td class="px-5 py-2 text-slate-500"><?= htmlspecialchars((string)($c['phone']??'')) ?></td>
                        <td class="px-5 py-2"><?php foreach (array_filter(array_map('trim', explode(',', (string)($c['tags']??'')))) as $t): ?><span class="inline-block px-2 py-0.5 rounded bg-violet-50 text-violet-700 text-[11px] mr-1"><?= htmlspecialchars($t) ?></span><?php endforeach; ?></td>
                        <?php if (!empty($canEdit)): ?>
                        <td class="px-5 py-2 text-right whitespace-nowrap">
                            <?php if (agent_can($db,'clients','edit')): ?><a href="<?= root ?>agent/clients/<?= (int)$c['id'] ?>/edit" class="text-xs text-violet-600 hover:underline mr-3">Edit</a><?php endif; ?>
                            <?php if (agent_can($db,'clients','delete')): ?>
                            <form method="post" action="<?= root ?>agent/clients/delete" class="inline" onsubmit="return confirm('Delete this client?');">
                                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="text-xs text-red-600 hover:underline">Delete</button>
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
