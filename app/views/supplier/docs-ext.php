<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Document & compliance (PMS #55 ext). Data from supplierDocsExtRoutes.php:
// $documents, $checklists[]{items,done,total,pct}, $expiring{expired,due_soon},
// $retentionDue, $retentionRules[doc_type=>months], $report, $reminderState,
// $propMap, $propList, $canEdit, $stayFilter, $leadDays.
$documents      = (isset($documents) && is_array($documents)) ? $documents : [];
$checklists     = (isset($checklists) && is_array($checklists)) ? $checklists : [];
$expiring       = (isset($expiring) && is_array($expiring)) ? $expiring : ['expired' => [], 'due_soon' => []];
$retentionDue   = (isset($retentionDue) && is_array($retentionDue)) ? $retentionDue : [];
$retentionRules = (isset($retentionRules) && is_array($retentionRules)) ? $retentionRules : [];
$report         = (isset($report) && is_array($report)) ? $report : [];
$reminderState  = (isset($reminderState) && is_array($reminderState)) ? $reminderState : [];
$propMap        = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$propList       = (isset($propList) && is_array($propList)) ? $propList : [];
$canEdit        = !empty($canEdit);
$stayFilter     = (int) ($stayFilter ?? 0);
$leadDays       = (int) ($leadDays ?? 60);
$base = root . 'supplier/compliance';
$typeLabel = ['licence' => 'Licence', 'insurance' => 'Insurance', 'certificate' => 'Certificate', 'contract' => 'Contract', 'other' => 'Other'];
$stColor = ['active' => 'bg-green-50 text-green-700', 'superseded' => 'bg-gray-100 text-gray-600', 'expired' => 'bg-red-50 text-red-700', 'archived' => 'bg-gray-100 text-gray-400'];
$scopeLabel = function_exists('docs_scopes') ? docs_scopes() : ['property' => 'Property', 'employee' => 'Employee', 'guest' => 'Guest', 'vendor' => 'Vendor/contractor'];
$statusLabel = function_exists('docs_statuses') ? docs_statuses() : ['active' => 'Active', 'superseded' => 'Superseded', 'expired' => 'Expired', 'archived' => 'Archived'];
$propName = function ($sid) use ($propMap) { return $propMap[(int) $sid] ?? ('#' . (int) $sid); };
$propSel = function () use ($propList) { $o = ''; foreach ($propList as $sid => $pn) { $o .= '<option value="' . (int) $sid . '">' . htmlspecialchars((string) $pn) . '</option>'; } return $o; };
?>

<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Document &amp; compliance</h1>
      <p class="text-sm text-gray-600">Licences, insurance and contracts; compliance checklists, expiry reminders and data-retention.</p>
    </div>
    <form method="GET" action="<?= $base ?>" class="flex items-end gap-2">
      <select name="stay_id" class="input text-xs" onchange="this.form.submit()">
        <option value="0">All properties</option>
        <?php foreach ($propList as $sid => $pn): ?>
          <option value="<?= (int) $sid ?>" <?= $stayFilter === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars((string) $pn) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="number" name="lead" value="<?= (int) $leadDays ?>" min="1" max="365" class="input text-xs w-20" title="Reminder lead days">
      <button type="submit" class="btn secondary text-xs">Apply</button>
    </form>
  </div>

  <!-- COMPLIANCE REPORT (rollup) -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
    <div class="card p-4"><div class="text-2xl font-bold text-gray-900"><?= (int) ($report['total'] ?? 0) ?></div><div class="text-xs text-gray-500">Documents</div></div>
    <div class="card p-4"><div class="text-2xl font-bold text-red-600"><?= (int) ($report['expired'] ?? 0) ?></div><div class="text-xs text-gray-500">Expired</div></div>
    <div class="card p-4"><div class="text-2xl font-bold text-amber-600"><?= (int) ($report['due_soon'] ?? 0) ?></div><div class="text-xs text-gray-500">Due &le; <?= (int) $leadDays ?>d</div></div>
    <div class="card p-4"><div class="text-2xl font-bold text-gray-900"><?= (int) ($report['checklist_pct'] ?? 0) ?>%</div><div class="text-xs text-gray-500">Checklist done</div></div>
    <div class="card p-4"><div class="text-2xl font-bold text-gray-900"><?= (int) ($report['retention_due'] ?? 0) ?></div><div class="text-xs text-gray-500">Past retention</div></div>
  </div>

  <!-- EXPIRY REMINDERS -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900">Expiry reminders</h2>
      <span class="text-xs <?= !empty($reminderState['connected']) ? 'text-green-600' : 'text-gray-400' ?>">
        <span class="material-symbols-outlined text-sm align-middle"><?= !empty($reminderState['connected']) ? 'notifications_active' : 'notifications_off' ?></span>
        <?= !empty($reminderState['connected']) ? 'Channel connected' : 'Channel not connected' ?>
      </span>
    </div>
    <?php if (empty($reminderState['connected'])): ?>
      <p class="text-xs text-gray-500 mb-3"><?= htmlspecialchars((string) ($reminderState['message'] ?? 'Reminder channel not connected.')) ?></p>
    <?php endif; ?>
    <?php $exp = $expiring['expired'] ?? []; $soon = $expiring['due_soon'] ?? []; ?>
    <?php if (empty($exp) && empty($soon)): ?>
      <p class="text-sm text-gray-500">No documents expired or expiring within <?= (int) $leadDays ?> days.</p>
    <?php else: ?>
      <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-50">
          <?php foreach (array_merge($exp, $soon) as $r): $past = ((string) ($r['expires_on'] ?? '')) < date('Y-m-d'); ?>
            <tr>
              <td class="py-2"><?= htmlspecialchars((string) ($r['title'] ?? '')) ?> <span class="text-xs text-gray-400">(<?= htmlspecialchars($typeLabel[$r['doc_type'] ?? 'other'] ?? 'Other') ?>)</span></td>
              <td class="py-2 text-xs text-gray-500"><?= htmlspecialchars($propName($r['stay_id'] ?? 0)) ?></td>
              <td class="py-2 text-right"><span class="px-2 py-0.5 rounded text-xs <?= $past ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700' ?>"><?= htmlspecialchars((string) ($r['expires_on'] ?? '')) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- DOCUMENT REPOSITORY -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Document repository</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/document" method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-2 mb-4">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-xs"><?= $propSel() ?></select>
      <input type="text" name="title" class="input text-xs md:col-span-2" placeholder="Title *" required>
      <select name="doc_type" class="input text-xs">
        <?php foreach ($typeLabel as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
      </select>
      <select name="owner_scope" class="input text-xs">
        <?php foreach ($scopeLabel as $k => $v): ?><option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
      </select>
      <input type="date" name="expires_on" class="input text-xs" title="Expires on">
      <input type="text" name="reference" class="input text-xs md:col-span-2" placeholder="Reference / number">
      <input type="text" name="file_ref" class="input text-xs md:col-span-2" placeholder="File path (uploads/...) — optional">
      <input type="text" name="notes" class="input text-xs md:col-span-2" placeholder="Notes">
      <div class="md:col-span-6"><button type="submit" class="btn secondary text-xs">Add document</button></div>
    </form>
    <?php endif; ?>
    <?php if (!empty($documents)): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs text-gray-400"><th class="py-1">Title</th><th>Type</th><th>Scope</th><th>Property</th><th>Expires</th><th>Retain until</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($documents as $d): $st = (string) ($d['status'] ?? 'active'); ?>
          <tr>
            <td class="py-2">
              <?= htmlspecialchars((string) ($d['title'] ?? '')) ?>
              <?php if (!empty($d['reference'])): ?><span class="text-xs text-gray-400">· <?= htmlspecialchars((string) $d['reference']) ?></span><?php endif; ?>
              <?php if (!empty($d['file_ref'])): ?><a href="<?= root . htmlspecialchars(ltrim((string) $d['file_ref'], '/')) ?>" target="_blank" class="text-xs text-blue-600 ml-1">file</a><?php endif; ?>
            </td>
            <td class="py-2 text-xs"><?= htmlspecialchars($typeLabel[$d['doc_type'] ?? 'other'] ?? 'Other') ?></td>
            <td class="py-2 text-xs text-gray-500"><?= htmlspecialchars($scopeLabel[$d['owner_scope'] ?? 'property'] ?? 'Property') ?></td>
            <td class="py-2 text-xs text-gray-500"><?= htmlspecialchars($propName($d['stay_id'] ?? 0)) ?></td>
            <td class="py-2 text-xs text-gray-500"><?= htmlspecialchars((string) ($d['expires_on'] ?? '—') ?: '—') ?></td>
            <td class="py-2 text-xs text-gray-400"><?= htmlspecialchars((string) ($d['retain_until'] ?? '—') ?: '—') ?></td>
            <td class="py-2"><span class="px-2 py-0.5 rounded text-xs <?= $stColor[$st] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars($statusLabel[$st] ?? ucfirst($st)) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="py-2 text-right">
              <form action="<?= $base ?>/document/status" method="POST" class="inline-flex items-center gap-1">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= (int) ($d['stay_id'] ?? 0) ?>">
                <input type="hidden" name="id" value="<?= (int) ($d['id'] ?? 0) ?>">
                <select name="status" class="input text-xs py-0.5" onchange="this.form.submit()">
                  <?php foreach ($statusLabel as $k => $v): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
                </select>
              </form>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <p class="text-sm text-gray-500">No documents yet.</p>
    <?php endif; ?>
  </div>

  <!-- COMPLIANCE CHECKLISTS -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Compliance checklists</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/checklist" method="POST" class="flex flex-wrap items-end gap-2 mb-4">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-xs"><?= $propSel() ?></select>
      <input type="text" name="title" class="input text-xs flex-1 min-w-[160px]" placeholder="Checklist title *" required>
      <input type="text" name="category" class="input text-xs" placeholder="Category (e.g. fire safety)">
      <button type="submit" class="btn secondary text-xs">Create checklist</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($checklists)): ?>
      <div class="space-y-4">
        <?php foreach ($checklists as $cl): ?>
        <div class="border border-gray-100 rounded p-3">
          <div class="flex items-center justify-between mb-2">
            <div>
              <span class="font-medium text-sm text-gray-900"><?= htmlspecialchars((string) ($cl['title'] ?? '')) ?></span>
              <?php if (!empty($cl['category'])): ?><span class="text-xs text-gray-400">· <?= htmlspecialchars((string) $cl['category']) ?></span><?php endif; ?>
              <span class="text-xs text-gray-400">· <?= htmlspecialchars($propName($cl['stay_id'] ?? 0)) ?></span>
            </div>
            <span class="text-xs text-gray-500"><?= (int) ($cl['done'] ?? 0) ?>/<?= (int) ($cl['total'] ?? 0) ?> (<?= (int) ($cl['pct'] ?? 0) ?>%)</span>
          </div>
          <div class="w-full bg-gray-100 rounded h-1.5 mb-3"><div class="bg-green-500 h-1.5 rounded" style="width: <?= (int) ($cl['pct'] ?? 0) ?>%"></div></div>
          <?php foreach (($cl['items'] ?? []) as $it): $done = !empty($it['done']); ?>
            <div class="flex items-center justify-between py-1 text-sm">
              <span class="<?= $done ? 'text-gray-400 line-through' : 'text-gray-700' ?>">
                <?= htmlspecialchars((string) ($it['label'] ?? '')) ?>
                <?php if ($done && !empty($it['done_at'])): ?><span class="text-xs text-gray-300">· <?= htmlspecialchars((string) $it['done_at']) ?></span><?php endif; ?>
              </span>
              <?php if ($canEdit): ?>
              <form action="<?= $base ?>/checklist/item/attest" method="POST" class="inline">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= (int) ($cl['stay_id'] ?? 0) ?>">
                <input type="hidden" name="item_id" value="<?= (int) ($it['id'] ?? 0) ?>">
                <input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>">
                <button type="submit" class="text-xs <?= $done ? 'text-gray-400' : 'text-green-600' ?>"><?= $done ? 'Undo' : 'Mark done' ?></button>
              </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($canEdit): ?>
          <form action="<?= $base ?>/checklist/item" method="POST" class="flex items-end gap-2 mt-2">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="stay_id" value="<?= (int) ($cl['stay_id'] ?? 0) ?>">
            <input type="hidden" name="checklist_id" value="<?= (int) ($cl['id'] ?? 0) ?>">
            <input type="text" name="label" class="input text-xs flex-1" placeholder="Add item">
            <button type="submit" class="btn secondary text-xs">Add</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="text-sm text-gray-500">No checklists yet.</p>
    <?php endif; ?>
  </div>

  <!-- DATA-RETENTION -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Data-retention</h2>
    <p class="text-xs text-gray-500 mb-3">Retention is informational: documents past their retention date are flagged for review. Nothing is auto-deleted.</p>
    <?php if ($canEdit && $stayFilter > 0): ?>
    <form action="<?= $base ?>/retention" method="POST" class="flex flex-wrap items-end gap-2 mb-4">
      <?= CSRF::tokenField() ?>
      <input type="hidden" name="stay_id" value="<?= (int) $stayFilter ?>">
      <select name="doc_type" class="input text-xs">
        <?php foreach ($typeLabel as $k => $v): ?><option value="<?= $k ?>"><?= $v ?> (<?= (int) ($retentionRules[$k] ?? 0) ?>m)</option><?php endforeach; ?>
      </select>
      <input type="number" name="retain_months" class="input text-xs w-28" min="1" max="600" placeholder="Months" required>
      <button type="submit" class="btn secondary text-xs">Save rule</button>
    </form>
    <?php elseif ($canEdit): ?>
      <p class="text-xs text-gray-400 mb-3">Select a single property above to edit its retention rules.</p>
    <?php endif; ?>
    <div class="flex flex-wrap gap-2 mb-4">
      <?php foreach ($typeLabel as $k => $v): ?>
        <span class="px-2 py-1 rounded bg-gray-50 text-xs text-gray-600"><?= $v ?>: <?= (int) ($retentionRules[$k] ?? 0) ?> months</span>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($retentionDue)): ?>
      <h3 class="text-xs font-semibold text-gray-700 mb-2">Past retention (review for purge)</h3>
      <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($retentionDue as $r): ?>
            <tr>
              <td class="py-2"><?= htmlspecialchars((string) ($r['title'] ?? '')) ?> <span class="text-xs text-gray-400">(<?= htmlspecialchars($typeLabel[$r['doc_type'] ?? 'other'] ?? 'Other') ?>)</span></td>
              <td class="py-2 text-xs text-gray-500"><?= htmlspecialchars($propName($r['stay_id'] ?? 0)) ?></td>
              <td class="py-2 text-right text-xs text-gray-400">retain until <?= htmlspecialchars((string) ($r['retain_until'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="text-sm text-gray-500">No documents are past their retention date.</p>
    <?php endif; ?>
  </div>
</div>
