<?php @$SECURE or die('Access Denied!'); ?>
<?php
// DATA MIGRATION & ONBOARDING (PMS #60 deepening). Passed from the route:
//   $cx (owner/actor/org/is_owner), $stays (owned id,name), $stayId (selected),
//   $kinds (mig_import_kinds), $goLive (mig_go_live_report|null),
//   $batches (mig_recent_batches), $connectors (mig_connector_status),
//   $preview (pending preview|null), $previewNonce (string|null).
// Read-only report + upload forms + a confirm-commit panel for a pending preview.
$stays      = (isset($stays) && is_array($stays)) ? $stays : [];
$kinds      = (isset($kinds) && is_array($kinds)) ? $kinds : [];
$goLive     = (isset($goLive) && is_array($goLive)) ? $goLive : null;
$batches    = (isset($batches) && is_array($batches)) ? $batches : [];
$connectors = (isset($connectors) && is_array($connectors)) ? $connectors : [];
$preview    = (isset($preview) && is_array($preview)) ? $preview : null;
$previewNonce = $previewNonce ?? null;
$stayId     = (int) ($stayId ?? 0);
$base       = root . 'supplier/migration';
$kindLabel  = function ($k) use ($kinds) { return htmlspecialchars((string) ($kinds[$k]['label'] ?? ucfirst(str_replace('_', ' ', (string) $k)))); };
// NOTE: the pending preview stays in the session until the operator commits or
// discards it (both routes unset it). The commit route re-parses + re-validates
// the stored raw CSV, so this is confirm-then-write, never a client echo.
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <nav class="text-xs text-gray-500 mb-1">
      <a href="<?= root ?>supplier/dashboard" class="hover:underline">Dashboard</a>
      <span class="mx-1">/</span><span class="text-gray-700">Data migration</span>
    </nav>
    <h1 class="text-2xl font-bold text-gray-900">Data migration &amp; onboarding</h1>
    <p class="text-sm text-gray-500 mt-1">Bulk-load your rooms, guests, rates, availability and opening balances from CSV, then check you're ready to go live. Every file is previewed and validated before anything is written.</p>
  </div>

  <!-- Property picker (scopes the property-level importers + go-live report) -->
  <div class="card p-4">
    <form action="<?= $base ?>" method="GET" class="flex items-end gap-2 flex-wrap">
      <div class="form-control">
        <label class="block text-xs text-gray-600 mb-1">Property</label>
        <select name="stay" class="input text-sm" onchange="this.form.submit()">
          <?php if (empty($stays)): ?>
            <option value="0">No properties yet</option>
          <?php else: foreach ($stays as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $stayId ? 'selected' : '' ?>><?= htmlspecialchars((string) ($s['name'] ?? ('#' . $s['id']))) ?></option>
          <?php endforeach; endif; ?>
        </select>
      </div>
      <noscript><button type="submit" class="btn secondary text-sm">Select</button></noscript>
      <p class="text-xs text-gray-400 ml-auto">Guest &amp; opening-balance imports apply to your whole business; rooms, rates and availability apply to the selected property.</p>
    </form>
  </div>

  <?php if ($preview !== null && $previewNonce): ?>
    <!-- PENDING PREVIEW — confirm before committing -->
    <?php
      $pk = (string) ($preview['kind'] ?? '');
      $pm = $kinds[$pk] ?? [];
      $sum = $preview['summary'] ?? [];
      $isMoney = !empty($pm['money']);
      $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
      $shown = array_slice($rows, 0, 50);
    ?>
    <div class="card p-4 space-y-3 border-2" style="border-color:#7c3aed;">
      <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600">fact_check</span> Preview: <?= $kindLabel($pk) ?></h2>
          <p class="text-xs text-gray-500 mt-0.5">Nothing has been written yet. Review the rows below, then confirm or discard.</p>
        </div>
        <div class="flex items-center gap-4 text-sm">
          <div><span class="text-xs text-gray-500">Valid</span> <span class="font-bold text-green-600 tabular-nums"><?= (int) ($preview['valid'] ?? 0) ?></span></div>
          <div><span class="text-xs text-gray-500">Skipped</span> <span class="font-bold text-red-600 tabular-nums"><?= (int) ($preview['invalid'] ?? 0) ?></span></div>
        </div>
      </div>

      <?php if ($isMoney): ?>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm bg-gray-50 rounded p-3">
          <div><div class="text-xs text-gray-500">Total debits</div><div class="font-bold tabular-nums"><?= number_format((float) ($sum['total_debit'] ?? 0), 2) ?></div></div>
          <div><div class="text-xs text-gray-500">Total credits</div><div class="font-bold tabular-nums"><?= number_format((float) ($sum['total_credit'] ?? 0), 2) ?></div></div>
          <div><div class="text-xs text-gray-500">Difference → Owner Equity (3000)</div><div class="font-bold tabular-nums <?= abs((float) ($sum['difference'] ?? 0)) >= 0.01 ? 'text-amber-600' : 'text-green-600' ?>"><?= number_format((float) ($sum['difference'] ?? 0), 2) ?></div></div>
        </div>
        <p class="text-xs text-gray-400">A single balanced journal will be posted. Any difference is auto-offset to Owner Equity so the opening trial balance nets to zero. Re-running the identical file is a safe no-op (idempotent).</p>
      <?php endif; ?>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-3 py-2 font-medium">Line</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th class="px-3 py-2 font-medium">Row</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($shown as $row): ?>
              <?php $ok = !empty($row['ok']); $raw = is_array($row['raw'] ?? null) ? $row['raw'] : []; ?>
              <tr>
                <td class="px-3 py-1.5 text-gray-500 tabular-nums"><?= (int) ($row['line'] ?? 0) ?></td>
                <td class="px-3 py-1.5">
                  <?php if ($ok): ?><span class="badge-emerald">OK</span><?php else: ?><span class="badge-gray">Skip</span><?php endif; ?>
                </td>
                <td class="px-3 py-1.5 text-gray-600">
                  <?php
                    $cells = [];
                    foreach ($raw as $k => $v) { $cells[] = htmlspecialchars((string) $k) . '=' . htmlspecialchars(mb_substr((string) $v, 0, 60)); }
                  ?>
                  <span class="text-xs text-gray-700"><?= implode(' &middot; ', $cells) ?></span>
                  <?php if (!$ok && !empty($row['error'])): ?>
                    <div class="text-xs text-red-600 mt-0.5"><?= htmlspecialchars((string) $row['error']) ?></div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (count($rows) > count($shown)): ?>
              <tr><td colspan="3" class="px-3 py-2 text-center text-xs text-gray-400">… and <?= count($rows) - count($shown) ?> more row(s) not shown.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="flex items-center gap-3 border-t border-gray-100 pt-3 flex-wrap">
        <form action="<?= $base ?>/commit" method="POST" class="inline-flex items-end gap-2">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="nonce" value="<?= htmlspecialchars((string) $previewNonce) ?>">
          <?php if ($isMoney): ?>
            <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Opening date</label><input type="date" name="opening_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" class="input text-sm"></div>
          <?php endif; ?>
          <button type="submit" class="btn emerald text-sm" <?= (int) ($preview['valid'] ?? 0) === 0 ? 'disabled' : '' ?>>Import <?= (int) ($preview['valid'] ?? 0) ?> row(s)</button>
        </form>
        <form action="<?= $base ?>/discard" method="POST" class="inline">
          <?= CSRF::tokenField() ?>
          <button type="submit" class="btn secondary text-sm">Discard</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- GO-LIVE READINESS -->
  <?php if ($goLive !== null): ?>
    <div class="card p-4 space-y-3">
      <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600">rocket_launch</span> Go-live checklist</h2>
          <p class="text-xs text-gray-500 mt-0.5">Readiness for the selected property. The first four items make a property sellable; the rest are recommended.</p>
        </div>
        <div class="text-right">
          <div class="text-2xl font-bold tabular-nums <?= !empty($goLive['sellable']) ? 'text-green-600' : 'text-gray-900' ?>"><?= (int) ($goLive['percent'] ?? 0) ?>%</div>
          <div class="text-xs text-gray-500"><?= (int) ($goLive['done'] ?? 0) ?> / <?= (int) ($goLive['total'] ?? 0) ?> complete</div>
        </div>
      </div>
      <div class="w-full h-2 rounded-full bg-gray-100 overflow-hidden">
        <div class="h-full transition-all" style="width: <?= (int) ($goLive['percent'] ?? 0) ?>%; background-color:#7c3aed;"></div>
      </div>
      <ul class="divide-y divide-gray-50">
        <?php foreach (($goLive['items'] ?? []) as $it): ?>
          <li class="flex items-start gap-3 py-2">
            <span class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-semibold <?= !empty($it['done']) ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400' ?>">
              <span class="material-symbols-outlined text-sm"><?= !empty($it['done']) ? 'check' : 'remove' ?></span>
            </span>
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium <?= !empty($it['done']) ? 'text-gray-700' : 'text-gray-900' ?>"><?= htmlspecialchars((string) ($it['label'] ?? '')) ?></span>
                <span class="text-xs text-gray-400"><?= htmlspecialchars((string) ($it['detail'] ?? '')) ?></span>
              </div>
              <p class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars((string) ($it['hint'] ?? '')) ?></p>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- IMPORTERS -->
  <div class="card p-4 space-y-4">
    <div>
      <h2 class="text-sm font-semibold text-gray-900">Importers</h2>
      <p class="text-xs text-gray-400">Download a template, fill it in, upload it, and preview before committing.</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <?php foreach ($kinds as $k => $meta): ?>
        <?php
          $scope = (string) ($meta['scope'] ?? 'org');
          $propScoped = $scope === 'property';
          $disabled = $propScoped && $stayId <= 0;
          $isMoney = !empty($meta['money']);
        ?>
        <div class="border border-gray-100 rounded-lg p-4 space-y-2">
          <div class="flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-gray-900"><?= htmlspecialchars((string) ($meta['label'] ?? $k)) ?></h3>
            <?php if ($propScoped): ?><span class="badge-gray">Per property</span><?php else: ?><span class="badge-gray">Whole business</span><?php endif; ?>
          </div>
          <p class="text-xs text-gray-500"><?= htmlspecialchars((string) ($meta['hint'] ?? '')) ?></p>
          <p class="text-[11px] text-gray-400">Columns: <code class="text-gray-600"><?= htmlspecialchars(implode(', ', (array) ($meta['columns'] ?? []))) ?></code></p>
          <?php if ($isMoney): ?>
            <p class="text-[11px] text-amber-600">Posts a balanced opening journal to the general ledger. Owner only.</p>
          <?php endif; ?>
          <div class="flex items-center gap-2 pt-1 flex-wrap">
            <a href="<?= $base ?>/template/<?= htmlspecialchars((string) $k) ?>" class="text-xs text-violet-600 hover:underline inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">download</span> Template</a>
          </div>
          <form action="<?= $base ?>/preview" method="POST" enctype="multipart/form-data" class="flex items-end gap-2 pt-1 flex-wrap <?= $disabled ? 'opacity-50' : '' ?>">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="kind" value="<?= htmlspecialchars((string) $k) ?>">
            <input type="hidden" name="stay_id" value="<?= $stayId ?>">
            <input type="file" name="csv" accept=".csv,text/csv" class="input text-xs" <?= $disabled ? 'disabled' : 'required' ?>>
            <button type="submit" class="btn secondary text-xs" <?= $disabled ? 'disabled' : '' ?>>Preview</button>
          </form>
          <?php if ($disabled): ?><p class="text-[11px] text-gray-400">Select a property above to enable this importer.</p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- CONNECTORS — honest status -->
  <div class="card p-4 space-y-2">
    <h2 class="text-sm font-semibold text-gray-900">Import from another system</h2>
    <p class="text-xs text-gray-400">There is no live two-way sync with other PMS or channel managers. Export a CSV from your current system and use the importers above.</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
      <?php foreach ($connectors as $c): ?>
        <div class="flex items-center justify-between gap-2 text-sm border border-gray-100 rounded px-3 py-2">
          <span class="text-gray-700"><?= htmlspecialchars((string) ($c['label'] ?? '')) ?></span>
          <?php if (!empty($c['connected'])): ?>
            <span class="badge-emerald">Supported</span>
          <?php else: ?>
            <span class="badge-gray" title="<?= htmlspecialchars((string) ($c['message'] ?? '')) ?>">Not connected</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- IMPORT HISTORY -->
  <div class="card p-4 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Recent imports</h2>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-3 py-2 font-medium">When</th>
          <th class="px-3 py-2 font-medium">Type</th>
          <th class="px-3 py-2 font-medium text-right">Imported</th>
          <th class="px-3 py-2 font-medium text-right">Skipped</th>
          <th class="px-3 py-2 font-medium">Notes</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($batches)): ?>
            <tr><td colspan="5" class="px-3 py-4 text-center text-gray-400 text-xs">No imports yet.</td></tr>
          <?php else: foreach ($batches as $b): ?>
            <tr>
              <td class="px-3 py-1.5 text-gray-600 whitespace-nowrap"><?= htmlspecialchars((string) ($b['created_at'] ?? '')) ?></td>
              <td class="px-3 py-1.5 text-gray-900"><?= $kindLabel((string) ($b['kind'] ?? '')) ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums text-green-600"><?= (int) ($b['rows_applied'] ?? 0) ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums text-gray-500"><?= (int) ($b['rows_skipped'] ?? 0) ?></td>
              <td class="px-3 py-1.5 text-gray-400 text-xs"><?= htmlspecialchars(mb_substr((string) ($b['error_digest'] ?? ''), 0, 120)) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
