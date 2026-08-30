<?php
@$SECURE or die('Access Denied!');

// Render the section shell only when the eSIM module is enabled (same as before).
if (!in_array('esim', array_column($GLOBALS['modules'] ?? [], 'type'), true)) {
    return;
}
$cur = strtoupper($_SESSION['app_currency'] ?? 'USD');
?>
<style>#esim-featured-track{-ms-overflow-style:none;scrollbar-width:none}#esim-featured-track::-webkit-scrollbar{display:none}[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout so the scroll check has a real position; content shows once loaded -->
<div x-data="esimFeatured('<?= root ?>api/esim/featured?currency=<?= $cur ?>')" style="min-height:220px">
    <!-- SKELETON — VISIBLE FROM FIRST PAINT UNTIL THE FEATURED DATA ARRIVES -->
    <div x-show="!loaded" class="container py-5 mt-5">
        <div class="animate-pulse">
            <div class="h-6 w-48 bg-gray-200 rounded mb-2"></div>
            <div class="h-3 w-72 bg-gray-100 rounded mb-5"></div>
            <div class="flex gap-3 overflow-hidden">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <div class="w-[250px] shrink-0 bg-white rounded-xl border border-gray-200 p-3">
                    <div class="h-4 w-16 bg-gray-100 rounded-full mb-3"></div>
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-gray-200 shrink-0"></div>
                        <div class="flex-1">
                            <div class="h-3.5 w-24 bg-gray-200 rounded mb-1.5"></div>
                            <div class="h-3 w-16 bg-gray-100 rounded"></div>
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    <section class="relative py-5 mt-5" x-show="items.length" x-cloak
             x-transition:enter="transition-opacity duration-500"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100">
        <div class="container">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-[1.2rem] font-bold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[1.4rem]">sim_card</span><?= T::featured ?> <?= T::esims ?></h2>
                    <p class="text-xs text-gray-600"><?= T::featured_countries_description ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn light w-9 h-9 p-0 inline-flex items-center justify-center rounded-lg" @click="scrollByCards(-1)" :disabled="atStart" aria-label="Scroll featured eSIM left"><span class="material-symbols-outlined text-[18px]">chevron_left</span></button>
                    <button type="button" class="btn light w-9 h-9 p-0 inline-flex items-center justify-center rounded-lg" @click="scrollByCards(1)" :disabled="atEnd" aria-label="Scroll featured eSIM right"><span class="material-symbols-outlined text-[18px]">chevron_right</span></button>
                </div>
            </div>

            <div id="esim-featured-track" class="overflow-x-auto pb-2" x-ref="esimTrack" @scroll="syncScroll()">
                <div class="flex gap-3 min-w-max">
                    <template x-for="item in items" :key="item.id">
                        <a :href="item.url" class="group w-[250px] bg-white rounded-xl border border-gray-200 p-3 hover:border-blue-400 hover:shadow-md transition-all duration-300">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <span class="text-[11px] text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full font-semibold">Country</span>
                                <span class="text-[10px] text-slate-500" x-text="item.country_iso"></span>
                            </div>
                            <div class="flex items-center gap-2 mb-2">
                                <div class="w-8 h-8 rounded-full overflow-hidden bg-slate-100 border border-slate-200 flex items-center justify-center shrink-0">
                                    <img :src="item.flag" :alt="item.country_iso" class="w-full h-full object-cover" loading="lazy" decoding="async" onerror="this.style.display='none';this.parentNode.innerHTML='<span class=&quot;material-symbols-outlined text-slate-500 text-[16px]&quot;>flag</span>';">
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 leading-tight line-clamp-2" x-text="item.title"></h3>
                                    <p class="text-xs text-gray-500" x-text="item.country_name"></p>
                                </div>
                            </div>
                        </a>
                    </template>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
function esimFeatured(url) {
    return {
        items: [], atStart: true, atEnd: false, loaded: false,
        init() {
            // Fetch the data only when the wrapper is about to enter the viewport.
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(url).then(r => r.json())
                        .then(d => {
                            this.items = (d.data && d.data.featured) || [];
                            this.loaded = true;
                            this.$el.style.minHeight = '';           // release the reserved space
                            this.$nextTick(() => this.syncScroll());
                        })
                        .catch(() => { this.loaded = true; this.$el.style.minHeight = ''; });
                }
            };
            addEventListener('scroll', f, { passive: true });
            f();
        },
        scrollByCards(dir) {
            const t = this.$refs.esimTrack; if (!t) return;
            t.scrollBy({ left: dir * Math.max(260, Math.floor(t.clientWidth * 0.9)), behavior: 'smooth' });
        },
        syncScroll() {
            const t = this.$refs.esimTrack; if (!t) return;
            this.atStart = t.scrollLeft <= 5;
            this.atEnd = Math.ceil(t.scrollLeft + t.clientWidth) >= t.scrollWidth - 5;
        }
    };
}
</script>
