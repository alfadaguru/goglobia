<?php
// app/views/agent/client-detail.php — GAP-2 client detail: profile + booking
// history + documents. Vars: $client, $bookings, $documents, $ltvClient,
// $ltvMargin, $paidCount, $defaultCurrency, $canEdit, $canUpload, $csrf.
@$SECURE or die('Access Denied!');
$fmt  = fn($n) => number_format((float)$n, 2);
$ccy  = htmlspecialchars((string)($defaultCurrency ?? 'NGN'));
$cid  = (int)($client['id'] ?? 0);
$cname = trim(((string)($client['title']??'')).' '.((string)($client['first_name']??'')).' '.((string)($client['last_name']??''))) ?: 'Client';
$docLabels = ['passport'=>'Passport','id_card'=>'ID card','visa'=>'Visa','ticket'=>'Ticket','insurance'=>'Insurance','photo'=>'Photo','other'=>'Other'];
$statusPill = function($s){
    $s = strtolower((string)$s);
    $map = ['paid'=>'bg-emerald-50 text-emerald-700','unpaid'=>'bg-amber-50 text-amber-700',
            'refunded'=>'bg-slate-100 text-slate-600','partially_paid'=>'bg-amber-50 text-amber-700',
            'awaiting_verification'=>'bg-amber-50 text-amber-700'];
    return $map[$s] ?? 'bg-slate-100 text-slate-600';
};
?>
<div class="max-w-5xl mx-auto px-4 py-6">
    <a href="<?= root ?>agent/clients" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700 mb-4">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span> Back to clients
    </a>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <!-- Profile header -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-800"><?= htmlspecialchars($cname) ?></h1>
            <p class="text-sm text-slate-500">
                <?= htmlspecialchars((string)($client['email'] ?? '')) ?>
                <?php if (!empty($client['phone'])): ?> · <?= htmlspecialchars((string)($client['phone_country_code'] ?? '').' '.(string)($client['phone'] ?? '')) ?><?php endif; ?>
                <?php if (!empty($client['nationality'])): ?> · <?= htmlspecialchars((string)$client['nationality']) ?><?php endif; ?>
            </p>
            <?php if (!empty($client['passport_number'])): ?>
            <p class="text-[12px] text-slate-400 mt-0.5">Passport <?= htmlspecialchars((string)$client['passport_number']) ?><?php if (!empty($client['passport_expiry'])): ?> · exp <?= htmlspecialchars((string)$client['passport_expiry']) ?><?php endif; ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($canEdit)): ?>
        <a href="<?= root ?>agent/clients/<?= $cid ?>/edit" class="text-sm px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Edit client</a>
        <?php endif; ?>
    </div>

    <!-- LTV KPIs -->
    <div class="grid sm:grid-cols-3 gap-4 mb-6">
        <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-[11px] uppercase text-slate-400">Paid bookings</p><p class="text-xl font-bold text-slate-800 mt-1"><?= (int)$paidCount ?></p></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-[11px] uppercase text-slate-400">Lifetime value</p><p class="text-xl font-bold text-slate-800 mt-1"><?= $ccy ?> <?= $fmt($ltvClient) ?></p></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-[11px] uppercase text-slate-400">Your margin</p><p class="text-xl font-bold text-emerald-700 mt-1"><?= $ccy ?> <?= $fmt($ltvMargin) ?></p></div>
    </div>

    <!-- Booking history -->
    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-800">Booking history</h2>
            <p class="text-[12px] text-slate-400">Bookings linked to this client (matched by email at checkout)</p></div>
        <?php if (empty($bookings)): ?>
            <p class="px-5 py-6 text-sm text-slate-500">No bookings yet for this client.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Invoice</th><th class="text-left px-5 py-2">Service</th><th class="text-right px-5 py-2">Client price</th><th class="text-right px-5 py-2">Margin</th><th class="text-left px-5 py-2">Status</th><th class="text-left px-5 py-2">When</th></tr>
                </thead>
                <tbody>
                <?php foreach ($bookings as $b): $cost=(float)($b['price_markup']??0); $clientPrice=(float)($b['agent_client_price']??0)?:$cost; ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 font-mono text-[12px] text-slate-700"><?= htmlspecialchars((string)($b['invoice_id']??'')) ?></td>
                        <td class="px-5 py-2 capitalize text-slate-600"><?= htmlspecialchars((string)($b['module_type']??'')) ?></td>
                        <td class="px-5 py-2 text-right text-slate-600"><?= htmlspecialchars((string)($b['currency_markup']??$ccy)) ?> <?= $fmt($clientPrice) ?></td>
                        <td class="px-5 py-2 text-right text-emerald-700"><?= $fmt((float)($b['agent_client_margin']??0)) ?></td>
                        <td class="px-5 py-2"><span class="inline-block px-2 py-0.5 rounded text-[11px] <?= $statusPill($b['payment_status']??'') ?>"><?= htmlspecialchars(str_replace('_',' ',(string)($b['payment_status']??''))) ?></span></td>
                        <td class="px-5 py-2 text-slate-400 text-[12px]"><?= htmlspecialchars((string)($b['paid_at'] ?: ($b['created_at']??''))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Documents -->
    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-800">Documents</h2>
            <p class="text-[12px] text-slate-400">Passport scans, visas, tickets — stored privately for this client</p></div>

        <?php if (!empty($canUpload)): ?>
        <form method="post" action="<?= root ?>agent/clients/<?= $cid ?>/documents/upload" enctype="multipart/form-data" class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-end gap-3">
            <?= CSRF::tokenField() ?>
            <div>
                <label class="block text-[11px] uppercase text-slate-400 mb-1">Type</label>
                <select name="doc_type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <?php foreach ($docLabels as $k=>$lbl): ?><option value="<?= $k ?>"><?= $lbl ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[11px] uppercase text-slate-400 mb-1">Label (optional)</label>
                <input type="text" name="title" maxlength="191" placeholder="e.g. Passport bio page" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-[11px] uppercase text-slate-400 mb-1">File (JPG/PNG/WEBP/PDF, max 8MB)</label>
                <input type="file" name="document" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf" required class="text-sm">
            </div>
            <button class="px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Upload</button>
        </form>
        <?php endif; ?>

        <?php if (empty($documents)): ?>
            <p class="px-5 py-6 text-sm text-slate-500">No documents stored for this client.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Type</th><th class="text-left px-5 py-2">File</th><th class="text-right px-5 py-2">Size</th><th class="text-left px-5 py-2">Added</th><th class="px-5 py-2"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 text-slate-700"><?= htmlspecialchars($docLabels[(string)($d['doc_type']??'other')] ?? 'Other') ?><?php if (!empty($d['title'])): ?> <span class="text-slate-400">· <?= htmlspecialchars((string)$d['title']) ?></span><?php endif; ?></td>
                        <td class="px-5 py-2 text-slate-500"><?= htmlspecialchars((string)($d['original_name'] ?? '—')) ?></td>
                        <td class="px-5 py-2 text-right text-slate-400 text-[12px]"><?= !empty($d['size_bytes']) ? number_format((int)$d['size_bytes']/1024, 0).' KB' : '—' ?></td>
                        <td class="px-5 py-2 text-slate-400 text-[12px]"><?= htmlspecialchars((string)($d['created_at'] ?? '')) ?></td>
                        <td class="px-5 py-2 text-right whitespace-nowrap">
                            <a href="<?= root ?>agent/clients/<?= $cid ?>/documents/<?= (int)$d['id'] ?>/download" class="text-xs text-violet-600 hover:underline mr-3">Download</a>
                            <?php if (agent_can($db,'clients','edit')): ?>
                            <form method="post" action="<?= root ?>agent/clients/documents/delete" class="inline" onsubmit="return confirm('Delete this document?');">
                                <?= CSRF::tokenField() ?>
                                <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                                <input type="hidden" name="client_id" value="<?= $cid ?>">
                                <button class="text-xs text-red-600 hover:underline">Delete</button>
                            </form>
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
