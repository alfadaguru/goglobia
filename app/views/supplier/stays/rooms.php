<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier room/option manager (inc S10). Provided by the route:
//   $stay, $rooms (each with ['_options'] = stable-id options), $taxonomy,
//   $canEdit, $canAddRoom, $canDeleteRoom, + $buildings/$floorsByBuilding/$physicalRooms.
$stay          = $stay ?? [];
$rooms         = $rooms ?? [];
$taxonomy      = $taxonomy ?? ['room_type' => [], 'board' => []];
$roomTypes     = $taxonomy['room_type'] ?? [];
$boards        = $taxonomy['board'] ?? [];
$canEdit       = !empty($canEdit);
$canAddRoom    = !empty($canAddRoom);
$canDeleteRoom = !empty($canDeleteRoom);
$stayId        = (int) ($stay['id'] ?? 0);
$currency      = htmlspecialchars((string) ($stay['currency'] ?? ''));
$base          = root . 'supplier/stays/' . $stayId . '/rooms';

$roomImgMaxH   = function_exists('supplier_effective_img_max') ? supplier_human_bytes(supplier_effective_img_max()) : '5 MB';
$roomImgCount  = defined('SUPPLIER_IMG_MAX_COUNT') ? SUPPLIER_IMG_MAX_COUNT : 10;

// Summary counts for the header.
$roomCount = count($rooms);
$sellableCount = 0; $rateCount = 0;
foreach ($rooms as $r) {
  $os = is_array($r['_options'] ?? null) ? $r['_options'] : [];
  $rateCount += count($os);
  foreach ($os as $o) { if ((int) ($o['status'] ?? 0) === 1 && (float) ($o['price'] ?? 0) > 0) { $sellableCount++; break; } }
}
?>

<div class="max-w-5xl mx-auto px-4 py-8 space-y-6"
     x-data="{ roomForm:false, editRoom:null, optionFor:null, editOption:null }">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <!-- Header -->
  <div>
    <nav class="text-xs text-gray-500 mb-1">
      <a href="<?= root ?>supplier/stays" class="hover:underline">My hotels</a>
      <span class="mx-1">/</span>
      <a href="<?= root ?>supplier/stays/<?= $stayId ?>" class="hover:underline"><?= htmlspecialchars($stay['name'] ?? '') ?></a>
      <span class="mx-1">/</span><span class="text-gray-700">Rooms &amp; rates</span>
    </nav>
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Rooms &amp; rates</h1>
        <p class="text-sm text-gray-600">
          <?php if ($roomCount === 0): ?>
            Start by adding a room, then give it a priced rate so it can sell.
          <?php else: ?>
            <span class="tabular-nums"><?= $roomCount ?></span> room type<?= $roomCount === 1 ? '' : 's' ?> ·
            <span class="tabular-nums"><?= $rateCount ?></span> rate<?= $rateCount === 1 ? '' : 's' ?> ·
            <span class="<?= $sellableCount > 0 ? 'text-green-700' : 'text-amber-700' ?>"><span class="tabular-nums"><?= $sellableCount ?></span> sellable</span>
          <?php endif; ?>
        </p>
      </div>
      <?php if ($canAddRoom): ?>
        <button type="button" class="btn emerald flex-shrink-0" @click="roomForm = true; editRoom = null">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">add</span> Add room</span>
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Rooms list -->
  <?php if (empty($rooms)): ?>
    <div class="card p-10 text-center">
      <span class="material-symbols-outlined text-5xl text-gray-300">bed</span>
      <p class="mt-3 text-sm font-medium text-gray-700">No rooms yet</p>
      <p class="mt-1 text-xs text-gray-500 max-w-sm mx-auto">Add your first room type (e.g. “Deluxe Double”), then give it a rate with a price so guests can book it.</p>
      <?php if ($canAddRoom): ?>
        <button type="button" class="btn emerald mt-4" @click="roomForm = true; editRoom = null">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">add</span> Add your first room</span>
        </button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="space-y-4">
    <?php foreach ($rooms as $room): ?>
      <?php
        $rid = (int) $room['id'];
        $categoryName = $roomTypes[(int) ($room['room_type_id'] ?? 0)] ?? ('Room #' . $rid);
        $displayName = trim((string) ($room['room_name'] ?? ''));
        $rtName = $displayName !== '' ? $displayName : $categoryName;
        $opts = is_array($room['_options'] ?? null) ? $room['_options'] : [];
        $active = (int) ($room['status'] ?? 0) === 1;
        $imgs = json_decode((string) ($room['room_images'] ?? ''), true);
        $thumb = (is_array($imgs) && !empty($imgs)) ? (string) ($imgs[0]['thumb'] ?? ($imgs[0]['url'] ?? '')) : '';
      ?>
      <div class="card overflow-hidden">
        <!-- Room header row -->
        <div class="flex items-center gap-4 p-4 sm:p-5 border-b border-gray-100">
          <?php if ($thumb !== ''): ?>
            <img src="<?= htmlspecialchars(root . ltrim($thumb, '/')) ?>" alt="" class="w-14 h-14 rounded-lg object-cover flex-none bg-gray-100">
          <?php else: ?>
            <span class="w-14 h-14 rounded-lg bg-gray-100 grid place-items-center flex-none"><span class="material-symbols-outlined text-gray-300">bed</span></span>
          <?php endif; ?>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <h3 class="text-base font-semibold text-gray-900 truncate"><?= htmlspecialchars($rtName) ?></h3>
              <span class="px-2 py-0.5 rounded text-xs font-medium <?= $active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $active ? 'Active' : 'Inactive' ?></span>
            </div>
            <p class="text-xs text-gray-500 mt-0.5">
              <?= htmlspecialchars($categoryName) ?> · <?= count($opts) ?> rate<?= count($opts) === 1 ? '' : 's' ?>
            </p>
          </div>
          <div class="flex items-center gap-3 text-xs font-medium flex-none">
            <?php if ($canEdit): ?>
              <button type="button" class="text-blue-600 hover:underline"
                      @click='editRoom = <?= htmlspecialchars(json_encode([
                        "id" => $rid,
                        "room_type_id" => (int) ($room["room_type_id"] ?? 0),
                        "room_name" => (string) ($room["room_name"] ?? ""),
                        "status" => (int) ($room["status"] ?? 0),
                        "description" => (string) ($room["description"] ?? ""),
                        "size_sqm" => ($room["size_sqm"] ?? "") !== "" ? (float) $room["size_sqm"] : "",
                        "view_type" => (string) ($room["view_type"] ?? "none") ?: "none",
                        "beds" => (function ($j) { $d = json_decode((string) $j, true); return (is_array($d) && isset($d["beds"]) && is_array($d["beds"])) ? $d["beds"] : []; })($room["bed_config"] ?? null),
                      ]), ENT_QUOTES) ?>; roomForm = true'>Edit</button>
            <?php endif; ?>
            <?php if ($canDeleteRoom): ?>
              <form action="<?= $base ?>/delete" method="POST" class="inline"
                    onsubmit="return confirm('Delete this room and all its rates/availability? This cannot be undone.');">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="room_id" value="<?= $rid ?>">
                <button type="submit" class="text-red-600 hover:underline">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <!-- Rate options -->
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-gray-400 text-xs uppercase tracking-wide">
                <th class="px-4 sm:px-5 py-2 font-medium">Rate</th>
                <th class="px-3 py-2 font-medium">Sleeps</th>
                <th class="px-3 py-2 font-medium">Price / night</th>
                <th class="px-3 py-2 font-medium">Qty</th>
                <th class="px-3 py-2 font-medium">Board</th>
                <th class="px-3 py-2 font-medium">On</th>
                <th class="px-3 py-2 font-medium text-right">&nbsp;</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <?php if (empty($opts)): ?>
                <tr><td colspan="7" class="px-5 py-5 text-center">
                  <span class="text-xs text-amber-700">No rate yet — this room can't be booked until you add a priced rate.</span>
                </td></tr>
              <?php else: foreach ($opts as $o): ?>
                <?php
                  $oid = (int) ($o['option_id'] ?? 0);
                  $priced = (float) ($o['price'] ?? 0) > 0;
                  $on = (int) ($o['status'] ?? 0) === 1;
                ?>
                <tr>
                  <td class="px-4 sm:px-5 py-2.5 text-gray-400 tabular-nums">#<?= $oid ?></td>
                  <td class="px-3 py-2.5 text-gray-700"><?= (int) ($o['max_adults'] ?? 0) ?><span class="text-gray-400">a</span><?= (int) ($o['max_children'] ?? 0) > 0 ? ' + ' . (int) $o['max_children'] . '<span class="text-gray-400">c</span>' : '' ?></td>
                  <td class="px-3 py-2.5 font-semibold text-gray-900 tabular-nums"><?= $currency ?> <?= number_format((float) ($o['price'] ?? 0), 2) ?></td>
                  <td class="px-3 py-2.5 text-gray-700 tabular-nums"><?= (int) ($o['available_quantity'] ?? 0) ?></td>
                  <td class="px-3 py-2.5 text-gray-600"><?= htmlspecialchars($boards[(int) ($o['board_id'] ?? 0)] ?? '—') ?></td>
                  <td class="px-3 py-2.5">
                    <?php if ($on && $priced): ?>
                      <span class="inline-flex items-center gap-1 text-green-700"><span class="material-symbols-outlined text-sm">check_circle</span></span>
                    <?php else: ?>
                      <span class="inline-flex items-center gap-1 text-gray-400" title="<?= $on ? 'No price set' : 'Rate switched off' ?>"><span class="material-symbols-outlined text-sm">do_not_disturb_on</span></span>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-2.5 text-right">
                    <div class="flex items-center justify-end gap-3 text-xs font-medium">
                      <?php if ($canEdit): ?>
                        <button type="button" class="text-blue-600 hover:underline"
                                @click='optionFor = <?= $rid ?>; editOption = <?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>'>Edit</button>
                        <a href="<?= $base ?>/<?= $rid ?>/calendar" class="text-violet-600 hover:underline">Calendar</a>
                        <form action="<?= $base ?>/<?= $rid ?>/options/delete" method="POST" class="inline"
                              onsubmit="return confirm('Delete this rate?');">
                          <?= CSRF::tokenField() ?>
                          <input type="hidden" name="option_id" value="<?= $oid ?>">
                          <button type="submit" class="text-gray-400 hover:text-red-600">Delete</button>
                        </form>
                      <?php else: ?>
                        <a href="<?= $base ?>/<?= $rid ?>/calendar" class="text-violet-600 hover:underline">Calendar</a>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($canEdit): ?>
          <div class="px-4 sm:px-5 py-3 bg-gray-50 border-t border-gray-100">
            <button type="button" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium inline-flex items-center gap-1"
                    @click="optionFor = <?= $rid ?>; editOption = null"><span class="material-symbols-outlined text-sm">add</span> Add rate</button>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Physical rooms — collapsible (advanced; most suppliers set rates first) -->
  <?php
    $buildings       = (isset($buildings) && is_array($buildings)) ? $buildings : [];
    $floorsByBuilding = (isset($floorsByBuilding) && is_array($floorsByBuilding)) ? $floorsByBuilding : [];
    $physicalRooms   = (isset($physicalRooms) && is_array($physicalRooms)) ? $physicalRooms : [];
    $buildingName = [];
    foreach ($buildings as $b) { $buildingName[(int) $b['id']] = (string) $b['name']; }
    $floorName = [];
    foreach ($floorsByBuilding as $bid => $fls) { foreach ($fls as $fl) { $floorName[(int) $fl['id']] = (string) $fl['name']; } }
    $floorsJs = [];
    foreach ($floorsByBuilding as $bid => $fls) {
      $floorsJs[(string) $bid] = array_map(function ($f) { return ['id' => (int) $f['id'], 'name' => (string) $f['name']]; }, $fls);
    }
    $physCount = count($physicalRooms);
  ?>
  <?php if ($canAddRoom && !empty($rooms)): ?>
  <details class="card" <?= $physCount > 0 ? 'open' : '' ?>>
    <summary class="flex items-center justify-between gap-3 p-4 sm:p-5 cursor-pointer select-none list-none">
      <div class="flex items-center gap-3">
        <span class="w-9 h-9 rounded-lg bg-violet-50 grid place-items-center flex-none"><span class="material-symbols-outlined text-violet-600">meeting_room</span></span>
        <div>
          <h2 class="text-base font-semibold text-gray-900">Physical rooms <span class="text-xs font-normal text-gray-400">(optional)</span></h2>
          <p class="text-xs text-gray-500"><?= $physCount > 0 ? ($physCount . ' room(s) numbered') : 'Number individual units for front desk &amp; housekeeping' ?></p>
        </div>
      </div>
      <span class="material-symbols-outlined text-gray-400 transition-transform phys-chevron">expand_more</span>
    </summary>

    <div class="px-4 sm:px-5 pb-5 space-y-5 border-t border-gray-100 pt-4"
         x-data='physRooms(<?= htmlspecialchars(json_encode($floorsJs), ENT_QUOTES) ?>)'>
      <div class="flex items-center justify-end gap-3 text-xs">
        <a href="<?= root ?>supplier/stays/<?= $stayId ?>/buildings" class="text-violet-600 hover:underline inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">domain</span> Buildings &amp; floors</a>
        <a href="<?= root ?>supplier/housekeeping?stay_id=<?= $stayId ?>" class="text-violet-600 hover:underline inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">cleaning_services</span> Housekeeping</a>
      </div>

      <?php if (!empty($physicalRooms)): ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-400 text-xs uppercase tracking-wide">
              <th class="px-3 py-2 font-medium">Room</th><th class="px-3 py-2 font-medium">Type</th>
              <th class="px-3 py-2 font-medium">Building</th><th class="px-3 py-2 font-medium">Floor</th>
              <th class="px-3 py-2 font-medium">Wing</th><th class="px-3 py-2 font-medium">Status</th>
              <th class="px-3 py-2 font-medium text-right">&nbsp;</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
              <?php foreach ($physicalRooms as $pr):
                $prBid = (int) ($pr['building_id'] ?? 0); $prFid = (int) ($pr['floor_id'] ?? 0);
                $prType = $roomTypes[(int) ($pr['room_id'] ?? 0)] ?? ('Room #' . (int) ($pr['room_id'] ?? 0));
                $floorLabel = $prFid > 0 ? ($floorName[$prFid] ?? '') : (string) ($pr['floor'] ?? '');
              ?>
                <tr>
                  <td class="px-3 py-2 font-medium text-gray-900"><?= htmlspecialchars((string) $pr['room_number']) ?></td>
                  <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($prType) ?></td>
                  <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($prBid > 0 ? ($buildingName[$prBid] ?? '—') : '—') ?></td>
                  <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($floorLabel !== '' ? $floorLabel : '—') ?></td>
                  <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars((string) ($pr['wing'] ?? '') ?: '—') ?></td>
                  <td class="px-3 py-2 text-gray-500"><?= htmlspecialchars(ucfirst((string) ($pr['hk_status'] ?? ''))) ?></td>
                  <td class="px-3 py-2 text-right">
                    <div class="flex items-center justify-end gap-3 text-xs font-medium">
                      <button type="button" class="text-blue-600 hover:underline"
                              @click='editPhys(<?= htmlspecialchars(json_encode([
                                "id" => (int) $pr["id"], "room_id" => (int) ($pr["room_id"] ?? 0),
                                "room_number" => (string) $pr["room_number"], "building_id" => $prBid,
                                "floor_id" => $prFid, "wing" => (string) ($pr["wing"] ?? ""),
                              ]), ENT_QUOTES) ?>)'>Edit</button>
                      <form action="<?= root ?>supplier/housekeeping/room/delete" method="POST" class="inline" onsubmit="return confirm('Remove this room?');">
                        <?= CSRF::tokenField() ?>
                        <input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="phys_id" value="<?= (int) $pr['id'] ?>">
                        <button type="submit" class="text-gray-400 hover:text-red-600">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-xs text-gray-400">No physical rooms yet. Add one below, or bulk-add a range.</p>
      <?php endif; ?>

      <!-- Single add / edit -->
      <form x-ref="physForm"
            :action="editId ? '<?= root ?>supplier/housekeeping/room/update' : '<?= root ?>supplier/housekeeping/add'"
            method="POST"
            class="grid grid-cols-2 md:grid-cols-6 gap-2 items-end rounded-lg p-3 transition-colors"
            :class="editId ? 'bg-violet-50 ring-1 ring-violet-200' : ''">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="stay_id" value="<?= $stayId ?>">
        <input type="hidden" name="phys_id" :value="editId || ''">
        <div class="col-span-2 md:col-span-6" x-show="editId" x-cloak>
          <p class="text-sm font-semibold text-violet-800">Editing room <span x-text="f.room_number"></span></p>
        </div>
        <div class="form-control col-span-2 md:col-span-1"><label class="block text-xs text-gray-600 mb-1" x-text="editId ? 'Editing room' : 'Room type'"></label>
          <select name="room_id" class="input text-sm" x-model="f.room_id">
            <?php foreach ($rooms as $room): ?>
              <option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars(trim((string) ($room['room_name'] ?? '')) ?: ($roomTypes[(int) ($room['room_type_id'] ?? 0)] ?? ('Room #' . (int) $room['id']))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Room name/no.</label>
          <input type="text" name="room_number" x-model="f.room_number" class="input text-sm" placeholder="e.g. A-101" required></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Building</label>
          <select name="building_id" class="input text-sm" x-model="f.building_id">
            <option value="0">—</option>
            <?php foreach ($buildings as $b): ?><option value="<?= (int) $b['id'] ?>"><?= htmlspecialchars((string) $b['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Floor</label>
          <select name="floor_id" class="input text-sm" x-model="f.floor_id">
            <option value="0">—</option>
            <template x-for="fl in floorsFor(f.building_id)" :key="fl.id">
              <option :value="fl.id" x-text="fl.name" :selected="String(fl.id) === String(f.floor_id)"></option>
            </template>
          </select>
        </div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Wing</label>
          <input type="text" name="wing" x-model="f.wing" class="input text-sm" placeholder="opt."></div>
        <div class="flex items-center gap-2 col-span-2 md:col-span-1">
          <button type="submit" class="btn secondary text-sm" x-text="editId ? 'Save' : 'Add room'"></button>
          <button type="button" class="text-xs text-gray-500 hover:underline" x-show="editId" @click="resetForm()">Cancel</button>
        </div>
      </form>

      <!-- Bulk add by range -->
      <form action="<?= root ?>supplier/housekeeping/bulk-add" method="POST" class="grid grid-cols-2 md:grid-cols-6 gap-2 items-end border-t border-gray-100 pt-4">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="stay_id" value="<?= $stayId ?>">
        <div class="form-control col-span-2 md:col-span-6"><p class="text-xs font-medium text-gray-700">Bulk add a numbered range</p></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Room type</label>
          <select name="room_id" class="input text-sm">
            <?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars(trim((string) ($room['room_name'] ?? '')) ?: ($roomTypes[(int) ($room['room_type_id'] ?? 0)] ?? ('Room #' . (int) $room['id']))) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Building</label>
          <select name="building_id" class="input text-sm" x-model="bulkBuilding">
            <option value="0">—</option>
            <?php foreach ($buildings as $b): ?><option value="<?= (int) $b['id'] ?>"><?= htmlspecialchars((string) $b['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Floor</label>
          <select name="floor_id" class="input text-sm">
            <option value="0">—</option>
            <template x-for="fl in floorsFor(bulkBuilding)" :key="fl.id"><option :value="fl.id" x-text="fl.name"></option></template>
          </select>
        </div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Prefix</label>
          <input type="text" name="prefix" class="input text-sm" placeholder="A-"></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">From</label>
          <input type="number" name="from" min="1" class="input text-sm" placeholder="101"></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">To</label>
          <input type="number" name="to" min="1" class="input text-sm" placeholder="120"></div>
        <div class="flex items-end"><button type="submit" class="btn secondary text-sm">Bulk add</button></div>
      </form>
    </div>
  </details>

  <script>
    // Register the physical-rooms editor component the way the rest of the app does
    // (Alpine.data via alpine:init) instead of a bare global function. Alpine loads
    // deferred; a bare `function physRooms(){}` could be evaluated by Alpine BEFORE the
    // inline script defined it -> "physRooms is not defined" -> the whole editor silently
    // dead, so the Edit buttons did nothing. Registering on alpine:init guarantees the
    // component exists when Alpine resolves x-data. A plain re-declared global is kept as
    // a fallback for any code path that references it directly.
    function physRoomsComponent(floorsByBuilding) {
      return {
        floorsByBuilding: floorsByBuilding || {},
        editId: null,
        bulkBuilding: '0',
        f: { room_id: '', room_number: '', building_id: '0', floor_id: '0', wing: '' },
        floorsFor(bid) { return this.floorsByBuilding[String(bid)] || []; },
        editPhys(r) {
          this.editId = r.id;
          this.f = {
            room_id: String(r.room_id || ''),
            room_number: r.room_number || '',
            building_id: String(r.building_id || '0'),
            floor_id: String(r.floor_id || '0'),
            wing: r.wing || '',
          };
          // Scroll to the EDIT FORM itself (not the whole section — with a long room
          // table, centering the section landed mid-table and the form stayed off-screen,
          // so it looked like nothing happened). $nextTick so the form is in edit state first.
          this.$nextTick(() => {
            var form = this.$refs.physForm;
            if (form && form.scrollIntoView) { form.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            var first = form && form.querySelector('[name="room_number"]');
            if (first && first.focus) { try { first.focus(); } catch (e) {} }
          });
        },
        resetForm() {
          this.editId = null;
          this.f = { room_id: '', room_number: '', building_id: '0', floor_id: '0', wing: '' };
        },
      };
    }
    // Make it available as an Alpine component (resolves regardless of script order) AND
    // as a global function (x-data='physRooms(...)' calls it directly).
    window.physRooms = physRoomsComponent;
    document.addEventListener('alpine:init', function () {
      window.Alpine.data('physRooms', physRoomsComponent);
    });
  </script>
  <?php endif; ?>

  <!-- ============ ROOM add/edit MODAL ============ -->
  <?php if ($canEdit || $canAddRoom): ?>
  <div x-show="roomForm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
       @keydown.escape.window="roomForm = false">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto" @click.outside="roomForm = false">
      <form action="<?= $base ?>/save" method="POST" enctype="multipart/form-data" class="p-5 sm:p-6 space-y-4">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="room_id" :value="editRoom ? editRoom.id : 0">
        <h3 class="text-lg font-semibold text-gray-900" x-text="editRoom ? 'Edit room' : 'Add room'"></h3>

        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Room category *</label>
          <select name="room_type_id" required class="input">
            <option value="">Choose a category…</option>
            <?php foreach ($roomTypes as $rid => $rname): ?>
              <option value="<?= (int) $rid ?>" :selected="editRoom && String(editRoom.room_type_id) === '<?= (int) $rid ?>'"><?= htmlspecialchars($rname) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="mt-1 text-xs text-gray-500">The standard category — used for search &amp; filtering.</p>
          <?php if (empty($roomTypes)): ?>
            <p class="mt-1 text-xs text-amber-600">No categories yet — reload the page once (they seed automatically).</p>
          <?php endif; ?>
        </div>

        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Display name</label>
          <input type="text" name="room_name" class="input" maxlength="120"
                 :value="editRoom ? (editRoom.room_name || '') : ''"
                 placeholder="e.g. Presidential Suite, Garden Villa #2">
          <p class="mt-1 text-xs text-gray-500">What guests see. Leave blank to use the category name.</p>
        </div>

        <!-- WHITE-LABEL Phase 2b: rich room fields shown on the branded site -->
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Room description</label>
          <textarea name="room_description" rows="3" maxlength="2000" class="input text-sm"
                    placeholder="A few lines about this room — what makes it special."
                    x-text="editRoom ? (editRoom.description || '') : ''"></textarea>
          <p class="mt-1 text-xs text-gray-500">Shown on your branded site. Plain text.</p>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Size (m²)</label>
            <input type="number" name="room_size_sqm" min="0" max="99999" step="0.01" class="input text-sm"
                   :value="editRoom ? (editRoom.size_sqm || '') : ''" placeholder="e.g. 32">
          </div>
          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">View</label>
            <select name="room_view_type" class="input text-sm">
              <?php foreach ((function_exists('wl_view_types') ? wl_view_types() : ['none' => 'No specific view']) as $__vk => $__vl): ?>
                <option value="<?= htmlspecialchars($__vk) ?>" :selected="(editRoom ? (editRoom.view_type || 'none') : 'none') === '<?= htmlspecialchars($__vk) ?>'"><?= htmlspecialchars($__vl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Beds repeater -->
        <div class="form-control" x-data="{ beds: (editRoom && Array.isArray(editRoom.beds) ? editRoom.beds.map(b => ({type: b.type || 'double', qty: b.qty || 1, size: b.size || ''})) : []) }">
          <label class="block text-sm font-medium text-gray-700 mb-1">Beds</label>
          <template x-for="(bed, i) in beds" :key="i">
            <div class="flex items-center gap-2 mb-2">
              <select :name="'beds['+i+'][type]'" x-model="bed.type" class="input text-sm flex-1">
                <?php foreach ((function_exists('wl_bed_types') ? wl_bed_types() : ['double' => 'Double']) as $__bk => $__bl): ?>
                  <option value="<?= htmlspecialchars($__bk) ?>"><?= htmlspecialchars($__bl) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="number" :name="'beds['+i+'][qty]'" x-model="bed.qty" min="1" max="20" class="input text-sm w-20" placeholder="Qty">
              <input type="text" :name="'beds['+i+'][size]'" x-model="bed.size" maxlength="40" class="input text-sm w-28" placeholder="Size (opt)">
              <button type="button" class="text-red-600 text-sm" @click="beds.splice(i,1)">&times;</button>
            </div>
          </template>
          <button type="button" class="text-sm text-violet-600 hover:underline" @click="beds.push({type:'double', qty:1, size:''})">+ Add bed</button>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" name="room_status" value="1" class="checkbox-input" :checked="!editRoom || editRoom.status == 1">
          Active (bookable once the property is approved &amp; live)
        </label>

        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Room photos</label>
          <input type="file" name="room_images[]" accept="image/*" multiple class="input">
          <p class="mt-1 text-xs text-gray-500">JPG/PNG/WEBP/GIF, up to <strong><?= htmlspecialchars($roomImgMaxH) ?></strong> each, <strong><?= (int) $roomImgCount ?></strong> per upload. Added to any existing photos; auto-resized.</p>
        </div>

        <div class="flex items-center gap-3 pt-2 border-t border-gray-100">
          <button type="submit" class="btn emerald">Save room</button>
          <button type="button" class="btn secondary" @click="roomForm = false">Cancel</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- ============ RATE add/edit MODAL ============ -->
  <?php if ($canEdit): ?>
  <div x-show="optionFor !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
       @keydown.escape.window="optionFor = null">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto" @click.outside="optionFor = null">
      <form method="POST" class="p-5 sm:p-6 space-y-4" :action="'<?= $base ?>/' + optionFor + '/options/save'">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="option_id" :value="editOption ? editOption.option_id : 0">
        <h3 class="text-lg font-semibold text-gray-900" x-text="editOption ? 'Edit rate' : 'Add rate'"></h3>

        <div class="grid grid-cols-2 gap-3">
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Price / night (<?= $currency ?>) *</label>
            <input type="number" name="price" min="0" step="0.01" required class="input" :value="editOption ? editOption.price : ''" placeholder="0.00">
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Rooms available</label>
            <input type="number" name="available_quantity" min="0" class="input" :value="editOption ? editOption.available_quantity : 1">
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Max adults</label>
            <input type="number" name="max_adults" min="1" class="input" :value="editOption ? editOption.max_adults : 2">
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Max children</label>
            <input type="number" name="max_children" min="0" class="input" :value="editOption ? editOption.max_children : 0">
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Board</label>
            <select name="board_id" class="input">
              <option value="0" :selected="!editOption || !editOption.board_id || String(editOption.board_id) === '0'">—</option>
              <?php foreach ($boards as $bid => $bname): ?>
                <option value="<?= (int) $bid ?>" :selected="editOption && String(editOption.board_id) === '<?= (int) $bid ?>'"><?= htmlspecialchars($bname) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Discount %</label>
            <input type="number" name="discount_percentage" min="0" max="100" step="0.01" class="input" :value="editOption ? editOption.discount_percentage : 0">
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Extra bed charge (<?= $currency ?>)</label>
            <input type="number" name="extra_bed_charge" min="0" step="0.01" class="input" :value="editOption ? editOption.extra_bed_charge : 0">
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2 text-sm text-gray-700 pt-1">
          <label class="flex items-center gap-2"><input type="checkbox" name="extra_bed_available" value="1" class="checkbox-input" :checked="editOption && editOption.extra_bed_available == 1"> Extra bed available</label>
          <label class="flex items-center gap-2"><input type="checkbox" name="breakfast_included" value="1" class="checkbox-input" :checked="editOption && editOption.breakfast_included == 1"> Breakfast included</label>
          <label class="flex items-center gap-2"><input type="checkbox" name="cancellation_free" value="1" class="checkbox-input" :checked="editOption && editOption.cancellation_free == 1"> Free cancellation</label>
          <label class="flex items-center gap-2"><input type="checkbox" name="refundable" value="1" class="checkbox-input" :checked="editOption && editOption.refundable == 1"> Refundable</label>
          <label class="flex items-center gap-2"><input type="checkbox" name="status" value="1" class="checkbox-input" :checked="!editOption || editOption.status == 1"> Rate active</label>
        </div>

        <div class="flex items-center gap-3 pt-2 border-t border-gray-100">
          <button type="submit" class="btn emerald">Save rate</button>
          <button type="button" class="btn secondary" @click="optionFor = null">Cancel</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<style>
[x-cloak]{display:none!important}
/* Collapsible physical-rooms chevron: rotate when the <details> is open. Plain CSS so
   it works without a Tailwind rebuild (the arbitrary variant isn't in the precompiled build). */
details[open] > summary .phys-chevron { transform: rotate(180deg); }
details > summary::-webkit-details-marker { display: none; }
</style>
