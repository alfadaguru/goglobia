<?php
// Determine if this is edit or add mode
$suggestionId = $_GET['id'] ?? 0;
$isEdit = $suggestionId > 0;

// Fetch existing suggestion data if editing
$suggestion = [];
if ($isEdit) {
    $suggestion = $db->get('ai_suggestions', '*', ['id' => $suggestionId]);
    if (!$suggestion) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'AI Suggestion not found'
        ];
        redirect(root . admin . '/settings#ai');
    }
}

$materialSymbolsUrl = root . 'assets/data/material-symbols.json';
$quickIcons = [
    'flight', 'hotel', 'directions_car', 'train', 'directions_boat',
    'luggage', 'beach_access', 'map', 'restaurant', 'auto_awesome',
    'travel_explore', 'public', 'local_activity', 'surfing', 'cottage',
];
$iconColors = [
    '#0058E6', '#0ea5e9', '#10b981', '#22c55e', '#f59e0b',
    '#ef4444', '#ec4899', '#8b5cf6', '#6366f1', '#64748b',
    '#0f172a', '#d97706',
];
?>

<div class="container my-4"
     x-data="aiSuggestionForm(<?= htmlspecialchars(json_encode([
         'iconsUrl' => $materialSymbolsUrl,
         'quickIcons' => $quickIcons,
         'colors' => $iconColors,
         'text' => (string)($suggestion['suggestions'] ?? ''),
     ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP), ENT_QUOTES) ?>)"
     x-init="loadIcons()"
     :class="pickerOpen ? 'pb-72' : 'pb-8'">
    <form method="POST" @submit="loading = true">
        <input type="hidden" name="action" value="save_ai_suggestion">
        <input type="hidden" name="suggestions" :value="composedText">
        <?= CSRF::tokenField() ?>
        <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $suggestionId ?>">
        <?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/settings#ai" class="btn white !w-12 !px-0" title="Back">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-1xl font-bold text-slate-800 flex items-center gap-2">
                    <span class="material-symbols-outlined text-sky-600 text-2xl">smart_toy</span>
                    <?= $isEdit ? 'Edit AI Suggestion' : 'Add AI Suggestion' ?>
                </h1>

            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg"
                    :class="{ 'opacity-75 cursor-not-allowed': loading }"
                    :disabled="loading">
                <span class="material-symbols-outlined text-sm" x-show="!loading">save</span>
                <span class="material-symbols-outlined text-sm animate-spin" x-show="loading" style="display: none;">progress_activity</span>
                <span class="font-medium" x-text="loading ? 'Saving...' : 'Save'"></span>
            </button>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-6">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="card p-6 bg-white rounded-xl shadow-sm border border-slate-200 overflow-visible">
        <h2 class="text-base font-semibold text-slate-800 mb-6 pb-4 border-b border-slate-100 flex items-center gap-2">
            <span class="material-symbols-outlined text-sky-600 text-xl">auto_awesome</span>
            Suggestion Details
        </h2>

        <div class="form-control overflow-visible">
            <label for="suggestion_text" class="flex items-center gap-1 mb-2">
                Suggestions <span class="text-red-500">*</span>
            </label>

            <div class="relative overflow-visible" @click.outside="pickerOpen = false">
                <div class="flex w-full">
                    <button type="button"
                            class="inline-flex items-center justify-center shrink-0 px-3 border border-r-0 border-gray-300 rounded-l-md hover:bg-gray-50 transition-colors"
                            style="min-width: 3rem; height: var(--input-height, 2.5rem);"
                            :style="selectedColor
                                ? 'background:' + selectedColor + '18; border-color:' + selectedColor + '55;'
                                : 'background:#f3f4f6;'"
                            title="Choose color & icon"
                            @click="pickerOpen = !pickerOpen; if (pickerOpen) $nextTick(() => $refs.iconSearch && $refs.iconSearch.focus())">
                        <span class="material-symbols-outlined text-[22px]"
                              :style="'color:' + (selectedColor || '#64748b')"
                              x-text="selectedIcon || 'palette'"></span>
                    </button>
                    <input id="suggestion_text"
                           type="text"
                           x-model="bodyText"
                           required
                           maxlength="220"
                           class="input !rounded-l-none flex-1 min-w-0"
                           placeholder="e.g. Looking for flights to Paris next month">
                </div>

                <!-- Opens downward; page gets bottom padding so footer doesn’t cover it -->
                <div x-show="pickerOpen" x-cloak x-transition
                     class="absolute left-0 top-full z-50 mt-2 w-full max-w-lg rounded-xl border border-slate-200 bg-white p-4 shadow-lg overflow-x-hidden">
                    <p class="text-xs font-semibold text-slate-700 mb-2">1. Choose color</p>
                    <div class="flex flex-wrap gap-2 mb-4">
                        <template x-for="c in colors" :key="'c-'+c">
                            <button type="button"
                                    class="w-8 h-8 rounded-full border-2 transition-transform hover:scale-110 shrink-0"
                                    :class="selectedColor === c ? 'ring-2 ring-offset-2 ring-slate-400 scale-110' : 'border-white shadow'"
                                    :style="'background-color:' + c"
                                    :title="c"
                                    @click="selectedColor = c"></button>
                        </template>
                        <button type="button"
                                class="w-8 h-8 rounded-full border border-dashed border-slate-300 bg-white text-slate-400 flex items-center justify-center shrink-0"
                                title="No color"
                                @click="selectedColor = ''">
                            <span class="material-symbols-outlined text-sm">block</span>
                        </button>
                    </div>

                    <p class="text-xs font-semibold text-slate-700 mb-2">2. Choose Material icon</p>
                    <div class="relative mb-3">
                        <span class="material-symbols-outlined text-slate-400 text-base absolute left-2 top-1/2 -translate-y-1/2 pointer-events-none">search</span>
                        <input type="search"
                               x-ref="iconSearch"
                               x-model="iconQuery"
                               class="input w-full pl-8 text-sm h-9"
                               placeholder="Search Material icons…">
                    </div>

                    <div class="mb-2" x-show="!iconQuery.trim() && quickIcons.length">
                        <p class="text-[11px] text-slate-500 mb-1.5">Quick picks</p>
                        <div class="grid grid-cols-8 sm:grid-cols-10 gap-1.5">
                            <template x-for="name in quickIcons" :key="'q-'+name">
                                <button type="button"
                                        class="ai-icon-pick inline-flex items-center justify-center w-9 h-9 rounded-md border border-slate-200 bg-slate-50 hover:bg-white hover:border-slate-300 transition-colors overflow-hidden"
                                        :class="selectedIcon === name ? 'ring-2 ring-blue-400 border-blue-300' : ''"
                                        :title="name"
                                        @click="setIconOnly(name)">
                                    <span class="material-symbols-outlined ai-icon-glyph"
                                          :style="'color:' + (selectedColor || '#0058E6')"
                                          x-text="name"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <p class="text-[11px] text-slate-500 mb-2" x-show="iconsLoading">Loading Material icons…</p>
                    <div class="max-h-64 overflow-y-auto overflow-x-hidden overscroll-contain rounded-lg border border-slate-100 p-2"
                         x-show="!iconsLoading">
                        <div class="grid grid-cols-8 sm:grid-cols-10 gap-1.5 w-full min-w-0">
                            <template x-for="name in filteredIcons" :key="'m-'+name">
                                <button type="button"
                                        class="ai-icon-pick inline-flex items-center justify-center w-9 h-9 rounded-md border border-slate-200 bg-slate-50 hover:bg-white hover:border-slate-300 transition-colors overflow-hidden"
                                        :class="selectedIcon === name ? 'ring-2 ring-blue-400 border-blue-300' : ''"
                                        :title="name"
                                        @click="setIconOnly(name)">
                                    <span class="material-symbols-outlined ai-icon-glyph"
                                          :style="'color:' + (selectedColor || '#0058E6')"
                                          x-text="name"></span>
                                </button>
                            </template>
                        </div>
                        <p class="text-[11px] text-amber-600 p-2" x-show="filteredIcons.length === 0">No icons match.</p>
                    </div>

                    <div class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-2 justify-between">
                        <button type="button"
                                class="btn light flex items-center space-x-2"
                                x-show="selectedIcon || selectedColor"
                                @click="clearIcon()">
                            <span class="material-symbols-outlined text-sm">restart_alt</span>
                            <span class="font-medium">Clear</span>
                        </button>
                        <button type="button"
                                class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg ml-auto"
                                @click="pickerOpen = false">
                            <span class="material-symbols-outlined text-sm">check</span>
                            <span class="font-medium">Done</span>
                        </button>
                    </div>
                </div>
            </div>

            <p class="text-xs text-slate-500 mt-1">
                Pick a color, then an icon at the start of the suggestion.
            </p>
        </div>
    </div>
    </form>
</div>

<style>
/* Keep Material Symbol ligature text clipped inside fixed icon cells */
.ai-icon-pick .ai-icon-glyph {
    font-size: 20px;
    line-height: 1;
    width: 1.25rem;
    height: 1.25rem;
    display: inline-block;
    overflow: hidden;
    white-space: nowrap;
    text-align: center;
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 20;
}
</style>

<script>
function aiSuggestionForm(cfg) {
    cfg = cfg || {};
    return {
        loading: false,
        pickerOpen: false,
        iconsLoading: true,
        icons: [],
        iconQuery: '',
        quickIcons: Array.isArray(cfg.quickIcons) ? cfg.quickIcons : [],
        colors: Array.isArray(cfg.colors) ? cfg.colors : ['#0058E6'],
        selectedIcon: '',
        selectedColor: '#0058E6',
        bodyText: '',
        iconsUrl: cfg.iconsUrl || '',

        init() {
            const raw = String(cfg.text || '');
            // :icon:#RRGGBB: text  OR legacy :icon: text
            const m = raw.match(/^:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?\s*/i);
            if (m) {
                this.selectedIcon = m[1];
                this.selectedColor = m[2] ? ('#' + m[2]) : '#0058E6';
                this.bodyText = raw.slice(m[0].length);
            } else {
                this.selectedIcon = '';
                this.selectedColor = '#0058E6';
                this.bodyText = raw;
            }
        },

        get composedText() {
            const body = (this.bodyText || '').trim();
            if (!this.selectedIcon) return body.slice(0, 255);
            const hex = String(this.selectedColor || '').replace(/^#/, '');
            const prefix = hex
                ? (':' + this.selectedIcon + ':#' + hex + ':')
                : (':' + this.selectedIcon + ':');
            return (prefix + (body ? ' ' + body : '')).slice(0, 255);
        },

        get filteredIcons() {
            const q = (this.iconQuery || '').trim().toLowerCase().replace(/\s+/g, '_');
            const list = this.icons || [];
            if (!q) return list.slice(0, 240); // keep picker light; search for the rest
            return list.filter((n) => n.indexOf(q) !== -1).slice(0, 240);
        },

        async loadIcons() {
            this.iconsLoading = true;
            try {
                const res = await fetch(this.iconsUrl);
                const data = await res.json();
                this.icons = Array.isArray(data) ? data : [];
            } catch (e) {
                this.icons = this.quickIcons.slice();
            }
            this.iconsLoading = false;
        },

        setIconOnly(name) {
            if (!name) return;
            this.selectedIcon = name;
            // Keep picker open so color can still be tweaked; close on Done
            this.iconQuery = '';
        },

        clearIcon() {
            this.selectedIcon = '';
            this.selectedColor = '#0058E6';
        }
    };
}
</script>
