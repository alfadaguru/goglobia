<?php
// ============================================================================
// ADMIN VIEW — SEO Landing Pages manager.
// Controls the DB-backed SSR SEO landing pages (flight routes, hotel cities,
// umrah origins). Grouped by type; add/edit via a modal whose fields change per
// type. Toggle on/off, reorder (sort_order), delete, and a live "View" link to
// the public page. No inline auth: the route layer calls ADMIN_AUTH() (CLAUDE.md
// §4). All writes go to CSRF-guarded JSON endpoints; app.js attaches the
// X-CSRF-TOKEN header automatically.
// ============================================================================
@$SECURE or die('Access Denied!');

if (function_exists('ensureSeoLandingSchema')) { ensureSeoLandingSchema($db); }

$__types = function_exists('seoLandingTypes') ? seoLandingTypes() : [];
$__rows  = $db->select('seo_landing_pages', '*', [
    'ORDER' => ['type' => 'ASC', 'sort_order' => 'ASC', 'id' => 'ASC'],
]) ?: [];

$__byType = ['flight_route' => [], 'hotel_city' => [], 'umrah_origin' => []];
foreach ($__rows as $r) {
    $t = (string) ($r['type'] ?? '');
    if (isset($__byType[$t])) { $__byType[$t][] = $r; }
}
$__root = rtrim((string) root, '/');

// Flatten a row into the shape the JS editor expects (data JSON → flat fields).
$__editPayload = function (array $r): array {
    $d = json_decode((string) ($r['data'] ?? ''), true);
    if (!is_array($d)) { $d = []; }
    return [
        'id'         => (int) $r['id'],
        'type'       => (string) $r['type'],
        'slug'       => (string) $r['slug'],
        'status'     => (int) $r['status'],
        'sort_order' => (int) $r['sort_order'],
        'name'       => (string) ($d['name'] ?? ($r['title'] ?? '')),
        // flight_route
        'duration'   => (string) ($d['duration'] ?? ''),
        'airlines'   => is_array($d['airlines'] ?? null) ? implode("\n", $d['airlines']) : '',
        'note'       => (string) ($d['note'] ?? ''),
        // hotel_city
        'country'        => (string) ($d['country'] ?? ''),
        'nat'            => (string) ($d['nat'] ?? 'NG'),
        'intro'          => (string) ($d['intro'] ?? ''),
        'areas'          => is_array($d['areas'] ?? null) ? implode("\n", $d['areas']) : '',
        'airport'        => (string) ($d['airport'] ?? ''),
        'tip'            => (string) ($d['tip'] ?? ''),
        'currency'       => (string) ($d['currency'] ?? ''),
        'language'       => (string) ($d['language'] ?? ''),
        'best_time'      => (string) ($d['best_time'] ?? ''),
        'getting_around' => (string) ($d['getting_around'] ?? ''),
        'highlights'     => (string) ($d['highlights'] ?? ''),
        // umrah_origin (note/airport/name shared above; carriers here)
        'carriers'       => (string) ($d['carriers'] ?? ''),
    ];
};
?>
<div class="p-4 sm:p-6" x-data="seoLanding()">
  <!-- Header -->
  <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
    <div>
      <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
        <span class="material-symbols-outlined text-blue-600" aria-hidden="true">travel_explore</span>
        SEO Landing Pages
      </h1>
      <p class="text-sm text-gray-500 mt-1 max-w-3xl">Crawlable, search-optimised guide pages with their own title, content and schema. Each one links into the live search. Flight routes appear at <code>/flights/{from}-to-{to}</code>, hotel cities at <code>/hotels/{city}</code>, and Umrah origins at <code>/umrah/from-{city}</code>. They are listed in the sitemap and the <a href="<?= $__root ?>/travel-directory" target="_blank" class="text-blue-600 hover:underline">travel directory</a>.</p>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= $__root ?>/travel-directory" target="_blank" class="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-600 hover:text-blue-700 px-3 py-2">
        <span class="material-symbols-outlined !text-base" aria-hidden="true">open_in_new</span> View directory
      </a>
    </div>
  </div>

  <?php
  $__groupHeads = [
      'flight_route' => ['Flight routes', 'flight', 'blue'],
      'hotel_city'   => ['Hotel cities', 'hotel', 'emerald'],
      'umrah_origin' => ['Umrah origins', 'mosque', 'amber'],
  ];
  foreach ($__groupHeads as $type => [$heading, $icon, $color]):
      $pfx = $__types[$type]['url_prefix'] ?? '';
  ?>
  <div class="mb-9">
    <div class="flex items-center justify-between gap-3 mb-3">
      <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wide flex items-center gap-2">
        <span class="material-symbols-outlined !text-lg text-<?= $color ?>-600" aria-hidden="true"><?= $icon ?></span>
        <?= htmlspecialchars($heading) ?>
        <span class="text-gray-400 font-normal normal-case">(<?= count($__byType[$type]) ?>)</span>
      </h2>
      <button type="button" @click="openAdd('<?= $type ?>')" class="inline-flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg">
        <span class="material-symbols-outlined !text-base" aria-hidden="true">add</span> Add
      </button>
    </div>
    <?php if (empty($__byType[$type])): ?>
      <p class="text-sm text-gray-400 italic py-4">No <?= htmlspecialchars(strtolower($heading)) ?> yet.</p>
    <?php else: ?>
    <div class="overflow-x-auto bg-white border border-gray-200 rounded-lg">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
          <tr>
            <th class="text-left px-4 py-2.5">Slug / URL</th>
            <th class="text-left px-4 py-2.5"><?= $type === 'flight_route' ? 'Content summary' : 'Title' ?></th>
            <th class="text-center px-4 py-2.5">Order</th>
            <th class="text-center px-4 py-2.5">Status</th>
            <th class="text-right px-4 py-2.5">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($__byType[$type] as $r):
              $pl = $__editPayload($r);
              $publicUrl = $__root . '/' . $pfx . $r['slug'];
          ?>
          <tr data-id="<?= (int) $r['id'] ?>">
            <td class="px-4 py-3 whitespace-nowrap">
              <span class="font-semibold text-gray-900"><?= htmlspecialchars((string) $r['slug']) ?></span>
              <a href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" class="block text-xs text-blue-600 hover:underline mt-0.5"><?= htmlspecialchars('/' . $pfx . $r['slug']) ?></a>
            </td>
            <td class="px-4 py-3 text-gray-600">
              <?php if ($type === 'flight_route'): ?>
                <?= htmlspecialchars($pl['duration'] !== '' ? $pl['duration'] : '—') ?>
                <?php if ($pl['airlines'] !== ''): ?><span class="text-gray-400"> · <?= (int) substr_count($pl['airlines'], "\n") + 1 ?> airline(s)</span><?php endif; ?>
              <?php else: ?>
                <?= htmlspecialchars($pl['name'] !== '' ? $pl['name'] : (string) $r['slug']) ?>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-center text-gray-600"><?= (int) $r['sort_order'] ?></td>
            <td class="px-4 py-3 text-center">
              <button type="button" @click="toggle(<?= (int) $r['id'] ?>)"
                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold <?= ((int) $r['status'] === 1) ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                <?= ((int) $r['status'] === 1) ? 'Live' : 'Hidden' ?>
              </button>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <button type="button" @click='openEdit(<?= json_encode($pl, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)' class="text-blue-600 hover:underline text-xs font-semibold mr-3">Edit</button>
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
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto" @click.outside="open=false">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 sticky top-0 bg-white">
        <h3 class="font-bold text-gray-900">
          <span x-text="form.id ? 'Edit' : 'Add'"></span>
          <span x-text="typeLabel"></span>
        </h3>
        <button type="button" @click="open=false" class="text-gray-400 hover:text-gray-700" aria-label="Close"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
      </div>
      <div class="p-5 space-y-4">
        <!-- Slug + common -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Slug (URL)</label>
            <input type="text" x-model="form.slug" :disabled="!!form.id"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm disabled:bg-gray-50 disabled:text-gray-500">
            <p class="text-xs text-gray-400 mt-1" x-text="slugHint"></p>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Sort order</label>
            <input type="number" x-model.number="form.sort_order" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          </div>
        </div>

        <!-- Name (hotel_city + umrah_origin) -->
        <div x-show="form.type !== 'flight_route'">
          <label class="block text-xs font-semibold text-gray-600 mb-1">Display name</label>
          <input type="text" x-model="form.name" placeholder="e.g. Dubai / Kano"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>

        <!-- ===== FLIGHT ROUTE fields ===== -->
        <template x-if="form.type === 'flight_route'">
          <div class="space-y-4">
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Typical flight time</label>
              <input type="text" x-model="form.duration" placeholder="e.g. about 1h 10m"
                     class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Airlines <span class="text-gray-400 font-normal">(one per line)</span></label>
              <textarea x-model="form.airlines" rows="3" placeholder="Air Peace&#10;Ibom Air&#10;United Nigeria"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Route note <span class="text-gray-400 font-normal">(one honest sentence, optional)</span></label>
              <textarea x-model="form.note" rows="2" placeholder="e.g. One of the busiest domestic routes in Nigeria."
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
          </div>
        </template>

        <!-- ===== HOTEL CITY fields ===== -->
        <template x-if="form.type === 'hotel_city'">
          <div class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Country</label>
                <input type="text" x-model="form.country" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Default nationality</label>
                <input type="text" x-model="form.nat" maxlength="2" placeholder="NG" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm uppercase">
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Currency</label>
                <input type="text" x-model="form.currency" placeholder="Nigerian Naira (NGN)" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Airport</label>
                <input type="text" x-model="form.airport" placeholder="Murtala Muhammed International Airport (LOS)" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Language</label>
                <input type="text" x-model="form.language" placeholder="English" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              </div>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Intro <span class="text-gray-400 font-normal">(the hero paragraph)</span></label>
              <textarea x-model="form.intro" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Neighbourhoods / areas <span class="text-gray-400 font-normal">(one per line)</span></label>
              <textarea x-model="form.areas" rows="4" placeholder="Victoria Island — business hotels and upscale dining&#10;Ikeja — convenient for the airport" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Booking tip</label>
              <textarea x-model="form.tip" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Best time to visit</label>
              <textarea x-model="form.best_time" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Getting around</label>
              <textarea x-model="form.getting_around" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Things to see &amp; do</label>
              <textarea x-model="form.highlights" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
          </div>
        </template>

        <!-- ===== UMRAH ORIGIN fields ===== -->
        <template x-if="form.type === 'umrah_origin'">
          <div class="space-y-4">
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Intro <span class="text-gray-400 font-normal">(the hero paragraph)</span></label>
              <textarea x-model="form.intro" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Body note <span class="text-gray-400 font-normal">(the "with GoGlobia" paragraph)</span></label>
              <textarea x-model="form.note" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Departure airport</label>
              <input type="text" x-model="form.airport" placeholder="Mallam Aminu Kano International Airport (KAN)" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 mb-1">Carriers note <span class="text-gray-400 font-normal">(optional sentence about airlines/connections)</span></label>
              <textarea x-model="form.carriers" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
          </div>
        </template>

        <label class="flex items-center gap-2 text-sm pt-1">
          <input type="checkbox" x-model="form.statusOn"> <span>Live (indexable &amp; in sitemap)</span>
        </label>
        <p x-show="err" x-text="err" x-cloak class="text-sm text-red-600"></p>
      </div>
      <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-gray-100 sticky bottom-0 bg-white">
        <button type="button" @click="open=false" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancel</button>
        <button type="button" @click="save()" :disabled="saving" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-5 py-2 rounded-lg disabled:opacity-60">
          <span x-text="saving ? 'Saving…' : 'Save page'"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function seoLanding() {
  const HINTS = {
    flight_route: 'e.g. lagos-to-abuja (lowercase, must contain "-to-")',
    hotel_city:   'e.g. dubai (lowercase, hyphens for spaces)',
    umrah_origin: 'e.g. kano (lowercase departure city)'
  };
  const LABELS = { flight_route: 'flight route', hotel_city: 'hotel city', umrah_origin: 'Umrah origin' };
  const blank = (type) => ({
    id: 0, type: type, slug: '', name: '', sort_order: 0, statusOn: true,
    duration: '', airlines: '', note: '',
    country: '', nat: 'NG', intro: '', areas: '', airport: '', tip: '',
    currency: '', language: '', best_time: '', getting_around: '', highlights: '',
    carriers: ''
  });
  return {
    open: false, saving: false, err: '',
    form: blank('flight_route'),
    base: <?= json_encode($__root . '/' . admin . '/seo/landing-pages') ?>,

    get typeLabel() { return LABELS[this.form.type] || 'landing page'; },
    get slugHint() { return HINTS[this.form.type] || ''; },

    openAdd(type) { this.form = blank(type); this.err = ''; this.open = true; },
    openEdit(r) {
      const f = blank(r.type);
      for (const k in f) { if (k in r && r[k] !== null && r[k] !== undefined) f[k] = r[k]; }
      f.id = r.id; f.statusOn = Number(r.status) === 1;
      this.form = f; this.err = ''; this.open = true;
    },
    async save() {
      this.err = '';
      if (!this.form.slug || !this.form.slug.trim()) { this.err = 'Slug is required.'; return; }
      this.saving = true;
      try {
        const f = this.form, fd = new FormData();
        fd.append('id', f.id || 0);
        fd.append('type', f.type);
        fd.append('slug', f.slug);
        fd.append('name', f.name || '');
        fd.append('sort_order', f.sort_order || 0);
        fd.append('status', f.statusOn ? '1' : '0');
        // type-specific
        fd.append('duration', f.duration || '');
        fd.append('airlines', f.airlines || '');
        fd.append('note', f.note || '');
        fd.append('country', f.country || '');
        fd.append('nat', f.nat || '');
        fd.append('intro', f.intro || '');
        fd.append('areas', f.areas || '');
        fd.append('airport', f.airport || '');
        fd.append('tip', f.tip || '');
        fd.append('currency', f.currency || '');
        fd.append('language', f.language || '');
        fd.append('best_time', f.best_time || '');
        fd.append('getting_around', f.getting_around || '');
        fd.append('highlights', f.highlights || '');
        fd.append('carriers', f.carriers || '');
        const r = await fetch(this.base + '/save', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const j = await r.json();
        if (j.success) { location.reload(); } else { this.err = j.message || 'Could not save.'; }
      } catch (e) { this.err = 'Network error — try again.'; }
      finally { this.saving = false; }
    },
    async toggle(id) {
      try {
        const fd = new FormData(); fd.append('id', id);
        const r = await fetch(this.base + '/toggle', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const j = await r.json();
        if (j.success) location.reload();
      } catch (e) {}
    },
    async del(id) {
      if (!confirm('Delete this landing page? Its URL will 404/redirect afterwards.')) return;
      try {
        const fd = new FormData(); fd.append('id', id);
        const r = await fetch(this.base + '/delete', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const j = await r.json();
        if (j.success) location.reload();
      } catch (e) {}
    }
  };
}
</script>
