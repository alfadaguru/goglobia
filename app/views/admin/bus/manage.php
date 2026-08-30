<?php @$SECURE or die('Access Denied!');
$isEdit = ($mode ?? 'add') === 'edit';
$b = $bus ?? [];
$selAmenities = [];
if (!empty($b['amenity_ids'])) { $selAmenities = array_map('strval', (array)json_decode($b['amenity_ids'], true)); }
$seatClassNames = array_map(fn($s) => $s['name'], $seatClasses ?? []);
$curr = $defaultCurrency ?? 'USD';
?>
<div class="container my-4">

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert-success mb-5"><span class="material-symbols-outlined">check_circle</span><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert-error mb-5"><span class="material-symbols-outlined">error</span><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="flex items-center gap-2 mb-5">
        <a href="<?= root.admin ?>/bus" class="btn btn-sm light"><span class="material-symbols-outlined !text-[18px]">arrow_back</span></a>
        <h1 class="text-xl font-bold text-gray-900"><?= $isEdit ? (T::edit ?? 'Edit') : (T::add ?? 'Add') ?> <?= T::bus ?? 'Bus' ?></h1>
    </div>

    <!-- ================= MAIN BUS CARD ================= -->
    <form method="POST" action="<?= root.admin ?>/bus/save" enctype="multipart/form-data">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="id" value="<?= (int)($b['id'] ?? 0) ?>">
        <div class="card mb-5 p-0">
            <div class="card-header"><div><span class="card-header-icon">directions_bus</span><h3><?= T::bus ?? 'Bus' ?> <?= T::details ?? 'Details' ?></h3></div></div>
            <div class="card-body !p-4 space-y-4">
                <!-- IMAGE + NAME · OPERATOR · TYPE · STATUS ON ONE ROW -->
                <div class="flex flex-col sm:flex-row gap-4">
                    <div class="form-control shrink-0" x-data="{ preview: '<?= !empty($b['img']) ? root . htmlspecialchars($b['img'], ENT_QUOTES) : '' ?>' }">
                        <label><?= T::image ?? 'Image' ?></label>
                        <label class="relative group w-28 h-28 rounded-xl border-2 border-dashed border-gray-300 hover:border-primary bg-gray-50 flex items-center justify-center overflow-hidden cursor-pointer transition-colors">
                            <template x-if="preview">
                                <img :src="preview" alt="" class="w-full h-full object-cover transition-opacity duration-300">
                            </template>
                            <template x-if="!preview">
                                <div class="flex flex-col items-center text-gray-400">
                                    <span class="material-symbols-outlined">add_photo_alternate</span>
                                    <span class="text-[11px] mt-1"><?= T::upload ?? 'Upload' ?></span>
                                </div>
                            </template>
                            <div x-show="preview" class="absolute inset-0 bg-black/0 group-hover:bg-black/30 opacity-0 group-hover:opacity-100 flex items-center justify-center transition-all duration-200">
                                <span class="material-symbols-outlined text-white">edit</span>
                            </div>
                            <input type="file" name="img" accept="image/*" class="absolute inset-0 opacity-0 cursor-pointer" @change="const f=$event.target.files[0]; if(f) preview=URL.createObjectURL(f)">
                        </label>
                    </div>
                    <div class="flex-1 space-y-4">
                      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="form-control">
                            <label><?= T::name ?? 'Name' ?> <span class="text-red-500">*</span></label>
                            <input type="text" name="name" class="input" required value="<?= htmlspecialchars($b['name'] ?? '') ?>" placeholder="CityLink Express">
                        </div>
                        <div class="form-control">
                            <label><?= T::operator ?? 'Operator' ?></label>
                            <select name="operator_id" class="select">
                                <option value=""><?= T::select ?? 'Select' ?></option>
                                <?php foreach (($operators ?? []) as $o): ?>
                                    <option value="<?= (int)$o['id'] ?>" <?= (int)($b['operator_id'] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['company_name'] ?: $o['operator_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-control">
                            <label><?= T::type ?? 'Type' ?></label>
                            <select name="bus_type" class="select">
                                <option value=""><?= T::select ?? 'Select' ?></option>
                                <?php foreach (($busTypes ?? []) as $t): ?>
                                    <option value="<?= htmlspecialchars($t['name']) ?>" <?= ($b['bus_type'] ?? '') === $t['name'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-control">
                            <label><?= T::status ?? 'Status' ?></label>
                            <select name="status" class="select">
                                <option value="1" <?= ($b['status'] ?? '1') === '1' ? 'selected' : '' ?>><?= T::active ?? 'Active' ?></option>
                                <option value="0" <?= ($b['status'] ?? '1') === '0' ? 'selected' : '' ?>><?= T::inactive ?? 'Inactive' ?></option>
                            </select>
                        </div>
                      </div>

                      <!-- AMENITIES -->
                      <div class="form-control">
                        <label><?= T::amenities ?? 'Amenities' ?></label>
                        <div class="flex flex-nowrap gap-2 mt-1 overflow-x-auto">
                            <?php foreach (($amenities ?? []) as $a): ?>
                                <label class="flex items-center gap-1.5 text-sm border border-gray-200 rounded-lg px-2.5 py-2 cursor-pointer hover:bg-gray-50 whitespace-nowrap shrink-0">
                                    <input type="checkbox" name="amenity_ids[]" value="<?= (int)$a['id'] ?>" <?= in_array((string)$a['id'], $selAmenities, true) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($a['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto_auto] gap-4 items-end">
                    <div class="form-control">
                        <label><?= T::cancellation_policy ?? 'Cancellation Policy' ?></label>
                        <textarea name="cancellation_policy" class="textarea" rows="2"><?= htmlspecialchars($b['cancellation_policy'] ?? '') ?></textarea>
                    </div>
                    <div class="form-control">
                        <label><?= T::refundable ?? 'Refundable' ?></label>
                        <select name="refundable" class="select">
                            <option value="1" <?= ($b['refundable'] ?? '1') === '1' ? 'selected' : '' ?>><?= T::yes ?? 'Yes' ?></option>
                            <option value="0" <?= ($b['refundable'] ?? '1') === '0' ? 'selected' : '' ?>><?= T::no ?? 'No' ?></option>
                        </select>
                    </div>
                    <div class="form-control">
                        <label><?= T::featured ?? 'Featured' ?></label>
                        <select name="featured" class="select">
                            <option value="0" <?= ($b['featured'] ?? '0') === '0' ? 'selected' : '' ?>><?= T::no ?? 'No' ?></option>
                            <option value="1" <?= ($b['featured'] ?? '0') === '1' ? 'selected' : '' ?>><?= T::yes ?? 'Yes' ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-footer flex justify-end gap-3">
                <a href="<?= root.admin ?>/bus" class="btn light"><?= T::cancel ?? 'Cancel' ?></a>
                <button type="submit" class="btn"><span class="material-symbols-outlined">save</span> <?= T::save ?? 'Save' ?> <?= T::bus ?? 'Bus' ?></button>
            </div>
        </div>
    </form>

    <?php if ($isEdit): ?>
    <!-- ================= ROUTES: crud() LIST + MODAL (AJAX, NO RELOAD) ================= -->
    <div x-data="busRoutesManager()" x-init="window.__busMgr = $data">

        <div class="mb-3 mt-8">
            <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-primary">route</span> <?= T::routes ?? 'Routes' ?></h2>
        </div>

        <div id="busRoutesTable">
            <?php require __DIR__ . '/_routes_table.php'; ?>
        </div>

        <div class="mt-3">
            <button type="button" @click="openAdd()" class="btn"><span class="material-symbols-outlined">add</span> <?= T::add ?? 'Add' ?> <?= T::route ?? 'Route' ?></button>
        </div>

        <!-- ===== ROUTE MODAL (FADE) ===== -->
        <div x-show="modalOpen" x-cloak
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-[200] flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="modalOpen=false" style="display:none">
            <div x-show="modalOpen"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-3 scale-95" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                 class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl my-8">
                <div class="flex items-center justify-between px-5 h-14 border-b border-gray-200">
                    <h3 class="font-bold text-gray-900" x-text="editingId ? '<?= T::edit ?? 'Edit' ?> <?= T::route ?? 'Route' ?>' : '<?= T::add ?? 'Add' ?> <?= T::route ?? 'Route' ?>'"></h3>
                    <button type="button" @click="modalOpen=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-500"><span class="material-symbols-outlined">close</span></button>
                </div>

                <form x-ref="routeForm" @submit.prevent="save()" class="p-5 space-y-4 max-h-[70vh] overflow-y-auto">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="bus_id" value="<?= (int)$b['id'] ?>">
                    <input type="hidden" name="route_id" :value="editingId">

                    <div x-show="error" class="alert-error"><span class="material-symbols-outlined">error</span><p x-text="error"></p></div>

                    <!-- ORIGIN / DESTINATION (AJAX AUTOCOMPLETE) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-control relative" @click.away="oOpen=false">
                            <label><?= T::origin ?? 'Origin' ?> <span class="text-red-500">*</span></label>
                            <input type="text" name="origin" class="input" x-model="f.origin" @input.debounce.300ms="fetchLoc('o')" @focus="fetchLoc('o')" autocomplete="off" placeholder="Lahore" required>
                            <div x-show="oOpen && oResults.length" class="absolute z-10 left-0 right-0 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-56 overflow-y-auto">
                                <template x-for="l in oResults" :key="'o'+l.name">
                                    <div @click="f.origin=l.city; oOpen=false" class="flex items-center gap-2 px-3 py-2 cursor-pointer hover:bg-slate-50">
                                        <span class="material-symbols-outlined text-slate-400 !text-[18px]">location_on</span>
                                        <div><div class="text-sm font-medium" x-text="l.city"></div><div class="text-xs text-slate-500" x-text="l.country"></div></div>
                                    </div>
                                </template>
                            </div>
                            <p class="text-[11px] text-slate-500 mt-1"><?= T::city_not_found ?? 'City not found?' ?> <a href="<?= root.admin ?>/settings/locations" target="_blank" class="text-primary hover:underline"><?= T::manage_cities ?? 'Manage cities' ?></a></p>
                        </div>
                        <div class="form-control relative" @click.away="dOpen=false">
                            <label><?= T::destination ?? 'Destination' ?> <span class="text-red-500">*</span></label>
                            <input type="text" name="destination" class="input" x-model="f.destination" @input.debounce.300ms="fetchLoc('d')" @focus="fetchLoc('d')" autocomplete="off" placeholder="Karachi" required>
                            <div x-show="dOpen && dResults.length" class="absolute z-10 left-0 right-0 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-56 overflow-y-auto">
                                <template x-for="l in dResults" :key="'d'+l.name">
                                    <div @click="f.destination=l.city; dOpen=false" class="flex items-center gap-2 px-3 py-2 cursor-pointer hover:bg-slate-50">
                                        <span class="material-symbols-outlined text-slate-400 !text-[18px]">location_on</span>
                                        <div><div class="text-sm font-medium" x-text="l.city"></div><div class="text-xs text-slate-500" x-text="l.country"></div></div>
                                    </div>
                                </template>
                            </div>
                            <p class="text-[11px] text-slate-500 mt-1"><?= T::city_not_found ?? 'City not found?' ?> <a href="<?= root.admin ?>/settings/locations" target="_blank" class="text-primary hover:underline"><?= T::manage_cities ?? 'Manage cities' ?></a></p>
                        </div>
                    </div>

                    <!-- JOURNEY DURATION + SEAT CLASS -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="form-control">
                            <label><?= T::duration ?? 'Duration' ?> <span class="text-red-500">*</span></label>
                            <input type="text" name="duration" class="input" x-model="f.duration" @input="parseDuration()" placeholder="10h 30m">
                        </div>
                        <div class="form-control">
                            <label><?= T::seat_class ?? 'Seat Class' ?></label>
                            <select name="seat_class" class="select" x-model="f.seat_class">
                                <?php foreach ($seatClassNames as $sc): ?>
                                    <option value="<?= htmlspecialchars($sc) ?>"><?= htmlspecialchars($sc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- DEPARTURE TIMES MANAGER (FIXED LIST + INTERVAL GENERATOR) -->
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-bold text-gray-900"><?= T::departure ?? 'Departure' ?> <?= T::times ?? 'Times' ?></div>
                            <span class="text-xs text-slate-500"><span x-text="f.departures.length"></span> <?= T::scheduled ?? 'scheduled' ?></span>
                        </div>

                        <!-- MODE TABS -->
                        <div class="flex gap-2">
                            <button type="button" @click="timeMode='fixed'" class="flex items-center gap-1.5 text-sm border rounded-lg px-3 py-2 bg-white" :class="timeMode==='fixed' ? 'border-[#1570ef] text-primary' : 'border-gray-200'"><span class="material-symbols-outlined !text-[18px]">schedule</span> <?= T::fixed ?? 'Fixed' ?> <?= T::times ?? 'Times' ?></button>
                            <button type="button" @click="timeMode='interval'" class="flex items-center gap-1.5 text-sm border rounded-lg px-3 py-2 bg-white" :class="timeMode==='interval' ? 'border-[#1570ef] text-primary' : 'border-gray-200'"><span class="material-symbols-outlined !text-[18px]">avg_pace</span> <?= T::interval ?? 'Interval' ?></button>
                        </div>

                        <!-- FIXED: ADD A TIME -->
                        <div x-show="timeMode==='fixed'" class="flex items-end gap-2">
                            <div class="form-control flex-1">
                                <label><?= T::add ?? 'Add' ?> <?= T::departure ?? 'Departure' ?></label>
                                <input type="time" x-model="newTime" @keydown.enter.prevent="addTime(newTime)" class="input bg-white">
                            </div>
                            <button type="button" @click="addTime(newTime)" class="btn h-[42px]"><span class="material-symbols-outlined !text-[18px]">add</span> <?= T::add ?? 'Add' ?></button>
                        </div>

                        <!-- INTERVAL GENERATOR -->
                        <div x-show="timeMode==='interval'" class="grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
                            <div class="form-control"><label><?= T::from ?? 'From' ?></label><input type="time" x-model="interval.from" class="input bg-white"></div>
                            <div class="form-control"><label><?= T::to ?? 'To' ?></label><input type="time" x-model="interval.to" class="input bg-white"></div>
                            <div class="form-control"><label><?= T::every ?? 'Every' ?></label><input type="number" min="1" x-model.number="interval.n" class="input bg-white"></div>
                            <div class="form-control"><label>&nbsp;</label>
                                <select x-model="interval.unit" class="select bg-white">
                                    <option value="min"><?= T::minutes ?? 'Minutes' ?></option>
                                    <option value="hour"><?= T::hours ?? 'Hours' ?></option>
                                </select>
                            </div>
                            <button type="button" @click="generateInterval()" class="btn h-[42px]"><span class="material-symbols-outlined !text-[18px]">auto_mode</span> <?= T::generate ?? 'Generate' ?></button>
                        </div>

                        <!-- DEPARTURE CHIPS -->
                        <div class="flex flex-wrap gap-2 pt-1">
                            <template x-if="!f.departures.length"><span class="text-xs text-slate-400"><?= T::no ?? 'No' ?> <?= T::times ?? 'times' ?> <?= T::added ?? 'added' ?></span></template>
                            <template x-for="(tm, i) in f.departures" :key="tm">
                                <span class="inline-flex items-center gap-1.5 text-sm bg-white border border-gray-200 rounded-lg pl-2.5 pr-1.5 py-1">
                                    <span class="font-semibold text-gray-800" x-text="tm"></span>
                                    <span class="text-xs text-slate-400" x-text="'→ ' + arrivalFor(tm)"></span>
                                    <button type="button" @click="f.departures.splice(i,1)" class="w-5 h-5 flex items-center justify-center rounded hover:bg-gray-100 text-gray-400"><span class="material-symbols-outlined !text-[16px]">close</span></button>
                                </span>
                            </template>
                        </div>
                        <input type="hidden" name="departure_times" :value="f.departures.join(',')">
                        <input type="hidden" name="duration_minutes" :value="durationMinutes()">
                    </div>

                    <!-- CAPACITY + PER ADULT/CHILD PRICE -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="form-control">
                            <label><?= T::adults ?? 'Adults' ?> (<?= T::seats ?? 'Seats' ?>)</label>
                            <input type="number" min="0" name="adults" class="input" x-model="f.adults">
                        </div>
                        <div class="form-control">
                            <label><?= T::children ?? 'Children' ?> (<?= T::seats ?? 'Seats' ?>)</label>
                            <input type="number" min="0" name="children" class="input" x-model="f.children">
                        </div>
                        <div class="form-control">
                            <label><?= T::adult ?? 'Adult' ?> <?= T::price ?? 'Price' ?> (<?= htmlspecialchars($curr) ?>)</label>
                            <input type="number" step="0.01" min="0" name="adult_price" class="input" x-model="f.adult_price">
                        </div>
                        <div class="form-control">
                            <label><?= T::child ?? 'Child' ?> <?= T::price ?? 'Price' ?> (<?= htmlspecialchars($curr) ?>)</label>
                            <input type="number" step="0.01" min="0" name="child_price" class="input" x-model="f.child_price">
                        </div>
                    </div>

                    <!-- SCHEDULE (SLATE BG) -->
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
                        <div class="text-sm font-bold text-gray-900"><?= T::availability ?? 'Availability' ?> &amp; <?= T::date ?? 'Dates' ?></div>
                        <div class="flex flex-wrap gap-2">
                            <label class="flex items-center gap-2 text-sm border rounded-lg px-3 py-2 cursor-pointer bg-white" :class="f.schedule_type==='none' ? 'border-[#1570ef] text-primary' : 'border-gray-200'"><input type="radio" name="schedule_type" value="none" x-model="f.schedule_type"> <?= T::keep ?? 'Keep' ?> <?= T::existing ?? 'existing' ?></label>
                            <label class="flex items-center gap-2 text-sm border rounded-lg px-3 py-2 cursor-pointer bg-white" :class="f.schedule_type==='date' ? 'border-[#1570ef] text-primary' : 'border-gray-200'"><input type="radio" name="schedule_type" value="date" x-model="f.schedule_type"> <?= T::specific ?? 'Specific' ?> <?= T::date ?? 'Date' ?></label>
                            <label class="flex items-center gap-2 text-sm border rounded-lg px-3 py-2 cursor-pointer bg-white" :class="f.schedule_type==='range' ? 'border-[#1570ef] text-primary' : 'border-gray-200'"><input type="radio" name="schedule_type" value="range" x-model="f.schedule_type"> <?= T::date ?? 'Date' ?> <?= T::range ?? 'Range' ?></label>
                            <label class="flex items-center gap-2 text-sm border rounded-lg px-3 py-2 cursor-pointer bg-white" :class="f.schedule_type==='weekday' ? 'border-[#1570ef] text-primary' : 'border-gray-200'"><input type="radio" name="schedule_type" value="weekday" x-model="f.schedule_type"> <?= T::weekly ?? 'Weekly' ?></label>
                        </div>
                        <div x-show="f.schedule_type==='date'" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="form-control"><label><?= T::date ?? 'Date' ?></label><input type="text" name="sched_date" class="dp input cursor-pointer bg-white" readonly value="<?= date('d-m-Y', strtotime('+1 day')) ?>"></div>
                        </div>
                        <div x-show="f.schedule_type==='range'" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="form-control"><label><?= T::from ?? 'From' ?></label><input type="text" name="sched_from" class="dp input cursor-pointer bg-white" readonly value="<?= date('d-m-Y', strtotime('+1 day')) ?>"></div>
                            <div class="form-control"><label><?= T::to ?? 'To' ?></label><input type="text" name="sched_to" class="dp input cursor-pointer bg-white" readonly value="<?= date('d-m-Y', strtotime('+7 days')) ?>"></div>
                        </div>
                        <div x-show="f.schedule_type==='weekday'" class="form-control">
                            <label><?= T::days ?? 'Days' ?></label>
                            <div class="flex flex-nowrap gap-2 mt-1">
                                <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $n=>$wd): ?>
                                    <label class="flex-1 flex items-center justify-center gap-1.5 text-sm border rounded-lg px-2 py-2 cursor-pointer bg-white whitespace-nowrap" :class="f.weekdays.includes('<?= $n ?>') ? 'border-[#1570ef] text-primary' : 'border-gray-200'">
                                        <input type="checkbox" name="sched_weekday[]" value="<?= $n ?>" x-model="f.weekdays"> <?= $wd ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="status" :value="f.status">
                    <div class="flex items-center justify-between pt-2">
                        <!-- ACTIVE SWITCH -->
                        <div class="flex items-center gap-2">
                            <button type="button" role="switch" :aria-checked="f.status==='1'" @click="f.status = f.status==='1' ? '0' : '1'"
                                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none" :class="f.status==='1' ? 'bg-primary' : 'bg-gray-300'">
                                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform" :class="f.status==='1' ? 'translate-x-5' : 'translate-x-1'"></span>
                            </button>
                            <span class="text-sm text-gray-700"><?= T::active ?? 'Active' ?></span>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" @click="modalOpen=false" class="btn light"><?= T::cancel ?? 'Cancel' ?></button>
                            <button type="submit" class="btn" :disabled="saving"><span class="material-symbols-outlined" x-show="!saving">save</span><span class="material-symbols-outlined animate-spin" x-show="saving" x-cloak>progress_activity</span> <?= T::save ?? 'Save' ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function busRoutesManager() {
        return {
            busId: <?= (int)$b['id'] ?>,
            csrf: '<?= CSRF::getToken() ?>',
            routes: <?= json_encode($routes ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            modalOpen: false, editingId: 0, saving: false, error: '',
            oResults: [], dResults: [], oOpen: false, dOpen: false,
            timeMode: 'fixed', newTime: '08:00',
            interval: { from: '06:00', to: '22:00', n: 30, unit: 'min' },
            f: {},
            blank() {
                return { origin:'', destination:'', duration:'2h 0m', seat_class:'Economy', adults:40, children:0, adult_price:'', child_price:'', status:'1', schedule_type:'weekday', weekdays:['1'], departures:['08:00'] };
            },
            openAdd() { this.editingId = 0; this.error=''; this.timeMode='fixed'; this.f = this.blank(); this.modalOpen = true; },
            openEdit(id) {
                const r = this.routes.find(x => String(x.id) === String(id));
                if (!r) return;
                this.editingId = r.id; this.error=''; this.timeMode='fixed';
                this.f = {
                    origin:r.origin||'', destination:r.destination||'',
                    duration:r.duration||'2h 0m', seat_class:r.seat_class||'',
                    adults:r.adults||0, children:r.children||0, adult_price:r.adult_price||'', child_price:r.child_price||'',
                    status:r.status||'1', schedule_type:'none', weekdays:['1'],
                    departures: r.departure_time ? [(r.departure_time||'').slice(0,5)] : []
                };
                this.modalOpen = true;
            },
            parseDuration() { /* duration typed manually; nothing to compute */ },
            durationMinutes() {
                const s = String(this.f.duration||''); let mins = 0;
                const h = s.match(/(\d+)\s*h/i); const m = s.match(/(\d+)\s*m/i);
                if (h) mins += parseInt(h[1])*60; if (m) mins += parseInt(m[1]);
                if (!h && !m) { const n = parseInt(s); if (!isNaN(n)) mins = n*60; }
                return mins;
            },
            arrivalFor(hhmm) {
                const p = (hhmm||'').split(':').map(Number);
                if (p.length<2 || isNaN(p[0])) return '';
                let t = p[0]*60 + p[1] + this.durationMinutes();
                const nextDay = t >= 1440; t %= 1440;
                return String(Math.floor(t/60)).padStart(2,'0') + ':' + String(t%60).padStart(2,'0') + (nextDay ? ' +1' : '');
            },
            addTime(tm) {
                if (!tm) return;
                if (!this.f.departures.includes(tm)) { this.f.departures.push(tm); this.f.departures.sort(); }
            },
            generateInterval() {
                const toMin = (s) => { const p=(s||'').split(':').map(Number); return p[0]*60+p[1]; };
                let start = toMin(this.interval.from), end = toMin(this.interval.to);
                const step = this.interval.unit === 'hour' ? this.interval.n*60 : this.interval.n;
                if (isNaN(start) || isNaN(end) || !step || step < 1) return;
                if (end < start) end += 1440;
                const out = [];
                for (let t = start; t <= end && out.length < 96; t += step) {
                    const tt = t % 1440;
                    out.push(String(Math.floor(tt/60)).padStart(2,'0') + ':' + String(tt%60).padStart(2,'0'));
                }
                this.f.departures = [...new Set(out)].sort();
            },
            fetchLoc(which) {
                const q = ((which==='o' ? this.f.origin : this.f.destination) || '').trim();
                if (q.length < 3) { if(which==='o'){this.oResults=[];this.oOpen=false;}else{this.dResults=[];this.dOpen=false;} return; }
                $.ajax({ url:'<?=root?>bus-location-suggestion', method:'POST', data:{query:q}, success:(data)=>{
                    const arr = Array.isArray(data)?data:[];
                    if(which==='o'){this.oResults=arr;this.oOpen=arr.length>0;}else{this.dResults=arr;this.dOpen=arr.length>0;}
                }});
            },
            refreshTable() {
                fetch('<?=root.admin?>/bus/' + this.busId + '/routes-table', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(r=>r.text()).then(html=>{ const el=document.getElementById('busRoutesTable'); if(el) el.innerHTML = html; });
            },
            save() {
                this.saving = true; this.error='';
                const fd = new FormData(this.$refs.routeForm);
                fd.set('csrf_token', this.csrf);
                fd.set('duration', this.f.duration);
                fetch('<?=root.admin?>/bus/routes/ajax-save', { method:'POST', body: fd })
                    .then(r=>r.json()).then(res=>{
                        this.saving=false;
                        if(res.csrf) this.csrf = res.csrf;
                        if(res.success){ this.routes = res.routes || this.routes; this.modalOpen=false; this.refreshTable(); }
                        else { this.error = res.error || 'Failed'; }
                    }).catch(()=>{ this.saving=false; this.error='Network error'; });
            },
            del(id) {
                if(!confirm('<?= T::are_you_sure ?? 'Are you sure?' ?>')) return;
                const fd = new FormData();
                fd.set('csrf_token', this.csrf); fd.set('bus_id', this.busId); fd.set('route_id', id);
                fetch('<?=root.admin?>/bus/routes/ajax-delete', { method:'POST', body: fd })
                    .then(r=>r.json()).then(res=>{ if(res.csrf) this.csrf = res.csrf; if(res.success){ this.routes = res.routes || this.routes; this.refreshTable(); } });
            }
        };
    }
    </script>
    <?php else: ?>
    <div class="card">
        <div class="card-body text-center text-gray-500 py-8">
            <span class="material-symbols-outlined text-4xl text-gray-300">route</span>
            <p class="mt-2 text-sm"><?= T::save ?? 'Save' ?> <?= T::bus ?? 'Bus' ?> <?= T::to ?? 'to' ?> <?= T::add ?? 'add' ?> <?= T::routes ?? 'routes' ?></p>
        </div>
    </div>
    <?php endif; ?>
</div>
