<!-- CMS cms_page Content -->
<div class="bg-white">
    <!-- Compact Header -->
    <div class="border-b border-gray-200">
        <div class="container mx-auto px-4 py-8">
            <div class="max-w-4xl mx-auto">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">
                    <?php
                    $title = $cms_page['page_name'] ?? $cms_page['page_name'] ?? $cms_page['page_name'] ?? $cms_page['page_name'] ?? 'Title';
                    echo htmlspecialchars($title);
                    ?>
                </h1>

                <?php if (!empty($cms_page['desc'])): ?>
                    <p class="text-lg text-gray-600 mb-3">
                        <?= htmlspecialchars($cms_page['desc']) ?>
                    </p>
                <?php endif; ?>

                <!-- Compact Meta Info -->
                <?php if (!empty($cms_page['created_at']) || !empty($cms_page['author']) || !empty($cms_page['category'])): ?>
                <div class="flex flex-wrap gap-4 text-sm text-gray-500">
                    <?php if (!empty($cms_page['created_at'])): ?>

                        <?php if(basename($_SERVER['REQUEST_URI']) != 'contact-us'){ ?>
                        <span><?= date('M j, Y', strtotime($cms_page['created_at'])) ?></span>
                        <?php } ?>

                    <?php endif; ?>
                    <?php if (!empty($cms_page['author'])): ?>
                        <span>By <?= htmlspecialchars($cms_page['author']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($cms_page['category'])): ?>
                        <span class="px-2 py-1 bg-gray-100 rounded text-xs"><?= htmlspecialchars($cms_page['category']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <div class="prose prose-lg max-w-none [&_p]:leading-relaxed
                [&_h1]:text-2xl [&_h1]:leading-[28px] [&_h1]:font-semibold [&_h1]:text-[#1E2358] [&_h1]:mb-4 [&_h1]:mt-6 [&_h1:first-child]:mt-0
                [&_h2]:text-xl [&_h2]:leading-6 [&_h2]:font-semibold [&_h2]:text-[#1E2358] [&_h2]:mb-3 [&_h2]:mt-5 [&_h2:first-child]:mt-0
                [&_h3]:text-lg [&_h3]:leading-5 [&_h3]:font-semibold [&_h3]:text-[#1E2358] [&_h3]:mb-2 [&_h3]:mt-4
                [&_ul]:list-disc [&_ul]:ml-6 [&_ul]:mb-4 [&_ul]:space-y-1
                [&_ol]:list-decimal [&_ol]:ml-6 [&_ol]:mb-4 [&_ol]:space-y-1
                [&_a]:font-semibold [&_a]:text-[#1E2358] [&_a]:underline [&_a]:underline-offset-2 hover:[&_a]:text-[#253183]
                [&_strong]:font-semibold [&_strong]:text-[#1E2358]
                [&_figure.table]:!border-0 [&_figure.table]:!rounded-none [&_figure.table]:!shadow-none
                [&_figure.table]:!w-full [&_figure.table]:max-w-full [&_figure.table]:overflow-x-auto md:[&_figure.table]:overflow-visible
                [&_figure.table_table]:!w-full [&_figure.table_table]:!border-0 [&_figure.table_table]:min-w-0 [&_figure.table_table]:border-collapse
                [&_figure.table_td]:!border-0 [&_figure.table_td]:p-0 [&_figure.table_th]:!border-0
                [&_figure.table_tr]:border-0 hover:[&_figure.table_tr]:!bg-transparent
                max-lg:[&_figure.table_colgroup]:hidden
                max-lg:[&_figure.table_tbody]:block max-lg:[&_figure.table_tr]:flex max-lg:[&_figure.table_tr]:flex-col max-lg:[&_figure.table_tr]:gap-6
                max-lg:[&_figure.table_td]:!block max-lg:[&_figure.table_td]:!w-full max-lg:[&_figure.table_td]:align-top
                md:[&_figure.table_td]:align-top
                [&_figure.image]:!w-full [&_figure.image]:max-w-full [&_figure.image]:mx-0
                [&_figure.image_img]:!h-auto [&_figure.image_img]:!w-full [&_figure.image_img]:!max-w-full [&_figure.image_img]:rounded-xl [&_figure.image_img]:object-cover
                [&_img]:h-auto [&_img]:max-w-full">
                <?php
                $content = $cms_page['content'] ?? '';

                if (empty($content)) {
                    echo '<p class="text-gray-500 italic">No content available.</p>';
                } elseif (strip_tags($content) !== $content) {
                    // HTML content
                    $processedContent = $content;
                    $processedContent = str_replace('<h1>', '<h1 class="text-2xl font-bold text-gray-900 mb-4 mt-6 first:mt-0">', $processedContent);
                    $processedContent = str_replace('<h2>', '<h2 class="text-xl font-bold text-gray-900 mb-3 mt-5 first:mt-0">', $processedContent);
                    $processedContent = str_replace('<h3>', '<h3 class="text-lg font-semibold text-gray-900 mb-2 mt-4">', $processedContent);
                    // $processedContent = str_replace('<p>', '<p class="text-gray-700 mb-4 leading-relaxed">', $processedContent);
                    $processedContent = str_replace('<ul>', '<ul class="list-disc ml-6 mb-4 space-y-1">', $processedContent);
                    $processedContent = str_replace('<ol>', '<ol class="list-decimal ml-6 mb-4 space-y-1">', $processedContent);
                    $processedContent = str_replace('<a ', '<a class="text-blue-600 hover:underline" ', $processedContent);
                    echo $processedContent;
                } else {
                    // Plain text content
                    $sections = explode("\n\n", trim($content));
                    foreach ($sections as $section) {
                        $section = trim($section);
                        if (empty($section)) continue;
                        // echo '<p class="text-gray-700 mb-4 leading-relaxed">' . nl2br(htmlspecialchars($section)) . '</p>';
                    }
                }
                ?>
            </div>
        </div>
    </div>

    <?php if (isset($slug) && $slug === 'contact-us'): ?>
        <?php $mapUrl = $GLOBALS['app']['map_address'] ?? ''; ?>
        <?php if (!empty($mapUrl)): ?>
        <div class="container mx-auto px-4 pb-8">
            <div class="max-w-4xl mx-auto">
                <iframe src="<?= htmlspecialchars($mapUrl) ?>" width="100%" height="450" style="border:0; border-radius: 12px;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>