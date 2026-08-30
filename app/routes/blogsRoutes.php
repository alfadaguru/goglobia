<?php
// app/routes/blogsRoutes.php
@$SECURE or die('Access Denied!');

// ====================================
// BLOG LISTING PAGE
// ====================================
$router->get('/blog', function () use ($SECURE, $db) {
    $lang = $_SESSION['app_language'] ?? 'en';

    // Pagination
    $per_page = 9;
    $current_page = max(1, intval($_GET['page'] ?? 1));
    $offset = ($current_page - 1) * $per_page;

    // Total count for pagination
    $total_blogs = $db->count("blogs", ["status" => 1]);
    $total_pages = ceil($total_blogs / $per_page);

    // Fetch active blogs with pagination
    $blogs = $db->select("blogs", "*", [
        "status" => 1,
        "ORDER" => ["created_at" => "DESC"],
        "LIMIT" => [$offset, $per_page]
    ]);

    $title = (T::blogs ?? 'Blogs') . ' - ' . $GLOBALS['app']['home_title'];
    $description = $GLOBALS['app']['meta_description'];
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."modules/blogs/listing.php";
    require_once views."includes/footer.php";
});

// ====================================
// BLOG DETAIL PAGE
// ====================================
$router->get('/blog/(.+)', function ($slug) use ($SECURE, $db) {
    $lang = $_SESSION['app_language'] ?? 'en';

    // Fetch the blog post by slug (trimming any trailing slash)
    $clean_slug = rtrim(urldecode($slug), '/');
    $blog = $db->get("blogs", "*", ["post_slug" => $clean_slug, "status" => 1]);

    if (!$blog) {
        header("Location: " . root . "blog");
        exit();
    }

    // Increment view count
    $db->update("blogs", ["views[+]" => 1], ["id" => $blog['id']]);
    $blog['views'] = ($blog['views'] ?? 0) + 1;

    $post_title = $blog['post_title'];
    $post_desc = $blog['post_desc'];

    // Handle translations if active language is not English
    if ($lang !== 'en' && !empty($blog['translations'])) {
        $translations = json_decode($blog['translations'], true);
        if (isset($translations[$lang])) {
            $post_title = $translations[$lang]['title'] ?? $post_title;
            $post_desc = $translations[$lang]['desc'] ?? $post_desc;
        }
    }

    $blog['post_title'] = $post_title;
    $blog['post_desc'] = $post_desc;

    // Get category name
    $category_name = '';
    if (!empty($blog['post_category'])) {
        $category_name = $db->get('blog_categories', 'cat_name', ['id' => $blog['post_category']]) ?? '';
    }

    // Get related posts (same category, exclude current)
    $related_posts = [];
    if (!empty($blog['post_category'])) {
        $related_posts = $db->select("blogs", "*", [
            "post_category" => $blog['post_category'],
            "id[!]" => $blog['id'],
            "status" => 1,
            "ORDER" => ["created_at" => "DESC"],
            "LIMIT" => 3
        ]);
    }
    // If not enough related, fill with recent posts
    if (count($related_posts) < 3) {
        $exclude_ids = array_merge([$blog['id']], array_column($related_posts, 'id'));
        $more = $db->select("blogs", "*", [
            "id[!]" => $exclude_ids,
            "status" => 1,
            "ORDER" => ["created_at" => "DESC"],
            "LIMIT" => 3 - count($related_posts)
        ]);
        $related_posts = array_merge($related_posts, $more);
    }

    $title = $post_title . ' - ' . $GLOBALS['app']['home_title'];
    $description = $blog['meta_description'] ?? substr(strip_tags($post_desc), 0, 160);
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."modules/blogs/detail.php";
    require_once views."includes/footer.php";
});
