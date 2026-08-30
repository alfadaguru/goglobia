<?php
// Fetch umrah destinations from database (active packages only)
$umrah_locations = $db->select('umrah', 'location', [
    'status' => '1',
    'location[!]' => '',
    'GROUP' => 'location',
    'ORDER' => ['id' => 'ASC'],
    'LIMIT' => 8
]);

// Filter empties just in case
$umrah_locations = array_values(array_filter($umrah_locations ?: [], fn($v) => trim((string)$v) !== ''));

$location_umrah = [];
$seen_umrah = [];

foreach ($umrah_locations as $location) {
    $featured_umrah_loc = $db->select('umrah', '*', [
        'status' => '1',
        'featured' => '1',
        'location' => $location,
        'LIMIT' => 4
    ]);

    $unique_umrah = [];
    foreach ($featured_umrah_loc as $pkg) {
        if (in_array($pkg['id'], $seen_umrah)) continue;
        $seen_umrah[] = $pkg['id'];
        $unique_umrah[] = $pkg;
    }
    $location_umrah[$location] = $unique_umrah;
}

$first_location_slug = !empty($umrah_locations[0]) ? strtolower(str_replace(' ', '', $umrah_locations[0])) : 'makkah';
$sessionCurrency = strtoupper($_SESSION['app_currency'] ?? 'USD');
$umrahModule = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah', 'status' => 1]);
?>

<?php if (!empty($umrah_locations)): ?>
    <section class="relative bg-gradient-to-b py-5 mt-5" x-data="{ activeTab: '<?= $first_location_slug ?>' }">
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

                <!-- Location Tabs -->
                <div class="relative">
                    <div class="flex gap-2 overflow-x-auto pb-3 scrollbar-hide">
                        <?php foreach ($umrah_locations as $location): ?>
                            <button @click="activeTab = '<?= strtolower(str_replace(' ', '', $location)) ?>'"
                                :class="activeTab === '<?= strtolower(str_replace(' ', '', $location)) ?>' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                                class="px-5 py-2.5 rounded-full font-medium text-sm whitespace-nowrap transition-all duration-200 border">
                                <?= htmlspecialchars($location) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php foreach ($umrah_locations as $location):
                $location_slug = strtolower(str_replace(' ', '', $location));
                $packages = $location_umrah[$location] ?? [];
                ?>
                <div x-show="activeTab === '<?= $location_slug ?>'" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 transform translate-y-2"
                    x-transition:enter-end="opacity-100 transform translate-y-0"
                    class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">

                    <?php if (empty($packages)): ?>
                        <div class="col-span-4 text-center py-12">
                            <div class="w-16 h-16 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                                <span class="material-symbols-outlined text-gray-400 text-2xl">mosque</span>
                            </div>
                            <h3 class="text-gray-600 font-medium text-lg mb-2">
                                <?= defined('T::no_featured_umrah_in') ? T::no_featured_umrah_in : 'No Featured Umrah Packages in' ?> <?= htmlspecialchars($location) ?></h3>
                            <p class="text-gray-500 text-sm">
                                <?= defined('T::check_back_later_for_featured_umrah') ? T::check_back_later_for_featured_umrah : 'Check back later for featured packages' ?></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($packages as $pkg):
                            // Build SEO-friendly URL
                            $slugBase = strtolower(preg_replace('/[^a-z0-9\s-]/', '', strtolower($pkg['name'] ?? 'umrah-package')));
                            $umrahSlug = trim(preg_replace('/[^a-z0-9]+/', '-', $slugBase), '-');
                            if ($umrahSlug === '') $umrahSlug = 'umrah-package';

                            $umrahId = $pkg['id'];
                            $supplier = 'umrah';
                            $startDate = date('d-m-Y', strtotime('+3 days'));
                            $duration = !empty($pkg['days']) ? $pkg['days'] : 'any';
                            $adults = $_SESSION['umrah_adults'] ?? 1;
                            $children = $_SESSION['umrah_children'] ?? 0;

                            $link = root . "umrah/detail/{$umrahSlug}/{$umrahId}/{$supplier}/{$startDate}/{$duration}/{$adults}-{$children}/any";

                            // Process image
                            $images = json_decode($pkg['img'] ?? '[]', true);
                            $imagePath = 'uploads/no_img.jpg';
                            if (is_array($images) && !empty($images)) {
                                $defaultImg = array_filter($images, fn($i) => is_array($i) && !empty($i['default']));
                                if (!empty($defaultImg)) {
                                    $imagePath = reset($defaultImg)['url'] ?? $imagePath;
                                } else {
                                    $first = $images[0];
                                    $imagePath = is_array($first) ? ($first['url'] ?? $imagePath) : (string)$first;
                                }
                            }
                            $umrahImage = root . ltrim($imagePath, '/');

                            // Stars / discount
                            $stars = !empty($pkg['stars']) ? intval($pkg['stars']) : 0;
                            $discount = !empty($pkg['discount_percentage']) ? $pkg['discount_percentage'] : null;

                            // Price calc
                            $pkgCurrency = !empty($pkg['currency']) ? $pkg['currency'] : 'USD';
                            $basePrice = (float)($pkg['adult_price'] ?? 0);
                            $priceWithMarkup = MARKUP($basePrice, $umrahModule ?: 'umrah', $db, $pkgCurrency, $sessionCurrency);
                            $displayPrice = $priceWithMarkup['price'] ?? 0;

                            // Umrah type label
                            $umrahTypeLabel = '';
                            if (!empty($pkg['umrah_type_id'])) {
                                $umrahTypeLabel = $db->get('umrah_settings', 'setting_label', ['id' => $pkg['umrah_type_id']]) ?: '';
                            }
                            ?>

                            <!-- Umrah Card -->
                            <div class="group relative bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-2xl transition-all duration-500 border border-gray-100 flex flex-col">

                                <!-- Image Container -->
                                <div class="relative h-52 overflow-hidden">
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent z-10"></div>

                                    <!-- Badges Top Left -->
                                    <div class="absolute top-3 left-3 z-20 flex flex-col gap-2">
                                        <span class="bg-black/60 backdrop-blur-sm text-white text-xs font-medium px-2.5 py-1 rounded-lg flex items-center gap-1">
                                            <span class="material-symbols-outlined" style="font-size: 14px;">location_on</span>
                                            <?= htmlspecialchars($location) ?>
                                        </span>
                                    </div>

                                    <!-- Umrah Type Badge Top Right -->
                                    <?php if ($umrahTypeLabel): ?>
                                        <div class="absolute top-3 right-3 z-20">
                                            <span class="bg-blue-600 text-white text-xs font-semibold px-2.5 py-1 rounded-lg shadow-lg">
                                                <?= htmlspecialchars($umrahTypeLabel) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <img src="<?= $umrahImage ?>" alt="<?= htmlspecialchars($pkg['name']) ?>"
                                        loading="lazy" decoding="async" fetchpriority="low"
                                        class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700"
                                        onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                                </div>

                                <!-- Content -->
                                <div class="p-4 flex flex-col flex-1">
                                    <h3 class="font-bold text-gray-900 text-base mb-2 line-clamp-1 group-hover:text-blue-600 transition-colors">
                                        <?= htmlspecialchars($pkg['name']) ?>
                                    </h3>

                                    <!-- Duration -->
                                    <?php if (!empty($pkg['days'])): ?>
                                        <div class="flex items-center gap-1 mb-2 text-sm text-gray-900">
                                            <span class="material-symbols-outlined" style="font-size: 16px;">schedule</span>
                                            <span class="font-medium">
                                                <?= $pkg['days'] ?> <?= ($pkg['days'] > 1) ? (defined('T::days') ? T::days : 'Days') : (defined('T::day') ? T::day : 'Day') ?>
                                                <?php if (!empty($pkg['nights'])): ?>
                                                    / <?= $pkg['nights'] ?> <?= ($pkg['nights'] > 1) ? (defined('T::nights') ? T::nights : 'Nights') : (defined('T::night') ? T::night : 'Night') ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Price and Stars Row -->
                                    <div class="flex items-end justify-between mt-auto pt-3 border-t border-gray-100">
                                        <div class="flex flex-col">
                                            <?php if ($displayPrice > 0): ?>
                                                <span class="text-xs text-gray-500"><?= defined('T::from') ? T::from : 'From' ?></span>
                                                <div class="flex items-baseline gap-1">
                                                    <span class="text-lg font-bold text-gray-900">
                                                        <?= strtoupper($sessionCurrency) ?> <?= number_format($displayPrice, 2) ?>
                                                    </span>
                                                    <span class="text-xs text-gray-500">/<?= defined('T::person') ? T::person : 'person' ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-sm font-semibold text-gray-600"><?= defined('T::contact_for_price') ? T::contact_for_price : 'Contact for Price' ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($stars > 0): ?>
                                            <div class="flex items-center gap-1">
                                                <div class="bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold flex items-center gap-1">
                                                    <svg class="w-3.5 h-3.5 text-orange-500" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                                    </svg>
                                                    <span><?= $stars ?>.0</span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Clickable Link Overlay -->
                                <a href="<?= $link ?>" target="_blank" rel="noopener noreferrer" class="absolute inset-0 z-10"></a>
                            </div>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
