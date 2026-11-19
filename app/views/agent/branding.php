<?php
// app/views/agent/branding.php — logo + brand colours + client markup.
// Vars: $agency, $logoUrl, $markupVal, $markupType, $selfMarkupEnabled,
//       $canEdit, $canPrice.
@$SECURE or die('Access Denied!');
$primary = (string)($agency['brand_primary'] ?? '') ?: '#7c3aed';
$accent  = (string)($agency['brand_accent'] ?? '') ?: '#f59e0b';
?>
<div class="max-w-3xl mx-auto px-4 py-6" x-data="{
    primary: '<?= htmlspecialchars($primary) ?>',
    accent: '<?= htmlspecialchars($accent) ?>',
    mtype: '<?= htmlspecialchars((string)$markupType) ?>',
    mval: <?= (float)$markupVal ?>,
    sample: 100000,
    get margin(){ return this.mtype==='fixed' ? Number(this.mval||0) : Math.round(this.sample*Number(this.mval||0)/100); },
    get client(){ return this.sample + this.margin; }
}">
    <h1 class="text-xl font-bold text-slate-800 mb-1">Branding</h1>
    <p class="text-sm text-slate-500 mb-5">Your logo, colours and contact appear on every ticket, invoice and email you share with clients.</p>

    <?php if (!empty($_SESSION['message'])): $m=$_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type']??'')==='success'?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><?= htmlspecialchars((string)($m['text']??'')) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= root ?>agent/branding/save" enctype="multipart/form-data" class="space-y-6">
        <?= CSRF::tokenField() ?>

        <!-- Logo + colours -->
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="font-semibold text-slate-800 mb-4">Logo &amp; colours</h2>
            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-[11px] uppercase text-slate-400 mb-1">Agency logo</label>
                    <?php if (!empty($logoUrl)): ?>
                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="logo" class="h-12 mb-2 rounded border border-slate-100 bg-white p-1">
                    <?php endif; ?>
                    <?php if (!empty($canEdit)): ?>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm text-slate-600">
                    <p class="text-[11px] text-slate-400 mt-1">PNG/JPG/WebP, max 2&nbsp;MB.</p>
                    <?php endif; ?>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="block text-[11px] uppercase text-slate-400 mb-1">Primary colour</label>
                        <div class="flex items-center gap-2">
                            <input type="color" name="brand_primary" x-model="primary" <?= empty($canEdit)?'disabled':'' ?> class="h-9 w-12 rounded border border-slate-300">
                            <span class="text-sm font-mono text-slate-600" x-text="primary"></span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] uppercase text-slate-400 mb-1">Accent colour</label>
                        <div class="flex items-center gap-2">
                            <input type="color" name="brand_accent" x-model="accent" <?= empty($canEdit)?'disabled':'' ?> class="h-9 w-12 rounded border border-slate-300">
                            <span class="text-sm font-mono text-slate-600" x-text="accent"></span>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Live preview strip -->
            <div class="mt-4 rounded-lg overflow-hidden border border-slate-100">
                <div class="px-4 py-2 text-white text-sm font-semibold" :style="`background:${primary}`">Sample document header — <?= htmlspecialchars((string)($agency['agency_name'] ?? 'Your Agency')) ?></div>
                <div class="px-4 py-2 text-xs" :style="`color:${accent}`">Accent text preview</div>
            </div>
        </div>

        <!-- Client markup (owner only) -->
        <?php if (!empty($canPrice) && !empty($selfMarkupEnabled)): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="font-semibold text-slate-800 mb-1">Your client price (markup)</h2>
            <p class="text-sm text-slate-500 mb-4">Add your own margin on top of the net price you pay us. Your client sees the higher price on their branded invoice; you keep the difference.</p>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-[11px] uppercase text-slate-400 mb-1">Default markup type</label>
                    <select name="markup_type" x-model="mtype" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="percentage">Percentage (%)</option>
                        <option value="fixed">Fixed amount</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] uppercase text-slate-400 mb-1">Default value</label>
                    <input type="number" name="markup_value" x-model="mval" step="0.01" min="0" class="w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] uppercase text-slate-400 mb-1">Min margin (floor)</label>
                    <input type="number" name="markup_floor" value="<?= (float)($markupFloor ?? 0) ?: '' ?>" step="0.01" min="0" placeholder="0" class="w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>
            <div class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
                On a sample net of <strong>100,000</strong>: you pay <strong>100,000</strong> · client price <strong class="text-slate-800" x-text="client.toLocaleString()"></strong> · your margin <strong class="text-emerald-700" x-text="margin.toLocaleString()"></strong>.
            </div>

            <!-- AG-G4: per-service overrides (optional — blank = use the default above) -->
            <?php if (!empty($bcServices)): ?>
            <details class="mt-5">
                <summary class="cursor-pointer text-sm font-semibold text-violet-700">Per-service markup (optional)</summary>
                <p class="text-xs text-slate-400 mt-1 mb-3">Override the default for specific services. Leave a value blank to use your default. The min-margin floor applies to every service.</p>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($bcServices as $s): $k = (string)$s['key'];
                        $ov = (isset($svcMarkup[$k]) && is_array($svcMarkup[$k])) ? $svcMarkup[$k] : null;
                        $ovV = $ov ? (float)($ov['value'] ?? 0) : null;
                        $ovT = $ov ? (string)($ov['type'] ?? 'percentage') : 'percentage';
                    ?>
                    <div class="rounded-lg border border-slate-200 p-2.5">
                        <div class="text-xs font-medium text-slate-600 mb-1.5"><?= htmlspecialchars((string)$s['label']) ?></div>
                        <div class="flex gap-2">
                            <input type="number" name="svc_markup_value[<?= htmlspecialchars($k) ?>]" value="<?= $ovV !== null ? htmlspecialchars((string)$ovV) : '' ?>" step="0.01" min="0" placeholder="default" class="w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            <select name="svc_markup_type[<?= htmlspecialchars($k) ?>]" class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                <option value="percentage" <?= $ovT==='percentage'?'selected':'' ?>>%</option>
                                <option value="fixed" <?= $ovT==='fixed'?'selected':'' ?>>Fixed</option>
                            </select>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($canEdit)): ?>
        <button class="px-5 py-2.5 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Save branding</button>
        <?php endif; ?>
    </form>
</div>
