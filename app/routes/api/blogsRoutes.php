<?php
// app/routes/api/blogsRoutes.php
@$SECURE or die('Access Denied!');

/*
|--------------------------------------------------------------------------
| API: BLOG LISTING
|--------------------------------------------------------------------------
*/
$router->get('/api/blogs', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    $lang = $_GET['lang'] ?? 'en';

    // Pagination
    $per_page = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 5;
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

    // Process each blog for category name and translations
    foreach ($blogs as &$blog) {
        // Get category name
        $blog['category_name'] = !empty($blog['post_category'])
            ? ($db->get('blog_categories', 'cat_name', ['id' => $blog['post_category']]) ?? '')
            : '';

        // Handle translations if active language is not English
        if ($lang !== 'en' && !empty($blog['translations'])) {
            $translations = json_decode($blog['translations'], true);
            if (isset($translations[$lang])) {
                $blog['post_title'] = $translations[$lang]['title'] ?? $blog['post_title'];
                $blog['post_desc'] = $translations[$lang]['desc'] ?? $blog['post_desc'];
            }
        }
    }

    echo json_encode([
        'status' => true,
        'message' => 'Blogs fetched successfully',
        'data' => [
            'blogs' => $blogs,
            'pagination' => [
                'current_page' => $current_page,
                'per_page' => $per_page,
                'total_pages' => $total_pages,
                'total_items' => $total_blogs
            ]
        ]
    ]);
});

/*
|--------------------------------------------------------------------------
| API: BLOG DETAIL
|--------------------------------------------------------------------------
*/
$router->get('/api/blogs/(.+)', function ($slug) use ($SECURE, $db) {
    header('Content-Type: application/json');

    $lang = $_GET['lang'] ?? 'en';

    // Fetch the blog post by slug (trimming any trailing slash)
    $clean_slug = rtrim(urldecode($slug), '/');
    $blog = $db->get("blogs", "*", ["post_slug" => $clean_slug, "status" => 1]);

    if (!$blog) {
        echo json_encode(['status' => false, 'message' => 'Blog post not found']);
        return;
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
    $blog['category_name'] = $category_name;

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

    // Handle translations for related posts
    if ($lang !== 'en') {
        foreach ($related_posts as &$r_blog) {
            if (!empty($r_blog['translations'])) {
                $translations = json_decode($r_blog['translations'], true);
                if (isset($translations[$lang])) {
                    $r_blog['post_title'] = $translations[$lang]['title'] ?? $r_blog['post_title'];
                    $r_blog['post_desc'] = $translations[$lang]['desc'] ?? $r_blog['post_desc'];
                }
            }
        }
    }

    echo json_encode([
        'status' => true,
        'message' => 'Blog fetched successfully',
        'data' => [
            'blog' => $blog,
            'related_posts' => $related_posts
        ]
    ]);
});
