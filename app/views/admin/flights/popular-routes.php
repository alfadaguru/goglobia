<?php
// ============================================================================
// ADMIN VIEW — Popular Flight Routes manager.
// Controls the homepage "Popular flight routes" strip. Grouped Local vs
// International; add/edit via a modal with live airport autocomplete (local
// flights_airports). Toggle on/off, reorder (sort_order), delete.
// No inline auth: the route layer calls ADMIN_AUTH() (CLAUDE.md §4). All writes
// go to CSRF-guarded JSON endpoints; app.js attaches X-CSRF-TOKEN automatically.
// ============================================================================
@$SECURE or die('Access Denied!');

$__rows = $db->select('flights_popular_routes', '*', [
    'ORDER' => ['scope' => 'ASC', 'sort_order' => 'ASC', 'id' => 'ASC'],
]) ?: [];
$__byScope = ['local' => [], 'international' => []];
foreach ($__rows as $r) {
    $s = ($r['scope'] ?? 'international') === 'local' ? 'local' : 'international';
    $__byScope[$s][] = $r;
}
$__root = rtrim((string) root, '/');
?>
<div class="p-4 sm:p-6" x-data="popularRoutes()">
  <!-- Header -->
  <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
    <div>
      <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
        <span class="material-symbols-outlined text-blue-600">interests</span>
        Popular Flight Routes
      </h1>
      <p class="text-sm text-gray-500 mt-1">These tiles appear on the homepage. A tile opens the flight search prefilled with the route; the customer picks the dates. A real "from" price shows only when a matching Featured Flight deal exists.</p>
    </div>
    <button type="button" @click="openAdd()" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">
      <span class="material-symbols-outlined !text-base">add</span> Add Route
    </button>
  </div>

  <?php foreach (['local' => 'Within Nigeria (local)', 'international' => 'International'] as $scope => $heading): ?>
  <div class="mb-8">
    <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wide mb-3"><?= $heading ?></h2>
    <?php if (empty($__byScope[$scope])): ?>
      <p class="text-sm text-gray-400 italic py-4">No <?= $scope ?> routes yet.</p>
    <?php else: ?>
    <div class="overflow-x-auto bg-white border border-gray-200 rounded-lg">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
          <tr>
            <th class="text-left px-4 py-2.5">Route</th>
            <th class="text-left px-4 py-2.5">Labels</th>
            <th class="text-left px-4 py-2.5">Image</th>
            <th class="text-center px-4 py-2.5">Order</th>
            <th class="text-center px-4 py-2.5">Status</th>
            <th class="text-right px-4 py-2.5">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($__byScope[$scope] as $r): ?>
          <tr data-id="<?= (int) $r['id'] ?>">
            <td class="px-4 py-3 font-semibold text-gray-900 whitespace-nowrap">
              <?= htmlspecialchars($r['from_code']) ?> <span class="text-blue-600">→</span> <?= htmlspecialchars($r['to_code']) ?>
            </td>
            <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars((string) $r['from_label']) ?> · <?= htmlspecialchars((string) $r['to_label']) ?></td>
            <td class="px-4 py-3">
              <?php if (!empty($r['image'])): ?>
                <img src="<?= htmlspecialchars($__root . '/' . ltrim((string) $r['image'], '/')) ?>" alt="" class="w-14 h-9 object-cover rounded border border-gray-200">
              <?php else: ?>
                <span class="text-xs text-gray-400">—</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-center text-gray-600"><?= (int) $r['sort_order'] ?></td>
            <td class="px-4 py-3 text-center">
              <button type="button" @click="toggle(<?= (int) $r['id'] ?>, $event)"
                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold <?= ((int) $r['status'] === 1) ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                <?= ((int) $r['status'] === 1) ? 'Active' : 'Hidden' ?>
              </button>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <button type="button" @click='openEdit(<?= json_encode([
                  "id" => (int) $r["id"], "from_code" => $r["from_code"], "to_code" => $r["to_code"],
                  "from_label" => $r["from_label"], "to_label" => $r["to_label"], "scope" => $r["scope"],
                  "image" => $r["image"], "sort_order" => (int) $r["sort_order"], "status" => (int) $r["status"],
              ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="text-blue-600 hover:underline text-xs font-semibold mr-3">Edit</button>
              <button type="button" @click="del(<?= (int) $r['id'] ?>)" class="text-red-600 hover:underline text-xs font-semibold">Delete</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <!-- ADD/EDIT MODAL -->
  <div x-show="open" x-cloak class="fixed inset-0 z-[9999] flex items-center justify-center p-4" style="background:rgba(0,0,0,.5)" @keydown.escape.window="open=false">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto" @click.outside="open=false">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
        <h3 class="font-bold text-gray-900" x-text="form.id ? 'Edit route' : 'Add route'"></h3>
        <button type="button" @click="open=false" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">close</span></button>
      </div>
      <div class="p-5 space-y-4">
        <!-- FROM autocomplete -->
        <div class="relative">
          <label class="block text-xs font-semibold text-gray-600 mb-1">From airport</label>
          <input type="text" x-model="fromQ" @input.debounce.250ms="search('from')" placeholder="City or code (e.g. Lagos / LOS)"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
          <div x-show="fromResults.length" class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-56 overflow-y-auto">
            <template x-for="a in fromResults" :key="'f'+a.id">
              <div @click="pick('from', a)" class="px-3 py-2 cursor-pointer hover:bg-blue-50 text-sm">
                <span class="font-semibold" x-text="a.code"></span> — <span x-text="a.city"></span>, <span class="text-gray-500" x-text="a.country"></span>
              </div>
            </template>
          </div>
          <p class="text-xs text-gray-500 mt-1">Selected: <span class="font-semibold" x-text="form.from_code || '—'"></span></p>
        </div>
        <!-- TO autocomplete -->
        <div class="relative">
          <label class="block text-xs font-semibold text-gray-600 mb-1">To airport</label>
          <input type="text" x-model="toQ" @input.debounce.250ms="search('to')" placeholder="City or code (e.g. Dubai / DXB)"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
          <div x-show="toResults.length" class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-56 overflow-y-auto">
            <template x-for="a in toResults" :key="'t'+a.id">
              <div @click="pick('to', a)" class="px-3 py-2 cursor-pointer hover:bg-blue-50 text-sm">
                <span class="font-semibold" x-text="a.code"></span> — <span x-text="a.city"></span>, <span class="text-gray-500" x-text="a.country"></span>
              </div>
            </template>
          </div>
          <p class="text-xs text-gray-500 mt-1">Selected: <span class="font-semibold" x-text="form.to_code || '—'"></span></p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">From label</label>
            <input type="text" x-model="form.from_label" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">To label</label>
            <input type="text" x-model="form.to_label" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Group</label>
            <select x-model="form.scope" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option value="local">Within Nigeria (local)</option>
              <option value="international">International</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Sort order</label>
            <input type="number" x-model.number="form.sort_order" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Tile image (path under the site, optional)</label>
          <input type="text" x-model="form.image" placeholder="uploads/global/destinations/dubai.jpg"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <p class="text-xs text-gray-400 mt-1">Leave blank for a clean card. Reuse an existing destination photo under uploads/global/destinations/.</p>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" x-model="form.statusOn"> <span>Active (show on homepage)</span>
        </label>
        <p x-show="err" x-text="err" x-cloak class="text-sm text-red-600"></p>
      </div>
      <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-gray-100">
        <button type="button" @click="open=false" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</button>
        <button type="button" @click="save()" :disabled="saving" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-5 py-2 rounded-lg disabled:opacity-60">
          <span x-text="saving ? 'Saving…' : 'Save route'"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function popularRoutes() {
  return {
    open: false, saving: false, err: '',
    fromQ: '', toQ: '', fromResults: [], toResults: [],
    form: { id: 0, from_code: '', to_code: '', from_label: '', to_label: '', scope: 'international', image: '', sort_order: 0, statusOn: true },
    base: <?= json_encode($__root . '/' . admin . '/flights') ?>,
    searchUrl: <?= json_encode($__root . '/' . admin . '/flights/search-airports') ?>,

    openAdd() {
      this.form = { id: 0, from_code: '', to_code: '', from_label: '', to_label: '', scope: 'international', image: '', sort_order: 0, statusOn: true };
      this.fromQ = ''; this.toQ = ''; this.fromResults = []; this.toResults = []; this.err = ''; this.open = true;
    },
    openEdit(r) {
      this.form = {
        id: r.id, from_code: r.from_code, to_code: r.to_code, from_label: r.from_label || '',
        to_label: r.to_label || '', scope: r.scope || 'international', image: r.image || '',
        sort_order: r.sort_order || 0, statusOn: Number(r.status) === 1
      };
      this.fromQ = r.from_code; this.toQ = r.to_code; this.fromResults = []; this.toResults = []; this.err = ''; this.open = true;
    },
    async search(which) {
      const q = (which === 'from' ? this.fromQ : this.toQ).trim();
      if (q.length < 2) { if (which === 'from') this.fromResults = []; else this.toResults = []; return; }
      try {
        const fd = new FormData(); fd.append('search', q);
        const r = await fetch(this.searchUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const j = await r.json();
        const list = (j && j.airports) || [];
        if (which === 'from') this.fromResults = list; else this.toResults = list;
      } catch (e) { if (which === 'from') this.fromResults = []; else this.toResults = []; }
    },
    pick(which, a) {
      if (which === 'from') {
        this.form.from_code = (a.code || '').toUpperCase();
        if (!this.form.from_label) this.form.from_label = a.city || a.code;
        this.fromQ = a.code; this.fromResults = [];
      } else {
        this.form.to_code = (a.code || '').toUpperCase();
        if (!this.form.to_label) this.form.to_label = a.city || a.code;
        this.toQ = a.code; this.toResults = [];
      }
    },
    async save() {
      this.err = '';
      if (!this.form.from_code || !this.form.to_code) { this.err = 'Pick both From and To airports from the suggestions.'; return; }
      this.saving = true;
      try {
        const fd = new FormData();
        fd.append('id', this.form.id || 0);
        fd.append('from_code', this.form.from_code);
        fd.append('to_code', this.form.to_code);
        fd.append('from_label', this.form.from_label || '');
        fd.append('to_label', this.form.to_label || '');
        fd.append('scope', this.form.scope);
        fd.append('image', this.form.image || '');
        fd.append('sort_order', this.form.sort_order || 0);
        fd.append('status', this.form.statusOn ? '1' : '0');
        const r = await fetch(this.base + '/popular-routes/save', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const j = await r.json();
        if (j.success) { location.reload(); } else { this.err = j.message || 'Could not save.'; }
      } catch (e) { this.err = 'Network error — try again.'; }
      finally { this.saving = false; }
    },
    async toggle(id, ev) {
      try {
        const fd = new FormData(); fd.append('id', id);
        const r = await fetch(this.base + '/popular-routes/toggle', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const j = await r.json();
        if (j.success) location.reload();
      } catch (e) {}
    },
    async del(id) {
      if (!confirm('Delete this route from the homepage strip?')) return;
      try {
        const fd = new FormData(); fd.append('id', id);
        const r = await fetch(this.base + '/popular-routes/delete', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const j = await r.json();
        if (j.success) location.reload();
      } catch (e) {}
    }
  };
}
</script>
