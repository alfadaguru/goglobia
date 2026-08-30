<?php @$SECURE or die('Access Denied!');
// Countries list required by the shared guest-details component (booking-auth.php)
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['status' => 'active', 'ORDER' => ['nicename' => 'ASC']]) ?: [];
$d = $booking ?? [];
$t = $d['trip'] ?? [];
$journeys = $d['journeys'] ?? [['type' => 'outbound', 'route_id' => $d['route_id'] ?? 0, 'date' => $d['date'] ?? '', 'trip' => $t, 'total' => $d['total'] ?? 0]];
$seats = array_values($d['seats'] ?? []);
$cur = htmlspecialchars($t['currency'] ?? 'USD');
$adults = (int)($d['adults'] ?? 1);
$children = (int)($d['children'] ?? 0);
$adultPrice = array_sum(array_map(fn($j) => (float)($j['trip']['adult_price'] ?? $j['trip']['price'] ?? 0), $journeys));
$childPrice = array_sum(array_map(fn($j) => (float)($j['trip']['child_price'] ?? 0), $journeys));
$grandTotal = (float)($d['total'] ?? ($adults * $adultPrice + $children * $childPrice));
// Passenger slots (adults first, then children)
$paxSlots = [];
for ($i = 0; $i < $adults; $i++)   $paxSlots[] = ['type' => 'adult',   'label' => (T::adult ?? 'Adult') . ' ' . ($i + 1)];
for ($i = 0; $i < $children; $i++) $paxSlots[] = ['type' => 'child',   'label' => (T::child ?? 'Child') . ' ' . ($i + 1)];
?>
<div class="min-h-screen bg-slate-100" x-data="busBookingForm()" x-init="init()">

    <?php include views . 'includes/booking/loading.php'; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 min-h-screen w-full">

        <!-- Left Column: 50% -->
        <div class="order-2 lg:order-1 bg-white border-r border-slate-200/80 shadow-lg min-h-screen">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pr-12 max-w-[720px] mx-auto lg:ml-auto lg:mx-0 w-full">

                <!-- Header Section -->
                <div class="grid grid-cols-2 gap-3 items-center sm:flex sm:items-center sm:gap-5 mb-6">
                    <!-- Back Button -->
                    <div class="col-span-1 justify-self-start">
                        <a href="javascript:history.back()"
                            class="btn secondary inline-flex items-center justify-start w-13 h-12 rounded-lg transition-colors">
                            <span class="material-symbols-outlined text-xl">arrow_back</span>
                        </a>
                    </div>

                    <!-- Logo (Ordered last on tablet/desktop) -->
                    <div class="col-span-1 justify-self-end sm:order-last">
                        <a href="<?= root ?>" class="flex items-center">
                            <img src="<?= root ?>uploads/global/logo.png" alt="logo" class="h-8 w-auto">
                        </a>
                    </div>

                    <!-- Title & Subtitle (Pushed to the left on desktop, spanning full width on mobile) -->
                    <div class="col-span-2 sm:col-span-1 sm:mr-auto">
                        <h1 class="text-xl font-bold text-slate-800">
                            <?= T::complete_booking ?? 'Complete Booking' ?>
                        </h1>
                        <p class="text-sm text-slate-500 mt-1">
                            <?= htmlspecialchars($t['origin'] ?? '') ?> <?= count($journeys) > 1 ? '⇄' : '→' ?> <?= htmlspecialchars($t['destination'] ?? '') ?> · <?= htmlspecialchars($d['date'] ?? '') ?>
                        </p>
                    </div>
                </div>

                <!-- GUEST DETAILS (shared default component) -->
                <div @guest-updated.window="handleGuestUpdate($event.detail)">
                    <?php include views . 'includes/booking/booking-auth.php'; ?>
                </div>

                <!-- PASSENGER DETAILS -->
                <div class="card p-0 mb-5">
                    <div class="card-header-responsive">
                        <div>
                            <span class="card-header-icon">groups</span>
                            <h3><?= T::passenger ?? 'Passenger' ?> <?= T::details ?? 'Details' ?></h3>
                        </div>
                    </div>
                    <div class="card-body space-y-4">
                        <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                            <input type="checkbox" class="accent-[#1570ef]" x-model="sameAsGuest" @change="syncFirstFromGuest()">
                            <?= T::same_as_guest ?? 'First passenger same as guest' ?>
                        </label>
                        <?php foreach ($paxSlots as $i => $p): ?>
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="material-symbols-outlined text-[#1570ef] !text-[18px]"><?= $p['type'] === 'child' ? 'child_care' : 'person' ?></span>
                                <span class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($p['label']) ?></span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-control">
                                    <label><?= T::first_name ?? 'First Name' ?> <span class="text-red-500">*</span></label>
                                    <input type="text" class="input" x-model="passengers[<?= $i ?>].first_name" placeholder="John">
                                </div>
                                <div class="form-control">
                                    <label><?= T::last_name ?? 'Last Name' ?></label>
                                    <input type="text" class="input" x-model="passengers[<?= $i ?>].last_name" placeholder="Doe">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- PAYMENT METHODS (shared component) -->
                <?php include views . 'includes/booking/payment-methods.php'; ?>

                <!-- BOOKING OPTIONS -->
                <div class="card p-0 mb-5">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">settings</span>
                            <h3><?= T::booking_options ?? 'Booking Options' ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Special Requests -->
                        <div class="form-control mb-6">
                            <label><?= T::special ?? 'Special' ?> <?= T::requests ?? 'Requests' ?> (<?= T::optional ?? 'Optional' ?>)</label>
                            <textarea x-model="formData.special_requests" class="input" rows="3"
                                placeholder="<?= T::any ?? 'Any' ?> <?= T::special ?? 'special' ?> <?= T::requests ?? 'requests' ?> <?= T::or ?? 'or' ?> <?= T::notes ?? 'notes' ?>..."></textarea>
                        </div>

                        <!-- Terms & Conditions -->
                        <div class="pt-6 border-t border-gray-200">
                            <div class="checkbox-item">
                                <div class="checkbox-container">
                                    <input type="checkbox" id="terms_accepted" x-model="formData.terms_accepted" class="checkbox-input" required>
                                    <div class="checkbox-custom">
                                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                    </div>
                                </div>
                                <label for="terms_accepted" class="cursor-pointer text-sm text-gray-700">
                                    <?= T::i_agree_to_the ?? 'I agree to the' ?>
                                    <a href="<?= root ?>page/terms-of-use" target="_blank" class="text-blue-600 hover:underline"><?= T::terms ?? 'Terms' ?> & <?= T::conditions ?? 'Conditions' ?></a>
                                    <?= T::and ?? 'and' ?>
                                    <a href="<?= root ?>page/privacy-policy" target="_blank" class="text-blue-600 hover:underline"><?= T::privacy ?? 'Privacy' ?> <?= T::policy ?? 'Policy' ?></a>
                                </label>
                            </div>
                        </div>

                        <div x-show="error" class="alert-error mt-4"><span class="material-symbols-outlined">error</span><p x-text="error"></p></div>

                        <!-- Submit Button -->
                        <button type="button" @click="submit()" class="btn w-full mt-6"
                            :disabled="submitting || !formData.terms_accepted"
                            :class="{ 'opacity-50 cursor-not-allowed': !formData.terms_accepted }">
                            <span x-show="!submitting" class="material-symbols-outlined">lock</span>
                            <span x-show="submitting" class="material-symbols-outlined animate-spin">progress_activity</span>
                            <span x-show="!submitting"><?= T::confirm ?? 'Confirm' ?> <?= T::booking ?? 'Booking' ?></span>
                            <span x-show="submitting"><?= T::processing ?? 'Processing' ?>...</span>
                        </button>

                        <!-- Terms validation message -->
                        <div x-show="!formData.terms_accepted" class="mt-2">
                            <p class="text-sm text-red-600 text-center">
                                <span class="material-symbols-outlined !text-[16px]">info</span>
                                <?= T::please_accept_terms_to_proceed ?? 'Please accept the terms to proceed' ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: SUMMARY SIDEBAR -->
        <div class="order-1 lg:order-2 bg-slate-100 lg:sticky lg:top-0 lg:self-start lg:max-h-screen lg:overflow-y-auto">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pl-12 max-w-[550px] mx-auto lg:mr-auto lg:mx-0 w-full">
                <div class="card p-0 mb-5">
                    <div class="card-header">
                    <div>
                        <span class="card-header-icon">receipt_long</span>
                        <h3><?= T::booking ?? 'Booking' ?> <?= T::summary ?? 'Summary' ?></h3>
                    </div>
                </div>
                <div class="card-body">

                    <!-- Bus Information & Trip Details -->
                    <?php foreach ($journeys as $journey): $legTrip = $journey['trip'] ?? []; $legDate = $journey['date'] ?? ''; $legLabel = ($journey['type'] ?? 'outbound') === 'return' ? 'Return' : 'Outbound'; ?>
                    <div class="mb-4 p-4 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-700 rounded-lg border border-blue-200 dark:border-gray-600">
                        <?php if (count($journeys) > 1): ?><div class="text-[11px] font-bold uppercase tracking-wide text-blue-700 mb-2"><?= $legLabel ?></div><?php endif; ?>

                        <!-- Bus Image & Name -->
                        <div class="flex items-start gap-3 mb-3">
                            <div class="w-16 h-16 flex-shrink-0">
                                <img src="<?= !empty($legTrip['img']) ? root . htmlspecialchars($legTrip['img']) : root . 'uploads/no_img.jpg' ?>"
                                    class="w-full h-full object-cover rounded-lg border-2 border-white dark:border-gray-600 shadow-sm"
                                    onerror="this.src='<?= root ?>uploads/no_img.jpg'" alt="<?= htmlspecialchars($legTrip['service_name'] ?? '') ?>">
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="font-bold text-gray-900 dark:text-white text-sm mb-1 leading-tight"><?= htmlspecialchars($legTrip['service_name'] ?? '') ?></h4>
                                <p class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1">
                                    <span class="material-symbols-outlined !text-xs">directions_bus</span>
                                    <span class="line-clamp-2"><?= htmlspecialchars($legTrip['operator'] ?? '') ?> · <?= htmlspecialchars($legTrip['bus_type'] ?? '') ?> · <?= htmlspecialchars($legTrip['seat_class'] ?? '') ?></span>
                                </p>
                            </div>
                        </div>

                        <!-- Departure / Duration / Arrival -->
                        <div class="flex flex-wrap items-center justify-between text-xs bg-white dark:bg-gray-700 rounded-md p-2 mb-2">
                            <div class="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                                <span class="material-symbols-outlined !text-sm text-blue-600">login</span>
                                <span class="font-medium"><?= htmlspecialchars($legTrip['origin'] ?? '') ?> <?= htmlspecialchars($legTrip['departure_time'] ?? '') ?></span>
                            </div>
                            <div class="flex items-center gap-1 px-2 py-0.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-full font-semibold">
                                <span class="material-symbols-outlined !text-sm">schedule</span>
                                <span><?= htmlspecialchars($legTrip['duration'] ?? '') ?></span>
                            </div>
                            <div class="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                                <span class="material-symbols-outlined !text-sm text-red-600">logout</span>
                                <span class="font-medium"><?= htmlspecialchars($legTrip['destination'] ?? '') ?> <?= htmlspecialchars($legTrip['arrival_time'] ?? '') ?></span>
                            </div>
                        </div>

                        <!-- Travel Date -->
                        <div class="flex items-center gap-2 text-xs bg-white dark:bg-gray-700 rounded-md p-2 mb-2">
                            <span class="material-symbols-outlined text-blue-600 !text-sm">calendar_month</span>
                            <span class="text-gray-600 dark:text-gray-400"><?= T::date ?? 'Date' ?>:</span>
                            <span class="font-semibold text-blue-700 dark:text-blue-300"><?= htmlspecialchars($legDate) ?></span>
                        </div>

                        <!-- Passengers -->
                        <div class="flex items-center gap-2 text-xs bg-white dark:bg-gray-700 rounded-md p-2">
                            <span class="material-symbols-outlined text-blue-600 !text-sm">group</span>
                            <span class="text-gray-600 dark:text-gray-400"><?= T::passengers ?? 'Passengers' ?>:</span>
                            <span class="font-semibold text-blue-700 dark:text-blue-300">
                                <?= $adults ?> <?= T::adults ?? 'Adults' ?><?= $children > 0 ? ', ' . $children . ' ' . (T::children ?? 'Children') : '' ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Price Breakdown -->
                    <div class="pt-4 border rounded-lg p-4 dark:border-gray-700 space-y-2 text-sm">
                        <?php if ($adults > 0): ?>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::adults ?? 'Adults' ?> × <?= $adults ?>:</span>
                            <span><?= $cur ?> <?= number_format($adults * $adultPrice, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($children > 0): ?>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::children ?? 'Children' ?> × <?= $children ?>:</span>
                            <span><?= $cur ?> <?= number_format($children * $childPrice, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="flex justify-between text-lg font-bold text-gray-900 dark:text-gray-100 pt-2 border-t border-gray-200 dark:border-gray-700">
                            <span><?= T::total ?? 'Total' ?>:</span>
                            <span class="text-lg font-bold"><?= $cur ?> <?= number_format($grandTotal, 2) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
function busBookingForm() {
    return {
        hash: '<?= htmlspecialchars($bookingHash, ENT_QUOTES) ?>',
        submitting: false,
        showBookingLoader: false,
        error: '',
        sameAsGuest: true,
        formData: { selected_payment: '<?= htmlspecialchars($defaultGatewayId ?? '', ENT_QUOTES) ?>', special_requests: '', terms_accepted: false },
        guest: { title: '', first_name: '', last_name: '', email: '', phone: '', country_code: '' },
        passengers: <?= json_encode(array_map(fn($p) => ['type' => $p['type'], 'first_name' => '', 'last_name' => ''], $paxSlots)) ?>,
        init() {},
        handleGuestUpdate(detail) {
            if (!detail || !detail.primary_guest) return;
            const g = detail.primary_guest;
            this.guest = {
                title: g.title || '', first_name: g.first_name || '', last_name: g.last_name || '',
                email: g.email || '', phone: g.phone || '', country_code: g.country_code || ''
            };
            if (this.sameAsGuest) this.syncFirstFromGuest();
        },
        syncFirstFromGuest() {
            if (this.sameAsGuest && this.passengers.length) {
                this.passengers[0].first_name = this.guest.first_name || '';
                this.passengers[0].last_name = this.guest.last_name || '';
            }
        },
        submit() {
            if (this.submitting || !this.formData.terms_accepted) return;
            if (!this.guest.first_name || !this.guest.email) { this.error = '<?= T::fill_required_fields ?? 'Please fill the required fields' ?>'; window.scrollTo({top:0,behavior:'smooth'}); return; }
            if (this.passengers.some(passenger => !String(passenger.first_name || '').trim())) { this.error = 'Please enter the first name of every passenger'; return; }
            if (!this.formData.selected_payment) { this.error = '<?= T::select_payment_method ?? 'Please select a payment method' ?>'; return; }
            this.submitting = true; this.showBookingLoader = true; this.error = '';
            const guest = {
                first_name: this.guest.first_name, last_name: this.guest.last_name,
                email: this.guest.email, phone: this.guest.phone,
                phone_country_code: this.guest.country_code || '', country: this.guest.country_code || '',
                special_requests: this.formData.special_requests || ''
            };
            fetch('<?=root?>api/bus/booking/submit', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ hash: this.hash, guest, travellers: this.passengers, payment_gateway: this.formData.selected_payment })
            }).then(r => r.json()).then(res => {
                if (res && res.success && res.redirect_url) { window.location.href = res.redirect_url; }
                else { this.submitting = false; this.showBookingLoader = false; this.error = res.message || 'Could not complete booking'; }
            }).catch(() => { this.submitting = false; this.showBookingLoader = false; this.error = 'Network error'; });
        }
    };
}
</script>

<style>
    footer,
    header,
    .cart-button {
        display: none;
    }
</style>
