<?php @$SECURE or die('Access Denied!'); ?>
<!-- ToursBMS — Content Import tab (admin) -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5" x-data="toursbmsImport()">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center gap-2">
        <span class="material-symbols-outlined text-gray-600 text-lg">upload_file</span>
        <h3 class="text-sm font-semibold text-gray-900">Content Import</h3>
    </div>
    <div class="p-4 space-y-4">
        <p class="text-sm text-gray-600">
            Pull the product catalogue, types and regions from ToursBMS into the local
            database. Prices and availability are always fetched live at search time, so only
            product content is stored here. Run a <strong>Full import</strong> once, then use
            <strong>Incremental</strong> (or a cron) to stay in sync.
        </p>

        <div class="flex flex-wrap gap-3">
            <button type="button" @click="run('full')" :disabled="busy"
                class="btn" :class="busy ? 'opacity-50 cursor-not-allowed' : ''">
                <span x-show="!busy" class="material-symbols-outlined text-sm">cloud_download</span>
                <span x-show="busy && mode==='full'" class="material-symbols-outlined text-sm animate-spin">progress_activity</span>
                Full Import
            </button>
            <button type="button" @click="run('incremental')" :disabled="busy"
                class="btn secondary" :class="busy ? 'opacity-50 cursor-not-allowed' : ''">
                <span x-show="!busy" class="material-symbols-outlined text-sm">sync</span>
                <span x-show="busy && mode==='incremental'" class="material-symbols-outlined text-sm animate-spin">progress_activity</span>
                Incremental Update
            </button>
        </div>

        <div x-show="busy" class="text-sm text-gray-500 flex items-center gap-2">
            <span class="material-symbols-outlined text-sm animate-spin">progress_activity</span>
            Importing… this can take a while for a large catalogue. Please keep this tab open.
        </div>
        <div x-show="result" x-cloak class="text-sm rounded-lg border p-3"
             :class="ok ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'">
            <span x-text="result"></span>
        </div>
    </div>
</div>

<script>
function toursbmsImport() {
    return {
        busy: false, mode: '', result: '', ok: false,
        run(mode) {
            if (this.busy) return;
            this.busy = true; this.mode = mode; this.result = '';
            const body = new URLSearchParams({ mode });
            fetch('<?= root ?>modules/tours/toursbms/import', {
                method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body
            }).then(r => r.json()).then(res => {
                this.busy = false;
                this.ok = !!res.success;
                this.result = res.message || (res.success ? 'Import complete.' : 'Import failed.');
            }).catch(e => {
                this.busy = false; this.ok = false;
                this.result = 'Network error: ' + e.message;
            });
        }
    };
}
</script>
