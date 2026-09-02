<?php
// app/routes/api/globalApiRoutes.php
@$SECURE or die('Access Denied!');

// ---------------------------------------------------------------------------
// CORS — allow browser requests from any origin (static exports, dev servers)
// ---------------------------------------------------------------------------
// SECURITY (H6): only reflect an origin that is explicitly allow-listed, and
// only then send Allow-Credentials. Never emit `Allow-Origin: *` together with
// credentials (browsers reject it, and it would otherwise open the API to any
// site). Requests with no/other Origin simply get no CORS grant.
$allowed_origins = ['http://localhost:3000', 'http://localhost:3001', 'http://localhost:8080', 'http://127.0.0.1:3000'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| API: CHANGE LANGUAGE 
|--------------------------------------------------------------------------
*/
$router->post('/api/lang', function () use ($db) {

    header('Content-Type: application/json');

    $lang_code = $_POST['lang'] ?? null;

    if (!$lang_code) {
        echo json_encode([
            'success' => false,
            'message' => 'Language code is required'
        ]);
        exit;
    }

    $language = $db->get("languages", "*", [
        "lang_code" => $lang_code,
        "status" => "1"
    ]);

    if (!$language) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid language'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'lang_code' => $language['lang_code'],
            'name' => $language['name'],
            'dir' => $language['type']
        ]
    ]);
    exit;
});


/*
|--------------------------------------------------------------------------
| API: CHANGE CURRENCY 
|--------------------------------------------------------------------------
*/
$router->post('/api/currency', function () use ($db) {

    header('Content-Type: application/json');

    $currency_code = $_POST['currency'] ?? null;

    if (!$currency_code) {
        echo json_encode([
            'success' => false,
            'message' => 'Currency code is required'
        ]);
        exit;
    }

    $currency = $db->get("currencies", [
        "[>]countries" => ["country" => "iso"]
    ], [
        "currencies.name",
        "currencies.rate",
        "currencies.country",
        "countries.nicename(country_name)"
    ], [
        "currencies.name" => $currency_code,
        "currencies.status" => "1"
    ]);

    if (!$currency) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid currency'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'currency' => $currency['name'],
            'rate' => $currency['rate'],
            'country' => $currency['country'],
            'country_name' => $currency['country_name']
        ]
    ]);
    exit;
});

/*
|--------------------------------------------------------------------------
| API: GET CURRENCY LIST
|--------------------------------------------------------------------------
*/
$router->get('/api/currency', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $currencies = $db->select("currencies", [
            "[>]countries" => ["country" => "iso"]
        ], [
            "currencies.id",
            "currencies.name(currency_code)",
            "currencies.rate",
            "currencies.country(country_code)",
            "countries.nicename(country_name)"
        ], [
            "currencies.status" => 1,
            "ORDER" => ["currencies.name" => "ASC"]
        ]);

        $currencies = is_array($currencies) ? $currencies : [];
        $currencies = array_map(function ($curr) {
            $flagCode = !empty($curr['country_code']) ? strtolower($curr['country_code']) : 'xx';
            $curr['flag'] = root . "assets/img/flags_sqaure/{$flagCode}.svg";
            return $curr;
        }, $currencies);

        echo json_encode([
            'success' => true,
            'count' => count($currencies),
            'data' => $currencies
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});


/*
|--------------------------------------------------------------------------
| API: GET LANGUAGE LIST
|--------------------------------------------------------------------------
*/

$router->get('/api/lang', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    $languages = $db->select('languages', [
        'lang_code',
        'name',
        'type',
        'country'
    ], [
        'status' => '1',
        'ORDER'  => ['name' => 'ASC']
    ]);

    $languages = is_array($languages) ? $languages : [];

    echo json_encode([
        'success' => true,
        'count'   => count($languages),
        'data'    => array_map(function ($lang) {
            $flagCode = !empty($lang['country']) ? strtolower($lang['country']) : 'xx';
            return [
                'lang_code' => $lang['lang_code'],
                'name'      => $lang['name'],
                'direction' => $lang['type'],
                'country'   => $lang['country'],
                'flag'      => root . "assets/img/flags_sqaure/{$flagCode}.svg"
            ];
        }, $languages)
    ]);
    exit;
});


/*
|--------------------------------------------------------------------------
| API: GET MODULES LIST (Active Suppliers) + B2B/B2C Markup Config
|--------------------------------------------------------------------------
*/
$router->get('/api/modules', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {
        // --------------------------------------------------
        // OPTIONAL JWT AUTH - Detect agent role for markup
        // --------------------------------------------------
        $userId = null;
        $userRole = 'user';
        $userData = null;

        require_once 'app/lib/jwt.php';

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization']
            ?? $headers['authorization']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? '';

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            try {
                $tokenData = JWT::verify($matches[1]);
                if ($tokenData && !empty($tokenData['user_id'])) {
                    $userId = $tokenData['user_id'];
                    $userData = $db->get('users', '*', ['user_id' => $userId]);
                    if ($userData) {
                        $userRole = strtolower(trim($userData['role'] ?? 'user'));
                    }
                }
            } catch (Exception $e) {
                // Silent fail - continue as guest
            }
        }

        $isAgent = ($userRole === 'agent');

        // --------------------------------------------------
        // CUSTOM AGENT MARKUP CHECK
        // --------------------------------------------------
        $customMarkup = false;
        $customMarkupValue = 0;
        $customMarkupType = 'percentage';

        if ($isAgent && $userData) {
            if (($userData['apply_markup'] ?? 'global') === 'custom') {
                $customMarkup = true;
                $customMarkupValue = floatval($userData['markup_value'] ?? 0);
                $customMarkupType = $userData['markup_type'] ?? 'percentage';
            }
        }

        // --------------------------------------------------
        // FETCH ALL MODULES
        // --------------------------------------------------
        $modules = $db->select('modules', [
            'id',
            'name',
            'type',
            'status',
            'active',
            'tax',
            'tax_type',
            'markup_b2b',
            'markup_b2c',
            'markup_type_b2b',
            'markup_type_b2c'
        ], [
            'ORDER' => ['type' => 'ASC', 'name' => 'ASC']
        ]);

        echo json_encode([
            'success' => true,
            'count'   => count($modules),
            'role'    => $userRole,
            'is_agent' => $isAgent,
            'data'    => array_map(function ($module) use ($isAgent, $customMarkup, $customMarkupValue, $customMarkupType) {

                // Determine markup for this user
                if ($customMarkup) {
                    $markupType  = $customMarkupType;
                    $markupValue = $customMarkupValue;
                    $markupSource = 'custom';
                } else {
                    $markupType  = $isAgent
                        ? ($module['markup_type_b2b'] ?? 'percentage')
                        : ($module['markup_type_b2c'] ?? 'percentage');
                    $markupValue = $isAgent
                        ? floatval($module['markup_b2b'] ?? 0)
                        : floatval($module['markup_b2c'] ?? 0);
                    $markupSource = $isAgent ? 'b2b' : 'b2c';
                }

                return [
                    'id'           => $module['id'],
                    'name'         => $module['name'],
                    'type'         => $module['type'],
                    'status'       => $module['status'],
                    'active'       => $module['active'],
                    'tax'          => $module['tax'],
                    'tax_type'     => $module['tax_type'],
                    'markup_type'  => $markupType,
                    'markup_value' => $markupValue,
                    'markup_source' => $markupSource
                ];
            }, $modules)
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});

/*
|--------------------------------------------------------------------------
| API: GET SITE INFO (Domain Activation)
|--------------------------------------------------------------------------
*/
$router->get('/api/info', function () use ($db) {

    header('Content-Type: application/json');

    try {
        $s = $db->get("settings", "*", ["id" => 1]);
        if (!$s) $s = [];

        $social = [];
        if (!empty($s['social_media'])) {
            $decoded = json_decode($s['social_media'], true);
            if (is_array($decoded)) $social = $decoded;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'base_url'          => root,
                'site_name'         => $s['business_name']    ?? $s['site_name']    ?? 'PHPTRAVELS',
                'cover_image'       => root . 'uploads/global/cover.png',
                'multi_language'    => ($s['multi_language']    ?? '1') != '0',
                'multi_currency'    => ($s['multi_currency']    ?? '1') != '0',
                'user_registration' => ($s['user_registration'] ?? '1') != '0',
                'agent_registration'=> ($s['agent_registration']?? '0') != '0',
                'address'           => $s['address']         ?? $s['site_address']  ?? '',
                'city'              => $s['city']            ?? $s['site_city']     ?? '',
                'state'             => $s['state']           ?? $s['site_state']    ?? '',
                'post_code'         => $s['post_code']       ?? $s['zip']           ?? '',
                'country_name'      => $s['country_name']    ?? $s['site_country']  ?? '',
                'contact_phone'     => $s['contact_phone']   ?? $s['phone']         ?? '',
                'contact_email'     => $s['contact_email']   ?? $s['email']         ?? '',
                'social_media'      => $social,
                'footer_about'      => $s['footer_about']    ?? $s['description']   ?? '',
                'site_offline'      => !empty($s['site_offline']),
                'offline_message'   => $s['offline_message'] ?? '',
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});

/*
|--------------------------------------------------------------------------
| API: GET FOOTER NAV (CMS footer pages)
|--------------------------------------------------------------------------
*/
$router->get('/api/footer-nav', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $pages = $db->select("cms", [
            "id", "page_name", "slug_url", "parent_id", "external_url", "order"
        ], [
            "AND" => [
                "status" => "1",
                "position[~]" => ["footer", "headerfooter"]
            ],
            "ORDER" => ["parent_id" => "ASC", "order" => "ASC"]
        ]);

        $children_map = [];
        foreach ($pages as $page) {
            if ($page['parent_id']) {
                $children_map[$page['parent_id']][] = $page;
            }
        }

        $menus = [];
        $standalone = [];
        foreach ($pages as $page) {
            if (!$page['parent_id']) {
                if (!empty($children_map[$page['id']])) {
                    $menus[] = ['parent' => $page, 'children' => $children_map[$page['id']]];
                } else {
                    $standalone[] = $page;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'menus'      => $menus,
                'standalone' => $standalone,
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

    exit;
});

/*
|--------------------------------------------------------------------------
| API: GET HEADER NAV (CMS pages + blogs flag)
|--------------------------------------------------------------------------
*/
$router->get('/api/nav', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $pages = $db->select("cms", [
            "id", "page_name", "slug_url", "parent_id", "external_url", "order", "page_name_translations"
        ], [
            "AND" => [
                "status" => "1",
                "position[~]" => ["header", "headerfooter"]
            ],
            "ORDER" => ["parent_id" => "ASC", "order" => "ASC"]
        ]);

        $children_map = [];
        foreach ($pages as $page) {
            if ($page['parent_id']) {
                $children_map[$page['parent_id']][] = $page;
            }
        }

        $menus = [];
        $standalone = [];
        foreach ($pages as $page) {
            if (!$page['parent_id']) {
                if (!empty($children_map[$page['id']])) {
                    $menus[] = ['parent' => $page, 'children' => $children_map[$page['id']]];
                } else {
                    $standalone[] = $page;
                }
            }
        }

        $blogs_count = $db->count("blogs", ["status" => 1]);

        echo json_encode([
            'success' => true,
            'data' => [
                'menus'      => $menus,
                'standalone' => $standalone,
                'show_blogs' => $blogs_count > 0,
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

    exit;
});

/*
|--------------------------------------------------------------------------
| API: GET APP SETTINGS (Theme, Colors & Button Styles for Mobile App)
|--------------------------------------------------------------------------
*/
$handleAppSettingsApi = function () use ($db) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    try {
        $settingsRow = $db->get("settings", ["app_settings", "business_name", "show_apps", "android_store", "ios_store"], ["id" => 1]);
        $appSettings = [];
        if (!empty($settingsRow['app_settings'])) {
            $decoded = json_decode($settingsRow['app_settings'], true);
            if (is_array($decoded)) {
                $appSettings = $decoded;
            }
        }

        // Apply defaults if empty
        $appSettings = array_merge([
            'theme_mode' => 'light',
            'app_name' => $settingsRow['business_name'] ?? 'Mobile App',
            'theme_primary_color' => '#ffffff',
            'theme_secondary_color' => '#121212',
            'header_bg' => '#f9fafb',
            'header_text_color' => '#111827',
            'btn_color' => '#2563eb',
            'btn_text_color' => '#ffffff',
            'btn_hover_color' => '#1d4ed8',
            'btn_secondary_color' => '#f1f5f9',
            'btn_secondary_text_color' => '#334155',
            'btn_radius' => '10',
            'api_timeout' => '30',
            'show_apps' => $settingsRow['show_apps'] ?? '1',
            'android_store' => $settingsRow['android_store'] ?? '',
            'ios_store' => $settingsRow['ios_store'] ?? '',
        ], $appSettings);

        // Remove deprecated keys if present in DB JSON
        unset($appSettings['dark_mode_enabled'], $appSettings['app_bg_light'], $appSettings['app_bg_dark']);

        // Set the global homepage hero image (cover image)
        $appSettings['hero_image'] = root . 'uploads/global/cover.png';

        // Retrieve card styling values with defaults
        $cardBg = $appSettings['card_bg'] ?? '#ffffff';
        $cardTextColor = $appSettings['card_text_color'] ?? '#111827';
        $cardBgDark = $appSettings['card_bg_dark'] ?? '#1e1e1e';
        $cardTextColorDark = $appSettings['card_text_color_dark'] ?? '#f5f5f5';

        // Retrieve text color values with defaults
        $textPrimaryColor = $appSettings['text_primary_color'] ?? '#111827';
        $textSecondaryColor = $appSettings['text_secondary_color'] ?? '#6b7280';
        $textPrimaryColorDark = $appSettings['text_primary_color_dark'] ?? '#f5f5f5';
        $textSecondaryColorDark = $appSettings['text_secondary_color_dark'] ?? '#9e9e9e';

        // Retrieve home card styling values with defaults (with fallback to old tab keys)
        $homeCardBg = $appSettings['home_card_bg'] ?? ($appSettings['tab_bg'] ?? '#ffffff');
        $homeCardIconColor = $appSettings['home_card_icon_color'] ?? ($appSettings['tab_inactive_color'] ?? '#2563eb');
        $homeCardTextColor = $appSettings['home_card_text_color'] ?? ($appSettings['tab_inactive_text_color'] ?? '#111827');
        $homeCardBgDark = $appSettings['home_card_bg_dark'] ?? ($appSettings['tab_bg_dark'] ?? '#1e1e1e');
        $homeCardIconColorDark = $appSettings['home_card_icon_color_dark'] ?? ($appSettings['tab_inactive_color_dark'] ?? '#2563eb');
        $homeCardTextColorDark = $appSettings['home_card_text_color_dark'] ?? ($appSettings['tab_inactive_text_color_dark'] ?? '#ffffff');

        // Retrieve border color values with defaults
        $cardBorderColor = $appSettings['card_border_color'] ?? '#e4e6ec';
        $cardBorderColorDark = $appSettings['card_border_color_dark'] ?? '#1e1e1e';
        $homeCardBorderColor = $appSettings['home_card_border_color'] ?? ($appSettings['tab_border_color'] ?? '#e4e6ec');
        $homeCardBorderColorDark = $appSettings['home_card_border_color_dark'] ?? ($appSettings['tab_border_color_dark'] ?? '#1e1e1e');

        // Retrieve all tab color values with defaults
        $allTabBg = $appSettings['all_tab_bg'] ?? '#f9fafb';
        $allTabActiveBg = $appSettings['all_tab_active_bg'] ?? '#2563eb';
        $allTabBorderColor = $appSettings['all_tab_border_color'] ?? '#f9fafb';
        $allTabActiveTextColor = $appSettings['all_tab_active_text_color'] ?? '#ffffff';
        $allTabInactiveTextColor = $appSettings['all_tab_inactive_text_color'] ?? '#6b7280';

        $allTabBgDark = $appSettings['all_tab_bg_dark'] ?? '#1e1e1e';
        $allTabActiveBgDark = $appSettings['all_tab_active_bg_dark'] ?? '#2563eb';
        $allTabBorderColorDark = $appSettings['all_tab_border_color_dark'] ?? '#1e1e1e';
        $allTabActiveTextColorDark = $appSettings['all_tab_active_text_color_dark'] ?? '#ffffff';
        $allTabInactiveTextColorDark = $appSettings['all_tab_inactive_text_color_dark'] ?? '#9e9e9e';

        // Retrieve snackbar styling values with defaults (success, warning, failure)
        $snackbarTypes = ['success', 'warning', 'failure'];
        $snackbarDefaults = [
            'success' => [
                'light' => ['bg' => '#166534', 'text_color' => '#ffffff', 'border_color' => '#15803d'],
                'dark' => ['bg' => '#14532d', 'text_color' => '#bbf7d0', 'border_color' => '#166534'],
            ],
            'warning' => [
                'light' => ['bg' => '#b45309', 'text_color' => '#ffffff', 'border_color' => '#d97706'],
                'dark' => ['bg' => '#78350f', 'text_color' => '#fde68a', 'border_color' => '#92400e'],
            ],
            'failure' => [
                'light' => ['bg' => '#b91c1c', 'text_color' => '#ffffff', 'border_color' => '#dc2626'],
                'dark' => ['bg' => '#7f1d1d', 'text_color' => '#fecaca', 'border_color' => '#991b1b'],
            ],
        ];

        $buildSnackbars = function (bool $isDark) use ($appSettings, $snackbarTypes, $snackbarDefaults) {
            $suffix = $isDark ? '_dark' : '';
            $mode = $isDark ? 'dark' : 'light';
            $snackbars = [];

            foreach ($snackbarTypes as $type) {
                $defaults = $snackbarDefaults[$type][$mode];
                $legacyBg = ($type === 'success') ? ($appSettings['snackbar_bg' . $suffix] ?? null) : null;
                $legacyText = ($type === 'success') ? ($appSettings['snackbar_text_color' . $suffix] ?? null) : null;
                $legacyBorder = ($type === 'success') ? ($appSettings['snackbar_border_color' . $suffix] ?? null) : null;

                $snackbars[$type] = [
                    'bg' => $appSettings["snackbar_{$type}_bg{$suffix}"] ?? $legacyBg ?? $defaults['bg'],
                    'text_color' => $appSettings["snackbar_{$type}_text_color{$suffix}"] ?? $legacyText ?? $defaults['text_color'],
                    'border_color' => $appSettings["snackbar_{$type}_border_color{$suffix}"] ?? $legacyBorder ?? $defaults['border_color'],
                ];
            }

            return $snackbars;
        };

        $apiTimeout = max(5, min(300, (int) ($appSettings['api_timeout'] ?? 30)));

        // Generate dynamic and generic Light & Dark mode settings
        $themePrimary = $appSettings['theme_primary_color'] ?? '#ffffff';
        $themeSecondary = $appSettings['theme_secondary_color'] ?? '#121212';
        $headerBg = $appSettings['header_bg'] ?? '#f9fafb';
        $headerText = $appSettings['header_text_color'] ?? '#111827';
        $headerBgDark = $appSettings['header_bg_dark'] ?? '#1e1e1e';
        $headerTextDark = $appSettings['header_text_color_dark'] ?? '#f5f5f5';
        $btnColor = $appSettings['btn_color'] ?? '#2563eb';
        $btnTextColor = $appSettings['btn_text_color'] ?? '#ffffff';
        $btnBorderColor = $appSettings['btn_border_color'] ?? '#2563eb';

        $btnColorDark = $appSettings['btn_color_dark'] ?? '#2563eb';
        $btnTextColorDark = $appSettings['btn_text_color_dark'] ?? '#ffffff';
        $btnBorderColorDark = $appSettings['btn_border_color_dark'] ?? '#2563eb';

        $btnHoverColor = $appSettings['btn_hover_color'] ?? '#1d4ed8';
        $btnSecondaryColor = $appSettings['btn_secondary_color'] ?? '#f1f5f9';
        $btnSecondaryTextColor = $appSettings['btn_secondary_text_color'] ?? '#334155';

        $appSettings['light'] = [
            'theme_mode' => 'light',
            'theme_primary_color' => $themePrimary,
            'theme_secondary_color' => $themeSecondary,
            'header_bg' => $headerBg,
            'header_text_color' => $headerText,
            'btn_color' => $btnColor,
            'btn_text_color' => $btnTextColor,
            'btn_border_color' => $btnBorderColor,
            'card_bg' => $cardBg,
            'card_border_color' => $cardBorderColor,
            'text_primary_color' => $textPrimaryColor,
            'text_secondary_color' => $textSecondaryColor,
            'home_card_bg' => $homeCardBg,
            'home_card_icon_color' => $homeCardIconColor,
            'home_card_text_color' => $homeCardTextColor,
            'home_card_border_color' => $homeCardBorderColor,
            'all_tab_bg' => $allTabBg,
            'all_tab_active_bg' => $allTabActiveBg,
            'all_tab_border_color' => $allTabBorderColor,
            'all_tab_active_text_color' => $allTabActiveTextColor,
            'all_tab_inactive_text_color' => $allTabInactiveTextColor,
            'snackbar' => $buildSnackbars(false),
        ];

        $appSettings['dark'] = [
            'theme_mode' => 'dark',
            'theme_primary_color' => $themeSecondary,
            'theme_secondary_color' => $themePrimary,
            'header_bg' => $headerBgDark,
            'header_text_color' => $headerTextDark,
            'btn_color' => $btnColorDark,
            'btn_text_color' => $btnTextColorDark,
            'btn_border_color' => $btnBorderColorDark,
            'card_bg' => $cardBgDark,
            'card_border_color' => $cardBorderColorDark,
            'text_primary_color' => $textPrimaryColorDark,
            'text_secondary_color' => $textSecondaryColorDark,
            'home_card_bg' => $homeCardBgDark,
            'home_card_icon_color' => $homeCardIconColorDark,
            'home_card_text_color' => $homeCardTextColorDark,
            'home_card_border_color' => $homeCardBorderColorDark,
            'all_tab_bg' => $allTabBgDark,
            'all_tab_active_bg' => $allTabActiveBgDark,
            'all_tab_border_color' => $allTabBorderColorDark,
            'all_tab_active_text_color' => $allTabActiveTextColorDark,
            'all_tab_inactive_text_color' => $allTabInactiveTextColorDark,
            'snackbar' => $buildSnackbars(true),
        ];

        echo json_encode([
            'status' => true,
            'success' => true,
            'data' => [
                'app_name' => $appSettings['app_name'] ?? ($settingsRow['business_name'] ?? 'Mobile App'),
                'hero_image' => root . 'uploads/global/cover.png',
                'api_key' => $appSettings['api_key'] ?? '',
                'api_timeout' => $apiTimeout,
                'light' => $appSettings['light'],
                'dark' => $appSettings['dark']
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => false, 'success' => false, 'message' => $e->getMessage()]);
    }

    exit;
};

$router->get('/api/app-settings', $handleAppSettingsApi);
$router->get('/api/app/settings', $handleAppSettingsApi);

