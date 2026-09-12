<?php
// UMRAH v2 admin — full CRUD console (tabbed).
// Tabs: Departures | Packages | Tiers | Payment plans. Every entity supports
// create / edit / ARCHIVE (soft-delete) / restore. Backed by the admin JSON
// routes under admin/umrah-manager/* and app/lib/umrah/admin_crud.php.
// Expects: $rows (enriched departures), $templates, $tiers, $plans (full rows,
// archived-filtered by the ?archived toggle), $templatesActive, $tiersActive,
// $dueSoon, $overdue.
@$SECURE or die('Access Denied!');
$csrf = $_SESSION['csrf_token'] ?? '';
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$base = root . 'admin/umrah-manager';
$showArchived = (int) ($_GET['archived'] ?? 0) === 1;
$templatesActive = $templatesActive ?? [];
$tiersActive = $tiersActive ?? [];
$plans = $plans ?? [];
$stdTierId = 0; foreach ($tiersActive as $t) { if (($t['code'] ?? '') === 'standard') { $stdTierId = (int) $t['id']; } }
// Map template id -> name for the departures table.
$tplName = []; foreach ($templates as $t) { $tplName[(int) $t['id']] = $t['name']; }
foreach ($templatesActive as $t) { $tplName[(int) $t['id']] = $t['name']; }
?>
<style>[x-cloak]{display:none!important}</style>
<div class="container py-6" x-data="umrahMgr()">
  <div class="flex items-center justify-between flex-wrap gap-3 mb-5">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Umrah Manager</h1>
      <p class="text-sm text-slate-600 mt-1">Full control of packages, tiers, payment plans and departures — no code release needed.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <a href="<?= root ?>admin/umrah-manager/bookings" class="btn outline"><span class="material-symbols-outlined">list_alt</span><span>Bookings</span></a>
      <a href="<?= root ?>admin/umrah-manager/quote-requests" class="btn outline"><span class="material-symbols-outlined">contact_support</span><span>Quotes</span></a>
      <a href="<?= root ?>admin/umrah-manager/groups" class="btn outline"><span class="material-symbols-outlined">groups</span><span>Groups</span></a>
      <a href="<?= $base ?><?= $showArchived ? '' : '?archived=1' ?>" class="btn outline">
        <span class="material-symbols-outlined"><?= $showArchived ? 'visibility_off' : 'inventory_2' ?></span>
        <span><?= $showArchived ? 'Hide archived' : 'Show archived' ?></span>
      </a>
    </div>
  </div>

  <?php if ($showArchived): ?>
    <div class="alert-warning mb-4"><span class="material-icon material-symbols-outlined">inventory_2</span>
      <p>Showing archived records too. Archived items are hidden from the public site.</p></div>
  <?php endif; ?>

  <!-- KPI cards -->
  <div class="cards-grid-4 mb-6">
    <div class="card border"><div class="card-stat-label">Departures</div><div class="card-stat-value"><?= count($rows) ?></div></div>
    <div class="card border"><div class="card-stat-label">Published</div><div class="card-stat-value"><?= count(array_filter($rows, fn($r)=>$r['d']['status']==='published' && empty($r['d']['archived']))) ?></div></div>
    <div class="card border"><div class="card-stat-label">Due in 7 days</div><div class="card-stat-value"><?= $fmt($dueSoon) ?></div></div>
    <div class="card border"><div class="card-stat-label">Overdue</div><div class="card-stat-value text-red-600"><?= $fmt($overdue) ?></div></div>
  </div>

  <!-- TABS -->
  <div class="flex gap-1 border-b border-slate-200 mb-5 overflow-x-auto">
    <template x-for="t in tabs" :key="t.key">
      <button class="px-4 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap"
              :class="tab===t.key ? 'border-primary text-primary' : 'border-transparent text-slate-500 hover:text-slate-700'"
              @click="tab=t.key" x-text="t.label"></button>
    </template>
  </div>

  <!-- ============================ DEPARTURES ============================ -->
  <div x-show="tab==='departures'">
    <div class="flex gap-2 mb-4">
      <button class="btn" @click="showCreate=!showCreate"><span class="material-symbols-outlined">add</span><span>New departure</span></button>
      <button class="btn outline" @click="showBulk=!showBulk"><span class="material-symbols-outlined">event_repeat</span><span>Bulk 12th/28th</span></button>
    </div>

    <!-- Create form -->
    <div x-show="showCreate" x-cloak class="section mb-6">
      <div class="section-header"><h2>New departure</h2></div>
      <div class="form-grid">
        <div class="form-control"><label>Package</label>
          <select class="select" x-model="c.template_id">
            <?php foreach ($templatesActive as $t): ?><option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-control"><label>Tier</label>
          <select class="select" x-model="c.tier_id">
            <?php foreach ($tiersActive as $t): ?><option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['public_label'] ?: $t['code']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-control"><label>Departure date</label><input type="date" class="input" x-model="c.departure_date"></div>
        <div class="form-control"><label>Return date (blank = +14d)</label><input type="date" class="input" x-model="c.return_date"></div>
        <div class="form-control"><label>Capacity</label><input type="number" class="input" x-model.number="c.capacity"></div>
        <div class="form-control"><label>Regular price</label><input type="number" class="input" x-model.number="c.regular_price"></div>
        <div class="form-control"><label>Promo price</label><input type="number" class="input" x-model.number="c.promo_price"></div>
      </div>
      <div class="mt-4"><button class="btn" :disabled="busy" @click="create()">Create departure</button> <span class="text-sm text-slate-500" x-text="msg"></span></div>
    </div>

    <!-- Bulk form -->
    <div x-show="showBulk" x-cloak class="section mb-6">
      <div class="section-header"><h2>Bulk create (12th &amp; 28th)</h2><p>Generates two departures per month.</p></div>
      <div class="form-grid">
        <div class="form-control"><label>Package</label>
          <select class="select" x-model="bk.template_id">
            <?php foreach ($templatesActive as $t): ?><option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-control"><label>Months (comma YYYY-MM)</label><input class="input" x-model="bk.months" placeholder="2026-10,2026-11,2026-12"></div>
        <div class="form-control"><label>Capacity</label><input type="number" class="input" x-model.number="bk.capacity"></div>
        <div class="form-control"><label>Regular price</label><input type="number" class="input" x-model.number="bk.regular_price"></div>
        <div class="form-control"><label>Promo price</label><input type="number" class="input" x-model.number="bk.promo_price"></div>
      </div>
      <div class="mt-4"><button class="btn" :disabled="busy" @click="bulk()">Generate departures</button> <span class="text-sm text-slate-500" x-text="msg"></span></div>
    </div>

    <div class="section">
      <div class="table-container">
        <table class="table">
          <thead><tr><th>Date</th><th>Package</th><th>Code</th><th>Promo</th><th>Cap</th><th>Conf.</th><th>Avail</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="9" class="text-slate-500">No departures yet.</td></tr>
            <?php else: foreach ($rows as $r): $d=$r['d']; $dt=$r['dt']; $cap=$r['cap']; $arch=!empty($d['archived']); ?>
              <tr class="<?= $arch ? 'opacity-60' : '' ?>">
                <td class="whitespace-nowrap"><?= htmlspecialchars(date('d M Y', strtotime($d['departure_date']))) ?></td>
                <td class="text-sm"><?= htmlspecialchars($tplName[(int)$d['template_id']] ?? ('#'.$d['template_id'])) ?></td>
                <td class="text-xs font-mono"><?= htmlspecialchars($d['code']) ?></td>
                <td><?= $dt && $dt['promo_price'] ? $fmt($dt['promo_price']) : '—' ?></td>
                <td><?= (int)$cap['capacity'] ?></td>
                <td><?= (int)$r['confirmed_pax'] ?></td>
                <td><?= (int)$cap['remaining'] ?></td>
                <td>
                  <?php if ($arch): ?><span class="badge badge-gray">archived</span>
                  <?php else: ?><span class="badge <?= $d['status']==='published'?'badge-success':($d['status']==='closed'?'badge-error':'badge-gray') ?>"><?= htmlspecialchars($d['status']) ?></span><?php endif; ?>
                </td>
                <td class="whitespace-nowrap">
                  <?php if ($arch): ?>
                    <button class="btn btn-sm" @click="restore('departures',<?= (int)$d['id'] ?>)">Restore</button>
                  <?php else: ?>
                    <?php if ($d['status']!=='published'): ?>
                      <button class="btn btn-sm" @click="setStatus(<?= (int)$d['id'] ?>,'published')">Publish</button>
                    <?php else: ?>
                      <button class="btn btn-sm outline" @click="setStatus(<?= (int)$d['id'] ?>,'closed')">Close</button>
                    <?php endif; ?>
                    <button class="btn btn-sm outline" @click='editDeparture(<?= json_encode([
                      "id"=>(int)$d["id"],"departure_date"=>$d["departure_date"],"return_date"=>$d["return_date"],
                      "origin_city"=>$d["origin_city"],"capacity"=>(int)$d["capacity"],"low_stock_threshold"=>(int)$d["low_stock_threshold"],
                      "display_inventory_count"=>(int)$d["display_inventory_count"]
                    ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                    <button class="btn btn-sm outline" @click='openImages(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)($d['hero_image'] ?? '')), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(json_decode((string)($d['gallery'] ?? ''), true) ?: []), ENT_QUOTES) ?>)'>Images</button>
                    <button class="btn btn-sm outline" @click="clone(<?= (int)$d['id'] ?>)">Clone</button>
                    <a class="btn btn-sm outline" href="<?= root ?>admin/umrah-manager/operations/<?= (int)$d['id'] ?>">Ops</a>
                    <button class="btn btn-sm outline text-rose-600" @click="archive('departures',<?= (int)$d['id'] ?>)">Archive</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ============================ PACKAGES ============================ -->
  <div x-show="tab==='packages'" x-cloak>
    <div class="flex justify-between items-center mb-4">
      <h2 class="font-semibold text-slate-900">Packages (templates)</h2>
      <button class="btn" @click="newTemplate()"><span class="material-symbols-outlined">add</span><span>New package</span></button>
    </div>
    <div class="section"><div class="table-container"><table class="table">
      <thead><tr><th>Name</th><th>Slug</th><th>Nights</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($templates)): ?><tr><td colspan="5" class="text-slate-500">No packages yet.</td></tr>
        <?php else: foreach ($templates as $t): $arch=!empty($t['archived']); ?>
          <tr class="<?= $arch?'opacity-60':'' ?>">
            <td class="font-medium"><?= htmlspecialchars($t['name']) ?></td>
            <td class="text-xs font-mono"><?= htmlspecialchars($t['slug']) ?></td>
            <td class="text-sm"><?= (int)$t['madinah_nights'] ?>M + <?= (int)$t['makkah_nights'] ?>K</td>
            <td><?php if($arch): ?><span class="badge badge-gray">archived</span><?php else: ?><span class="badge <?= $t['status']?'badge-success':'badge-gray' ?>"><?= $t['status']?'active':'inactive' ?></span><?php endif; ?></td>
            <td class="whitespace-nowrap">
              <?php if($arch): ?><button class="btn btn-sm" @click="restore('templates',<?= (int)$t['id'] ?>)">Restore</button>
              <?php else: ?>
                <button class="btn btn-sm outline" @click='editTemplate(<?= json_encode([
                  "id"=>(int)$t["id"],"code"=>$t["code"],"slug"=>$t["slug"],"name"=>$t["name"],"season"=>$t["season"],
                  "marketing_duration"=>$t["marketing_duration"],"madinah_nights"=>(int)$t["madinah_nights"],"makkah_nights"=>(int)$t["makkah_nights"],
                  "rooming_note"=>$t["rooming_note"],"meta_title"=>$t["meta_title"],"meta_description"=>$t["meta_description"],
                  "status"=>(int)$t["status"],"inclusions"=>implode(", ", json_decode((string)$t["inclusions"],true)?:[]),
                  "itinerary_order"=>implode(", ", json_decode((string)$t["itinerary_order"],true)?:[])
                ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                <button class="btn btn-sm outline text-rose-600" @click="archive('templates',<?= (int)$t['id'] ?>)">Archive</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div></div>
  </div>

  <!-- ============================ TIERS ============================ -->
  <div x-show="tab==='tiers'" x-cloak>
    <div class="flex justify-between items-center mb-4">
      <h2 class="font-semibold text-slate-900">Comfort tiers</h2>
      <button class="btn" @click="newTier()"><span class="material-symbols-outlined">add</span><span>New tier</span></button>
    </div>
    <div class="section"><div class="table-container"><table class="table">
      <thead><tr><th>Order</th><th>Label</th><th>Code</th><th>Room</th><th>Min same-gender</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($tiers)): ?><tr><td colspan="7" class="text-slate-500">No tiers yet.</td></tr>
        <?php else: foreach ($tiers as $t): $arch=!empty($t['archived']); ?>
          <tr class="<?= $arch?'opacity-60':'' ?>">
            <td><?= (int)$t['sort_order'] ?></td>
            <td class="font-medium"><?= htmlspecialchars($t['public_label'] ?: $t['name']) ?></td>
            <td class="text-xs font-mono"><?= htmlspecialchars($t['code']) ?></td>
            <td class="text-sm"><?= htmlspecialchars($t['room_sharing'] ?? '') ?></td>
            <td><?= (int)$t['min_group_same_gender'] ?></td>
            <td><?php if($arch): ?><span class="badge badge-gray">archived</span><?php else: ?><span class="badge <?= $t['status']?'badge-success':'badge-gray' ?>"><?= $t['status']?'active':'inactive' ?></span><?php endif; ?></td>
            <td class="whitespace-nowrap">
              <?php if($arch): ?><button class="btn btn-sm" @click="restore('tiers',<?= (int)$t['id'] ?>)">Restore</button>
              <?php else: ?>
                <button class="btn btn-sm outline" @click='editTier(<?= json_encode([
                  "id"=>(int)$t["id"],"code"=>$t["code"],"name"=>$t["name"],"public_label"=>$t["public_label"],
                  "sort_order"=>(int)$t["sort_order"],"default_occupancy"=>(int)$t["default_occupancy"],"room_sharing"=>$t["room_sharing"],
                  "min_group_same_gender"=>(int)$t["min_group_same_gender"],"bookable"=>(int)$t["bookable"],"status"=>(int)$t["status"]
                ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                <button class="btn btn-sm outline text-rose-600" @click="archive('tiers',<?= (int)$t['id'] ?>)">Archive</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div></div>
  </div>

  <!-- ============================ PAYMENT PLANS ============================ -->
  <div x-show="tab==='plans'" x-cloak>
    <div class="flex justify-between items-center mb-4">
      <h2 class="font-semibold text-slate-900">Payment plans</h2>
      <button class="btn" @click="newPlan()"><span class="material-symbols-outlined">add</span><span>New plan</span></button>
    </div>
    <div class="section"><div class="table-container"><table class="table">
      <thead><tr><th>Name</th><th>Code</th><th>Split</th><th>Grace (h)</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($plans)): ?><tr><td colspan="6" class="text-slate-500">No payment plans yet.</td></tr>
        <?php else: foreach ($plans as $p): $arch=!empty($p['archived']); $g=fn($n)=>rtrim(rtrim(number_format((float)$n,2),'0'),'.'); ?>
          <tr class="<?= $arch?'opacity-60':'' ?>">
            <td class="font-medium"><?= htmlspecialchars($p['name']) ?></td>
            <td class="text-xs font-mono"><?= htmlspecialchars($p['code']) ?></td>
            <td class="text-sm"><?= $g($p['deposit_percent']) ?>% / <?= $g($p['second_percent']) ?>% / <?= $g($p['final_percent']) ?>%</td>
            <td><?= (int)$p['grace_hours'] ?></td>
            <td><?php if($arch): ?><span class="badge badge-gray">archived</span><?php else: ?><span class="badge <?= $p['active']?'badge-success':'badge-gray' ?>"><?= $p['active']?'active':'inactive' ?></span><?php endif; ?></td>
            <td class="whitespace-nowrap">
              <?php if($arch): ?><button class="btn btn-sm" @click="restore('plans',<?= (int)$p['id'] ?>)">Restore</button>
              <?php else: ?>
                <button class="btn btn-sm outline" @click='editPlan(<?= json_encode([
                  "id"=>(int)$p["id"],"code"=>$p["code"],"name"=>$p["name"],"deposit_percent"=>(float)$p["deposit_percent"],
                  "second_percent"=>(float)$p["second_percent"],"final_percent"=>(float)$p["final_percent"],
                  "second_due_days_before"=>$p["second_due_days_before"],"final_due_days_before"=>$p["final_due_days_before"],
                  "grace_hours"=>(int)$p["grace_hours"],"price_lock_on_cleared_deposit"=>(int)$p["price_lock_on_cleared_deposit"],"active"=>(int)$p["active"]
                ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                <button class="btn btn-sm outline text-rose-600" @click="archive('plans',<?= (int)$p['id'] ?>)">Archive</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div></div>
  </div>

  <!-- ==================== EDIT MODAL (shared) ==================== -->
  <div x-show="edit.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,.5)" @click.self="edit.open=false">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto p-5">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-slate-900" x-text="edit.title"></h2>
        <button class="text-slate-400 hover:text-slate-700" @click="edit.open=false"><span class="material-symbols-outlined">close</span></button>
      </div>

      <!-- Package form -->
      <template x-if="edit.type==='templates'">
        <div class="form-grid">
          <div class="form-control"><label>Name *</label><input class="input" x-model="f.name"></div>
          <div class="form-control"><label>Slug</label><input class="input" x-model="f.slug" placeholder="auto from name"></div>
          <div class="form-control"><label>Code</label><input class="input" x-model="f.code" placeholder="auto"></div>
          <div class="form-control"><label>Season</label><input class="input" x-model="f.season" placeholder="normal"></div>
          <div class="form-control"><label>Madinah nights</label><input type="number" class="input" x-model.number="f.madinah_nights"></div>
          <div class="form-control"><label>Makkah nights</label><input type="number" class="input" x-model.number="f.makkah_nights"></div>
          <div class="form-control"><label>Marketing duration</label><input class="input" x-model="f.marketing_duration" placeholder="14-Day"></div>
          <div class="form-control sm:col-span-2"><label>Inclusions (comma-separated codes)</label><input class="input" x-model="f.inclusions"></div>
          <div class="form-control sm:col-span-2"><label>Itinerary order (comma-separated)</label><input class="input" x-model="f.itinerary_order"></div>
          <div class="form-control sm:col-span-2"><label>Rooming note</label><input class="input" x-model="f.rooming_note"></div>
          <div class="form-control sm:col-span-2"><label>Meta title</label><input class="input" x-model="f.meta_title"></div>
          <div class="form-control sm:col-span-2"><label>Meta description</label><textarea class="input" x-model="f.meta_description"></textarea></div>
          <div class="form-control"><label class="flex items-center gap-2"><input type="checkbox" x-model="f.status"> Active</label></div>
        </div>
      </template>

      <!-- Tier form -->
      <template x-if="edit.type==='tiers'">
        <div class="form-grid">
          <div class="form-control"><label>Name *</label><input class="input" x-model="f.name"></div>
          <div class="form-control"><label>Public label</label><input class="input" x-model="f.public_label"></div>
          <div class="form-control"><label>Code</label><input class="input" x-model="f.code" placeholder="auto"></div>
          <div class="form-control"><label>Sort order</label><input type="number" class="input" x-model.number="f.sort_order"></div>
          <div class="form-control"><label>Room sharing</label><input class="input" x-model="f.room_sharing" placeholder="4-5 sharing"></div>
          <div class="form-control"><label>Default occupancy</label><input type="number" class="input" x-model.number="f.default_occupancy"></div>
          <div class="form-control"><label>Min same-gender (group)</label><input type="number" class="input" x-model.number="f.min_group_same_gender"></div>
          <div class="form-control"><label class="flex items-center gap-2"><input type="checkbox" x-model="f.bookable"> Bookable</label></div>
          <div class="form-control"><label class="flex items-center gap-2"><input type="checkbox" x-model="f.status"> Active</label></div>
        </div>
      </template>

      <!-- Plan form -->
      <template x-if="edit.type==='plans'">
        <div class="form-grid">
          <div class="form-control"><label>Name *</label><input class="input" x-model="f.name"></div>
          <div class="form-control"><label>Code</label><input class="input" x-model="f.code" placeholder="auto"></div>
          <div class="form-control"><label>Deposit %</label><input type="number" step="0.01" class="input" x-model.number="f.deposit_percent"></div>
          <div class="form-control"><label>Second %</label><input type="number" step="0.01" class="input" x-model.number="f.second_percent"></div>
          <div class="form-control"><label>Final %</label><input type="number" step="0.01" class="input" x-model.number="f.final_percent"></div>
          <div class="form-control"><label>Second due (days before)</label><input type="number" class="input" x-model.number="f.second_due_days_before"></div>
          <div class="form-control"><label>Final due (days before)</label><input type="number" class="input" x-model.number="f.final_due_days_before"></div>
          <div class="form-control"><label>Grace hours</label><input type="number" class="input" x-model.number="f.grace_hours"></div>
          <div class="form-control"><label class="flex items-center gap-2"><input type="checkbox" x-model="f.price_lock_on_cleared_deposit"> Lock price on cleared deposit</label></div>
          <div class="form-control"><label class="flex items-center gap-2"><input type="checkbox" x-model="f.active"> Active</label></div>
          <p class="sm:col-span-2 text-[11px] text-slate-400">Deposit + second + final must total 100%.</p>
        </div>
      </template>

      <!-- Departure edit form -->
      <template x-if="edit.type==='departures'">
        <div class="form-grid">
          <div class="form-control"><label>Departure date *</label><input type="date" class="input" x-model="f.departure_date"></div>
          <div class="form-control"><label>Return date</label><input type="date" class="input" x-model="f.return_date"></div>
          <div class="form-control"><label>Origin city</label><input class="input" x-model="f.origin_city"></div>
          <div class="form-control"><label>Capacity</label><input type="number" class="input" x-model.number="f.capacity"></div>
          <div class="form-control"><label>Low-stock threshold</label><input type="number" class="input" x-model.number="f.low_stock_threshold"></div>
          <div class="form-control"><label class="flex items-center gap-2"><input type="checkbox" x-model="f.display_inventory_count"> Show remaining seats publicly</label></div>
        </div>
      </template>

      <p class="text-xs mt-3" :class="edit.ok?'text-green-600':'text-rose-600'" x-text="edit.msg"></p>
      <div class="mt-4 flex justify-end gap-2">
        <button class="btn outline" @click="edit.open=false">Cancel</button>
        <button class="btn" :disabled="edit.busy" @click="saveEdit()" x-text="edit.busy?'Saving…':'Save'"></button>
      </div>
    </div>
  </div>

  <!-- ==================== IMAGE MANAGER MODAL ==================== -->
  <div x-show="img.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,.5)" @click.self="closeImages()">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto p-5">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-slate-900">Departure images</h2>
        <button class="text-slate-400 hover:text-slate-700" @click="closeImages()"><span class="material-symbols-outlined">close</span></button>
      </div>
      <div class="mb-5">
        <label class="block text-sm font-medium text-slate-700 mb-2">Hero image <span class="text-slate-400 font-normal">(main card + detail banner)</span></label>
        <div class="flex items-start gap-3">
          <div class="w-40 h-24 rounded-lg bg-slate-100 overflow-hidden shrink-0 flex items-center justify-center">
            <template x-if="img.hero"><img :src="img.hero" class="w-full h-full object-cover"></template>
            <template x-if="!img.hero"><span class="material-symbols-outlined text-slate-300 text-3xl">mosque</span></template>
          </div>
          <div class="flex-1">
            <label class="btn btn-sm cursor-pointer">
              <span class="material-symbols-outlined text-[18px]">upload</span>
              <span x-text="img.busy==='hero' ? 'Uploading…' : 'Upload hero'"></span>
              <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp" @change="uploadImage('hero',$event)">
            </label>
            <button class="btn btn-sm outline ml-2" x-show="img.hero" @click="deleteImage('hero', img.hero)">Remove</button>
            <p class="text-[11px] text-slate-400 mt-1">JPG/PNG/WebP, up to 6MB. Auto-converted to PNG.</p>
          </div>
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-2">Gallery</label>
        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 mb-3">
          <template x-for="g in img.gallery" :key="g">
            <div class="relative group">
              <img :src="g" class="w-full h-20 object-cover rounded-lg border">
              <button class="absolute top-1 right-1 bg-white/90 rounded-full w-6 h-6 flex items-center justify-center text-rose-600 shadow" @click="deleteImage('gallery', g)"><span class="material-symbols-outlined text-[16px]">delete</span></button>
            </div>
          </template>
          <template x-if="!img.gallery.length"><div class="col-span-full text-sm text-slate-400 py-3">No gallery images yet.</div></template>
        </div>
        <label class="btn btn-sm outline cursor-pointer">
          <span class="material-symbols-outlined text-[18px]">add_photo_alternate</span>
          <span x-text="img.busy==='gallery' ? 'Uploading…' : 'Add gallery image'"></span>
          <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp" @change="uploadImage('gallery',$event)">
        </label>
      </div>
      <p class="text-xs mt-3" :class="img.msgOk ? 'text-green-600' : 'text-rose-600'" x-text="img.msg"></p>
      <div class="mt-4 text-right"><button class="btn outline" @click="closeImages()">Done</button></div>
    </div>
  </div>
</div>

<script>
function umrahMgr() {
  return {
    csrf: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>', base: '<?= $base ?>',
    busy:false, msg:'', showCreate:false, showBulk:false,
    tab:'departures',
    tabs:[{key:'departures',label:'Departures'},{key:'packages',label:'Packages'},{key:'tiers',label:'Tiers'},{key:'plans',label:'Payment plans'}],
    c:{ template_id:'<?= $templatesActive[0]['id']??'' ?>', tier_id:'<?= $stdTierId ?>', departure_date:'', return_date:'', capacity:50, regular_price:2800000, promo_price:2490000 },
    bk:{ template_id:'<?= $templatesActive[0]['id']??'' ?>', months:'', capacity:50, regular_price:2800000, promo_price:2490000 },
    form(o){ const f=new FormData(); f.append('csrf_token',this.csrf); for(const k in o){ let v=o[k]; if(typeof v==='boolean') v=v?1:0; if(v===null||v===undefined) v=''; f.append(k,v);} return f; },
    async post(url,o){ const r=await fetch(url,{method:'POST',body:this.form(o)}); return r.json(); },
    async create(){ this.busy=true; this.msg='Creating…'; const j=await this.post(this.base+'/departures/create',this.c); this.busy=false; this.msg=j.message||''; if(j.success) location.reload(); },
    async bulk(){ this.busy=true; this.msg='Generating…'; const j=await this.post(this.base+'/departures/bulk',this.bk); this.busy=false; this.msg=(j.created||0)+' created'; if(j.success) setTimeout(()=>location.reload(),700); },
    async setStatus(id,s){ if(s==='closed'&&!confirm('Close bookings for this departure?'))return; const j=await this.post(this.base+'/departures/status',{departure_id:id,status:s}); if(j.success) location.reload(); else alert(j.message); },
    async clone(id){ const dt=prompt('New departure date (YYYY-MM-DD):'); if(!dt)return; const j=await this.post(this.base+'/departures/clone',{departure_id:id,departure_date:dt}); if(j.success) location.reload(); else alert(j.message); },

    // ---- Archive / restore (generic) ----
    async archive(kind,id){ if(!confirm('Archive this record? It will be hidden from the site but not deleted.'))return; const j=await this.post(this.base+'/'+kind+'/archive',{id:id,archive:1}); if(j.success) location.reload(); else alert(j.message||'Failed'); },
    async restore(kind,id){ const j=await this.post(this.base+'/'+kind+'/archive',{id:id,archive:0}); if(j.success) location.reload(); else alert(j.message||'Failed'); },

    // ---- Edit modal (shared across entity types) ----
    edit:{ open:false, type:'', title:'', busy:false, ok:false, msg:'' },
    f:{},
    openEdit(type,title,data){ this.edit={open:true,type:type,title:title,busy:false,ok:false,msg:''}; this.f=Object.assign({},data); },
    // Templates
    newTemplate(){ this.openEdit('templates','New package',{id:0,name:'',slug:'',code:'',season:'normal',marketing_duration:'',madinah_nights:4,makkah_nights:10,inclusions:'',itinerary_order:'',rooming_note:'',meta_title:'',meta_description:'',status:true}); },
    editTemplate(d){ d.status=!!d.status; this.openEdit('templates','Edit package',d); },
    // Tiers
    newTier(){ this.openEdit('tiers','New tier',{id:0,name:'',public_label:'',code:'',sort_order:0,default_occupancy:1,room_sharing:'',min_group_same_gender:0,bookable:true,status:true}); },
    editTier(d){ d.bookable=!!d.bookable; d.status=!!d.status; this.openEdit('tiers','Edit tier',d); },
    // Plans
    newPlan(){ this.openEdit('plans','New payment plan',{id:0,name:'',code:'',deposit_percent:100,second_percent:0,final_percent:0,second_due_days_before:45,final_due_days_before:21,grace_hours:72,price_lock_on_cleared_deposit:true,active:true}); },
    editPlan(d){ d.price_lock_on_cleared_deposit=!!d.price_lock_on_cleared_deposit; d.active=!!d.active; this.openEdit('plans','Edit payment plan',d); },
    // Departures
    editDeparture(d){ d.display_inventory_count=!!d.display_inventory_count; this.openEdit('departures','Edit departure',d); },
    async saveEdit(){
      this.edit.busy=true; this.edit.msg='';
      const map={templates:'/templates/save',tiers:'/tiers/save',plans:'/plans/save',departures:'/departures/update'};
      const j=await this.post(this.base+map[this.edit.type], this.f);
      this.edit.busy=false;
      if(j.success){ this.edit.ok=true; this.edit.msg='Saved'; setTimeout(()=>location.reload(),500); }
      else { this.edit.ok=false; this.edit.msg=j.message||'Save failed'; }
    },

    // ---- Per-departure image manager ----
    img:{ open:false, depId:0, hero:'', gallery:[], busy:'', msg:'', msgOk:false },
    openImages(id, hero, gallery){ this.img={ open:true, depId:id, hero:hero||'', gallery:Array.isArray(gallery)?gallery:[], busy:'', msg:'', msgOk:false }; },
    closeImages(){ this.img.open=false; },
    async uploadImage(slot, ev){
      const file = ev.target.files && ev.target.files[0]; if(!file){ return; }
      this.img.busy=slot; this.img.msg='';
      const f=new FormData(); f.append('csrf_token',this.csrf); f.append('departure_id',this.img.depId); f.append('slot',slot); f.append('image',file);
      try{ const r=await fetch(this.base+'/departures/images',{method:'POST',body:f}); const j=await r.json();
        if(j.success){ if(slot==='hero'){ this.img.hero=j.url; } else { this.img.gallery.push(j.url); } this.img.msg='Uploaded'; this.img.msgOk=true; }
        else { this.img.msg=j.message||'Upload failed'; this.img.msgOk=false; }
      }catch(e){ this.img.msg='Network error'; this.img.msgOk=false; }
      this.img.busy=''; ev.target.value='';
    },
    async deleteImage(slot, url){
      if(!confirm('Remove this image?')) return;
      const j=await this.post(this.base+'/departures/images/delete',{departure_id:this.img.depId,slot:slot,url:url});
      if(j.success){ if(slot==='hero'){ this.img.hero=''; } else { this.img.gallery=this.img.gallery.filter(g=>g!==url); } this.img.msg='Removed'; this.img.msgOk=true; }
      else { this.img.msg=j.message||'Delete failed'; this.img.msgOk=false; }
    }
  };
}
</script>
