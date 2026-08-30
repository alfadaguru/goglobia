<?php
// app/views/modules/blogs/listing.php
@$SECURE or die('Access Denied!');
?>

<div class="bg-gray-50 min-h-screen py-12">
    <div class="container mx-auto px-4">
        <!-- Header Section -->
        <div class="max-w-4xl mx-auto text-center mb-12">
            <h1 class="text-4xl font-bold text-gray-900 mb-4"><?= T::blogs ?? 'Latest Travel Stories' ?></h1>
            <p class="text-lg text-gray-600"><?= T::blogs_description ?? 'Discover tips, guides, and inspiration for your next adventure.' ?></p>
        </div>

        <?php if (!empty($blogs)): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 max-w-7xl mx-auto">
                <?php foreach ($blogs as $blog): ?>
                    <?php
                        // Handle translations for the list
                        $post_title = $blog['post_title'];
                        $post_desc = $blog['post_desc'];
                        $lang = $_SESSION['app_language'] ?? 'en';
                        
                        if ($lang !== 'en' && !empty($blog['translations'])) {
                            $translations = json_decode($blog['translations'], true);
                            if (isset($translations[$lang])) {
                                $post_title = $translations[$lang]['title'] ?? $post_title;
                                $post_desc = $translations[$lang]['desc'] ?? $post_desc;
                            }
                        }
                    ?>
                    <article class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition-shadow duration-300">
                        <!-- Blog Image -->
                        <div class="aspect-video relative overflow-hidden group">
                            <img src="<?= !empty($blog['post_img']) ? root . $blog['post_img'] : root . 'uploads/no_img.jpg' ?>" 
                                 alt="<?= htmlspecialchars($post_title) ?>" 
                                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/20 to-transparent"></div>
                        </div>

                        <!-- Content -->
                        <div class="p-6">
                            <div class="flex items-center gap-2 mb-3">
                                <?php if (!empty($blog['post_category'])): ?>
                                    <span class="px-2.5 py-1 bg-blue-50 text-blue-600 text-xs font-semibold rounded-full tracking-wide uppercase">
                                        <?= $db->get('blog_categories', 'cat_name', ['id' => $blog['post_category']]) ?>
                                    </span>
                                <?php endif; ?>
                                <span class="text-xs text-gray-400">
                                    <?= date('M d, Y', strtotime($blog['created_at'])) ?>
                                </span>
                            </div>

                            <h2 class="text-xl font-bold text-gray-900 mb-3 line-clamp-2">
                                <a href="<?= root ?>blog/<?= $blog['post_slug'] ?>" class="hover:text-blue-600 transition-colors">
                                    <?= htmlspecialchars($post_title) ?>
                                </a>
                            </h2>

                            <p class="text-gray-600 text-sm line-clamp-3 mb-6">
                                <?= strip_tags($post_desc) ?>
                            </p>

                            <a href="<?= root ?>blog/<?= $blog['post_slug'] ?>" 
                               class="inline-flex items-center gap-2 text-blue-600 text-sm font-bold hover:gap-3 transition-all">
                                <?= T::read_more ?? 'Read More' ?>
                                <span class="material-symbols-outlined text-sm">arrow_forward</span>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav class="flex items-center justify-center gap-2 mt-12" aria-label="Pagination">
                <?php if ($current_page > 1): ?>
                    <a href="<?= root ?>blog?page=<?= $current_page - 1 ?>" 
                       class="inline-flex items-center gap-1 px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all">
                        <span class="material-symbols-outlined !text-[18px]">chevron_left</span>
                        <?= T::previous ?? 'Previous' ?>
                    </a>
                <?php endif; ?>

                <div class="flex items-center gap-1">
                    <?php
                    // Smart page range: show first, last, and nearby pages
                    $range = 2;
                    $start = max(1, $current_page - $range);
                    $end = min($total_pages, $current_page + $range);
                    ?>

                    <?php if ($start > 1): ?>
                        <a href="<?= root ?>blog?page=1" class="w-10 h-10 flex items-center justify-center text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all">1</a>
                        <?php if ($start > 2): ?>
                            <span class="w-10 h-10 flex items-center justify-center text-gray-400 text-sm">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i === $current_page): ?>
                            <span class="w-10 h-10 flex items-center justify-center text-sm font-bold text-white bg-blue-600 rounded-xl shadow-sm"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= root ?>blog?page=<?= $i ?>" class="w-10 h-10 flex items-center justify-center text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end < $total_pages): ?>
                        <?php if ($end < $total_pages - 1): ?>
                            <span class="w-10 h-10 flex items-center justify-center text-gray-400 text-sm">...</span>
                        <?php endif; ?>
                        <a href="<?= root ?>blog?page=<?= $total_pages ?>" class="w-10 h-10 flex items-center justify-center text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all"><?= $total_pages ?></a>
                    <?php endif; ?>
                </div>

                <?php if ($current_page < $total_pages): ?>
                    <a href="<?= root ?>blog?page=<?= $current_page + 1 ?>" 
                       class="inline-flex items-center gap-1 px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all">
                        <?= T::next ?? 'Next' ?>
                        <span class="material-symbols-outlined !text-[18px]">chevron_right</span>
                    </a>
                <?php endif; ?>
            </nav>
            <p class="text-center text-sm text-gray-400 mt-4">
                <?= T::page ?? 'Page' ?> <?= $current_page ?> <?= T::of ?? 'of' ?> <?= $total_pages ?>
            </p>
            <?php endif; ?>

        <?php else: ?>
            <div class="text-center py-20 bg-white rounded-2xl border border-dashed border-gray-300">
                <span class="material-symbols-outlined text-6xl text-gray-200 mb-4">newspaper</span>
                <p class="text-gray-500"><?= T::no_blogs_found ?? 'No blog posts found at the moment.' ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>
