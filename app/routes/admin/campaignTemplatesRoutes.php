<?php
// app/routes/admin/campaignTemplatesRoutes.php
// Phase 3 — Campaign template library + block-style layout designer.
// Templates are reusable email layouts the campaign composer can "start from".
// A template is an ordered list of BLOCKS (hero/text/button/image/divider); the
// renderer compiles blocks -> HTML (what a campaign copies into its body).
@$SECURE or die('Access Denied!');

/**
 * Compile an ordered list of layout blocks into email-safe inline-styled HTML.
 * Single source of truth — used by both the designer save and the seed data.
 * Every block type is handled; unknown types are skipped (never fatal).
 * All user text is escaped; links are URL-safe. {{unsubscribe_url}} /
 * {$subscriber.first_name} placeholders are passed through untouched for the
 * campaign sender to substitute.
 */
if (!function_exists('campaign_blocks_to_html')) {
    function campaign_blocks_to_html($blocks): string
    {
        if (is_string($blocks)) { $blocks = json_decode($blocks, true); }
        if (!is_array($blocks)) { return ''; }

        $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        // Allow only http(s) + the unsubscribe placeholder in hrefs.
        $safeHref = function ($url) {
            $url = trim((string) $url);
            if ($url === '' ) { return '#'; }
            if ($url === '{{unsubscribe_url}}') { return $url; }
            if (preg_match('#^https?://#i', $url)) { return $url; }
            return '#';
        };

        $out = '';
        foreach ($blocks as $b) {
            if (!is_array($b)) { continue; }
            $type = (string) ($b['type'] ?? '');
            switch ($type) {
                case 'hero':
                    $out .= '<tr><td style="padding:0 0 8px">'
                          . '<h1 style="margin:0;font-size:26px;line-height:1.25;font-weight:800;color:#0f172a">' . $esc($b['title'] ?? '') . '</h1>'
                          . (isset($b['subtitle']) && $b['subtitle'] !== '' ? '<p style="margin:8px 0 0;font-size:15px;color:#475569">' . $esc($b['subtitle']) . '</p>' : '')
                          . '</td></tr>';
                    break;
                case 'text':
                    // Text blocks may contain simple inline HTML authored in the designer;
                    // we keep it but it originates from admins only.
                    $out .= '<tr><td style="padding:6px 0;font-size:15px;line-height:1.6;color:#111">' . (string) ($b['html'] ?? $esc($b['text'] ?? '')) . '</td></tr>';
                    break;
                case 'image':
                    $src = $safeHref($b['src'] ?? '');
                    if ($src !== '#') {
                        $img = '<img src="' . $esc($src) . '" alt="' . $esc($b['alt'] ?? '') . '" style="max-width:100%;height:auto;border-radius:8px;display:block">';
                        if (!empty($b['link'])) { $img = '<a href="' . $esc($safeHref($b['link'])) . '" target="_blank">' . $img . '</a>'; }
                        $out .= '<tr><td style="padding:10px 0">' . $img . '</td></tr>';
                    }
                    break;
                case 'button':
                    $href = $safeHref($b['href'] ?? '');
                    $out .= '<tr><td style="padding:14px 0"><a href="' . $esc($href) . '" target="_blank" '
                          . 'style="background:#2563eb;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:700;font-size:15px;display:inline-block">'
                          . $esc($b['label'] ?? 'Learn more') . '</a></td></tr>';
                    break;
                case 'divider':
                    $out .= '<tr><td style="padding:12px 0"><div style="height:1px;background:#e5e7eb"></div></td></tr>';
                    break;
                default:
                    continue 2;
            }
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%">' . $out . '</table>';
    }
}

/** Seed starter templates (used once by ensureCampaignTemplateSchema). */
if (!function_exists('campaignTemplateSeeds')) {
    function campaignTemplateSeeds(): array
    {
        $mk = function (array $blocks) { return campaign_blocks_to_html($blocks); };

        $deal = [
            ['type' => 'hero', 'title' => 'Exclusive travel deals just for you', 'subtitle' => 'Handpicked offers, limited time only.'],
            ['type' => 'text', 'html' => '<p>Hi {$subscriber.first_name},</p><p>We\'ve lined up some great fares and stays we think you\'ll love. Book early to lock in the best prices.</p>'],
            ['type' => 'button', 'label' => 'Browse deals', 'href' => 'https://'],
            ['type' => 'divider'],
            ['type' => 'text', 'html' => '<p style="font-size:13px;color:#6b7280">Prices and availability change fast — these offers won\'t last.</p>'],
        ];
        $welcome = [
            ['type' => 'hero', 'title' => 'Welcome aboard ✈️', 'subtitle' => 'Thanks for joining our travel community.'],
            ['type' => 'text', 'html' => '<p>Hi {$subscriber.first_name},</p><p>You\'ll now be first to hear about new destinations, exclusive fares and travel tips. Here\'s to your next journey!</p>'],
            ['type' => 'button', 'label' => 'Start exploring', 'href' => 'https://'],
        ];
        $update = [
            ['type' => 'hero', 'title' => 'A quick update'],
            ['type' => 'text', 'html' => '<p>Hi {$subscriber.first_name},</p><p>Write your announcement here.</p>'],
        ];

        return [
            ['name' => 'Deal announcement', 'description' => 'Promote fares/stays with a hero, copy and a call-to-action.', 'blocks' => $deal,    'body_html' => $mk($deal)],
            ['name' => 'Welcome',           'description' => 'Greet a new subscriber after they confirm.',               'blocks' => $welcome, 'body_html' => $mk($welcome)],
            ['name' => 'Plain update',      'description' => 'Minimal layout for a simple text announcement.',           'blocks' => $update,  'body_html' => $mk($update)],
        ];
    }
}

/**
 * Seed the built-in starter templates once. Called lazily from the admin list
 * route (after the renderer + seed data are defined — bootstrap runs too early).
 * Idempotent: only inserts a seed whose name isn't already present.
 */
if (!function_exists('campaignSeedStarters')) {
    function campaignSeedStarters($db): void
    {
        try {
            if (!function_exists('campaignTemplateSeeds') || !dbTableExists($db, 'email_templates_library')) { return; }
            // Only seed when NO system templates exist yet (don't fight admin deletes forever).
            if ((int) $db->count('email_templates_library', ['is_system' => 1]) > 0) { return; }
            foreach (campaignTemplateSeeds() as $seed) {
                if ($db->get('email_templates_library', 'id', ['name' => $seed['name']])) { continue; }
                $db->insert('email_templates_library', [
                    'name'        => $seed['name'],
                    'description' => $seed['description'],
                    'blocks_json' => json_encode($seed['blocks']),
                    'body_html'   => $seed['body_html'],
                    'is_system'   => 1,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('campaignSeedStarters: ' . $e->getMessage());
        }
    }
}

// ================================ LIST
$router->get(admin.'/campaign-templates', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureCampaignTemplateSchema')) { ensureCampaignTemplateSchema($db); }
    campaignSeedStarters($db); // lazy one-time seed of the built-in starters
    $title = 'Campaign Templates'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/campaign-templates/templates.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE / EDIT (form)
$router->get(admin.'/campaign-templates/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureCampaignTemplateSchema')) { ensureCampaignTemplateSchema($db); }
    $title = 'New Template'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views."includes/header.php";
    require_once "app/views/admin/campaign-templates/templates-manage.php";
    require_once views."includes/footer.php";
});
$router->get(admin.'/campaign-templates/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureCampaignTemplateSchema')) { ensureCampaignTemplateSchema($db); }
    $title = 'Edit Template'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = (int) $id;
    require_once views."includes/header.php";
    require_once "app/views/admin/campaign-templates/templates-manage.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (blocks -> rendered HTML)
$router->post(admin.'/campaign-templates/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    if (function_exists('ensureCampaignTemplateSchema')) { ensureCampaignTemplateSchema($db); }

    $id    = (int) ($_POST['id'] ?? 0);
    $name  = trim((string) ($_POST['name'] ?? ''));
    $desc  = trim((string) ($_POST['description'] ?? ''));
    $raw   = (string) ($_POST['blocks_json'] ?? '[]');
    $blocks = json_decode($raw, true);

    if ($name === '' || !is_array($blocks) || empty($blocks)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'A name and at least one content block are required.'];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/campaign-templates');
        return;
    }

    $html = campaign_blocks_to_html($blocks);
    $data = [
        'name'        => $name,
        'description' => $desc ?: null,
        'blocks_json' => json_encode($blocks),
        'body_html'   => $html,
    ];

    try {
        if ($id > 0) {
            $existing = $db->get('email_templates_library', '*', ['id' => $id]);
            if (!$existing) { throw new Exception('Template not found'); }
            $db->update('email_templates_library', $data, ['id' => $id]);
        } else {
            $data['is_system'] = 0;
            $db->insert('email_templates_library', $data);
            $id = (int) $db->id();
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Template saved.'];
        redirect(root . admin . '/campaign-templates/edit/' . $id);
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/campaign-templates');
    }
});

// ================================ DELETE (non-system only)
$router->post(admin.'/campaign-templates/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $t = $id > 0 ? $db->get('email_templates_library', '*', ['id' => $id]) : null;
        if ($t && (int) $t['is_system'] === 1) {
            throw new Exception('Built-in templates cannot be deleted.');
        }
        if ($t) { $db->delete('email_templates_library', ['id' => $id]); }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Template deleted.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    redirect(root . admin . '/campaign-templates');
});

// ================================ JSON: fetch a template's HTML (composer picker)
$router->get(admin.'/campaign-templates/html/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');
    $t = $db->get('email_templates_library', ['id', 'name', 'body_html'], ['id' => (int) $id]);
    if (!$t) { http_response_code(404); echo json_encode(['success' => false]); exit; }
    echo json_encode(['success' => true, 'id' => (int) $t['id'], 'name' => $t['name'], 'body_html' => $t['body_html']]);
    exit;
});
