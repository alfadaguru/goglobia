<?php
// app/views/modules/blogs/featured.php
@$SECURE or die('Access Denied!');
$lang = $_SESSION['app_language'] ?? 'en';
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout for the scroll check; content shows once loaded -->
<div style="min-height:300px" x-data="blogsFeatured('<?= root ?>api/blogs?limit=4&lang=<?= $lang ?>')">
    <section class="py-14 bg-gray-50" x-show="items.length" x-cloak>
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h2 class="text-[1.2rem] font-bold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[1.4rem]">article</span><?= T::featured ?? 'Featured' ?> <?= T::blogs ?? 'Blog' ?></h2>
                </div>
                <a href="<?= root ?>blog" class="hidden sm:inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700 transition-colors">
                    <?= T::view_all ?? 'View all' ?>
                    <span class="material-symbols-outlined !text-[18px]">arrow_forward</span>
                </a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <template x-for="b in items" :key="b.id">
                    <a :href="'<?= root ?>blog/' + b.post_slug" class="group bg-white rounded-xl overflow-hidden shadow-sm border border-gray-100 hover:shadow-md transition-all duration-300">
                        <div class="aspect-[3/2] overflow-hidden">
                            <img :src="b.post_img ? '<?= root ?>' + b.post_img : '<?= root ?>uploads/no_img.jpg'"
                                 :alt="b.post_title" loading="lazy" decoding="async" fetchpriority="low"
                                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                        </div>
                        <div class="p-4">
                            <template x-if="b.category_name">
                                <span class="text-[11px] font-bold tracking-wider uppercase text-blue-600" x-text="b.category_name"></span>
                            </template>
                            <h3 class="font-bold text-gray-900 group-hover:text-blue-600 transition-colors line-clamp-2 mt-1 mb-2 text-[15px] leading-snug" x-text="b.post_title"></h3>
                            <span class="text-xs text-gray-400" x-text="fmtDate(b.created_at)"></span>
                        </div>
                    </a>
                </template>
            </div>
            <div class="text-center mt-6 sm:hidden">
                <a href="<?= root ?>blog" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600">
                    <?= T::view_all ?? 'View all posts' ?>
                    <span class="material-symbols-outlined !text-[18px]">arrow_forward</span>
                </a>
            </div>
        </div>
    </section>
</div>

<script>
function blogsFeatured(url) {
    return {
        items: [],
        init() {
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(url).then(r => r.json())
                        .then(d => { this.items = ((d.data && d.data.blogs) || []).slice(0, 4); this.$el.style.minHeight = ''; })
                        .catch(() => { this.$el.style.minHeight = ''; });
                }
            };
            addEventListener('scroll', f, { passive: true });
            f();
        },
        fmtDate(d) {
            try { return new Date(String(d).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
            catch (e) { return ''; }
        }
    };
}
</script>
