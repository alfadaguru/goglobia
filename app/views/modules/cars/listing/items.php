<?php
// ============================================================================
// CAR CARD ITEM TEMPLATE - PRODUCTION READY
// ============================================================================
// app/views/modules/cars/listing/items.php
@$SECURE or die('Access Denied!'); ?>

<script>
/**
 * ============================================================================
 * RENDER CAR CARD
 * ============================================================================
 * Generates HTML markup for car listing cards with image carousel,
 * pricing, specifications, and booking information.
 *
 * @param {Object} car - Car data from supplier
 * @returns {String} HTML markup for car card
 */
function renderCarCard(car) {
    // ============================================================================
    // SAFELY EXTRACT CAR DATA WITH FALLBACKS
    // ============================================================================
    const carName = car.name || car.vehicle_name || '<?=T::car?>';
    const carType = car.car_type || car.vehicle_class || 'standard';
    const price = parseFloat(car.price || car.total_price || car.display_price || 0);
    const pricePerDay = parseFloat(car.price_per_day || car.actual_price_per_day || car.display_price_per_day || 0);
    const currency = car.currency || 'USD';
    const supplier = car.supplier || car.supplier_name || 'N/A';
    const supplierColor = car.color || '#3B82F6';
    // Mozio supplier badge is admin-only; other suppliers still show for everyone.
    const isAdmin = <?= (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') ? 'true' : 'false' ?>;
    const showSupplierBadge = isAdmin || String(supplier).toLowerCase() !== 'mozio';

    // ============================================================================
    // IMAGE HANDLING
    // ============================================================================
    const images = car.images && car.images.length > 0 ? car.images :
                   (car.image ? [car.image] : []);
    const hasMultipleImages = images.length > 1;
    const imagesJson = JSON.stringify(images).replace(/'/g, "\\'");

    // ============================================================================
    // CAR SPECIFICATIONS
    // ============================================================================
    const passengers = car.passengers || car.max_passengers || 4;
    const doors = car.doors || car.number_of_doors || 4;
    const bags = car.bags || car.luggage || 2;
    const transmission = car.transmission || 'Automatic';
    const fuelPolicy = car.fuel_policy || 'Full to Full';
    const ac = car.air_conditioning || car.ac || true;

    // ============================================================================
    // FEATURES & INCLUSIONS
    // ============================================================================
    const features = car.features || [];
    const inclusions = car.inclusions || [];
    const unlimited_mileage = car.unlimited_mileage || false;

    // ============================================================================
    // RENTAL DETAILS
    // ============================================================================
    const _sp = window.searchParams || {};
    const pickup_location = car.pickup_location || _sp.pickup_location || '';
    const dropoff_location = car.dropoff_location || _sp.dropoff_location || '';
    const rental_days = car.rental_days || 1;
    // Only trust the per-result value from the supplier — never guess from
    // the searched params, or a one-way result could show a Round Trip badge
    // it was never actually confirmed for.
    const tripType = car.trip_type || 'one_way';
    const isRoundTrip = tripType === 'round_trip';
    const returnDatetime = car.return_datetime || null;

    // ============================================================================
    // BUILD CAR DETAIL URL
    // ============================================================================
    const carSlug = carName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    const carId = car.car_id || car.id || 'unknown';
    const detailUrl = `<?=root?>car/${carSlug}/${carId}/${supplier}`;

    // ============================================================================
    // SAFELY ESCAPE STRINGS FOR HTML
    // ============================================================================
    const escapedName = carName.replace(/"/g, '&quot;').replace(/'/g, "\\'");

    // ============================================================================
    // BUILD CARD HTML
    // ============================================================================
    return `
        <div x-data='(() => {
            let imgs = ${imagesJson};
            return {
                currentImage: 0,
                images: imgs,
                nextImage() { this.currentImage = (this.currentImage + 1) % this.images.length; },
                prevImage() { this.currentImage = this.currentImage === 0 ? this.images.length - 1 : this.currentImage - 1; }
            };
        })()'
        class="card overflow-hidden mb-4 p-0 dark:bg-gray-800 dark:border-gray-700">

            <div class="flex flex-col md:flex-row">

                <!-- ============================================================================
                     IMAGE CAROUSEL SECTION
                     ============================================================================ -->
                <div class="md:w-1/3 relative group">
                    <div class="block w-full h-full">
                        ${images.length > 0 ? `
                            <template x-for="(img, index) in images" :key="index">
                                <img x-show="currentImage === index"
                                     :src="img"
                                     alt="${escapedName}"
                                     class="w-full h-48 md:h-full object-contain p-4"
                                     loading="lazy">
                            </template>
                        ` : `
                            <div class="w-full h-48 md:h-full bg-gradient-to-br from-blue-100 to-indigo-200 dark:from-gray-700 dark:to-gray-800 flex items-center justify-center">
                                <span class="material-symbols-outlined text-gray-400 dark:text-gray-500" style="font-size: 40px;">directions_car</span>
                            </div>
                        `}
                    </div>

                    <!-- SLIDER BUTTONS -->
                    ${hasMultipleImages ? `
                        <button @click="prevImage()"
                                type="button"
                                style="transform: translateY(-50%);"
                                class="absolute left-2 top-1/2 bg-black/50 hover:bg-black/90 text-white rounded-full w-8 h-8 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
                            <span class="material-symbols-outlined" style="font-size: 20px;">chevron_left</span>
                        </button>

                        <button @click="nextImage()"
                                type="button"
                                style="transform: translateY(-50%);"
                                class="absolute right-2 top-1/2 bg-black/50 hover:bg-black/90 text-white rounded-full w-8 h-8 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
                            <span class="material-symbols-outlined" style="font-size: 20px;">chevron_right</span>
                        </button>

                        <div class="absolute bottom-2 right-2 bg-black/70 text-white px-2 py-0.5 rounded-full text-xs font-semibold">
                            <span x-text="currentImage + 1"></span>/<span x-text="images.length"></span>
                        </div>
                    ` : ''}

                    <!-- CAR TYPE BADGE -->
                    <div class="absolute top-2 left-2 bg-blue-600 text-white px-2 py-0.5 rounded text-xs font-bold capitalize">
                        ${carType}
                    </div>

                    <!-- SUPPLIER BADGE (Mozio label only for admin) -->
                    ${showSupplierBadge ? `
                    <div class="absolute top-2 right-2 text-white px-2 py-0.5 rounded text-xs font-bold capitalize" style="background-color: ${supplierColor};">
                        ${supplier}
                    </div>
                    ` : ''}
                </div>

                <!-- ============================================================================
                     CAR INFORMATION SECTION
                     ============================================================================ -->
                <div class="md:w-2/3 p-4 flex flex-col">

                    <!-- CAR NAME & CATEGORY -->
                    <div class="flex-1">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-2">
                            ${carName}
                        </h3>

                        <!-- SPECIFICATIONS ROW -->
                        <div class="flex flex-wrap gap-3 mb-3 text-sm text-gray-600 dark:text-gray-400">
                            <div class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">person</span>
                                <span>${passengers} <?=T::passengers?></span>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">luggage</span>
                                <span>${bags} <?=T::bags?></span>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">sensor_door</span>
                                <span>${doors} <?=T::doors?></span>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">settings</span>
                                <span>${transmission}</span>
                            </div>
                            ${ac ? `
                            <div class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">ac_unit</span>
                                <span><?=T::air?> <?=T::conditioning?></span>
                            </div>
                            ` : ''}
                        </div>

                        <!-- FEATURES & INCLUSIONS -->
                        <div class="flex flex-wrap gap-1 mb-2">
                            ${isRoundTrip ? `
                                <span class="px-2 py-0.5 bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded-full text-xs font-medium inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs">sync_alt</span>
                                    <?=T::round_trip?>
                                </span>
                            ` : ''}
                            ${unlimited_mileage ? `
                                <span class="px-2 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-full text-xs font-medium">
                                    <span class="material-symbols-outlined text-xs">check_circle</span>
                                    <?=T::unlimited?> <?=T::mileage?>
                                </span>
                            ` : ''}
                            ${inclusions.slice(0, 3).map(inc => `
                                <span class="px-2 py-0.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-full text-xs font-medium">
                                    ${inc}
                                </span>
                            `).join('')}
                        </div>

                        <!-- RENTAL / TRANSFER INFO -->
                        <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
                            ${(_sp.service_type || 'rental') === 'rental' ? `
                                <div class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs">calendar_today</span>
                                    <span>${rental_days} ${rental_days > 1 ? '<?=T::days?>' : '<?=T::day?>'}</span>
                                </div>
                            ` : ''}
                            ${isRoundTrip && returnDatetime ? `
                                <div class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs">event_repeat</span>
                                    <span><?=T::return?>: ${new Date(returnDatetime).toLocaleString()}</span>
                                </div>
                            ` : ''}
                        </div>
                    </div>

                    <!-- ============================================================================
                         PRICING & BOOKING SECTION
                         ============================================================================ -->
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-between items-end gap-3">

                        <!-- PRICE DISPLAY -->
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1"><?=T::total?> <?=T::price?></p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                ${currency} ${price.toFixed(2)}
                            </p>
                            ${pricePerDay > 0 && (car.service_type || 'rental') === 'rental' && pricePerDay < price ? `
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    ${currency} ${pricePerDay.toFixed(2)} <?=T::per?> <?=T::day?>
                                </p>
                            ` : ''}
                        </div>

                        <!-- BOOK NOW BUTTON -->
                        <button type="button" id="book-btn-${carId}"
                           onclick='window.bookCar(${JSON.stringify(car).replace(/'/g, "&#39;")}, window.searchParams || {})'
                           class="btn flex items-center justify-center gap-2 min-w-[120px]">
                            <span class="material-symbols-outlined text-lg">shopping_cart</span>
                            <span><?=T::book?> <?=T::now?></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ============================================================================
                 FOOTER WITH PICKUP/DROPOFF INFO
                 ============================================================================ -->
            <div class="border-t border-gray-200 dark:border-gray-700 px-4 py-2 bg-gray-50 dark:bg-gray-900">
                <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined" style="font-size: 14px;">location_on</span>
                        <span><?=T::pickup?>: ${pickup_location}</span>
                    </div>
                    ${car.service_type === 'hourly' && car.hourly_duration ? `
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined" style="font-size: 14px;">schedule</span>
                            <span>${car.hourly_duration} ${car.hourly_duration == 1 ? '<?=T::hour?>' : '<?=T::hours?>'}</span>
                        </div>
                    ` : (dropoff_location && dropoff_location !== pickup_location ? `
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined" style="font-size: 14px;">location_on</span>
                            <span><?=T::dropoff?>: ${dropoff_location}</span>
                        </div>
                    ` : '')}
                </div>
            </div>
        </div>
    `;
}

/**
 * ============================================================================
 * BOOK CAR FUNCTION
 * ============================================================================
 * Creates a booking draft and redirects to filling page.
 */
window.bookCar = function(car, searchParams) {
    const carId = car.car_id || car.id || 'unknown';
    const btn = document.getElementById(`book-btn-${carId}`);
    if (!btn) return;
    
    const originalContent = btn.innerHTML;
    
    // ============================================================
    // KIWITAXI: Direct affiliate redirect - no on-site booking
    // ============================================================
    const supplierKey = (car.supplier || car.supplier_name || '').toLowerCase();
    if (supplierKey === 'kiwitaxi') {
        const affiliateUrl = car.booking_url || car._raw?.booking_url || '';
        if (affiliateUrl) {
            window.open(affiliateUrl, '_blank');
            return;
        }
    }

    // ============================================================
    // ALL OTHER SUPPLIERS: Standard on-site booking flow
    // ============================================================
    
    btn.innerHTML = `
        <div class="flex items-center gap-2">
            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>Processing...</span>
        </div>
    `;
    btn.disabled = true;
    btn.classList.add('opacity-80', 'cursor-not-allowed', 'pointer-events-none');

    // Create payload
    const payload = {
        car_data: car,
        search_params: searchParams
    };

    // Send to API
    fetch('<?=root?>cars/booking/save-draft', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Keep animation visible during the initial redirect handoff
            setTimeout(() => {
                let target = data.redirect;
                try {
                    const u = new URL(target, window.location.href);
                    if (u.hostname !== window.location.hostname) {
                        target = window.location.origin + u.pathname + u.search;
                    }
                } catch (e) {}
                window.location.href = target;
            }, 500);
        } else {
            alert('Failed to proceed: ' + (data.error || 'Unknown error'));
            btn.innerHTML = originalContent;
            btn.disabled = false;
            btn.classList.remove('opacity-80', 'cursor-not-allowed', 'pointer-events-none');
        }
    })
    .catch(error => {
        console.error('Booking Error:', error);
        alert('Something went wrong. Please try again.');
        btn.innerHTML = originalContent;
        btn.disabled = false;
        btn.classList.remove('opacity-80', 'cursor-not-allowed', 'pointer-events-none');
    });
};
</script>

<style>
/* ============================================================================
   CAR CARDS STYLING
   ============================================================================ */
[x-cloak] {
    display: none !important;
}

.card {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}

@media (prefers-reduced-motion: no-preference) {
    [x-show] {
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
}

img {
    transition: opacity 0.2s ease-in-out;
}

button[class*="group-hover:opacity-100"]:hover {
    transform: scale(1.05) translateY(-50%);
}
</style>
