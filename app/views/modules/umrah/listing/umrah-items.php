<?php
// ============================================================================
// UMRAH CARD ITEM TEMPLATE
// ============================================================================
// app/views/modules/umrah/listing/umrah-items.php
@$SECURE or die('Access Denied!'); ?>

<script>
/**
 * BUILD UMRAH URL - SEO-friendly URL builder
 */
function buildUmrahUrl(u, searchParams) {
    const slug = (u.slug || u.name || 'umrah').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    const umrahId = u.original_id || u.umrah_id || u.id || 'unknown';
    
    const params = searchParams || {};
    const startDate = params.start_date || 'any';
    const duration = params.duration || 'any';
    const adults = params.adults || 1;
    const children = params.children || 0;

    return `<?=root?>umrah/detail/${slug}/${umrahId}/umrah/${startDate}/${duration}/${adults}-${children}`;
}

/**
 * RENDER UMRAH CARD
 * ============================================================================
 * Generates compact HTML markup for umrah listing cards with carousel,
 * pricing, inclusions, exclusions, and booking information.
 */
function renderUmrahCard(u, idx, searchParams) {
    // Ensure searchParams is passed correctly
    const searchData = searchParams || this?.searchParams || {};
    
    // Build umrah detail URL
    const umrahUrl = buildUmrahUrl(u, searchData);

    // Star rating display - SVG stars
    const starSVG = (filled) => `
        <svg class="w-4 h-4 -ml-1 ${filled ? 'text-yellow-500' : 'text-gray-300 dark:text-gray-600'}" fill="currentColor" viewBox="0 0 20 20">
            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
        </svg>
    `;
    const stars = parseInt(u.stars) || 0;
    const starsHTML = Array.from({length: 5}, (_, i) => starSVG(i < stars)).join('');

    // Duration display
    const durationText = u.days ? 
        (u.days === 1 ? '1 <?= T::day ?>' : u.days + ' <?= T::days ?>') : '<?= T::flexible ?>';

    // Image carousel setup
    const images = u.images && u.images.length > 0 ? u.images : (u.image ? [u.image] : []);
    const hasMultipleImages = images.length > 1;
    const imagesJson = JSON.stringify(images).replace(/'/g, "\\'");

    // Safely escape umrah name for HTML
    const umrahName = u.name || '<?=T::umrah?>';
    const escapedUmrahName = umrahName.replace(/"/g, '&quot;').replace(/'/g, "\\'");
    
    // Safely escape title for addToCart function (use name if title doesn't exist)
    const umrahTitle = u.title || u.name || '<?=T::umrah?>';
    const escapedUmrahTitle = umrahTitle.replace(/'/g, "\\'");

    // Build compact card HTML
    return `
        <div x-data='(() => {
            const defaultImg = ${JSON.stringify(u.image || '')};
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
                <div class="md:w-1/3 relative group">
                    <!-- Main Image Link -->
                    <a href="${umrahUrl}" target="_blank" rel="noopener noreferrer" class="block w-full h-full">
                        ${images.length > 0 ? `
                            <template x-for="(img, index) in images" :key="index">
                                <img x-show="currentImage === index"
                                     :src="img"
                                     alt="${escapedUmrahName}"
                                     class="w-full h-48 md:h-64 object-cover"
                                     x-transition:enter="transition ease-out duration-200"
                                     x-transition:enter-start="opacity-0"
                                     x-transition:enter-end="opacity-100"
                                     onerror="this.onerror=null;this.src='<?=root?>uploads/no_img.jpg'">
                            </template>
                        ` : `
                            <div class="w-full h-48 md:h-full bg-gradient-to-br from-blue-100 to-purple-200 dark:from-gray-700 dark:to-gray-800 flex items-center justify-center">
                                <span class="material-symbols-outlined text-blue-600 dark:text-blue-500" style="font-size: 40px;">mosque</span>
                            </div>
                        `}
                    </a>

                    <!-- SLIDER BUTTONS -->
                    ${hasMultipleImages ? `
                        <!-- Left Arrow - Black Transparent Background -->
                        <button @click="prevImage()"
                                type="button"
                                style="transform: translateY(-50%);"
                                class="absolute left-2 top-1/2 bg-black/50 hover:bg-black/90 text-white rounded-full w-8 h-8 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
                            <span class="material-symbols-outlined" style="font-size: 20px; font-variation-settings: 'wght' 300;">chevron_left</span>
                        </button>

                        <!-- Right Arrow - Black Transparent Background -->
                        <button @click="nextImage()"
                                type="button"
                                style="transform: translateY(-50%);"
                                class="absolute right-2 top-1/2 bg-black/50 hover:bg-black/90 text-white rounded-full w-8 h-8 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
                            <span class="material-symbols-outlined" style="font-size: 20px; font-variation-settings: 'wght' 300;">chevron_right</span>
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

                    <!-- Umrah Type Badge -->
                    <div class="absolute top-2 right-2 bg-blue-600 text-white px-2 py-0.5 rounded text-[10px] sm:text-xs font-bold capitalize">
                        ${u.umrah_type}
                    </div>

                    <!-- Rating Badge -->
                    ${u.stars ? `
                        <div class="absolute bottom-2 left-2 bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold flex items-center gap-1">
                            <svg class="w-3.5 h-3.5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                            <span>${u.stars}.0</span>
                        </div>
                    ` : ''}
                </div>

                <!-- UMRAH INFORMATION SECTION -->
                <div class="md:w-2/3 p-4 flex flex-col">

                    <!-- Umrah Name & Location -->
                    <div class="flex-1">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-1 line-clamp-1">
                            ${umrahName}
                        </h3>

                        <div class="flex items-start gap-1.5 mb-2">
                            <span class="material-symbols-outlined text-blue-600 dark:text-blue-400" style="font-size: 16px;">location_on</span>
                            <span class="text-xs text-gray-600 dark:text-gray-400 line-clamp-1">
                                ${u.location || ''}
                            </span>
                        </div>

                        <!-- Rating Display -->
                        ${u.stars ? `
                            <div class="flex items-center gap-1 mb-2">
                                <div class="flex -gap-1">${starsHTML}</div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">(${u.stars}.0)</span>
                                ${u.review_count > 0 ? `
                                    <span class="text-xs text-gray-500 dark:text-gray-400">• ${u.review_count} <?=T::reviews?></span>
                                ` : ''}
                            </div>
                        ` : ''}

                        <!-- Inclusions, Exclusions & Badges Row -->
                        <div class="mt-1 space-y-1">

                            <!-- Inclusions Row -->
                            <div class="flex flex-wrap gap-1">
                                ${u.inclusions && u.inclusions.length > 0 ?
                                    u.inclusions.map(inclusion => {
                                        const inclusionName = inclusion.name || inclusion || '';
                                        const inclusionIcon = inclusion.icon || 'check_circle';
                                        return `
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400">
                                                <span class="material-symbols-outlined" style="font-size: 12px;">
                                                    ${inclusionIcon}
                                                </span>
                                                <span class="line-clamp-1">${inclusionName}</span>
                                            </span>
                                        `;
                                    }).join('') : ''
                                }
                            </div>

                            <!-- Exclusions Row -->
                            <div class="flex flex-wrap gap-1">
                                ${u.exclusions && u.exclusions.length > 0 ?
                                    u.exclusions.slice(0, 2).map(exclusion => {
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

                                ${(u.exclusions && u.exclusions.length > 2) ?
                                    `<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-red-100 dark:bg-red-800/50 text-red-800 dark:text-red-300">
                                        <span>+${u.exclusions.length - 2} <?=T::exclusions?></span>
                                    </span>` : ''
                                }
                            </div>

                        </div>

                    </div>

                    <!-- PRICING & BOOKING SECTION -->
                    <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-between items-end gap-3">

                        <!-- Price Display -->
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5"><?=T::from?></p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                ${u.currency || 'USD'} ${(u.price || 0).toFixed(2)}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                <?=T::per?> <?=T::person?> • ${u.currency || 'USD'} ${(u.price_per_person || u.price || 0).toFixed(2)}
                            </p>
                        </div>

                        <!-- More Details Button -->
                        <a href="${umrahUrl}" 
                           target="_blank" rel="noopener noreferrer"
                           class="btn bg-primary hover:bg-white/20 text-white">
                            <span class="material-symbols-outlined" style="font-size: 16px;">open_in_new</span>
                            <span><?= T::more_details ?></span>
                        </a>
                    </div>
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
