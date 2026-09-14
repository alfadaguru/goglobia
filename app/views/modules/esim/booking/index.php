<?php
@$SECURE or die('Access Denied!');

$countryCode = strtoupper($country ?? '');
$countryLabel = htmlspecialchars($countryName ?? $countryCode);
$packageType = strtoupper($type ?? 'ALL');
$defaultCurrency = htmlspecialchars($currency ?? 'USD');
$ajaxUrl = root . 'esim/' . (int) ($moduleId ?? 0) . '/' . strtolower($countryCode) . '/' . strtolower($type ?? 'all') . '/packages';
$submitUrl = root . 'esim/booking/submit';

$defaultPaymentId = '';
if (!empty($paymentGateways)) {
    $defaults = array_values(array_filter($paymentGateways, function ($gateway) {
        return (int) ($gateway['is_default'] ?? 0) === 1;
    }));
    if (!empty($defaults)) {
        $defaultPaymentId = (string) ($defaults[0]['id'] ?? '');
    } else {
        $defaultPaymentId = (string) ($paymentGateways[0]['id'] ?? '');
    }
}
?>

<div class="min-h-screen bg-slate-100" x-data="esimBookingFlow()" x-init="init()">
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
                            eSIM - <?= $countryLabel ?>
                        </h1>
                        <p class="text-sm text-slate-500 mt-1">
                            Select package and complete booking details
                        </p>
                    </div>
                </div>

                <div x-show="showAlert" class="mb-5" style="display:none;">
                    <div class="alert" :class="alertType === 'error' ? 'alert-error' : 'alert-success'">
                        <span class="material-symbols-outlined"
                            x-text="alertType === 'error' ? 'error' : 'check_circle'"></span>
                        <p x-text="alertMessage"></p>
                        <button @click="showAlert = false" class="ml-auto">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                <div x-show="loadingPackages" class="flex flex-col items-center justify-center py-20 gap-4">
                    <div class="w-10 h-10 border-4 border-blue-500 border-t-transparent rounded-full animate-spin">
                    </div>
                    <p class="text-slate-500 text-sm">Loading packages...</p>
                </div>

                <div x-show="!loadingPackages && packageError" class="alert alert-error mb-5">
                    <span class="material-symbols-outlined">error</span>
                    <p x-text="packageError"></p>
                </div>

                <div x-show="!loadingPackages && packageNotice && !packageError" class="alert alert-info mb-5">
                    <span class="material-symbols-outlined">info</span>
                    <p x-text="packageNotice"></p>
                </div>

                <form x-show="!loadingPackages && packages.length > 0" @submit.prevent="submitBooking" x-cloak>
                    <div @guest-updated.window="handleGuestUpdate($event.detail)">
                        <div class="card p-0 mb-5">
                            <div class="card-header">
                                <div><span class="card-header-icon">sim_card</span>
                                    <h3>Available Packages</h3>
                                </div>
                                <div class="flex gap-2 text-xs">
                                    <span class="badge"><?= $countryLabel ?></span>
                                    <span class="badge"><?= $packageType ?></span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="card p-0 mb-4">
                                    <div class="card-header">
                                        <div><span class="card-header-icon">tune</span>
                                            <h3>Filter Packages</h3>
                                        </div>
                                        <span class="text-sm text-slate-500"
                                            x-text="`${filteredPackages.length} result${filteredPackages.length !== 1 ? 's' : ''}`"></span>
                                    </div>
                                    <div class="card-body">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-2">Validity
                                                    Period</label>
                                                <select x-model="filterDuration" class="select input w-full text-sm">
                                                    <option value="">All Durations</option>
                                                    <template x-for="dur in durations" :key="dur">
                                                        <option :value="dur" x-text="dur"></option>
                                                    </template>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-2">Data
                                                    Limit</label>
                                                <select x-model="filterData" class="select input w-full text-sm">
                                                    <option value="">All Data Limits</option>
                                                    <template x-for="data in dataLimits" :key="data">
                                                        <option :value="data" x-text="data"></option>
                                                    </template>
                                                </select>
                                            </div>
                                            <div>
                                                <label
                                                    class="block text-sm font-medium text-slate-700 mb-2">Price</label>
                                                <select x-model="filterPrice" class="select input w-full text-sm">
                                                    <option value="">All Prices</option>
                                                    <template x-for="p in prices" :key="p">
                                                        <option :value="p"
                                                            x-text="`${currency} ${Number(p).toFixed(2)}`"></option>
                                                    </template>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div x-show="filteredPackages.length === 0"
                                    class="text-center py-8 text-slate-500 text-sm">
                                    No packages match your filters. Try adjusting them.
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <template x-for="pkg in filteredPackages" :key="pkg.id">
                                        <button type="button"
                                            class="text-left border rounded-xl p-4 transition-all hover:shadow-md"
                                            :class="selectedPackage && selectedPackage.id === pkg.id
                                                ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-400'
                                                : 'border-gray-200 bg-white hover:border-slate-300'"
                                            @click="selectedPackage = pkg; recalculateCoupon()">
                                            <div class="flex items-start justify-between gap-3 mb-3">
                                                <div>
                                                    <h4 class="font-semibold text-slate-900 text-sm" x-text="pkg.title">
                                                    </h4>
                                                    <p class="text-xs text-slate-400 mt-0.5" x-text="pkg.country"></p>
                                                </div>
                                                <div class="text-right">
                                                    <span class="text-base font-bold text-blue-600 whitespace-nowrap"
                                                        x-text="`${pkg.currency} ${Number(pkg.price).toFixed(2)}`"></span>
                                                    <p class="text-xs text-slate-400 mt-0.5 capitalize"
                                                        x-text="pkg.package_type || 'local'"></p>
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-2 gap-2 text-xs">
                                                <div class="bg-slate-50 rounded px-2 py-1.5">
                                                    <p class="uppercase tracking-wide text-slate-400 text-[10px]">Data
                                                    </p>
                                                    <p class="font-medium text-slate-700 mt-0.5"
                                                        x-text="pkg.data_limit"></p>
                                                </div>
                                                <div class="bg-slate-50 rounded px-2 py-1.5">
                                                    <p class="uppercase tracking-wide text-slate-400 text-[10px]">
                                                        Validity</p>
                                                    <p class="font-medium text-slate-700 mt-0.5" x-text="pkg.duration">
                                                    </p>
                                                </div>
                                            </div>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <?php include views . 'includes/booking/booking-auth.php'; ?>

                        <div class="card p-0 mb-5" x-data="{orderCollapsed:false}">
                            <div class="card-header cursor-pointer" @click="orderCollapsed = !orderCollapsed">
                                <div>
                                    <span class="card-header-icon text-[18px]">badge</span>
                                    <h3>Airalo Order Details</h3>
                                </div>
                                <span
                                    class="material-symbols-outlined text-gray-600 text-[20px] transition-transform duration-300"
                                    :class="orderCollapsed ? '' : 'rotate-180'">keyboard_arrow_down</span>
                            </div>
                            <div class="card-body" x-show="!orderCollapsed" x-collapse>
                                <p class="text-sm text-slate-600 mb-4">
                                    Airalo does not require traveler passport or date-of-birth details for order
                                    creation.
                                </p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-2">
                                    <div class="form-control">
                                        <label>Quantity</label>
                                        <input type="number" class="input"
                                            x-model.number="formData.airalo_order.quantity" min="1" max="50" required>
                                    </div>
                                    <div class="form-control">
                                        <label>Type</label>
                                        <select x-model="formData.airalo_order.type" class="select" required>
                                            <option value="sim">SIM</option>
                                            <option value="topup">Topup</option>
                                        </select>
                                    </div>
                                </div>

                                <div x-show="formData.airalo_order.type === 'topup'" x-cloak
                                    class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                                    <div class="form-control">
                                        <label>Topup Target Type</label>
                                        <select x-model="formData.airalo_order.topup_target_type" class="select"
                                            :required="formData.airalo_order.type === 'topup'">
                                            <option value="sim_iccid">SIM ICCID</option>
                                            <option value="sim_id">SIM ID</option>
                                        </select>
                                    </div>
                                    <div class="form-control">
                                        <label>Topup Target Number / ID</label>
                                        <input type="text" class="input" x-model="formData.airalo_order.topup_target"
                                            :required="formData.airalo_order.type === 'topup'"
                                            placeholder="Enter SIM ICCID or SIM ID">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php include views . 'includes/booking/payment-methods.php'; ?>

                        <div class="card p-0 mb-5">
                            <div class="card-header">
                                <div>
                                    <span class="card-header-icon">settings</span>
                                    <h3>Booking Options</h3>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="form-control mb-6">
                                    <label>Special Requests (Optional)</label>
                                    <textarea x-model="formData.special_requests" class="input" rows="3"
                                        placeholder="Enter any special requests or notes..."></textarea>
                                </div>

                                <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="terms_accepted" x-model="formData.terms_accepted"
                                                class="checkbox-input" required>
                                            <div class="checkbox-custom">
                                                <span
                                                    class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="terms_accepted"
                                            class="cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                                            I agree to the
                                            <a href="<?= root ?>page/terms-of-use" target="_blank"
                                                class="text-blue-600 hover:underline">Terms & Conditions</a>
                                            and
                                            <a href="<?= root ?>page/privacy-policy" target="_blank"
                                                class="text-blue-600 hover:underline">Privacy Policy</a>
                                        </label>
                                    </div>
                                </div>

                                <?= CSRF::tokenField() ?>

                                <button type="submit" class="btn w-full mt-6"
                                    :disabled="submitting || !selectedPackage || !formData.terms_accepted"
                                    :class="{ 'opacity-50 cursor-not-allowed': !selectedPackage || !formData.terms_accepted }">
                                    <span x-show="!submitting" class="material-symbols-outlined">lock</span>
                                    <span x-show="submitting"
                                        class="material-symbols-outlined animate-spin">progress_activity</span>
                                    <span x-show="!submitting">Confirm Booking</span>
                                    <span x-show="submitting">Processing...</span>
                                </button>

                                <!-- ADD TO CART BUTTON -->
                                <button type="button" @click="addToCart()"
                                    class="btn btn-outline w-full mt-3"
                                    :disabled="cartAdding || !selectedPackage"
                                    :class="{ 'opacity-50 cursor-not-allowed': cartAdding || !selectedPackage }">
                                    <span class="material-symbols-outlined"
                                        x-text="cartAdded ? 'check' : 'add_shopping_cart'"></span>
                                    <span x-text="cartAdding ? 'Adding…' : (cartAdded ? 'Added to cart' : 'Add to cart')"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

            </div><!-- Close bg-white card -->
        </div><!-- Close left column -->

        <!-- RIGHT: SUMMARY SIDEBAR -->
        <div class="order-1 lg:order-2 bg-slate-100 lg:sticky lg:top-0 lg:self-start lg:max-h-screen lg:overflow-y-auto">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pl-12 max-w-[550px] mx-auto lg:mr-auto lg:mx-0 w-full">
                <div class="sticky top-6 space-y-4">
                <div class="card p-0 mb-4">
                    <div class="card-header">
                        <div><span class="card-header-icon">receipt_long</span>
                            <h3>Booking Summary</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <template x-if="!selectedPackage">
                            <div class="text-center py-6 text-slate-400">
                                <span class="material-symbols-outlined text-4xl mb-2 block">sim_card</span>
                                <p class="text-sm">Select a package to see details</p>
                            </div>
                        </template>
                        <template x-if="selectedPackage">
                            <div>
                                <div class="flex items-start justify-between mb-3">
                                    <div>
                                        <p class="text-xs text-slate-400 uppercase tracking-wide">Package</p>
                                        <h4 class="font-semibold text-slate-900 text-sm mt-0.5"
                                            x-text="selectedPackage.title"></h4>
                                        <p class="text-xs text-slate-500 mt-0.5" x-text="selectedPackage.country"></p>
                                    </div>
                                    <button @click="selectedPackage = null; recalculateCoupon()"
                                        class="text-slate-400 hover:text-slate-600 ml-2">
                                        <span class="material-symbols-outlined text-sm">close</span>
                                    </button>
                                </div>
                                <div class="space-y-2 text-sm border-t border-slate-200 pt-3">
                                    <div class="flex justify-between">
                                        <span class="text-slate-500">Data</span>
                                        <span class="font-medium" x-text="selectedPackage.data_limit"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-500">Validity</span>
                                        <span class="font-medium" x-text="selectedPackage.duration"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-500">Type</span>
                                        <span class="font-medium capitalize"
                                            x-text="selectedPackage.package_type || 'standard'"></span>
                                    </div>
                                </div>
                                <template x-if="couponDiscount > 0">
                                    <div class="flex justify-between text-sm mt-3 text-green-600">
                                        <span>Discount (<span x-text="couponCode"></span>)</span>
                                        <span
                                            x-text="`-${selectedPackage.currency} ${couponDiscount.toFixed(2)}`"></span>
                                    </div>
                                </template>
                                <div class="flex justify-between items-center border-t border-slate-200 pt-3 mt-3">
                                    <span class="font-semibold text-slate-800">Total</span>
                                    <span class="text-xl font-bold text-blue-600"
                                        x-text="selectedPackage ? `${selectedPackage.currency} ${getFinalTotal().toFixed(2)}` : `${currency} 0.00`"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="card p-0">
                    <div class="card-header">
                        <div><span class="card-header-icon">local_offer</span>
                            <h3>Coupon Code</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="flex gap-2">
                            <input type="text" placeholder="Enter coupon code" class="input flex-1 text-sm"
                                x-model="couponCode" :disabled="couponApplied" @keydown.enter.prevent="applyCoupon()">
                            <button type="button" class="btn secondary px-4 text-sm"
                                @click="couponApplied ? removeCoupon() : applyCoupon()">
                                <span x-text="couponApplied ? 'Remove' : 'Apply'"></span>
                            </button>
                        </div>
                        <template x-if="couponApplied">
                            <div
                                class="mt-3 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700 flex items-center gap-2">
                                <span class="material-symbols-outlined text-base">check_circle</span>
                                Promo code applied - discount added
                            </div>
                        </template>
                        <template x-if="couponError">
                            <div
                                class="mt-3 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-600 flex items-center gap-2">
                                <span class="material-symbols-outlined text-base">error</span>
                                <span x-text="couponError"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<style>
    footer,
    header,
    .cart-button {
        display: none;
    }
</style>

<script>
    function esimBookingFlow() {
        return {
            loadingPackages: true,
            packageError: null,
            packageNotice: '',
            packages: [],
            durations: [],
            dataLimits: [],
            prices: [],
            filterDuration: '',
            filterData: '',
            filterPrice: '',
            selectedPackage: null,
            currency: '<?= $defaultCurrency ?>',

            showAlert: false,
            alertType: 'error',
            alertMessage: '',
            submitting: false,
            cartAdding: false,
            cartAdded: false,

            couponCode: '',
            couponApplied: false,
            couponDiscount: 0,
            couponError: null,

            formData: {
                primary_guest: {
                    title: '',
                    first_name: '',
                    last_name: '',
                    email: '',
                    country_code: 'US',
                    phone: ''
                },
                booking_for_someone_else: false,
                airalo_order: {
                    quantity: 1,
                    type: 'sim',
                    topup_target_type: 'sim_iccid',
                    topup_target: ''
                },
                selected_payment: '<?= htmlspecialchars($defaultPaymentId, ENT_QUOTES, 'UTF-8') ?>',
                special_requests: '',
                terms_accepted: false,
            },

            get filteredPackages() {
                return this.packages.filter(pkg => {
                    if (this.filterDuration && pkg.duration !== this.filterDuration) return false;
                    if (this.filterData && pkg.data_limit !== this.filterData) return false;
                    if (this.filterPrice !== '' && Number(pkg.price) !== Number(this.filterPrice)) return false;
                    return true;
                });
            },

            getFinalTotal() {
                if (!this.selectedPackage) return 0;
                return Math.max(0, Number(this.selectedPackage.price || 0) - Number(this.couponDiscount || 0));
            },

            showToast(message, type = 'error') {
                this.alertMessage = message;
                this.alertType = type;
                this.showAlert = true;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },

            handleGuestUpdate(data) {
                if (data.primary_guest) {
                    this.formData.primary_guest = { ...this.formData.primary_guest, ...data.primary_guest };
                }
                if (typeof data.booking_for_someone_else !== 'undefined') {
                    this.formData.booking_for_someone_else = data.booking_for_someone_else;
                }
                if (this.formData.airalo_order.quantity < 1) {
                    this.formData.airalo_order.quantity = 1;
                }
            },

            async recalculateCoupon() {
                if (!this.selectedPackage) {
                    this.couponDiscount = 0;
                    return;
                }
                if (this.couponApplied) {
                    const code = this.couponCode.trim().toUpperCase();
                    if (code === 'ESIM10') {
                        this.couponDiscount = +(Number(this.selectedPackage.price || 0) * 0.10).toFixed(2);
                    } else {
                        try {
                            const res = await fetch('<?= root ?>api/promo/validate', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    code: code,
                                    module: 'esim',
                                    order_amount: Number(this.selectedPackage.price || 0),
                                    currency: this.selectedPackage.currency || this.currency
                                })
                            });
                            const json = await res.json();
                            if (json.success && json.data) {
                                this.couponDiscount = Number(json.data.discount_amount || 0);
                                this.couponError = null;
                            } else {
                                this.couponApplied = false;
                                this.couponDiscount = 0;
                                this.couponError = json.message || 'Coupon is not valid for this package.';
                            }
                        } catch (e) {
                            this.couponApplied = false;
                            this.couponDiscount = 0;
                            this.couponError = 'Error validating coupon code.';
                        }
                    }
                }
            },

            async applyCoupon() {
                this.couponError = null;
                if (!this.selectedPackage) {
                    this.couponError = 'Select a package first.';
                    return;
                }
                const code = this.couponCode.trim().toUpperCase();
                if (!code) {
                    this.couponError = 'Please enter a coupon code.';
                    return;
                }
                if (code === 'ESIM10') {
                    this.couponApplied = true;
                    this.recalculateCoupon();
                } else {
                    try {
                        const res = await fetch('<?= root ?>api/promo/validate', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                code: code,
                                module: 'esim',
                                order_amount: Number(this.selectedPackage.price || 0),
                                currency: this.selectedPackage.currency || this.currency
                            })
                        });
                        const json = await res.json();
                        if (json.success && json.data) {
                            this.couponApplied = true;
                            this.couponDiscount = Number(json.data.discount_amount || 0);
                            this.couponError = null;
                        } else {
                            this.couponApplied = false;
                            this.couponDiscount = 0;
                            this.couponError = json.message || 'Invalid or expired coupon code.';
                        }
                    } catch (e) {
                        this.couponApplied = false;
                        this.couponDiscount = 0;
                        this.couponError = 'Error validating coupon code.';
                    }
                }
            },

            removeCoupon() {
                this.couponCode = '';
                this.couponApplied = false;
                this.couponDiscount = 0;
                this.couponError = null;
            },

            async loadPackages() {
                this.loadingPackages = true;
                this.packageError = null;
                this.packageNotice = '';
                try {
                    const res = await fetch('<?= $ajaxUrl ?>');
                    const json = await res.json();
                    if (!json.success) throw new Error(json.message || 'Failed to load packages');

                    this.packages = Array.isArray(json.packages) ? json.packages : [];
                    this.packageNotice = json.message || '';

                    if (this.packages.length === 0) {
                        this.packageError = 'No packages found for this country and type.';
                        return;
                    }

                    this.packages.sort((a, b) => Number(a.price || 0) - Number(b.price || 0));
                    this.selectedPackage = this.packages[0];
                    this.currency = this.selectedPackage.currency || this.currency;

                    const durSet = {};
                    const dataSet = {};
                    const priceSet = {};
                    this.packages.forEach(p => {
                        if (p.duration) durSet[p.duration] = true;
                        if (p.data_limit) dataSet[p.data_limit] = true;
                        if (Number(p.price) > 0) priceSet[Number(p.price).toFixed(2)] = true;
                    });

                    this.durations = Object.keys(durSet).sort();
                    this.dataLimits = Object.keys(dataSet).sort();
                    this.prices = Object.keys(priceSet).map(Number).sort((a, b) => a - b);
                } catch (e) {
                    this.packageError = e.message || 'An error occurred while loading packages.';
                } finally {
                    this.loadingPackages = false;
                }
            },

            // Add the selected eSIM package to the general cart. The cart
            // (app/routes/cartRoutes.php) trusts the package sell price but
            // re-validates the country is active in airalo_countries.
            async addToCart() {
                if (this.cartAdding) return;
                if (!this.selectedPackage) {
                    this.showToast('Please select a package first.');
                    return;
                }
                this.cartAdding = true;
                try {
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                        || document.querySelector('input[name="csrf_token"]')?.value || '';
                    const iso = '<?= htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') ?>';
                    const qty = Math.max(1, Number(this.formData.airalo_order.quantity || 1));
                    const draft = {
                        country: iso,
                        selected_package: this.selectedPackage,
                        qty: qty,
                    };
                    const res = await fetch('<?= root ?>cart/add', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify({
                            csrf_token: csrf,
                            module: 'esim',
                            ref: iso,
                            qty: qty,
                            title: this.selectedPackage.title || ('eSIM - ' + iso),
                            image: '',
                            pax: { qty: qty },
                            draft: draft
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        this.cartAdded = true;
                        window.dispatchEvent(new CustomEvent('cart:updated', { detail: json.cart }));
                        setTimeout(() => { this.cartAdded = false; }, 2500);
                    } else {
                        this.showToast(json.message || 'Could not add to cart.');
                    }
                } catch (e) {
                    console.error('Add to cart error:', e);
                    this.showToast('Could not add to cart. Please try again.');
                }
                this.cartAdding = false;
            },

            async submitBooking() {
                if (!this.selectedPackage) {
                    this.showToast('Please select a package first.');
                    return;
                }
                if (!this.formData.terms_accepted) {
                    this.showToast('Please accept terms and conditions to proceed.');
                    return;
                }

                if (this.formData.airalo_order.type === 'topup' && !String(this.formData.airalo_order.topup_target || '').trim()) {
                    this.showToast('Please enter the topup target number or ID.');
                    return;
                }

                const payload = {
                    module_id: <?= (int) ($moduleId ?? 0) ?>,
                    country: '<?= htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') ?>',
                    package_type: '<?= htmlspecialchars(strtolower($type ?? 'all'), ENT_QUOTES, 'UTF-8') ?>',
                    selected_package: this.selectedPackage,
                    guest_details: {
                        primary_guest: this.formData.primary_guest,
                        booking_for_someone_else: this.formData.booking_for_someone_else
                    },
                    airalo_order: this.formData.airalo_order,
                    selected_payment: this.formData.selected_payment,
                    special_requests: this.formData.special_requests,
                    coupon_code: this.couponApplied ? this.couponCode.trim().toUpperCase() : '',
                    coupon_discount: this.couponApplied ? this.couponDiscount : 0,
                    terms_accepted: this.formData.terms_accepted,
                    csrf_token: document.querySelector('input[name="csrf_token"]')?.value || ''
                };

                this.submitting = true;
                this.showBookingLoader = true;
                this.showAlert = false;

                try {
                    const res = await fetch('<?= $submitUrl ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    });
                    const json = await res.json();
                    if (!json.success) {
                        throw new Error(json.message || 'Failed to confirm booking');
                    }

                    this.showToast(json.message || 'Booking created successfully.', 'success');
                    if (json.redirect_url) {
                        window.location.href = json.redirect_url;
                    }
                } catch (e) {
                    this.showToast(e.message || 'Booking failed. Please try again.');
                } finally {
                    this.submitting = false;
                    this.showBookingLoader = false;
                }
            },

            init() {
                this.loadPackages();
            }
        };
    }
</script>