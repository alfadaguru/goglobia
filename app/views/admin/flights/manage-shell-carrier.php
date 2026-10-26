<?php
@$SECURE or die('Access Denied!');

$mode   = $mode ?? 'add';
$isEdit = ($mode === 'edit');
$rule   = $rule ?? ['id' => 0, 'supplier' => '', 'shell_code' => '', 'country' => '', 'match_mode' => 'either', 'active' => 1, 'note' => ''];
$modes  = function_exists('_fsc_match_modes') ? _fsc_match_modes() : ['either' => 'Either', 'from' => 'From', 'to' => 'To', 'both' => 'Both'];
$pageTitle = $isEdit ? ('Edit Shell-Carrier Rule #' . (int) $rule['id']) : 'Add Shell-Carrier Rule';
?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="flex items-center gap-5 mb-6">
        <a href="<?= root ?>admin/flight-shell-carriers" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
            <span class="material-symbols-outlined text-xl">arrow_back</span>
        </a>
        <h2 class="text-lg font-semibold text-slate-800"><?= htmlspecialchars($pageTitle) ?></h2>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 max-w-2xl">
        <div class="p-6 border-b border-gray-200">
            <p class="text-sm text-gray-600">
                A rule maps a ticketing/virtual <strong>shell carrier code</strong> (e.g. <code>A1</code> = "Atifly")
                to the real operating airline on matching flights, so customers see the airline they actually fly.
            </p>
        </div>

        <form method="POST" action="<?= root . admin ?>/flight-shell-carriers/<?= $isEdit ? 'edit/' . (int) $rule['id'] : 'add' ?>" class="p-6 space-y-5">
            <?= CSRF::tokenField() ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="form-control">
                    <label class="form-label">Shell code <span class="text-red-500">*</span></label>
                    <input type="text" name="shell_code" required maxlength="8" class="input" style="text-transform:uppercase"
                           value="<?= htmlspecialchars((string) $rule['shell_code']) ?>" placeholder="e.g. A1">
                    <p class="text-xs text-slate-500 mt-1">The wrong/shell carrier code returned by the supplier.</p>
                </div>

                <div class="form-control">
                    <label class="form-label">Supplier</label>
                    <input type="text" name="supplier" class="input" style="text-transform:lowercase"
                           value="<?= htmlspecialchars((string) $rule['supplier']) ?>" placeholder="e.g. adivaha (blank = any)">
                    <p class="text-xs text-slate-500 mt-1">Which search provider this rule applies to. Leave blank to apply to all providers.</p>
                </div>

                <div class="form-control">
                    <label class="form-label">Country</label>
                    <input type="text" name="country" class="input"
                           value="<?= htmlspecialchars((string) $rule['country']) ?>" placeholder="e.g. Nigeria (blank = any)">
                    <p class="text-xs text-slate-500 mt-1">Only correct flights touching this country (matches flights_airports.country). Blank = any country.</p>
                </div>

                <div class="form-control">
                    <label class="form-label">Match mode</label>
                    <select name="match_mode" class="select input">
                        <?php foreach ($modes as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= ((string) $rule['match_mode'] === $val) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">How the country binds to the flight (origin / destination / either / both).</p>
                </div>

                <div class="form-control">
                    <label class="form-label">Active</label>
                    <select name="active" class="select input">
                        <option value="1" <?= ((int) $rule['active'] === 1) ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= ((int) $rule['active'] === 0) ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>

                <div class="form-control sm:col-span-2">
                    <label class="form-label">Note</label>
                    <input type="text" name="note" maxlength="191" class="input"
                           value="<?= htmlspecialchars((string) ($rule['note'] ?? '')) ?>" placeholder="Optional reminder of why this rule exists">
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="btn text-sm inline-flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span><?= $isEdit ? 'Update rule' : 'Add rule' ?></span>
                </button>
            </div>
        </form>
    </div>
</div>
