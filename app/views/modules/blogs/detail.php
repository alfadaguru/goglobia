<?php
// app/views/modules/blogs/detail.php — Ghost CMS inspired design
@$SECURE or die('Access Denied!');

$post_img = !empty($blog['post_img']) ? root . $blog['post_img'] : null;

// Estimate reading time
$word_count = str_word_count(strip_tags($blog['post_desc'] ?? ''));
$reading_time = max(1, round($word_count / 230));

// Format date
$published_date = date('F j, Y', strtotime($blog['published_at'] ?? $blog['created_at']));
?>

<!-- Ghost-style Blog Detail -->
<article class="bg-white">

    <!-- ═══════════════════════════════════════════ -->
    <!-- ARTICLE HEADER                              -->
    <!-- ═══════════════════════════════════════════ -->
    <header class="pt-16 pb-10 px-4">
        <div class="max-w-3xl mx-auto text-center">
            <!-- Category Tag -->
            <?php if (!empty($category_name)): ?>
                <span class="inline-block px-3.5 py-1.5 text-xs font-bold tracking-widest uppercase text-blue-600 bg-blue-50 rounded-full mb-6">
                    <?= htmlspecialchars($category_name) ?>
                </span>
            <?php endif; ?>

            <!-- Title -->
            <h1 class="text-4xl md:text-5xl lg:text-[3.25rem] font-extrabold text-gray-950 leading-[1.15] tracking-tight mb-6">
                <?= htmlspecialchars($blog['post_title']) ?>
            </h1>

            <!-- Meta Row -->
            <div class="flex items-center justify-center gap-4 text-sm text-gray-400">
                <time datetime="<?= date('Y-m-d', strtotime($blog['created_at'])) ?>"><?= $published_date ?></time>
                <span class="w-1 h-1 rounded-full bg-gray-300"></span>
                <span><?= $reading_time ?> min read</span>
                <?php if (!empty($blog['views'])): ?>
                    <span class="w-1 h-1 rounded-full bg-gray-300"></span>
                    <span><?= number_format($blog['views']) ?> views</span>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- ═══════════════════════════════════════════ -->
    <!-- FEATURED IMAGE (full-width like Ghost)      -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if ($post_img): ?>
    <figure class="max-w-5xl mx-auto px-4 mb-14">
        <div class="rounded-2xl overflow-hidden shadow-lg aspect-[2/1]">
            <img src="<?= $post_img ?>"
                 alt="<?= htmlspecialchars($blog['post_title']) ?>"
                 class="w-full h-full object-cover">
        </div>
    </figure>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════ -->
    <!-- ARTICLE BODY                                -->
    <!-- ═══════════════════════════════════════════ -->
    <div class="px-4 pb-16">
        <div class="max-w-[720px] mx-auto">
            <div class="ghost-content text-[1.125rem] leading-[1.85] text-gray-700">
                <?php
                if (!empty($blog['post_desc'])) {
                    $content = $blog['post_desc'];
                    if (strip_tags($content) !== $content) {
                        echo $content;
                    } else {
                        // Plain text — wrap in paragraphs
                        $paragraphs = preg_split('/\n{2,}/', trim($content));
                        foreach ($paragraphs as $p) {
                            echo '<p>' . nl2br(htmlspecialchars($p)) . '</p>';
                        }
                    }
                }
                ?>
            </div>

            <!-- Tags / Keywords -->
            <?php if (!empty($blog['meta_keywords'])): ?>
            <div class="mt-12 pt-8 border-t border-gray-100 flex flex-wrap gap-2">
                <?php foreach (explode(',', $blog['meta_keywords']) as $tag): ?>
                    <span class="px-3 py-1.5 text-xs font-medium text-gray-500 bg-gray-100 rounded-full">
                        <?= htmlspecialchars(trim($tag)) ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Share & Navigate -->
            <div class="mt-10 pt-8 border-t border-gray-100 flex items-center justify-between">
                <a href="<?= root ?>blog" class="inline-flex items-center gap-2 text-gray-500 hover:text-gray-900 font-medium transition-colors">
                    <span class="material-symbols-outlined !text-[20px]">arrow_back</span>
                    <?= T::back_to_blogs ?? 'All posts' ?>
                </a>

                <!-- Share buttons -->
                <div class="flex items-center gap-1">
                    <span class="text-xs text-gray-400 mr-2 hidden sm:inline"><?= 'Share' ?></span>
                    <a href="https://twitter.com/intent/tweet?text=<?= urlencode($blog['post_title']) ?>&url=<?= urlencode(root . 'blog/' . $blog['post_slug']) ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="w-9 h-9 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-all" title="Share on X">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                    </a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode(root . 'blog/' . $blog['post_slug']) ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="w-9 h-9 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-all" title="Share on Facebook">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    </a>
                    <button onclick="navigator.clipboard.writeText(window.location.href);this.querySelector('span').textContent='check';setTimeout(()=>this.querySelector('span').textContent='link',2000)"
                            class="w-9 h-9 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-all" title="Copy link">
                        <span class="material-symbols-outlined !text-[18px]">link</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════ -->
    <!-- RELATED POSTS (Ghost-style "Read more")     -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (!empty($related_posts)): ?>
    <section class="border-t border-gray-100 bg-gray-50">
        <div class="max-w-6xl mx-auto px-4 py-16">
            <h2 class="text-2xl font-bold text-gray-900 mb-8 text-center"><?='Read next' ?></h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <?php foreach ($related_posts as $rp): ?>
                <a href="<?= root ?>blog/<?= htmlspecialchars((string) $rp['post_slug'], ENT_QUOTES, 'UTF-8') ?>" class="group bg-white rounded-xl overflow-hidden shadow-sm border border-gray-100 hover:shadow-md transition-all duration-300">
                    <div class="aspect-[3/2] overflow-hidden">
                        <img src="<?= !empty($rp['post_img']) ? root . $rp['post_img'] : root . 'uploads/no_img.jpg' ?>"
                             alt="<?= htmlspecialchars($rp['post_title']) ?>"
                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                    </div>
                    <div class="p-5">
                        <h3 class="font-bold text-gray-900 group-hover:text-blue-600 transition-colors line-clamp-2 mb-2">
                            <?= htmlspecialchars($rp['post_title']) ?>
                        </h3>
                        <p class="text-sm text-gray-500 line-clamp-2"><?= substr(strip_tags($rp['post_desc']), 0, 120) ?>...</p>
                        <span class="inline-block mt-3 text-xs text-gray-400"><?= date('M j, Y', strtotime($rp['created_at'])) ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

</article>

<!-- Ghost-style typography -->
<style>
/* ── Ghost Content Typography ── */
.ghost-content p {
    margin-bottom: 1.75em;
    color: #374151;
}
.ghost-content h2 {
    font-size: 1.875rem;
    font-weight: 800;
    color: #111827;
    margin: 2.5em 0 0.75em;
    letter-spacing: -0.02em;
    line-height: 1.25;
}
.ghost-content h3 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1f2937;
    margin: 2em 0 0.6em;
    line-height: 1.3;
}
.ghost-content h4 {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1f2937;
    margin: 1.75em 0 0.5em;
}
.ghost-content a {
    color: #2563eb;
    text-decoration: underline;
    text-decoration-color: rgba(37,99,235,0.3);
    text-underline-offset: 3px;
    transition: text-decoration-color 0.2s;
}
.ghost-content a:hover {
    text-decoration-color: #2563eb;
}
.ghost-content strong {
    color: #111827;
    font-weight: 700;
}
.ghost-content blockquote {
    margin: 2em 0;
    padding: 1.25em 1.5em;
    border-left: 3px solid #2563eb;
    background: #f8fafc;
    border-radius: 0 0.75rem 0.75rem 0;
    font-style: italic;
    color: #475569;
    font-size: 1.1rem;
    line-height: 1.7;
}
.ghost-content blockquote p:last-child {
    margin-bottom: 0;
}
.ghost-content ul,
.ghost-content ol {
    margin: 1.5em 0;
    padding-left: 1.75em;
}
.ghost-content ul {
    list-style: disc;
}
.ghost-content ol {
    list-style: decimal;
}
.ghost-content li {
    margin-bottom: 0.5em;
    padding-left: 0.25em;
}
.ghost-content img {
    border-radius: 1rem;
    margin: 2em auto;
    max-width: 100%;
    height: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.ghost-content figure {
    margin: 2.5em 0;
    text-align: center;
}
.ghost-content figcaption {
    margin-top: 0.75em;
    font-size: 0.875rem;
    color: #9ca3af;
}
.ghost-content pre {
    background: #1f2937;
    color: #e5e7eb;
    padding: 1.5em;
    border-radius: 0.75rem;
    overflow-x: auto;
    margin: 2em 0;
    font-size: 0.9rem;
    line-height: 1.6;
}
.ghost-content code {
    background: #f3f4f6;
    padding: 0.15em 0.4em;
    border-radius: 0.25rem;
    font-size: 0.9em;
    color: #e11d48;
}
.ghost-content pre code {
    background: none;
    padding: 0;
    color: inherit;
}
.ghost-content hr {
    border: none;
    border-top: 1px solid #e5e7eb;
    margin: 3em 0;
}
.ghost-content table {
    width: 100%;
    border-collapse: collapse;
    margin: 2em 0;
}
.ghost-content th,
.ghost-content td {
    padding: 0.75em 1em;
    border: 1px solid #e5e7eb;
    text-align: left;
}
.ghost-content th {
    background: #f9fafb;
    font-weight: 700;
    color: #111827;
}
</style>
