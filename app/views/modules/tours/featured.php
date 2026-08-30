<?php
@$SECURE or die('Access Denied!');

// Render the section shell only when the tours module is enabled.
if (!in_array('tours', array_column($GLOBALS['modules'] ?? [], 'type'), true)) {
    return;
}
$cur      = strtoupper($_SESSION['app_currency'] ?? 'USD');
$adults   = (int) ($_SESSION['tour_adults'] ?? $_SESSION['tour_travelers'] ?? 2);
$children = (int) ($_SESSION['tour_children'] ?? 0);
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout so the scroll check has a real position; content shows once loaded -->
<div style="min-height:420px"
    x-data="toursFeatured({url:'<?= root ?>api/tours/featured?currency=<?= $cur ?>', root:'<?= root ?>', cur:'<?= $cur ?>', adults:<?= $adults ?>, children:<?= $children ?>})">
    <section class="relative bg-gradient-to-b py-5 mt-5" x-show="locs.length" x-cloak>
        <div class="container">
            <!-- Section Header -->
            <div class="mb-10">
                <h2 class="text-[1.2rem] font-bold text-gray-900 mb-5 flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[1.4rem]">tour</span><?= T::popular_tours ?? 'Popular Tours' ?></h2>

                <!-- Feature Badges (static) -->
                <div class="flex flex-wrap items-center gap-6 mb-8">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-500" style="font-size: 20px;">verified</span>
                        <span class="text-sm text-gray-700 font-medium"><?= T::we_price_match ?? 'We price match' ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-teal-500" style="font-size: 20px;">task_alt</span>
                        <span class="text-sm text-gray-700 font-medium"><?= T::tour_booking_guarantee ?? 'Tour Booking Guarantee' ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-500" style="font-size: 20px;">workspace_premium</span>
                        <span class="text-sm text-gray-700 font-medium"><?= T::tour_quality_guarantee ?? 'Tour Quality Guarantee' ?></span>
                    </div>
                </div>

                <!-- Location Tabs -->
                <div class="relative">
                    <div class="flex gap-2 overflow-x-auto pb-3 scrollbar-hide">
                        <template x-for="loc in locs" :key="loc.slug">
                            <button @click="active = loc.slug"
                                :class="active === loc.slug ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                                class="px-5 py-2.5 rounded-full font-medium text-sm whitespace-nowrap transition-all duration-200 border"
                                x-text="loc.name"></button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Tour grids per location -->
            <template x-for="loc in locs" :key="loc.slug">
                <div x-show="active === loc.slug"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 transform translate-y-2"
                    x-transition:enter-end="opacity-100 transform translate-y-0"
                    class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">

                    <template x-for="t in (byLoc[loc.slug] || [])" :key="t.id">
                        <div class="group relative bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-2xl transition-all duration-500 border border-gray-100 flex flex-col">
                            <!-- Image -->
                            <div class="relative h-52 overflow-hidden">
                                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent z-10"></div>
                                <div class="absolute top-3 left-3 z-20 flex flex-col gap-2">
                                    <template x-if="t.discount">
                                        <span class="bg-red-500 text-white text-xs font-bold px-2.5 py-1 rounded-lg shadow-lg"><span x-text="t.discount"></span>% <?= T::off ?? 'OFF' ?></span>
                                    </template>
                                    <span class="bg-black/60 backdrop-blur-sm text-white text-xs font-medium px-2.5 py-1 rounded-lg flex items-center gap-1">
                                        <span class="material-symbols-outlined" style="font-size: 14px;">location_on</span>
                                        <span x-text="t.location"></span>
                                    </span>
                                </div>
                                <img :src="t.image" :alt="t.name" loading="lazy" decoding="async" fetchpriority="low"
                                    class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700"
                                    onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                            </div>

                            <!-- Content -->
                            <div class="p-4 flex flex-col flex-1">
                                <h3 class="font-bold text-gray-900 text-base mb-2 line-clamp-1 group-hover:text-blue-600 transition-colors" x-text="t.name"></h3>

                                <div class="flex items-center gap-3 mb-3">
                                    <template x-if="t.type">
                                        <span class="text-xs text-gray-500 flex items-center gap-1"><span class="material-symbols-outlined" style="font-size: 14px;">category</span><span x-text="t.type"></span></span>
                                    </template>
                                    <template x-if="t.max_people">
                                        <span class="text-xs text-gray-500 flex items-center gap-1"><span class="material-symbols-outlined" style="font-size: 14px;">groups</span><span x-text="t.max_people"></span> <?= T::people ?? 'people' ?></span>
                                    </template>
                                </div>

                                <template x-if="t.days">
                                    <div class="flex items-center gap-1 mb-2 text-sm text-gray-900">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">schedule</span>
                                        <span class="font-medium"><span x-text="t.days + ' ' + (t.days > 1 ? 'Days' : 'Day')"></span><span x-show="t.nights" x-text="' / ' + t.nights + ' ' + (t.nights > 1 ? 'Nights' : 'Night')"></span></span>
                                    </div>
                                </template>

                                <div class="flex items-end justify-between mt-auto pt-3 border-t border-gray-100">
                                    <div class="flex flex-col">
                                        <template x-if="t.price > 0">
                                            <div>
                                                <span class="text-xs text-gray-500"><?= T::from ?? 'From' ?></span>
                                                <div class="flex items-baseline gap-1">
                                                    <span class="text-lg font-bold text-gray-900"><span x-text="t.currency"></span> <span x-text="money(t.price)"></span></span>
                                                    <span class="text-xs text-gray-500">/<?= T::person ?? 'person' ?></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!(t.price > 0)">
                                            <span class="text-sm font-semibold text-gray-600"><?= T::contact_for_price ?? 'Contact for Price' ?></span>
                                        </template>
                                    </div>

                                    <template x-if="t.stars > 0">
                                        <div class="flex items-center gap-1">
                                            <div class="bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold flex items-center gap-1">
                                                <svg class="w-3.5 h-3.5 text-orange-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                                                <span x-text="t.stars + '.0'"></span>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <template x-if="t.highlights && t.highlights.length">
                                    <div class="flex flex-wrap gap-1.5">
                                        <template x-for="h in t.highlights" :key="h">
                                            <span class="bg-green-50 text-green-700 text-[10px] px-2 py-0.5 rounded-full font-medium" x-text="h"></span>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            <a :href="link(t)" target="_blank" rel="noopener noreferrer" class="absolute inset-0 z-10"></a>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </section>
</div>

<script>
function toursFeatured(cfg) {
    return {
        locs: [], byLoc: {}, active: '', date: '',
        init() {
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(cfg.url).then(r => r.json()).then(d => {
                        const x = d.data || {};
                        this.locs = (x.locations || []).slice(0, 8); // match the homepage's 8-tab limit
                        this.byLoc = x.tours_by_location || {};
                        this.date = (x.default_dates && x.default_dates.date) || '';
                        this.active = this.locs.length ? this.locs[0].slug : '';
                        this.$el.style.minHeight = '';
                    }).catch(() => { this.$el.style.minHeight = ''; });
                }
            };
            addEventListener('scroll', f, { passive: true });
            f();
        },
        money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        link(t) { return cfg.root + 'tour/' + t.slug + '/' + t.id + '/tours/' + this.date + '/' + (t.days || 1) + '/' + cfg.adults + '-' + cfg.children; }
    };
}
</script>
