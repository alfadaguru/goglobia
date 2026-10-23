<?php
// ============================================================================
// Homepage "Featured Umrah Packages" section — UMRAH v2.
//
// Reads the SAME published-departure inventory the public /umrah page and GIA
// read (umrahV2PublishedDepartures via umrahListingCards), so the homepage can
// never diverge from what's actually bookable. A package appears here simply by
// being PUBLISHED in the Umrah Manager (status='published', archived=0) with at
// least one active tier — there is NO separate "featured" flag in v2.
//
// The dead legacy v1 `umrah` table is intentionally NOT read (it held stale test
// rows and had a `featured` column that the v2 manager never writes — which is
// why nothing showed on the homepage before).
//
// Cards group under origin-city tabs, link to the real v2 detail page
// (/umrah/packages/{template-slug}?departure={id}), and show the already-resolved
// per-pilgrim "from" price in its own currency. Prices are NOT re-marked-up here —
// umrah_price_resolve already applied agent/promo logic upstream.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('umrahListingCards')) {
    // featured.php is at app/views/modules/umrah/ — the lib is at app/lib/umrah/,
    // so we go up THREE levels (umrah -> modules -> views -> app), not two.
    // (dirname(__DIR__,2) pointed at app/views/lib/... which does not exist, so
    // the function never loaded on the homepage and the section rendered empty.)
    $__lf = dirname(__DIR__, 3) . '/lib/umrah/listing.php';
    if (is_file($__lf)) { require_once $__lf; }
}

$__umrahCards = function_exists('umrahListingCards')
    ? (umrahListingCards(['items_limit' => 24], $db)['cards'] ?? [])
    : [];

// Group published packages by origin city (the v2 "location") for the tabs.
$umrah_by_city = [];
foreach ($__umrahCards as $c) {
    $city = trim((string) ($c['location'] ?? '')) ?: 'Kano';
    $umrah_by_city[$city][] = $c;
}
$umrah_cities = array_keys($umrah_by_city);
$first_city_slug = !empty($umrah_cities) ? strtolower(preg_replace('/[^a-z0-9]+/i', '', $umrah_cities[0])) : 'kano';
?>

<?php if (!empty($umrah_cities)): ?>
    <section class="relative bg-gradient-to-b py-5 mt-5" x-data="{ activeTab: '<?= $first_city_slug ?>' }">
        <div class="container">
            <!-- Section Header -->
            <div class="mb-10">
                <h2 class="text-[1.2rem] font-bold text-gray-900 mb-5 flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[1.4rem]">mosque</span><?= defined('T::featured_umrah_packages') ? T::featured_umrah_packages : 'Featured Umrah Packages' ?>
                </h2>

                <!-- Feature Badges -->
                <div class="flex flex-wrap items-center gap-6 mb-8">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-500" style="font-size: 20px;">verified</span>
                        <span class="text-sm text-gray-700 font-medium"><?= defined('T::we_price_match') ? T::we_price_match : 'We price match' ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-teal-500" style="font-size: 20px;">task_alt</span>
                        <span class="text-sm text-gray-700 font-medium"><?= defined('T::umrah_booking_guarantee') ? T::umrah_booking_guarantee : 'Umrah Booking Guarantee' ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-500" style="font-size: 20px;">workspace_premium</span>
                        <span class="text-sm text-gray-700 font-medium"><?= defined('T::umrah_quality_guarantee') ? T::umrah_quality_guarantee : 'Umrah Quality Guarantee' ?></span>
                    </div>
                </div>

                <!-- City Tabs (only shown when there is more than one origin city) -->
                <?php if (count($umrah_cities) > 1): ?>
                <div class="relative">
                    <div class="flex gap-2 overflow-x-auto pb-3 scrollbar-hide">
                        <?php foreach ($umrah_cities as $city):
                            $city_slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $city)); ?>
                            <button @click="activeTab = '<?= $city_slug ?>'"
                                :class="activeTab === '<?= $city_slug ?>' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                                class="px-5 py-2.5 rounded-full font-medium text-sm whitespace-nowrap transition-all duration-200 border">
                                <?= htmlspecialchars($city) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php foreach ($umrah_cities as $city):
                $city_slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $city));
                $packages = $umrah_by_city[$city] ?? [];
                ?>
                <div x-show="activeTab === '<?= $city_slug ?>'" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 transform translate-y-2"
                    x-transition:enter-end="opacity-100 transform translate-y-0"
                    class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">

                    <?php foreach ($packages as $pkg):
                        // Real v2 detail URL: /umrah/packages/{template-slug}?departure={id}.
                        // Fall back to the landing page if a slug is missing (never a dead link).
                        $slug = trim((string) ($pkg['slug'] ?? ''));
                        $depId = (int) ($pkg['departure_id'] ?? 0);
                        $link = ($slug !== '')
                            ? root . 'umrah/packages/' . rawurlencode($slug) . ($depId ? '?departure=' . $depId : '')
                            : root . 'umrah';

                        $umrahImage = $pkg['image'] !== '' ? $pkg['image'] : root . 'uploads/no_img.jpg';

                        $days          = (int) ($pkg['days'] ?? 0);
                        $nights        = (int) ($pkg['nights'] ?? 0);
                        $displayPrice  = (float) ($pkg['price'] ?? 0);
                        $pkgCurrency   = strtoupper((string) ($pkg['currency'] ?? 'NGN'));
                        $availability  = (string) ($pkg['availability'] ?? 'available');
                        $tierLabel     = (string) ($pkg['tier_label'] ?? '');
                        $dateLabel     = '';
                        if (!empty($pkg['departure_date']) && strtotime((string) $pkg['departure_date'])) {
                            $dateLabel = date('j M', strtotime((string) $pkg['departure_date']));
                            if (!empty($pkg['return_date']) && strtotime((string) $pkg['return_date'])) {
                                $dateLabel .= ' → ' . date('j M', strtotime((string) $pkg['return_date']));
                            }
                        } elseif (!empty($pkg['month_bucket'])) {
                            $dateLabel = (string) $pkg['month_bucket'];
                        }
                        ?>

                        <!-- Umrah Card -->
                        <div class="group relative bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-2xl transition-all duration-500 border border-gray-100 flex flex-col">

                            <!-- Image Container -->
                            <div class="relative h-52 overflow-hidden">
                                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent z-10"></div>

                                <!-- Badges Top Left: origin city -->
                                <div class="absolute top-3 left-3 z-20 flex flex-col gap-2">
                                    <span class="bg-black/60 backdrop-blur-sm text-white text-xs font-medium px-2.5 py-1 rounded-lg flex items-center gap-1">
                                        <span class="material-symbols-outlined" style="font-size: 14px;">location_on</span>
                                        <?= htmlspecialchars($city) ?>
                                    </span>
                                </div>

                                <!-- Availability Badge Top Right -->
                                <?php if ($availability === 'limited'): ?>
                                    <div class="absolute top-3 right-3 z-20">
                                        <span class="bg-orange-600 text-white text-xs font-semibold px-2.5 py-1 rounded-lg shadow-lg">
                                            <?= defined('T::limited_seats') ? T::limited_seats : 'Limited seats' ?>
                                        </span>
                                    </div>
                                <?php elseif ($availability === 'sold_out'): ?>
                                    <div class="absolute top-3 right-3 z-20">
                                        <span class="bg-gray-800 text-white text-xs font-semibold px-2.5 py-1 rounded-lg shadow-lg">
                                            <?= defined('T::sold_out') ? T::sold_out : 'Sold out' ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <img src="<?= htmlspecialchars($umrahImage) ?>" alt="<?= htmlspecialchars($pkg['name'] ?? 'Umrah package') ?>"
                                    loading="lazy" decoding="async" fetchpriority="low"
                                    class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700"
                                    onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                            </div>

                            <!-- Content -->
                            <div class="p-4 flex flex-col flex-1">
                                <h3 class="font-bold text-gray-900 text-base mb-2 line-clamp-1 group-hover:text-blue-600 transition-colors">
                                    <?= htmlspecialchars($pkg['name'] ?? 'Umrah package') ?>
                                </h3>

                                <!-- Departure dates -->
                                <?php if ($dateLabel !== ''): ?>
                                    <div class="flex items-center gap-1 mb-1 text-sm text-gray-700">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">event</span>
                                        <span class="font-medium"><?= htmlspecialchars($dateLabel) ?></span>
                                    </div>
                                <?php endif; ?>

                                <!-- Duration -->
                                <?php if ($days > 0): ?>
                                    <div class="flex items-center gap-1 mb-2 text-sm text-gray-900">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">schedule</span>
                                        <span class="font-medium">
                                            <?= $days ?> <?= ($days > 1) ? (defined('T::days') ? T::days : 'Days') : (defined('T::day') ? T::day : 'Day') ?>
                                            <?php if ($nights > 0): ?>
                                                / <?= $nights ?> <?= ($nights > 1) ? (defined('T::nights') ? T::nights : 'Nights') : (defined('T::night') ? T::night : 'Night') ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <!-- Price and Tier Row -->
                                <div class="flex items-end justify-between mt-auto pt-3 border-t border-gray-100">
                                    <div class="flex flex-col">
                                        <?php if ($displayPrice > 0): ?>
                                            <span class="text-xs text-gray-500"><?= defined('T::from') ? T::from : 'From' ?></span>
                                            <div class="flex items-baseline gap-1">
                                                <span class="text-lg font-bold text-gray-900">
                                                    <?= $pkgCurrency ?> <?= number_format($displayPrice, 2) ?>
                                                </span>
                                                <span class="text-xs text-gray-500">/<?= defined('T::person') ? T::person : 'person' ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-sm font-semibold text-gray-600"><?= defined('T::contact_for_price') ? T::contact_for_price : 'Contact for Price' ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($tierLabel !== ''): ?>
                                        <div class="flex items-center gap-1">
                                            <div class="bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold">
                                                <?= htmlspecialchars($tierLabel) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Clickable Link Overlay -->
                            <a href="<?= htmlspecialchars($link) ?>" class="absolute inset-0 z-10"></a>
                        </div>

                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
