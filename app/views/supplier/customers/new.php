<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Customer Book — create / edit a contact (Front-desk overhaul Phase 1).
// $types (type=>label), $contact (null=new | row=edit).
$types   = (isset($types) && is_array($types)) ? $types : [];
$contact = (isset($contact) && is_array($contact)) ? $contact : null;
$base = root . 'supplier/customers';
$v = function ($k, $d = '') use ($contact) { return htmlspecialchars((string) ($contact[$k] ?? $d)); };
$isEdit = $contact !== null;
$curType = (string) ($contact['type'] ?? 'individual');
?>
<div class="max-w-2xl mx-auto px-4 py-8 space-y-6">
  <div class="flex items-center gap-2 text-sm text-gray-500">
    <a href="<?= $base ?>" class="hover:underline">Customers</a><span>/</span><span class="text-gray-700"><?= $isEdit ? 'Edit' : 'New customer' ?></span>
  </div>
  <h1 class="text-2xl font-bold text-gray-900"><?= $isEdit ? 'Edit customer' : 'New customer' ?></h1>

  <form action="<?= $base ?>/save" method="POST" class="card p-6 space-y-5">
    <?= CSRF::tokenField() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $contact['id'] ?>"><?php endif; ?>

    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
      <select name="type" id="cb-type" class="input text-sm w-full">
        <?php foreach ($types as $tk => $tl): ?>
          <option value="<?= htmlspecialchars($tk) ?>" <?= $curType === $tk ? 'selected' : '' ?>><?= htmlspecialchars($tl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1" id="cb-name-label"><?= in_array($curType, ['company','ngo','agent'], true) ? 'Organisation name' : 'Full name' ?> <span class="text-red-500">*</span></label>
      <input type="text" name="display_name" required value="<?= $v('display_name') ?>" class="input text-sm w-full" placeholder="e.g. Jane Doe / UNICEF Nigeria">
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
        <input type="text" name="phone" value="<?= $v('phone') ?>" class="input text-sm w-full" placeholder="+234…">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
        <input type="email" name="email" value="<?= $v('email') ?>" class="input text-sm w-full">
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Country (2-letter)</label>
        <input type="text" name="country" maxlength="2" value="<?= $v('country') ?>" class="input text-sm w-full" placeholder="NG">
      </div>
      <div class="form-control cb-org-only" style="<?= in_array($curType, ['company','ngo','agent'], true) ? '' : 'display:none' ?>">
        <label class="block text-sm font-medium text-gray-700 mb-1">Tax ID / VAT</label>
        <input type="text" name="tax_id" value="<?= $v('tax_id') ?>" class="input text-sm w-full">
      </div>
    </div>

    <!-- NGO/company arrangement (stored now; applied/enforced in later phases) -->
    <div class="cb-org-only border-t border-gray-100 pt-4 space-y-4" style="<?= in_array($curType, ['company','ngo','agent'], true) ? '' : 'display:none' ?>">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Negotiated discount %</label>
          <input type="number" name="negotiated_discount_pct" min="0" max="100" step="0.01" value="<?= $v('negotiated_discount_pct') ?>" class="input text-sm w-full">
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Credit limit</label>
          <input type="number" name="credit_limit" min="0" step="0.01" value="<?= $v('credit_limit') ?>" class="input text-sm w-full">
          <p class="text-[11px] text-gray-400 mt-1">Stored now; enforced at booking in a later phase.</p>
        </div>
      </div>
      <label class="inline-flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" name="tax_exempt" value="1" class="checkbox-input" <?= !empty($contact['tax_exempt']) ? 'checked' : '' ?>>
        Tax-exempt (e.g. NGO / government)
        <span class="text-[11px] text-gray-400">— off by default; turn on only if confirmed exempt</span>
      </label>
    </div>

    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
      <textarea name="notes" rows="2" class="input text-sm w-full" placeholder="Internal notes"><?= $v('notes') ?></textarea>
    </div>

    <div class="flex items-center gap-3">
      <button type="submit" class="btn primary text-sm"><?= $isEdit ? 'Save changes' : 'Create customer' ?></button>
      <a href="<?= $base ?>" class="text-sm text-gray-500 hover:underline">Cancel</a>
    </div>
  </form>
</div>

<script>
// Show organisation-only fields + relabel the name field when type changes. Progressive
// enhancement only — the server stores whatever is submitted regardless.
(function () {
  var sel = document.getElementById('cb-type');
  if (!sel) return;
  function sync() {
    var org = ['company', 'ngo', 'agent'].indexOf(sel.value) !== -1;
    document.querySelectorAll('.cb-org-only').forEach(function (el) { el.style.display = org ? '' : 'none'; });
    var lbl = document.getElementById('cb-name-label');
    if (lbl) lbl.firstChild.textContent = (org ? 'Organisation name ' : 'Full name ');
  }
  sel.addEventListener('change', sync);
})();
</script>
