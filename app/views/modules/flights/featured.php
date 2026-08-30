<?php
@$SECURE or die('Access Denied!');

// Render the section shell only when the flights module is enabled.
if (!in_array('flights', array_column($GLOBALS['modules'] ?? [], 'type'), true)) {
    return;
}
$cur      = strtoupper($_SESSION['app_currency'] ?? 'USD');
$adults   = (int) ($_SESSION['flight_adults'] ?? 1);
$children = (int) ($_SESSION['flight_children'] ?? 0);
$infants  = (int) ($_SESSION['flight_infants'] ?? 0);
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout for the scroll check; content shows once loaded -->
<div style="min-height:300px"
    x-data="flightsFeatured({url:'<?= root ?>api/flights/featured?currency=<?= $cur ?>', root:'<?= root ?>', a:<?= $adults ?>, c:<?= $children ?>, i:<?= $infants ?>})">
    <section class="my-12" x-show="items.length" x-cloak>
        <div class="container">
            <div class="mb-6">
                <h2 class="text-[1.2rem] font-bold text-gray-900 mb-2 flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[1.4rem]">flight_takeoff</span><?= T::featured ?> <?= T::flights ?></h2>
                <p class="text-gray-600 text-sm"><?= T::featured_flights_description ?></p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <template x-for="f in items" :key="f.id">
                    <a :href="link(f)" class="group relative bg-white border border-gray-200 rounded-lg p-3 hover:border-blue-500 hover:shadow-lg transition-all duration-300 overflow-hidden">
                        <div class="absolute top-0 right-0 w-16 h-16 bg-blue-50 rounded-bl-full -mr-8 -mt-8 group-hover:bg-blue-100 transition-colors"></div>
                        <div class="relative">
                            <!-- ROUTE -->
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-lg font-bold text-gray-900" x-text="(f.from.code || '').toUpperCase()"></span>
                                <div class="flex-1 flex items-center justify-center px-3">
                                    <div class="flex-1 h-px bg-gradient-to-r from-blue-600 to-blue-300"></div>
                                    <span class="material-symbols-outlined text-blue-600 !text-lg bg-blue-50 rounded-full p-0.5 mx-2">flight</span>
                                    <div class="flex-1 h-px bg-gradient-to-r from-blue-300 to-blue-600"></div>
                                </div>
                                <span class="text-lg font-bold text-gray-900" x-text="(f.to.code || '').toUpperCase()"></span>
                            </div>

                            <!-- DETAILS -->
                            <div class="flex items-center justify-between text-xs">
                                <div class="flex-1"><p class="text-gray-600 font-medium truncate" x-text="f.from.city"></p></div>
                                <div class="flex-1 text-center"><p class="text-gray-500 truncate flex items-center justify-center gap-1"><span class="material-symbols-outlined !text-xs">airlines</span><span x-text="f.airline.name"></span></p></div>
                                <div class="flex-1 text-right"><p class="text-gray-600 font-medium truncate" x-text="f.to.city"></p></div>
                            </div>

                            <!-- PRICE -->
                            <div class="mt-2 flex items-center justify-between">
                                <div class="flex items-center gap-1.5 text-xs text-gray-500"><span class="material-symbols-outlined !text-sm">calendar_today</span> One Way</div>
                                <span class="bg-blue-600 text-white px-3 py-1 rounded-md text-xs font-semibold">from <span x-text="f.currency"></span> <span x-text="money(f.price)"></span></span>
                            </div>
                        </div>
                    </a>
                </template>
            </div>
        </div>
    </section>
</div>

<script>
function flightsFeatured(cfg) {
    return {
        items: [],
        init() {
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(cfg.url).then(r => r.json())
                        .then(d => { this.items = ((d.data && d.data.flights) || []).slice(0, 6); this.$el.style.minHeight = ''; })
                        .catch(() => { this.$el.style.minHeight = ''; });
                }
            };
            addEventListener('scroll', f, { passive: true });
            f();
        },
        money(v) { return Math.round(v).toLocaleString(); },
        link(f) {
            return cfg.root + 'flights/' + (f.from.code || '').toLowerCase() + '/' + (f.to.code || '').toLowerCase()
                + '/oneway/' + f.cabin + '/' + f.date + '/' + cfg.a + '/' + cfg.c + '/' + cfg.i;
        }
    };
}
</script>
