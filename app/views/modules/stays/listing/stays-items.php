<!--
============================================================================
HOTEL CARD ITEM TEMPLATE - PRODUCTION READY
============================================================================
Compact, professional hotel listing cards with optimized design:
- Multi-image carousel with black transparent navigation arrows
- Minimalist card layout for maximum content density
- Star ratings, amenities, and pricing in compact format
- Smooth transitions and responsive design
- Admin room details section (commented out for production)

DEPENDENCIES:
- Alpine.js v3 for reactivity (image carousel state management)
- Material Symbols Outlined icons
- Tailwind CSS utility framework

PERFORMANCE:
- Lazy image loading with fallback placeholders
- Optimized JSON escaping for Alpine.js compatibility
- Minimal DOM elements for fast rendering

@author Development Team
@version 3.0 - Production Release
@since December 2025
============================================================================
-->
<script>
    /**
     * STORE HOTEL DATA FOR DETAILS PAGE
     * Saves clicked hotel info to localStorage for retrieval on detail page
     */
    function storeHotelData(hotel) {
        const hotelData = {
            address: hotel.address || '',
            city: hotel.city || '',
            country: hotel.country || '',
            amenities: hotel.amenities || [],
            rating: hotel.rating || 0,
            stars: hotel.stars || 0
        };
        localStorage.setItem('last_hotel', JSON.stringify(hotelData));
    }

    /**
     * BUILD HOTEL URL - Simple SEO-friendly URL builder
     */
    function buildHotelUrl(h, searchParams, roomsData) {
        const slug = h.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        const hotelId = h.original_id || h.hotel_id || h.id || 'unknown';
        const rooms = roomsData.map(r => {
            const ages = r.childAges && r.childAges.length > 0 ? '-' + r.childAges.join('-') : '';
            return `${r.adults}-${r.children}${ages}`;
        }).join('/');

        const hotelChain = h.original_data.chain || h.hotel_chain || '_';

        return `<?= root ?>stay/${slug}/${hotelId}/${h.supplier.toLowerCase()}/${hotelChain}/${searchParams.checkin}/${searchParams.checkout}/${searchParams.nationality}/${searchParams.rooms}/${rooms}`;
    }

    /**
     * RENDER HOTEL CARD
     * ============================================================================
     * Generates compact HTML markup for hotel listing cards with carousel,
     * pricing, amenities, and booking information.
     *
     * @param {Object} h - Normalized hotel object from API
     * @param {number} idx - Card index for unique identification
     * @returns {string} Complete HTML string for hotel card component
     */
    function renderHotelCard(h, idx) {

        // Build hotel detail URL
        const hotelUrl = buildHotelUrl(h, this.searchParams, this.roomsData);

        // Star rating display - SVG stars
        const starSVG = (filled) => `
        <svg class="w-4 h-4 -ml-1 ${filled ? 'text-orange-500' : 'text-gray-300'}" fill="currentColor" viewBox="0 0 20 20">
            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
        </svg>
    `;
        const starsHTML = h.stars ? Array.from({ length: 5 }, (_, i) => starSVG(i < h.stars)).join('') : '';

        // Image carousel setup with proper JSON escaping for Alpine.js
        const images = h.images && h.images.length > 0 ? h.images : (h.image ? [h.image] : []);
        const hasMultipleImages = images.length > 1;
        const imagesJson = JSON.stringify(images).replace(/'/g, "\\'");
        const displayAddress = h.address || '';
        const displayCity = h.city || h.location || h.original_data?.city || '';
        const locationText = displayAddress && displayCity && !displayAddress.toLowerCase().includes(displayCity.toLowerCase())
            ? `${displayAddress}, ${displayCity}`
            : (displayAddress || displayCity);

        // Build compact card HTML
        return `
        <div x-data='(() => {
            const defaultImg = ${JSON.stringify(h.image || '')};
            let imgs = ${imagesJson};
            // Reorder images if default exists
            if (defaultImg && imgs.length > 0) {
                const idx = imgs.indexOf(defaultImg);
                if (idx > 0) {
                    imgs = [...imgs];
                    const [item] = imgs.splice(idx, 1);
                    imgs.unshift(item);
                }
            }
            return {
                currentImage: 0,
                images: imgs,
                nextImage() { this.currentImage = (this.currentImage + 1) % this.images.length; },
                prevImage() { this.currentImage = this.currentImage === 0 ? this.images.length - 1 : this.currentImage - 1; }
            };
        })()'
        class="card overflow-hidden mb-3 p-0">

            <div class="flex flex-col md:flex-row">

                <!-- IMAGE CAROUSEL SECTION -->
                <div class="w-full h-44 md:w-[280px] md:h-auto md:self-stretch shrink-0 relative group overflow-hidden">
                    <!-- Main Image Link -->
                    <a href="${h.original_data?.redirect || hotelUrl}" 
                            onclick="storeHotelData({address: decodeURIComponent('${encodeURIComponent(h.address || '')}'), city: decodeURIComponent('${encodeURIComponent(h.city || '')}'), country: decodeURIComponent('${encodeURIComponent(h.country || '')}'), amenities: JSON.parse(decodeURIComponent('${encodeURIComponent(JSON.stringify(h.amenities || []))}')), rating: ${h.rating || 0}, stars: ${h.stars || 0}});"
                            target="_blank" rel="noopener noreferrer"
                            class="absolute inset-0 block">

                        ${images.length > 0 ? `
                            <template x-for="(img, index) in images" :key="index">
                                <img x-show="currentImage === index"
                                     :src="img"
                                     alt="${h.name.replace(/"/g, '&quot;')}"
                                     class="w-full h-full object-cover"
                                     x-transition:enter="transition ease-out duration-200"
                                     x-transition:enter-start="opacity-0"
                                     x-transition:enter-end="opacity-100"
                                     onerror="this.onerror=null;this.src='<?= root ?>uploads/no_img.jpg'">
                            </template>
                        ` : `
                            <div class="w-full h-full bg-gradient-to-br from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-800 flex items-center justify-center">
                                <span class="material-symbols-outlined text-gray-400 dark:text-gray-500" style="font-size: 32px;">hotel</span>
                            </div>
                        `}
                    </a>

                    <!-- SLIDER BUTTONS -->
                    ${hasMultipleImages ? `
                        <!-- Left Arrow - Black Transparent Background -->
                        <button @click="prevImage()"
                                type="button"
                                style="transform: translateY(-50%);"
                                class="absolute left-2 top-1/2 bg-black/50 hover:bg-black/90 text-white rounded-full w-7 h-7 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
                            <span class="material-symbols-outlined" style="font-size: 18px; font-variation-settings: 'wght' 300;">chevron_left</span>
                        </button>

                        <!-- Right Arrow - Black Transparent Background -->
                        <button @click="nextImage()"
                                type="button"
                                style="transform: translateY(-50%);"
                                class="absolute right-2 top-1/2 bg-black/50 hover:bg-black/90 text-white rounded-full w-7 h-7 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
                            <span class="material-symbols-outlined" style="font-size: 18px; font-variation-settings: 'wght' 300;">chevron_right</span>
                        </button>

                        <!-- Image Counter -->
                        <div class="absolute bottom-2 right-2 bg-black/70 text-white px-2 py-0.5 rounded-full text-xs font-semibold">
                            <span x-text="currentImage + 1"></span>/<span x-text="images.length"></span>
                        </div>
                    ` : ''}

                    <!-- Star Rating Badge -->
                    ${h.stars ? `
                        <div class="absolute top-2 left-2 bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold flex items-center gap-1">
                            <svg class="w-3.5 h-3.5 text-orange-500" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                            <span>${h.stars}.0</span>
                        </div>
                    ` : ''}

                    <!-- Supplier Badge -->
                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') { ?>
                        <div class="absolute top-2 right-2 bg-blue-600 text-white px-2 py-0.5 rounded text-xs font-bold uppercase">
                            ${h.supplier}
                        </div>
                    <?php } ?>
                </div>

                <!-- HOTEL INFORMATION SECTION -->
                <div class="flex-1 p-3 flex flex-col min-w-0">

                    <!-- Hotel Name & Location -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-0.5 line-clamp-1">
                            ${h.name}
                        </h3>

                        <div class="flex items-start gap-1.5 mb-2">
                            <span class="material-symbols-outlined text-gray-500 dark:text-gray-400" style="font-size: 16px;">location_on</span>
                            <span class="text-xs text-gray-600 dark:text-gray-400 line-clamp-1">
                                ${locationText}
                            </span>
                        </div>

                        <!-- Star Rating Display -->
                        ${h.stars ? `
                            <div class="flex items-center gap-1 mb-2">
                                <div class="flex -gap-1">${starsHTML}</div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">(${h.stars}.0)</span>
                            </div>
                        ` : ''}

                        <!-- Amenities & Badges Row -->
                        <div class="flex flex-wrap gap-1 mt-2">
                            ${h.original_data && h.original_data.amenities && h.original_data.amenities.length > 0 ?
                                h.original_data.amenities.slice(0, 3).map(amenity => `
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                                        ${typeof amenity === 'string' ? amenity : (amenity?.name || amenity?.label || '')}
                                    </span>
                                `).join('') : ''
                            }
                            ${h.original_data && h.original_data.amenities && h.original_data.amenities.length > 3 ? `
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                    +${h.original_data.amenities.length - 3}
                                </span>
                            ` : ''}
                            ${h.original_data && h.original_data.refundable ? `
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300">
                                    <span class="material-symbols-outlined" style="font-size: 14px;">check_circle</span>
                                    <span><?= T::refundable ?></span>
                                </span>
                            ` : ''}
                            ${(String(h.supplier || h.original_data?.supplier || '').toLowerCase() === 'hotelbeds' && (h.original_data?.zone_name || h.original_data?.chain_name)) ? `
                                ${h.original_data.zone_name ? `<span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200">${h.original_data.zone_name}</span>` : ''}
                                ${h.original_data.chain_name ? `<span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200">${h.original_data.chain_name}</span>` : ''}
                            ` : ''}
                            ${h.original_data && h.original_data.discount && h.original_data.discount > 0 ? `
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300">
                                    <span class="material-symbols-outlined" style="font-size: 14px;">local_offer</span>
                                    <span>${h.original_data.discount}% <?= T::off ?></span>
                                </span>
                            ` : ''}
                        </div>
                    </div>

                    <!-- PRICING & BOOKING SECTION -->
                    <div class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2">

                        <!-- Price Display -->
                        <div class="w-full sm:w-auto">
                            ${h.has_available_rooms ? `
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5">
                                    <?= T::from ?>
                                </p>

                                <!-- Price + per night in same line -->
                                <div class="flex items-baseline gap-2">
                                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 leading-none mb-0">
                                        ${h.currency} ${h.price_per_night.toFixed(2)}
                                    </p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-0"><?= T::per ?> <?= T::night ?> </p>
                                </div>
                                <!-- Total -->
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-0"> ${h.currency} ${h.price.toFixed(2)} <?= T::total ?> </p>
                            ` : `
                                <p class="text-sm text-amber-600 dark:text-amber-400 font-semibold">   <?= T::contact ?> <?= T::for ?> <?= T::pricing ?></p>
                            `}
                        </div>

                        <!-- Book Button -->
                        <div class="w-full sm:w-auto">
                            ${h.has_available_rooms ? `
                                <a href="${h.original_data?.redirect || hotelUrl}" 
                                onclick="storeHotelData({address: decodeURIComponent('${encodeURIComponent(h.address || '')}'), city: decodeURIComponent('${encodeURIComponent(h.city || '')}'), country: decodeURIComponent('${encodeURIComponent(h.country || '')}'), amenities: JSON.parse(decodeURIComponent('${encodeURIComponent(JSON.stringify(h.amenities || []))}')), rating: ${h.rating || 0}, stars: ${h.stars || 0}});"
                                target="_blank" rel="noopener noreferrer"
                                class="btn w-full sm:w-auto flex items-center justify-center gap-1">
                                    <span><?= T::more ?> <?= T::details ?></span>
                                    <span class="material-symbols-outlined" style="font-size: 14px;">open_in_new</span>
                                </a>
                            ` : `
                                <span class="btn bg-gray-400 text-white px-5 py-2.5 font-semibold rounded-lg cursor-not-allowed opacity-60 w-full sm:w-auto flex items-center justify-center">
                                    <?= T::no ?> <?= T::rooms ?>
                                </span>
                            `}
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER - Supplier Info (COMMENTED OUT FOR PRODUCTION) -->
            <!--
            <div class="border-t border-gray-200 dark:border-gray-700 px-4 py-2 bg-gray-50 dark:bg-gray-900">
                <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                    <span class="material-symbols-outlined" style="font-size: 14px;">business</span>
                    <span><?= T::powered ?> <?= T::by ?> ${h.supplier}</span>
                    <span class="w-1 h-1 bg-gray-400 rounded-full"></span>
                    <span>${h.currency}</span>
                </div>
            </div>
            -->

            <!--
            ============================================================================
            ADMIN ROOM DETAILS SECTION - COMMENTED OUT FOR PRODUCTION
            ============================================================================
            This section displays detailed room options with pricing for admin users.
            Uncomment the section below to enable admin-only room details view.

            Features:
            - Expandable accordion with room type breakdown
            - Capacity information (adults/children)
            - Room amenities with badges
            - Per-night pricing for each room option
            - Number of available booking options per room

            To enable:
            1. Uncomment the entire PHP block below (lines 196-357)
            2. Ensure $_SESSION['user_role'] is set to 'admin' for authorized users
            3. Add back the expanded: false property to Alpine.js x-data above (line 78)

            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') { ?>

                <div x-show="expanded"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 transform -translate-y-2"
                     x-transition:enter-end="opacity-100 transform translate-y-0"
                     x-transition:leave="transition ease-in duration-200"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     class="border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-4">

                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-blue-600 dark:text-blue-400" style="font-size: 20px;">meeting_room</span>
                        <h4 class="text-base font-bold text-gray-900 dark:text-gray-100"><?= T::available ?>     <?= T::rooms ?></h4>
                        <span class="ml-auto text-xs text-gray-500 dark:text-gray-400">
                            ${h.room_options?.length || 0} <?= T::type ?>${h.room_options?.length > 1 ? 's' : ''}
                        </span>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">

                                <thead class="bg-gray-100 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 text-left text-xs font-bold text-gray-700 dark:text-gray-300 uppercase">
                                            <?= T::room ?>
                                        </th>
                                        <th scope="col" class="px-3 py-2 text-left text-xs font-bold text-gray-700 dark:text-gray-300 uppercase">
                                            <?= T::capacity ?>
                                        </th>
                                        <th scope="col" class="px-3 py-2 text-left text-xs font-bold text-gray-700 dark:text-gray-300 uppercase">
                                            <?= T::amenities ?>
                                        </th>
                                        <th scope="col" class="px-3 py-2 text-right text-xs font-bold text-gray-700 dark:text-gray-300 uppercase">
                                            <?= T::price ?>
                                        </th>
                                        <th scope="col" class="px-3 py-2 text-center text-xs font-bold text-gray-700 dark:text-gray-300 uppercase">
                                            <?= T::options ?>
                                        </th>
                                    </tr>
                                </thead>

                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">

                                    ${h.room_options && h.room_options.length > 0 ? h.room_options.map((room, roomIdx) => `
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">

                                        <td class="px-3 py-2">
                                            <div class="flex items-center gap-2">
                                                <img src="${room.room_main_image || 'data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Crect width=\'60\' height=\'60\' fill=\'%23f3f4f6\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' font-family=\'Arial\' font-size=\'10\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3ERoom%3C/text%3E%3C/svg%3E'}"
                                                     alt="${(room.room_name || 'Room').replace(/"/g, '&quot;')}"
                                                     class="w-12 h-12 rounded object-cover border border-gray-200 dark:border-gray-600"
                                                     onerror="this.src='data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Crect width=\'60\' height=\'60\' fill=\'%23f3f4f6\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' font-family=\'Arial\' font-size=\'10\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3ERoom%3C/text%3E%3C/svg%3E'">
                                                <div>
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 line-clamp-1">
                                                        ${room.room_name || '<?= T::standard ?>     <?= T::room ?>'}
                                                    </p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                                        #${roomIdx + 1}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="px-3 py-2">
                                            <div class="flex flex-col gap-0.5">
                                                <div class="flex items-center gap-1 text-xs text-gray-700 dark:text-gray-300">
                                                    <span class="material-symbols-outlined text-blue-600" style="font-size: 14px;">person</span>
                                                    <span>${room.max_adults} <?= T::adults ?></span>
                                                </div>
                                                ${room.max_children > 0 ? `
                                                    <div class="flex items-center gap-1 text-xs text-gray-700 dark:text-gray-300">
                                                        <span class="material-symbols-outlined text-green-600" style="font-size: 14px;">child_care</span>
                                                        <span>${room.max_children} <?= T::children ?></span>
                                                    </div>
                                                ` : ''}
                                            </div>
                                        </td>

                                        <td class="px-3 py-2">
                                            ${room.amenities && room.amenities.length > 0 ? `
                                                <div class="flex flex-wrap gap-0.5 max-w-xs">
                                                    ${room.amenities.slice(0, 2).map(amenity => `
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300">
                                                            ${typeof amenity === 'string' ? amenity : (amenity?.name || amenity?.label || '<?= T::amenity ?>')}
                                                        </span>
                                                    `).join('')}
                                                    ${room.amenities.length > 2 ? `
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                                            +${room.amenities.length - 2}
                                                        </span>
                                                    ` : ''}
                                                </div>
                                            ` : `
                                                <span class="text-xs text-gray-400 dark:text-gray-500">-</span>
                                            `}
                                        </td>

                                        <td class="px-3 py-2 text-right">
                                            <div class="inline-flex flex-col items-end">
                                                <span class="text-base font-bold text-green-600 dark:text-green-400">
                                                    ${h.currency} ${room.min_price_per_night.toFixed(2)}
                                                </span>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    <?= T::per ?> <?= T::night ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td class="px-3 py-2 text-center">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-bold bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300">
                                                ${room.options_count}
                                            </span>
                                        </td>
                                    </tr>
                                `).join('') : `
                                    <tr>
                                        <td colspan="5" class="px-4 py-6 text-center">
                                            <div class="flex flex-col items-center gap-2">
                                                <span class="material-symbols-outlined text-gray-400" style="font-size: 32px;">hotel_class</span>
                                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                                    <?= T::no ?> <?= T::room ?> <?= T::options ?> <?= T::available ?>
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                `}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                    <div class="flex items-center gap-3">
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined" style="font-size: 14px;">attach_money</span>
                            <span><?= T::prices ?> <?= T::include ?> <?= T::taxes ?></span>
                        </span>
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined" style="font-size: 14px;">verified</span>
                            <span><?= T::verified ?> <?= T::by ?> ${h.supplier}</span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="border-t border-gray-200 dark:border-gray-700 px-4 py-2 bg-gray-50 dark:bg-gray-900">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                        <span class="material-symbols-outlined" style="font-size: 14px;">business</span>
                        <span>${h.supplier}</span>
                        <span class="w-1 h-1 bg-gray-400 rounded-full"></span>
                        <span>${h.currency}</span>
                    </div>
                    <button @click="expanded = !expanded"
                            type="button"
                            class="flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded transition-colors">
                        <span x-text="expanded ? '<?= T::hide ?>' : '<?= T::view ?>'"></span>
                        <span class="material-symbols-outlined transition-transform"
                              :class="expanded && 'rotate-180'"
                              style="font-size: 16px;">expand_more</span>
                    </button>
                </div>
            </div>
            <?php } ?>

            END OF COMMENTED ADMIN SECTION
            ============================================================================
            -->
        </div>
    `;
    }
</script>

<style>
    /**
 * CUSTOM STYLES FOR HOTEL CARDS
 * ============================================================================
 */

    /* Hide Alpine.js elements before initialization */
    [x-cloak] {
        display: none !important;
    }

    /* Star rating glow effect for visual appeal */
    .text-yellow-400 {
        text-shadow: 0 0 6px rgba(251, 191, 36, 0.4);
    }


    /* Smooth transitions for Alpine.js show/hide */
    @media (prefers-reduced-motion: no-preference) {
        [x-show] {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
    }

    /* Image loading fade-in effect */
    img {
        transition: opacity 0.2s ease-in-out;
    }

    /* Carousel navigation button hover scale */
    button[class*="group-hover:opacity-100"]:hover {
        transform: scale(1.05);
    }
</style>
