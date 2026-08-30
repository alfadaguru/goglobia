<?php
@$SECURE or die('Access Denied!');

// Render the section shell only when the stays module is enabled.
if (!in_array('stays', array_column($GLOBALS['modules'] ?? [], 'type'), true)) {
    return;
}
$cur = strtoupper($_SESSION['app_currency'] ?? 'USD');
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout for the scroll check; content shows once loaded -->
<div style="min-height:420px" x-data="staysFeatured('<?= root ?>api/stays/featured?currency=<?= $cur ?>')">
    <section class="relative bg-gradient-to-b py-5 mt-5" x-show="locs.length" x-cloak>
        <div class="container">
            <div class="mb-10">
                <h2 class="text-[1.2rem] font-bold text-gray-900 mb-5 flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[1.4rem]">hotel</span><?= T::featured_properties ?></h2>

                <div class="flex flex-wrap items-center gap-6 mb-8">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-500" style="font-size: 20px;">verified</span>
                        <span class="text-sm text-gray-700 font-medium"><?= T::we_price_match ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-teal-500" style="font-size: 20px;">task_alt</span>
                        <span class="text-sm text-gray-700 font-medium"><?= T::hotel_booking_guarantee ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-500" style="font-size: 20px;">workspace_premium</span>
                        <span class="text-sm text-gray-700 font-medium"><?= T::hotel_stay_guarantee ?></span>
                    </div>
                </div>

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

            <template x-for="loc in locs" :key="loc.slug">
                <div x-show="active === loc.slug"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform translate-y-2"
                     x-transition:enter-end="opacity-100 transform translate-y-0"
                     class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">

                    <template x-if="!(byLoc[loc.slug] || []).length">
                        <div class="col-span-4 text-center py-12">
                            <div class="w-16 h-16 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                                <span class="material-symbols-outlined text-gray-400 text-2xl">hotel</span>
                            </div>
                            <h3 class="text-gray-600 font-medium text-lg mb-2"><?= T::no_featured_hotels_in ?> <span x-text="loc.name"></span></h3>
                            <p class="text-gray-500 text-sm"><?= T::check_back_later_for_featured_properties ?></p>
                        </div>
                    </template>

                    <template x-for="h in (byLoc[loc.slug] || [])" :key="h.id">
                        <div class="group relative bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-2xl transition-all duration-500 border border-gray-100 flex flex-col">
                            <div class="relative h-52 overflow-hidden">
                                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent z-10"></div>
                                <div class="absolute top-3 left-3 z-20 flex flex-col gap-2">
                                    <template x-if="h.discount">
                                        <span class="bg-red-500 text-white text-xs font-bold px-2.5 py-1 rounded-lg shadow-lg"><span x-text="h.discount"></span>% <?= T::off ?></span>
                                    </template>
                                    <span class="bg-black/60 backdrop-blur-sm text-white text-xs font-medium px-2.5 py-1 rounded-lg flex items-center gap-1">
                                        <span class="material-symbols-outlined" style="font-size: 14px;">location_on</span>
                                        <span x-text="h.location"></span>
                                    </span>
                                </div>
                                <img :src="h.image" :alt="h.name" loading="lazy" decoding="async" fetchpriority="low"
                                     class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700"
                                     onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                            </div>

                            <div class="p-4 flex flex-col flex-1">
                                <h3 class="font-bold text-gray-900 text-base mb-2 line-clamp-1 group-hover:text-blue-600 transition-colors" x-text="h.name"></h3>
                                <p class="text-xs text-gray-500 mb-3 flex items-center gap-1 truncate">
                                    <span class="material-symbols-outlined shrink-0" style="font-size: 14px;">home</span>
                                    <span class="truncate" x-text="h.address"></span>
                                </p>

                                <div class="flex items-center justify-between mt-auto pt-3 border-t border-gray-100">
                                    <div class="flex flex-col">
                                        <template x-if="h.min_room_price > 0">
                                            <div>
                                                <span class="text-xs text-gray-600"><?= T::from ?></span>
                                                <span class="text-base font-bold text-gray-900 block"><span x-text="h.currency"></span> <span x-text="money(h.min_room_price)"></span></span>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <div class="bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5 text-orange-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                                            <span x-text="h.stars + '.0'"></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="a in (h.amenities || [])" :key="a">
                                        <span class="bg-blue-50 text-blue-700 text-[10px] px-2 py-0.5 rounded-full font-medium" x-text="a"></span>
                                    </template>
                                </div>
                            </div>

                            <a :href="h.url" target="_blank" rel="noopener noreferrer" class="absolute inset-0 z-10"></a>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </section>
</div>

<script>
function staysFeatured(url) {
    return {
        locs: [], byLoc: {}, active: '',
        init() {
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(url).then(r => r.json()).then(d => {
                        const x = d.data || {};
                        this.locs = x.locations || [];
                        this.byLoc = x.hotels_by_location || {};
                        this.active = this.locs.length ? this.locs[0].slug : '';
                        this.$el.style.minHeight = '';
                    }).catch(() => { this.$el.style.minHeight = ''; });
                }
            };
            addEventListener('scroll', f, { passive: true });
            f();
        },
        money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    };
}
</script>
