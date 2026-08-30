<?php
// FILE: app/routes/cms/page.php
// CMS dynamic page route

@$SECURE or die('Access Denied!');

// ====================================
// CMS DYNAMIC PAGE
// ====================================

$router->get('/page/(.+)', function ($slug) use ($SECURE,$db) {
    // Redirect /page/blog to the dedicated blog listing route
    if ($slug === 'blog') {
        header("Location: " . root . "blog");
        exit();
    }

    $lang = $_SESSION['app_language'] ?? 'en';

    $cms_page = $db->get("cms", "*", ["slug_url" => $slug, "status" => 1]);
    if (!$cms_page) {
        header("Location: " . root);
        exit();
    }

    $page_name = $cms_page['page_name'];
    $content   = $cms_page['content'];

    if ($lang !== 'en') {
        $name_trans = json_decode($cms_page['page_name_translations'] ?? '', true) ?? [];
        $content_trans = json_decode($cms_page['content_translations'] ?? '', true) ?? [];

        if (!empty($name_trans[$lang])) {
            $page_name = $name_trans[$lang];
        }
        if (!empty($content_trans[$lang])) {
            $content = $content_trans[$lang];
        }
    }

    $cms_page['page_name'] = $page_name;
    $cms_page['content'] = $content;
    $title = $page_name . ' - ' . $GLOBALS['app']['home_title'];
    $description = $content ? substr(strip_tags($content), 0, 160) : $GLOBALS['app']['home_title'];
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."modules/cms/page.php";
    require_once views."includes/footer.php";
});
