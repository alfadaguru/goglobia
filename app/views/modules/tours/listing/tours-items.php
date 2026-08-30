<?php
// ============================================================================
// TOUR CARD ITEM TEMPLATE - PRODUCTION READY
// ============================================================================
// app/views/modules/tours/listing/tours-items.php
@$SECURE or die('Access Denied!'); ?>

<script>
/**
 * BUILD TOUR URL - SEO-friendly URL builder
 */
function buildTourUrl(t, searchParams) {
    const slug = t.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    const tourId = t.original_id || t.tour_id || t.id || 'unknown';
    const tourType = (t.tour_type || 'tour').toLowerCase().replace(/\s+/g, '-');
    
    const adults = searchParams.adults || 1;
    const children = searchParams.children || 0;

    return `<?=root?>tour/${slug}/${tourId}/${t.supplier.toLowerCase()}/${searchParams.start_date}/${searchParams.duration}/${adults}-${children}`;
}

/**
 * RENDER TOUR CARD
 * ============================================================================
 * Generates compact HTML markup for tour listing cards with carousel,
 * pricing, inclusions, exclusions, and booking information.
 */
function renderTourCard(t, idx, searchParams) {
    // Ensure searchParams is passed correctly
    const searchData = searchParams || this?.searchParams || {};
    
    // Build tour detail URL
    const tourUrl = buildTourUrl(t, searchData);

    // Star rating display - SVG stars
    const starSVG = (filled) => `
        <svg class="w-4 h-4 -ml-1 ${filled ? 'text-yellow-500' : 'text-gray-300 dark:text-gray-600'}" fill="currentColor" viewBox="0 0 20 20">
            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
        </svg>
    `;
    const stars = parseInt(t.stars) || 0;
    const starsHTML = Array.from({length: 5}, (_, i) => starSVG(i < stars)).join('');

    // Duration display
    const durationText = t.duration ? 
        (t.duration === '1' ? '1 <?=T::day?>' : 
         t.duration.includes('-') ? t.duration.replace('-', '-') + ' <?=T::days?>' : 
         t.duration + ' <?=T::days?>') : '<?=T::Flexible?>';

    // Image carousel setup
    const images = t.images && t.images.length > 0 ? t.images : (t.image ? [t.image] : []);
    const hasMultipleImages = images.length > 1;
    const imagesJson = JSON.stringify(images).replace(/'/g, "\\'");

    // Safely escape tour name for HTML
    const tourName = t.name || '<?=T::Tour?>';
    const escapedTourName = tourName.replace(/"/g, '&quot;').replace(/'/g, "\\'");
    
    // Safely escape title for addToCart function (use name if title doesn't exist)
    const tourTitle = t.title || t.name || '<?=T::Tour?>';
    const escapedTourTitle = tourTitle.replace(/'/g, "\\'");

    // Build compact card HTML
    return `
        <div x-data='(() => {
            const defaultImg = ${JSON.stringify(t.image || '')};
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
        class="card overflow-hidden mb-3 p-0 dark:bg-gray-800 dark:border-gray-700">

            <div class="flex flex-col md:flex-row">

                <!-- IMAGE CAROUSEL SECTION -->
                <div class="w-full h-44 md:w-[280px] md:h-auto md:self-stretch shrink-0 relative group overflow-hidden">
                    <!-- Main Image Link -->
                    <a href="${tourUrl}" target="_blank" rel="noopener noreferrer" class="absolute inset-0 block">
                        ${images.length > 0 ? `
                            <template x-for="(img, index) in images" :key="index">
                                <img x-show="currentImage === index"
                                     :src="img"
                                     alt="${escapedTourName}"
                                     class="w-full h-full object-cover"
                                     x-transition:enter="transition ease-out duration-200"
                                     x-transition:enter-start="opacity-0"
                                     x-transition:enter-end="opacity-100"
                                     onerror="this.onerror=null;this.src='<?=root?>uploads/no_img.jpg'">
                            </template>
                        ` : `
                            <div class="w-full h-full bg-gradient-to-br from-green-100 to-blue-200 dark:from-gray-700 dark:to-gray-800 flex items-center justify-center">
                                <span class="material-symbols-outlined text-gray-400 dark:text-gray-500" style="font-size: 32px;">travel_explore</span>
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

                    <!-- Duration Badge -->
                    <div class="absolute top-2 left-2 bg-blue-600 text-white px-2 py-0.5 rounded text-xs font-bold">
                        ${durationText}
                    </div>

                    <!-- Tour Type Badge -->
                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') { ?>
                    <div class="absolute top-2 right-2 bg-green-600 text-white px-2 py-0.5 rounded text-xs font-bold capitalize">
                        ${t.supplier || '<?=T::tour?>'}
                    </div>
                    <?php } ?>

                    <!-- Rating Badge -->
                    ${t.stars ? `
                        <div class="absolute bottom-2 left-2 bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold flex items-center gap-1">
                            <svg class="w-3.5 h-3.5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                            <span>${t.stars}.0</span>
                        </div>
                    ` : ''}
                </div>

                <!-- TOUR INFORMATION SECTION -->
                <div class="flex-grow flex-1 p-3 flex flex-col min-w-0">

                    <!-- Tour Name & Location -->
                    <div class="flex-grow">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-0.5 line-clamp-1">
                            ${tourName}
                        </h3>

                        <div class="flex items-start gap-1.5 mb-2">
                            <span class="material-symbols-outlined text-gray-500 dark:text-gray-400" style="font-size: 16px;">location_on</span>
                            <span class="text-xs text-gray-600 dark:text-gray-400 line-clamp-1">
                                ${t.location || ''}${t.city ? ', ' + t.city : ''}${t.country ? ', ' + t.country : ''}
                            </span>
                        </div>

                        <!-- Rating Display -->
                        ${t.stars ? `
                            <div class="flex items-center gap-1 mb-2">
                                <div class="flex -gap-1">${starsHTML}</div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">(${t.stars}.0)</span>
                                ${t.review_count > 0 ? `
                                    <span class="text-xs text-gray-500 dark:text-gray-400">• ${t.review_count} <?=T::reviews?></span>
                                ` : ''}
                            </div>
                        ` : ''}

                        <!-- Inclusions, Exclusions & Badges Row -->
                        <div class="mt-1 space-y-1">

                            <!-- Inclusions Row -->
                            <div class="flex flex-wrap gap-1">
                                ${t.inclusions && t.inclusions.length > 0 ?
                                    t.inclusions.slice(0, 2).map(inclusion => {
                                        const inclusionName = inclusion.name || inclusion || '';
                                        const inclusionIcon = inclusion.icon || 'check_circle';
                                        
                                        return `
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300">
                                                <span class="material-symbols-outlined" style="font-size: 12px;">
                                                    ${inclusionIcon}
                                                </span>
                                                <span class="line-clamp-1">${inclusionName}</span>
                                            </span>
                                        `;
                                    }).join('') : ''
                                }

                                ${(t.inclusions && t.inclusions.length > 2) ?
                                    `<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-green-100 dark:bg-green-800/50 text-green-800 dark:text-green-300">
                                        <span>+${t.inclusions.length - 2} <?=T::inclusions?></span>
                                    </span>` : ''
                                }
                            </div>

                            <!-- Exclusions Row -->
                            <div class="flex flex-wrap gap-1">
                                ${t.exclusions && t.exclusions.length > 0 ?
                                    t.exclusions.slice(0, 2).map(exclusion => {
                                        const exclusionName = exclusion.name || exclusion || '';
                                        const exclusionIcon = exclusion.icon || 'cancel';
                                        
                                        return `
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300">
                                                <span class="material-symbols-outlined" style="font-size: 12px;">
                                                    ${exclusionIcon}
                                                </span>
                                                <span class="line-clamp-1">${exclusionName}</span>
                                            </span>
                                        `;
                                    }).join('') : ''
                                }

                                ${(t.exclusions && t.exclusions.length > 2) ?
                                    `<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-red-100 dark:bg-red-800/50 text-red-800 dark:text-red-300">
                                        <span>+${t.exclusions.length - 2} <?=T::exclusions?></span>
                                    </span>` : ''
                                }
                            </div>

                        </div>

                    </div>

                    <!-- PRICING & BOOKING SECTION -->
                    <div class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2">

                        <!-- Price Display -->
                        <div class="w-full sm:w-auto">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5"><?=T::from?></p>
                            <!-- Price + per person in same line -->
                            <div class="flex items-baseline gap-2">
                                <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 leading-none mb-0">
                                    ${t.currency || 'USD'} ${(t.price || 0).toFixed(2)}
                                </p>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mb-0"><?=T::per?> <?=T::person?></p>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-0">
                                ${t.currency || 'USD'} ${(t.price_per_person || 0).toFixed(2)}
                            </p>
                        </div>

                        <!-- Book Button -->
                        <div class="w-full sm:w-auto">
                            <a href="${tourUrl}" target="_blank" rel="noopener noreferrer" class="btn w-full sm:w-auto flex items-center justify-center gap-1">
                                <span class="material-symbols-outlined" style="font-size: 14px;">open_in_new</span>
                                <span><?= T::more ?> <?= T::details ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER -->
            <div class="border-t border-gray-200 dark:border-gray-700 px-4 py-2 bg-gray-50 dark:bg-gray-900">
                <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined" style="font-size: 14px;">group</span>
                        <span><?=T::max?> ${t.max_travelers || '<?=T::unlimited?>'} <?=T::travelers?></span>
                    </div>
                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') { ?>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined" style="font-size: 14px;">business</span>
                        <span><?=T::powered?> <?=T::by?> ${t.supplier || 'N/A'}</span>
                        <span class="w-1 h-1 bg-gray-400 dark:bg-gray-600 rounded-full"></span>
                        <span>${t.currency || 'USD'}</span>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    `;
}
</script>

<style>
[x-cloak] {
    display: none !important;
}

.text-yellow-500 {
    text-shadow: 0 0 6px rgba(251, 191, 36, 0.4);
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
    transform: scale(1.05);
}
</style>