<?php
// ============================================================================
// ADMIN — SEO Landing Pages manager
//
// CRUD over `seo_landing_pages` — the DB source of truth for the SSR SEO landing
// pages (flight routes /flights/{a}-to-{b}, hotel cities /hotels/{city}, umrah
// origins /umrah/from-{city}). The hardcoded registry in app/lib/seo_registry.php
// (*Defaults() functions) seeds this table on first deploy and is the fallback
// if the table is empty; once seeded, the admin owns the content entirely.
//
// The per-page content is a small typed form serialised into the row's JSON
// `data` column (list fields like airlines/areas are newline-separated in the UI
// and stored as JSON arrays). All handlers are ADMIN_AUTH()-gated and every
// state-changing POST is CSRF-guarded (CLAUDE.md §6/§10).
// ============================================================================
@$SECURE or die('Access Denied!');

// Valid types + their public URL shape (for the "View" link + slug hint).
if (!function_exists('seoLandingTypes')) {
    function seoLandingTypes(): array
    {
        return [
            'flight_route' => [
                'label'      => 'Flight route',
                'slug_hint'  => 'e.g. lagos-to-abuja (lowercase, must contain "-to-")',
                'url_prefix' => 'flights/',
            ],
            'hotel_city' => [
                'label'      => 'Hotel city',
                'slug_hint'  => 'e.g. dubai (lowercase, hyphens for spaces)',
                'url_prefix' => 'hotels/',
            ],
            'umrah_origin' => [
                'label'      => 'Umrah origin',
                'slug_hint'  => 'e.g. kano (lowercase departure city)',
                'url_prefix' => 'umrah/from-',
            ],
            'tour_destination' => [
                'label'      => 'Tour destination',
                'slug_hint'  => 'e.g. dubai (lowercase, hyphens for spaces)',
                'url_prefix' => 'tours/',
            ],
        ];
    }
}

// Normalise a slug: lowercase, strip anything but a-z 0-9 and hyphen.
if (!function_exists('seoLandingNormalizeSlug')) {
    function seoLandingNormalizeSlug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9-]+/', '-', $s);
        $s = preg_replace('/-+/', '-', $s);
        return trim($s, '-');
    }
}

// Turn a newline/comma list from a textarea into a clean string array.
if (!function_exists('seoLandingLines')) {
    function seoLandingLines($raw): array
    {
        $raw = (string) $raw;
        if ($raw === '') { return []; }
        $parts = preg_split('/\r\n|\r|\n/', $raw);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') { $out[] = $p; }
        }
        return $out;
    }
}

// ---- LIST / SCREEN --------------------------------------------------------
$router->get(admin.'/seo/landing-pages', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureSeoLandingSchema')) {
        ensureSeoLandingSchema($db);
    }
    $title = 'SEO Landing Pages';
    $description = '';
    $header = true;
    $footer = true;
    require_once views . 'includes/header.php';
    require_once 'app/views/admin/seo/landing-pages.php';
    require_once views . 'includes/footer.php';
});

// ---- CREATE / UPDATE (single JSON endpoint) -------------------------------
$router->post(admin.'/seo/landing-pages/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard();
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('ensureSeoLandingSchema')) { ensureSeoLandingSchema($db); }

    $id    = (int) ($_POST['id'] ?? 0);
    $type  = (string) ($_POST['type'] ?? '');
    $types = seoLandingTypes();
    if (!isset($types[$type])) {
        echo json_encode(['success' => false, 'message' => 'Unknown page type.']); exit;
    }

    $slug = seoLandingNormalizeSlug((string) ($_POST['slug'] ?? ''));
    if ($slug === '') {
        echo json_encode(['success' => false, 'message' => 'Slug is required.']); exit;
    }
    // Flight-route slugs must be "{from}-to-{to}" to match the public route.
    if ($type === 'flight_route' && !preg_match('/^[a-z][a-z-]*-to-[a-z][a-z-]*$/', $slug)) {
        echo json_encode(['success' => false, 'message' => 'Flight-route slug must look like "lagos-to-abuja".']); exit;
    }

    $name   = trim((string) ($_POST['name'] ?? ''));
    $status = (isset($_POST['status']) && (string) $_POST['status'] === '1') ? 1 : 0;
    $sort   = (int) ($_POST['sort_order'] ?? 0);

    // Build the per-type `data` payload (mirrors the *Defaults() shapes exactly so
    // the views consume DB rows and hardcoded defaults identically).
    $data = [];
    if ($type === 'flight_route') {
        $data = [
            'duration' => trim((string) ($_POST['duration'] ?? '')),
            'airlines' => seoLandingLines($_POST['airlines'] ?? ''),
            'note'     => trim((string) ($_POST['note'] ?? '')),
        ];
    } elseif ($type === 'hotel_city') {
        $data = [
            'name'           => $name,
            'country'        => trim((string) ($_POST['country'] ?? '')),
            'nat'            => trim((string) ($_POST['nat'] ?? 'NG')) ?: 'NG',
            'intro'          => trim((string) ($_POST['intro'] ?? '')),
            'areas'          => seoLandingLines($_POST['areas'] ?? ''),
            'airport'        => trim((string) ($_POST['airport'] ?? '')),
            'tip'            => trim((string) ($_POST['tip'] ?? '')),
            'currency'       => trim((string) ($_POST['currency'] ?? '')),
            'language'       => trim((string) ($_POST['language'] ?? '')),
            'best_time'      => trim((string) ($_POST['best_time'] ?? '')),
            'getting_around' => trim((string) ($_POST['getting_around'] ?? '')),
            'highlights'     => trim((string) ($_POST['highlights'] ?? '')),
        ];
    } elseif ($type === 'umrah_origin') {
        $data = [
            'name'     => $name,
            'intro'    => trim((string) ($_POST['intro'] ?? '')),
            'note'     => trim((string) ($_POST['note'] ?? '')),
            'airport'  => trim((string) ($_POST['airport'] ?? '')),
            'carriers' => trim((string) ($_POST['carriers'] ?? '')),
        ];
    } else { // tour_destination
        $data = [
            'name'           => $name,
            'country'        => trim((string) ($_POST['country'] ?? '')),
            'intro'          => trim((string) ($_POST['intro'] ?? '')),
            'experiences'    => seoLandingLines($_POST['experiences'] ?? ''),
            'tip'            => trim((string) ($_POST['tip'] ?? '')),
            'best_time'      => trim((string) ($_POST['best_time'] ?? '')),
            'getting_around' => trim((string) ($_POST['getting_around'] ?? '')),
            'highlights'     => trim((string) ($_POST['highlights'] ?? '')),
        ];
    }

    // Guard the unique (type, slug) pair against a DIFFERENT row.
    $dupe = $db->get('seo_landing_pages', ['id'], ['type' => $type, 'slug' => $slug]);
    if ($dupe && (int) $dupe['id'] !== $id) {
        echo json_encode(['success' => false, 'message' => 'A page with that type + slug already exists.']); exit;
    }

    $row = [
        'type'       => $type,
        'slug'       => $slug,
        'title'      => $name !== '' ? $name : null,
        'data'       => json_encode($data, JSON_UNESCAPED_UNICODE),
        'status'     => $status,
        'sort_order' => $sort,
    ];

    try {
        if ($id > 0) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            $db->update('seo_landing_pages', $row, ['id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Landing page updated.', 'id' => $id]);
        } else {
            $db->insert('seo_landing_pages', $row);
            echo json_encode(['success' => true, 'message' => 'Landing page added.', 'id' => (int) $db->id()]);
        }
    } catch (\Throwable $e) {
        error_log('seo-landing save: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not save the landing page.']);
    }
    exit;
});

// ---- TOGGLE STATUS --------------------------------------------------------
$router->post(admin.'/seo/landing-pages/toggle', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard();
    header('Content-Type: application/json; charset=utf-8');
    $id  = (int) ($_POST['id'] ?? 0);
    $row = $id > 0 ? $db->get('seo_landing_pages', ['status'], ['id' => $id]) : null;
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Page not found.']); exit; }
    $new = ((int) $row['status'] === 1) ? 0 : 1;
    $db->update('seo_landing_pages', ['status' => $new, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    echo json_encode(['success' => true, 'status' => $new]);
    exit;
});

// ---- DELETE ---------------------------------------------------------------
$router->post(admin.'/seo/landing-pages/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard();
    header('Content-Type: application/json; charset=utf-8');
    $id = (int) ($_POST['id'] ?? 0);
    if ($id < 1) { echo json_encode(['success' => false, 'message' => 'Invalid page.']); exit; }
    $db->delete('seo_landing_pages', ['id' => $id]);
    echo json_encode(['success' => true]);
    exit;
});
