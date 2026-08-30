<?php
// FILE: app/routes/api/cms/pageRoutes.php
// CMS dynamic page API route for mobile

@$SECURE or die('Access Denied!');

$router->get('/api/page/(.+)', function ($slug) use ($db) {
    header('Content-Type: application/json');

    // Language can be passed as a query parameter
    $lang = strtolower($_GET['lang'] ?? 'en');

    try {
        // Fetch active page from CMS table
        $cms_page = $db->get("cms", "*", ["slug_url" => $slug, "status" => 1]);

        if (!$cms_page) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Page not found'
            ]);
            exit();
        }

        $page_name = $cms_page['page_name'] ?? '';
        $content = $cms_page['content'] ?? '';

        // Handle Translations if lang is not english
        if ($lang !== 'en') {
            $name_trans = json_decode($cms_page['page_name_translations'] ?? '{}', true) ?? [];
            $content_trans = json_decode($cms_page['content_translations'] ?? '{}', true) ?? [];

            if (!empty($name_trans[$lang])) {
                $page_name = $name_trans[$lang];
            }
            if (!empty($content_trans[$lang])) {
                $content = $content_trans[$lang];
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Page fetched successfully',
            'data' => [
                'title' => $page_name,
                'slug' => $slug,
                'content' => $content
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server Error: ' . $e->getMessage()
        ]);
    }

    exit();
});
