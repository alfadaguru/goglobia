<?php
@$SECURE or die('Access Denied!');

$backUrl = $backUrl ?? (root.admin . '/settings/modules#esim');
$countryIso = strtoupper((string) ($country['iso'] ?? ''));
$countryName = (string) ($country['nicename'] ?? $countryIso);
$rows = is_array($packages) ? $packages : [];
if (empty($rows)) {
    $rows[] = [
        'id' => 0,
        'package_type' => 'all',
        'commission_type' => 'percentage',
        'value' => '0.00',
        'featured' => 0,
        'status' => 1,
    ];
}
?>

<div class="container py-6" x-data="airaloCountryPackages()">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between mb-6">
        <div class="flex items-start gap-4">
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn secondary inline-flex items-center justify-center w-11 h-11 rounded-xl bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors shadow-sm">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 border border-blue-100">
                        <span class="material-symbols-outlined text-[14px]">public</span>
                        Airalo
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 border border-slate-200">
                        <?= htmlspecialchars($countryIso) ?>
                    </span>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">Edit Packages - <?= htmlspecialchars($countryName) ?></h1>
                    <p class="text-sm text-slate-500 max-w-2xl">Manage multiple package rules for this active country. The country stays fixed and uses the same admin form controls as the rest of the system.</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-2 rounded-xl bg-white border border-slate-200 px-3 py-2 text-sm text-slate-700 shadow-sm">
                <span class="material-symbols-outlined text-[18px] text-slate-500">tune</span>
                <span><strong><?= count($rows) ?></strong> rules</span>
            </span>
            <button type="button" class="btn secondary" @click="addRow()">
                <span class="material-symbols-outlined text-base">add</span>
                Add Package
            </button>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 bg-slate-50">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Package Rules</h2>
                    <p class="text-sm text-slate-500 mt-1">Each rule can target a package type with its own commission, featured state, and activation status.</p>
                </div>
            </div>
        </div>

        <form method="POST" class="p-5 space-y-4" @submit="submitting = true">
            <div class="rounded-xl border border-blue-100 bg-blue-50/60 px-4 py-3 text-sm text-blue-900">
                <div class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-[18px] mt-0.5 text-blue-600">info</span>
                    <p>Country selection is locked to <?= htmlspecialchars($countryName) ?>. Add or adjust rules below, then save to return to the Airalo database tab.</p>
                </div>
            </div>

            <template x-for="(row, idx) in rows" :key="row.uid">
                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 lg:p-5">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between mb-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">Rule <span x-text="idx + 1"></span></h3>
                            <p class="text-xs text-slate-500">Configure one package rule for this country.</p>
                        </div>
                        <button type="button" class="btn light self-start lg:self-auto" @click="removeRow(idx)" x-show="rows.length > 1">
                            <span class="material-symbols-outlined text-base">delete</span>
                            Remove
                        </button>
                    </div>

                    <input type="hidden" :name="`rows[${idx}][id]`" x-model="row.id">

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                        <div class="grid grid-cols-2 gap-3 items-start">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Featured</label>
                                <label class="switch-container switch-md switch-blue flex h-10 items-center justify-between gap-3 px-3 bg-white border border-slate-200 rounded-lg">
                                    <input type="checkbox" value="1" class="switch-input" :name="`rows[${idx}][featured]`" x-model="row.featured">
                                    <span class="switch-track shrink-0"><span class="switch-thumb"></span></span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Active</label>
                                <label class="switch-container switch-md switch-blue flex h-10 items-center justify-between gap-3 px-3 bg-white border border-slate-200 rounded-lg">
                                    <input type="checkbox" value="1" class="switch-input" :name="`rows[${idx}][status]`" x-model="row.status">
                                    <span class="switch-track shrink-0"><span class="switch-thumb"></span></span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Package Type</label>
                            <select class="select" :name="`rows[${idx}][package_type]`" x-model="row.package_type" required>
                                <option value="all">All</option>
                                <option value="global">Global</option>
                                <option value="local">Local</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Commission Type</label>
                            <select class="select" :name="`rows[${idx}][commission_type]`" x-model="row.commission_type" required>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Value</label>
                            <input type="number" min="0" step="0.01" class="input" :name="`rows[${idx}][value]`" x-model="row.value" required>
                        </div>
                    </div>
                </div>
            </template>

            <div class="flex flex-col-reverse gap-3 pt-2 border-t border-slate-200 sm:flex-row sm:items-center sm:justify-end">
                <a href="<?= htmlspecialchars($backUrl) ?>" class="btn light">Back to Countries</a>
                <button type="submit" class="btn" :disabled="submitting" :class="submitting ? 'opacity-70 cursor-not-allowed' : ''">
                    <span x-show="!submitting" class="material-symbols-outlined text-base">save</span>
                    <span x-show="submitting" class="material-symbols-outlined text-base animate-spin">progress_activity</span>
                    <span x-text="submitting ? 'Saving...' : 'Save Packages'"></span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function airaloCountryPackages() {
    return {
        submitting: false,
        rows: <?= json_encode(array_map(function ($r) {
            return [
                'uid' => uniqid('row_', true),
                'id' => (int) ($r['id'] ?? 0),
                'package_type' => (string) ($r['package_type'] ?? 'all'),
                'commission_type' => (string) ($r['commission_type'] ?? 'percentage'),
                'value' => (string) ($r['value'] ?? '0.00'),
                'featured' => !empty($r['featured']),
                'status' => isset($r['status']) ? ((int)$r['status'] === 1) : true,
            ];
        }, $rows), JSON_UNESCAPED_SLASHES) ?>,
        addRow() {
            this.rows.push({
                uid: 'row_' + Date.now() + '_' + Math.random().toString(16).slice(2),
                id: 0,
                package_type: 'all',
                commission_type: 'percentage',
                value: '0.00',
                featured: false,
                status: true,
            });
        },
        removeRow(index) {
            this.rows.splice(index, 1);
        }
    };
}
</script>
