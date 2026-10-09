<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier room/option manager (inc S10). Provided by the route:
//   $stay, $rooms (each with ['_options'] = stable-id options), $taxonomy,
//   $canEdit, $canAddRoom, $canDeleteRoom.
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
?>

<div class="max-w-6xl mx-auto px-4 py-8 space-y-6"
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
  <div class="flex items-center justify-between gap-3">
    <div>
      <nav class="text-xs text-gray-500 mb-1">
        <a href="<?= root ?>supplier/stays" class="hover:underline">My Hotels</a>
        <span class="mx-1">/</span>
        <a href="<?= root ?>supplier/stays/edit/<?= $stayId ?>" class="hover:underline"><?= htmlspecialchars($stay['name'] ?? '') ?></a>
        <span class="mx-1">/</span><span class="text-gray-700">Rooms</span>
      </nav>
      <h1 class="text-2xl font-bold text-gray-900">Rooms &amp; rates</h1>
      <p class="text-sm text-gray-600">Add rooms, set their rate options, and manage per-date pricing &amp; availability.</p>
    </div>
    <?php if ($canAddRoom): ?>
      <button type="button" class="btn emerald" @click="roomForm = true; editRoom = null">
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">add</span> Add room</span>
      </button>
    <?php endif; ?>
  </div>

  <!-- Add/Edit room form (shown on demand) -->
  <?php if ($canEdit || $canAddRoom): ?>
  <div class="card p-5" x-show="roomForm" x-cloak>
    <h2 class="text-lg font-semibold text-gray-900 mb-4" x-text="editRoom ? 'Edit room' : 'Add room'"></h2>
    <form action="<?= $base ?>/save" method="POST" enctype="multipart/form-data" class="space-y-4">
      <?= CSRF::tokenField() ?>
      <input type="hidden" name="room_id" :value="editRoom ? editRoom.id : 0">

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Room type *</label>
          <select name="room_type_id" required class="input" x-model="editRoom && editRoom.room_type_id">
            <option value="">Select a room type…</option>
            <?php foreach ($roomTypes as $rid => $rname): ?>
              <option value="<?= (int) $rid ?>"><?= htmlspecialchars($rname) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($roomTypes)): ?>
            <p class="mt-1 text-xs text-amber-600">No room types are configured yet. Ask the administrator to add room types.</p>
          <?php endif; ?>
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
          <label class="flex items-center gap-2 mt-2 text-sm text-gray-700">
            <input type="checkbox" name="room_status" value="1" class="checkbox-input" checked>
            Active (bookable once the property is approved &amp; live)
          </label>
        </div>
      </div>

      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Room photos</label>
        <input type="file" name="room_images[]" accept="image/*" multiple class="input">
        <p class="mt-1 text-xs text-gray-500">JPG, PNG, GIF or WEBP, up to 5&nbsp;MB each. New uploads are added to any existing photos.</p>
      </div>

      <div class="flex items-center gap-3">
        <button type="submit" class="btn emerald">Save room</button>
        <button type="button" class="btn secondary" @click="roomForm = false">Cancel</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- Rooms list -->
  <?php if (empty($rooms)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">bed</span>
      <p class="mt-2 text-sm">No rooms yet. Add a room, then give it at least one rate option so it can sell.</p>
    </div>
  <?php else: ?>
    <?php foreach ($rooms as $room): ?>
      <?php
        $rid = (int) $room['id'];
        $rtName = $roomTypes[(int) ($room['room_type_id'] ?? 0)] ?? ('Room #' . $rid);
        $opts = is_array($room['_options'] ?? null) ? $room['_options'] : [];
        $active = (int) ($room['status'] ?? 0) === 1;
      ?>
      <div class="card p-5 space-y-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="text-base font-semibold text-gray-900"><?= htmlspecialchars($rtName) ?></h3>
            <span class="text-xs <?= $active ? 'text-green-700' : 'text-gray-500' ?>">
              <?= $active ? 'Active' : 'Inactive' ?> · <?= count($opts) ?> rate<?= count($opts) === 1 ? '' : 's' ?>
            </span>
          </div>
          <div class="flex items-center gap-3 text-xs font-medium">
            <?php if ($canEdit): ?>
              <button type="button" class="text-blue-600 hover:underline"
                      @click='editRoom = <?= htmlspecialchars(json_encode(["id"=>$rid,"room_type_id"=>(int)($room["room_type_id"]??0)]), ENT_QUOTES) ?>; roomForm = true'>Edit room</button>
            <?php endif; ?>
            <?php if ($canDeleteRoom): ?>
              <form action="<?= $base ?>/delete" method="POST" class="inline"
                    onsubmit="return confirm('Delete this room and all its rates/availability? This cannot be undone.');">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="room_id" value="<?= $rid ?>">
                <button type="submit" class="text-red-600 hover:underline">Delete room</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <!-- Rate options table -->
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-gray-500 border-b border-gray-100">
                <th class="px-3 py-2 font-medium">Rate</th>
                <th class="px-3 py-2 font-medium">Occupancy</th>
                <th class="px-3 py-2 font-medium">Price</th>
                <th class="px-3 py-2 font-medium">Qty</th>
                <th class="px-3 py-2 font-medium">Board</th>
                <th class="px-3 py-2 font-medium">Status</th>
                <th class="px-3 py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <?php if (empty($opts)): ?>
                <tr><td colspan="7" class="px-3 py-4 text-gray-400 text-center text-xs">No rates yet — add one so this room can be booked.</td></tr>
              <?php else: foreach ($opts as $o): ?>
                <?php $oid = (int) ($o['option_id'] ?? 0); ?>
                <tr>
                  <td class="px-3 py-2 text-gray-500 tabular-nums">#<?= $oid ?></td>
                  <td class="px-3 py-2 text-gray-700"><?= (int) ($o['max_adults'] ?? 0) ?> adult<?= (int) ($o['max_adults'] ?? 0) === 1 ? '' : 's' ?><?= (int) ($o['max_children'] ?? 0) > 0 ? ', ' . (int) $o['max_children'] . ' child' : '' ?></td>
                  <td class="px-3 py-2 text-gray-900 font-medium tabular-nums"><?= $currency ?> <?= number_format((float) ($o['price'] ?? 0), 2) ?></td>
                  <td class="px-3 py-2 text-gray-700 tabular-nums"><?= (int) ($o['available_quantity'] ?? 0) ?></td>
                  <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($boards[(int) ($o['board_id'] ?? 0)] ?? '—') ?></td>
                  <td class="px-3 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= (int) ($o['status'] ?? 0) === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= (int) ($o['status'] ?? 0) === 1 ? 'On' : 'Off' ?></span></td>
                  <td class="px-3 py-2">
                    <div class="flex items-center gap-3 text-xs font-medium">
                      <?php if ($canEdit): ?>
                        <button type="button" class="text-blue-600 hover:underline"
                                @click='optionFor = <?= $rid ?>; editOption = <?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>'>Edit</button>
                        <form action="<?= $base ?>/<?= $rid ?>/options/delete" method="POST" class="inline"
                              onsubmit="return confirm('Delete this rate?');">
                          <?= CSRF::tokenField() ?>
                          <input type="hidden" name="option_id" value="<?= $oid ?>">
                          <button type="submit" class="text-red-600 hover:underline">Delete</button>
                        </form>
                      <?php endif; ?>
                      <a href="<?= $base ?>/<?= $rid ?>/calendar" class="text-violet-600 hover:underline">Calendar</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($canEdit): ?>
          <button type="button" class="text-sm text-emerald-700 hover:underline font-medium"
                  @click="optionFor = <?= $rid ?>; editOption = null">+ Add rate option</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Physical rooms (inc S22): individual units for housekeeping + front-desk assignment -->
  <?php if ($canAddRoom && !empty($rooms)): ?>
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <div>
        <h2 class="text-base font-semibold text-gray-900">Physical rooms</h2>
        <p class="text-xs text-gray-500">Add individual rooms (by number) under a room type for housekeeping &amp; check-in assignment.</p>
      </div>
      <a href="<?= root ?>supplier/housekeeping?stay_id=<?= $stayId ?>" class="text-sm text-violet-600 hover:underline">Housekeeping board</a>
    </div>
    <form action="<?= root ?>supplier/housekeeping/add" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <input type="hidden" name="stay_id" value="<?= $stayId ?>">
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Room type</label>
        <select name="room_id" class="input text-sm">
          <?php foreach ($rooms as $room): ?>
            <option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars($roomTypes[(int) ($room['room_type_id'] ?? 0)] ?? ('Room #' . (int) $room['id'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Room number</label>
        <input type="text" name="room_number" class="input text-sm w-28" placeholder="e.g. 101" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Floor</label>
        <input type="text" name="floor" class="input text-sm w-24" placeholder="e.g. 1"></div>
      <button type="submit" class="btn secondary text-sm">Add physical room</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Option add/edit modal (Alpine) -->
  <?php if ($canEdit): ?>
  <div x-show="optionFor !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
       @keydown.escape.window="optionFor = null">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
      <form method="POST" class="p-5 space-y-4" :action="'<?= $base ?>/' + optionFor + '/options/save'">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="option_id" :value="editOption ? editOption.option_id : 0">
        <h3 class="text-lg font-semibold text-gray-900" x-text="editOption ? 'Edit rate' : 'Add rate'"></h3>

        <div class="grid grid-cols-2 gap-3">
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Max adults</label>
            <input type="number" name="max_adults" min="1" class="input" :value="editOption ? editOption.max_adults : 2">
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Max children</label>
            <input type="number" name="max_children" min="0" class="input" :value="editOption ? editOption.max_children : 0">
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Price (<?= $currency ?>)</label>
            <input type="number" name="price" min="0" step="0.01" required class="input" :value="editOption ? editOption.price : ''">
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Discount %</label>
            <input type="number" name="discount_percentage" min="0" max="100" step="0.01" class="input" :value="editOption ? editOption.discount_percentage : 0">
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Available quantity</label>
            <input type="number" name="available_quantity" min="0" class="input" :value="editOption ? editOption.available_quantity : 1">
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Board</label>
            <select name="board_id" class="input" x-model="editOption && editOption.board_id">
              <option value="0">—</option>
              <?php foreach ($boards as $bid => $bname): ?>
                <option value="<?= (int) $bid ?>"><?= htmlspecialchars($bname) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Extra bed charge (<?= $currency ?>)</label>
            <input type="number" name="extra_bed_charge" min="0" step="0.01" class="input" :value="editOption ? editOption.extra_bed_charge : 0">
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2 text-sm text-gray-700">
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

<style>[x-cloak]{display:none!important}</style>
