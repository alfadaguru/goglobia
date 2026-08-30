<?php
// Get modules data from database - order is now maintained in DB
$modules = $db->select('modules', '*', [ 'active' => '1' ], [
    'ORDER' => [
        'type' => 'ASC',
        'order' => 'ASC',

    ]
]);

// Organize modules by type
$modulesByType = [];
$totalActive = 0;

foreach ($modules as $module) {
    $type = $module['type'];
    if (!isset($modulesByType[$type])) {
        $modulesByType[$type] = [];
    }

    $modulesByType[$type][] = $module;
    if ($module['status'] == 1) $totalActive++;
}

// Category icons and colors
$categories = [
    'flights' => ['icon' => 'flight', 'color' => '#3b82f6', 'label' => T::flights],
    'stays' => ['icon' => 'hotel', 'color' => '#10b981', 'label' => T::stays],
    'tours' => ['icon' => 'tour', 'color' => '#f59e0b', 'label' => T::tours],
    'cars' => ['icon' => 'directions_car', 'color' => '#8b5cf6', 'label' => T::cars],
    'bus' => ['icon' => 'directions_bus', 'color' => '#f43f5e', 'label' => T::bus ?? 'Bus'],
    'visa' => ['icon' => 'description', 'color' => '#ef4444', 'label' => T::visa],
    'umrah' => ['icon' => 'mosque', 'color' => '#16a34a', 'label' => T::umrah ?? 'Umrah'],
    'ferries' => ['icon' => 'directions_boat', 'color' => '#0ea5e9', 'label' => T::ferries ?? 'Ferries'],
    'rail' => ['icon' => 'directions_railway', 'color' => '#4f46e5', 'label' => 'Rail'],
    'cruises' => ['icon' => 'sailing', 'color' => '#06b6d4', 'label' => T::cruises],
    'esim' => ['icon' => 'sim_card', 'color' => '#0f6fff', 'label' => T::esim ?? 'eSIM'],
    'extra' => ['icon' => 'apps', 'color' => '#6366f1', 'label' => 'Extra']
];

// Count active modules per category and sort
$categoriesWithCount = [];
foreach ($categories as $key => $cat) {
    if (isset($modulesByType[$key]) && !empty($modulesByType[$key])) {
        $activeCount = count(array_filter($modulesByType[$key], function($m) {
            return $m['status'] == 1;
        }));

        $categoriesWithCount[$key] = [
            'data' => $cat,
            'activeCount' => $activeCount,
            'totalCount' => count($modulesByType[$key])
        ];
    }
}

// Sort categories by active count (descending)
uasort($categoriesWithCount, function($a, $b) {
    if ($b['activeCount'] != $a['activeCount']) {
        return $b['activeCount'] - $a['activeCount']; // Most active first
    }
    return $b['totalCount'] - $a['totalCount']; // Then by total count
});

// Default to the category with the most active modules (first after the sort
// above), unless a hash explicitly selects a category.
$defaultCategory = array_key_first($categoriesWithCount) ?? 'all';
?>

<div class="container mt-5">
<!-- Flash Message -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success mb-5' : 'alert-error mb-5' ?>">
        <span class="material-icon material-symbols-outlined">
            <?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?>
        </span>
        <p><?= isset($_SESSION['message']['key']) ? constant('T::' . $_SESSION['message']['key']) : $_SESSION['message']['text'] ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>
</div>

<!-- Page Header -->
<div class="bg-white border-b border-gray-200">
    <div class="container py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900"><?=T::modules?></h1>
                <p class="text-sm text-gray-500 mt-1"><?=T::manage_your_travel_service_modules?></p>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-center">
                    <div class="text-2xl font-bold text-green-600"><?= $totalActive ?></div>
                    <div class="text-xs text-gray-500"><?=T::active?></div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-gray-900"><?= count($modules) ?></div>
                    <div class="text-xs text-gray-500"><?=T::total?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modules Grid -->
<div class="container my-10" x-data="{
    activeCategory: window.location.hash ? window.location.hash.substring(1) : '<?= $defaultCategory ?>',
    init() {
        // Watch for hash changes
        window.addEventListener('hashchange', () => {
            this.activeCategory = window.location.hash ? window.location.hash.substring(1) : '<?= $defaultCategory ?>';
        });
    },
    setCategory(category) {
        this.activeCategory = category;
        if (category === 'all') {
            history.replaceState(null, null, window.location.pathname + window.location.search);
            return;
        }
        window.location.hash = category;
    }
}">

    <div class="flex flex-col lg:flex-row gap-6">

        <!-- Category List (vertical card — shows every type without a scrollbar) -->
        <aside class="lg:w-64 lg:shrink-0">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden lg:sticky lg:top-4">
                <!-- Card header -->
                <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-100 bg-gray-50/60">
                    <span class="material-symbols-outlined !text-[18px] text-gray-400">category</span>
                    <h3 class="text-sm font-semibold text-gray-900"><?= T::categories ?? 'Categories' ?></h3>
                    <span class="ml-auto text-xs font-medium text-gray-400"><?= count($categoriesWithCount) ?></span>
                </div>
                <!-- Category list -->
                <div class="p-2 flex flex-col gap-0.5">
                    <?php foreach ($categoriesWithCount as $key => $catData): ?>
                        <?php $cat = $catData['data']; ?>
                        <button @click="setCategory('<?= $key ?>')"
                                class="group w-full flex items-center gap-2.5 h-10 px-2 rounded-lg text-sm font-medium transition-colors"
                                :class="activeCategory === '<?= $key ?>' ? 'bg-primary/10 text-primary' : 'text-gray-700 hover:bg-gray-100'">
                            <span class="w-7 h-7 shrink-0 flex items-center justify-center rounded-md transition-colors"
                                  :class="activeCategory === '<?= $key ?>' ? 'bg-white shadow-sm' : 'bg-gray-100 group-hover:bg-white'">
                                <span class="material-symbols-outlined !text-[18px]" style="color: <?= $cat['color'] ?>;"><?= $cat['icon'] ?></span>
                            </span>
                            <span class="flex-1 text-left truncate"><?= $cat['label'] ?></span>
                            <span class="inline-flex items-center justify-center w-5 h-5 shrink-0 rounded-full text-[11px] font-semibold leading-none"
                                  :class="activeCategory === '<?= $key ?>' ? 'bg-primary text-white' : 'bg-primary/10 text-primary'"><?= $catData['activeCount'] ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>

        <!-- Modules Cards -->
        <div class="flex-1 min-w-0">
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php 
        // Sort modules to show status=1 first, then keep a stable type/order grouping
        usort($modules, function($a, $b) {
            if ($b['status'] != $a['status']) {
                return intval($b['status']) - intval($a['status']);
            }
            if ($a['type'] !== $b['type']) {
                return strcmp($a['type'], $b['type']);
            }
            return intval($a['order']) - intval($b['order']);
        });
        
        foreach ($modules as $module):
            if($module['active']==1){
            // Additional module processing can go here
            ?>
              <div x-show="activeCategory === 'all' || activeCategory === '<?= $module['type'] ?>'"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 transform scale-95"
                 x-transition:enter-end="opacity-100 transform scale-100"
                 class="bg-white rounded-lg border overflow-hidden hover:shadow-lg transition-shadow <?= $totalActive === 0 ? 'border-yellow-400 border-2' : 'border-gray-200' ?>">

                <!-- Module Header -->
                <div class="p-3" style="background: linear-gradient(135deg, <?= $module['module_color'] ?? '#3b82f6' ?>15 0%, <?= $module['module_color'] ?? '#3b82f6' ?>05 100%);">
                    <div class="flex justify-between align-center">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: <?= $module['module_color'] ?? '#3b82f6' ?>20;">
                                <img src="<?= root ?>assets/img/modules/<?= strtolower($module['name']) ?>.png"
                                     alt="<?= ucfirst($module['name']) ?>"
                                     class="w-10 h-10 object-contain rounded-lg">
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900"><?= ucwords(str_replace('_', ' ', $module['name'])) ?></h3>
                                <p class="text-xs text-gray-500"><?= ucfirst($module['type']) ?></p>
                            </div>
                        </div>

                        <!-- Status Toggle -->
                        <form method="POST" action="<?= root.admin ?>/settings/modules/toggle#<?= $module['type'] ?>" class="flex items-center"
                              x-data="{ isSubmitting: false }"
                              @submit="isSubmitting = true">
                            <input type="hidden" name="module_id" value="<?= $module['id'] ?>">
                            <input type="hidden" name="status" value="<?= $module['status'] ? 0 : 1 ?>">

                            <label class="switch-container switch-md switch-blue">
                                <input type="checkbox"
                                       class="switch-input"
                                       <?= $module['status'] ? 'checked' : '' ?>
                                       :disabled="isSubmitting"
                                       onchange="this.form.submit()">
                                <div class="switch-track">
                                    <div class="switch-thumb"></div>
                                </div>
                            </label>
                        </form>
                    </div>
                </div>

                <!-- Module Details -->
                <div class="p-4 space-y-0">

                    <!-- Status Badge -->
                    <div class="flex items-center gap-2">
                        <!-- <div class="flex-1">
                            <?php if ($module['status']): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                                    <?=T::active?>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                    <span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span>
                                    <?=T::inactive?>
                                </span>
                            <?php endif; ?>
                        </div> -->
                    </div>

                    <!-- Module Info -->
                    <?php $_isAiralo = (strtolower($module['name'] ?? '') === 'airalo'); ?>
                    <?php if (!$_isAiralo): ?>
                    <div class="flex gap-2 text-xs justify-between align-center pb-3">
                        <div>
                            <div class="text-gray-500"><?=T::markup?> <?=T::b2c?></div>
                            <div class="font-medium text-gray-900"><?= $module['markup_type_b2c'] ?> <?= $module['markup_b2c'] ?? '0' ?><?= ($module['markup_type_b2c'] === 'percentage') ? '%' : '' ?></div>
                        </div>
                        <div>
                            <div class="text-gray-500"><?=T::markup?> <?=T::b2b?></div>
                            <div class="font-medium text-gray-900"><?= $module['markup_type_b2b'] ?> <?= $module['markup_b2b'] ?? '0' ?><?= ($module['markup_type_b2b'] === 'percentage') ? '%' : '' ?></div>
                        </div>
                        <div>
                            <div class="text-gray-500"><?=T::currency?></div>
                            <div class="font-medium text-gray-900"><?= $module['currency'] ?? 'USD' ?></div>
                        </div>
                        <!-- <div class="flex gap-2 text-xs justify-between align-center pt-2">
                            <div class="w-3 h-3 rounded-full" style="background-color: <?= $module['module_color'] ?? '#3b82f6' ?>;"></div>
                        </div> -->
                    </div>
                    <?php else: ?>
                    <div class="flex gap-2 text-xs justify-between align-center pb-3">
                        <div>
                            <div class="text-gray-500">Pricing</div>
                            <div class="font-medium text-gray-900">Per-package</div>
                        </div>
                        <div>
                            <div class="text-gray-500">Countries</div>
                            <div class="font-medium text-gray-900"><?= (int) $db->count('airalo_countries', ['status' => 1]) ?> active</div>
                        </div>
                        <div>
                            <div class="text-gray-500">Rules</div>
                            <div class="font-medium text-gray-900"><?= (int) $db->count('airalo_packages') ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="flex gap-2 pt-2 border-t border-gray-100">
                        <a href="<?= root.admin . '/settings/modules/' . $module['id'] ?>"
                           class="btn w-full p-2 h-9">
                            <span class="material-symbols-outlined text-sm">settings</span>
                            <?=T::settings?>
                        </a>
                        <a href="https://docs.phptravels.com/modules/<?= strtolower($module['type']) ?>/<?= strtolower($module['name']) ?>"
                           target="_blank"
                           class="btn light w-full p-2 h-9">
                            <span class="material-symbols-outlined text-sm">description</span>
                            <?=T::docs?>
                        </a>
                    </div>
                </div>
            </div>
        <?php
    }
    endforeach; ?>
    </div>

    <!-- Empty State -->
    <div x-show="activeCategory !== 'all' && document.querySelectorAll(`[x-show*='${activeCategory}']`).length === 0"
         class="text-center py-12">
        <div class="text-gray-400 mb-2">
            <span class="material-symbols-outlined text-5xl">apps</span>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-1"><?=T::no_modules_found?></h3>
        <p class="text-sm text-gray-500"><?=T::no_modules_available_category?></p>
    </div>

        </div><!-- /modules cards column -->
    </div><!-- /two-column layout -->

</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>