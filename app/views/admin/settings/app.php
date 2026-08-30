<?php
// Get settings from database
$settingsData = $db->get('settings', '*');
$appSettingsJson = isset($settingsData['app_settings']) && $settingsData['app_settings'] !== '' ? $settingsData['app_settings'] : '{}';
$appSettings = json_decode($appSettingsJson, true);
if (!is_array($appSettings)) {
    $appSettings = [];
}

// Default configuration values
$appThemeMode = $appSettings['theme_mode'] ?? 'light';
$appBtnColor = $appSettings['btn_color'] ?? '#2563eb';
$appBtnTextColor = $appSettings['btn_text_color'] ?? '#ffffff';
$appBtnBorderColor = $appSettings['btn_border_color'] ?? '#2563eb';

$appBtnColorDark = $appSettings['btn_color_dark'] ?? '#2563eb';
$appBtnTextColorDark = $appSettings['btn_text_color_dark'] ?? '#ffffff';
$appBtnBorderColorDark = $appSettings['btn_border_color_dark'] ?? '#2563eb';

$appApiKey = $appSettings['api_key'] ?? '';
$appApiTimeout = $appSettings['api_timeout'] ?? '30';

$appBtnHoverColor = $appSettings['btn_hover_color'] ?? '#1d4ed8';
$appBtnSecondaryColor = $appSettings['btn_secondary_color'] ?? '#f1f5f9';
$appBtnSecondaryTextColor = $appSettings['btn_secondary_text_color'] ?? '#334155';
$appBtnRadius = $appSettings['btn_radius'] ?? '10';

// Theme Primary and Secondary colors (directly affect App Background)
$themePrimaryColor = $appSettings['theme_primary_color'] ?? ($appSettings['app_bg_light'] ?? '#ffffff');
$themeSecondaryColor = $appSettings['theme_secondary_color'] ?? ($appSettings['app_bg_dark'] ?? '#121212');

$appHeaderBg = $appSettings['header_bg'] ?? '#f9fafb';
$appHeaderTextColor = $appSettings['header_text_color'] ?? '#111827';
$appHeaderBgDark = $appSettings['header_bg_dark'] ?? '#1e1e1e';
$appHeaderTextColorDark = $appSettings['header_text_color_dark'] ?? '#f5f5f5';
$appCardBg = $appSettings['card_bg'] ?? '#ffffff';
$appCardTextColor = $appSettings['card_text_color'] ?? '#111827';
$appCardBgDark = $appSettings['card_bg_dark'] ?? '#1e1e1e';
$appCardTextColorDark = $appSettings['card_text_color_dark'] ?? '#f5f5f5';
$textPrimaryColor = $appSettings['text_primary_color'] ?? '#111827';
$textSecondaryColor = $appSettings['text_secondary_color'] ?? '#6b7280';
$textPrimaryColorDark = $appSettings['text_primary_color_dark'] ?? '#f5f5f5';
$textSecondaryColorDark = $appSettings['text_secondary_color_dark'] ?? '#9e9e9e';
$appHomeCardBg = $appSettings['home_card_bg'] ?? ($appSettings['tab_bg'] ?? '#ffffff');
$appHomeCardIconColor = $appSettings['home_card_icon_color'] ?? ($appSettings['tab_inactive_color'] ?? '#2563eb');
$appHomeCardTextColor = $appSettings['home_card_text_color'] ?? ($appSettings['tab_inactive_text_color'] ?? '#111827');
$appHomeCardBgDark = $appSettings['home_card_bg_dark'] ?? ($appSettings['tab_bg_dark'] ?? '#1e1e1e');
$appHomeCardIconColorDark = $appSettings['home_card_icon_color_dark'] ?? ($appSettings['tab_inactive_color_dark'] ?? '#2563eb');
$appHomeCardTextColorDark = $appSettings['home_card_text_color_dark'] ?? ($appSettings['tab_inactive_text_color_dark'] ?? '#ffffff');
$appCardBorderColor = $appSettings['card_border_color'] ?? '#e4e6ec';
$appCardBorderColorDark = $appSettings['card_border_color_dark'] ?? '#1e1e1e';
$appHomeCardBorderColor = $appSettings['home_card_border_color'] ?? ($appSettings['tab_border_color'] ?? '#e4e6ec');
$appHomeCardBorderColorDark = $appSettings['home_card_border_color_dark'] ?? ($appSettings['tab_border_color_dark'] ?? '#1e1e1e');

// All Tab defaults
$appAllTabBg = $appSettings['all_tab_bg'] ?? '#f9fafb';
$appAllTabActiveBg = $appSettings['all_tab_active_bg'] ?? '#2563eb';
$appAllTabBorderColor = $appSettings['all_tab_border_color'] ?? '#f9fafb';
$appAllTabActiveTextColor = $appSettings['all_tab_active_text_color'] ?? '#ffffff';
$appAllTabInactiveTextColor = $appSettings['all_tab_inactive_text_color'] ?? '#6b7280';

$appAllTabBgDark = $appSettings['all_tab_bg_dark'] ?? '#1e1e1e';
$appAllTabActiveBgDark = $appSettings['all_tab_active_bg_dark'] ?? '#2563eb';
$appAllTabBorderColorDark = $appSettings['all_tab_border_color_dark'] ?? '#1e1e1e';
$appAllTabActiveTextColorDark = $appSettings['all_tab_active_text_color_dark'] ?? '#ffffff';
$appAllTabInactiveTextColorDark = $appSettings['all_tab_inactive_text_color_dark'] ?? '#9e9e9e';

$snackbarThemeConfig = [
    'success' => [
        'label' => 'Success',
        'icon' => 'check_circle',
        'message' => 'Booking saved successfully',
        'defaults' => [
            'bg' => '#166534',
            'text_color' => '#ffffff',
            'border_color' => '#15803d',
            'bg_dark' => '#14532d',
            'text_color_dark' => '#bbf7d0',
            'border_color_dark' => '#166534',
        ],
    ],
    'warning' => [
        'label' => 'Warning',
        'icon' => 'warning',
        'message' => 'Session expiring soon',
        'defaults' => [
            'bg' => '#b45309',
            'text_color' => '#ffffff',
            'border_color' => '#d97706',
            'bg_dark' => '#78350f',
            'text_color_dark' => '#fde68a',
            'border_color_dark' => '#92400e',
        ],
    ],
    'failure' => [
        'label' => 'Failure',
        'icon' => 'error',
        'message' => 'Payment failed',
        'defaults' => [
            'bg' => '#b91c1c',
            'text_color' => '#ffffff',
            'border_color' => '#dc2626',
            'bg_dark' => '#7f1d1d',
            'text_color_dark' => '#fecaca',
            'border_color_dark' => '#991b1b',
        ],
    ],
];

$appSnackbarColors = [];
foreach ($snackbarThemeConfig as $type => $config) {
    $legacyBg = ($type === 'success') ? ($appSettings['snackbar_bg'] ?? null) : null;
    $legacyText = ($type === 'success') ? ($appSettings['snackbar_text_color'] ?? null) : null;
    $legacyBorder = ($type === 'success') ? ($appSettings['snackbar_border_color'] ?? null) : null;
    $legacyBgDark = ($type === 'success') ? ($appSettings['snackbar_bg_dark'] ?? null) : null;
    $legacyTextDark = ($type === 'success') ? ($appSettings['snackbar_text_color_dark'] ?? null) : null;
    $legacyBorderDark = ($type === 'success') ? ($appSettings['snackbar_border_color_dark'] ?? null) : null;

    $appSnackbarColors[$type] = [
        'bg' => $appSettings["snackbar_{$type}_bg"] ?? $legacyBg ?? $config['defaults']['bg'],
        'text_color' => $appSettings["snackbar_{$type}_text_color"] ?? $legacyText ?? $config['defaults']['text_color'],
        'border_color' => $appSettings["snackbar_{$type}_border_color"] ?? $legacyBorder ?? $config['defaults']['border_color'],
        'bg_dark' => $appSettings["snackbar_{$type}_bg_dark"] ?? $legacyBgDark ?? $config['defaults']['bg_dark'],
        'text_color_dark' => $appSettings["snackbar_{$type}_text_color_dark"] ?? $legacyTextDark ?? $config['defaults']['text_color_dark'],
        'border_color_dark' => $appSettings["snackbar_{$type}_border_color_dark"] ?? $legacyBorderDark ?? $config['defaults']['border_color_dark'],
    ];
}

$appName = $appSettings['app_name'] ?? ($settingsData['business_name'] ?? 'Mobile App');
?>

<div class="container my-4">

    <!-- Success/Error Notification -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800' ?> p-4 rounded-xl mb-4 flex items-center justify-between shadow-sm">
            <div class="flex items-center space-x-2">
                <span class="material-symbols-outlined text-lg"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
                <span class="text-sm font-medium"><?= htmlspecialchars($_SESSION['message']['text']) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600">
                <span class="material-symbols-outlined text-sm">close</span>
            </button>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Header Section -->
    <div class="card p-5 mb-6 border border-gray-200 shadow-sm bg-white rounded-xl">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-blue-600 text-white rounded-xl flex items-center justify-center shadow-md shadow-blue-500/20">
                    <span class="material-symbols-outlined text-xl">smartphone</span>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Mobile App Settings</h1>
                    <p class="text-xs text-gray-500">Configure app themes, button colors, text styles, and live mobile preview</p>
                </div>
            </div>
            <div class="flex items-center space-x-2">
                <a href="<?= root . admin ?>/settings" class="btn white text-gray-700 hover:bg-gray-50 border border-gray-300 py-2 px-4 rounded-lg flex items-center space-x-1.5 text-sm">
                    <span class="material-symbols-outlined text-sm">arrow_back</span>
                    <span>Back to Settings</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content Form -->
    <form action="<?= root . admin ?>/settings/app" method="post" id="app-settings-form">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Controls & Customization Forms (7 Columns) -->
            <div class="lg:col-span-7 space-y-6">

                <!-- Tabs Navigation -->
                <div class="flex border border-gray-200 mb-4 bg-white rounded-xl overflow-hidden shadow-sm">
                    <button type="button" onclick="switchAdminTab('general-tab', this)" class="admin-tab-btn flex-1 py-3 px-4 font-bold text-xs text-blue-600 border-b-2 border-blue-600 bg-blue-50/30 transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-sm">settings</span>
                        <span>General Settings</span>
                    </button>
                    <button type="button" onclick="switchAdminTab('light-tab', this)" class="admin-tab-btn flex-1 py-3 px-4 font-semibold text-xs text-gray-500 border-b-2 border-transparent transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-sm">light_mode</span>
                        <span>Light Theme</span>
                    </button>
                    <button type="button" onclick="switchAdminTab('dark-tab', this)" class="admin-tab-btn flex-1 py-3 px-4 font-semibold text-xs text-gray-500 border-b-2 border-transparent transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-sm">dark_mode</span>
                        <span>Dark Theme</span>
                    </button>
                </div>

                <!-- TAB 1: GENERAL SETTINGS -->
                <div id="general-tab" class="admin-tab-content space-y-6">
                    <!-- App Theme Mode Section -->
                    <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm">
                        <div class="flex items-center mb-4 pb-3 border-b border-gray-100">
                            <div class="w-8 h-8 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="material-symbols-outlined text-lg">dark_mode</span>
                            </div>
                            <div>
                                <h3 class="text-md font-semibold text-gray-900">App Theme & Appearance</h3>
                                <p class="text-xs text-gray-500">Configure default mode and overall visual style of your mobile application</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="text-xs font-semibold text-gray-700 mb-1 block">Default Theme Mode</label>
                                <select name="app_settings[theme_mode]" id="appThemeMode" class="select w-full" onchange="updateAppPreview()">
                                    <option value="light" <?= $appThemeMode === 'light' ? 'selected' : '' ?>>☀️ Light Mode</option>
                                    <option value="dark" <?= $appThemeMode === 'dark' ? 'selected' : '' ?>>🌙 Dark Mode</option>
                                </select>
                            </div>

                            <div class="form-control">
                                <label class="text-xs font-semibold text-gray-700 mb-1 block">App Display Name</label>
                                <input type="text" name="app_settings[app_name]" id="appNameInput" value="<?= htmlspecialchars($appName) ?>" class="input w-full" oninput="updateAppPreview()">
                            </div>
                        </div>
                    </div>

                    <!-- API & Security Section -->
                    <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm">
                        <div class="flex items-center mb-4 pb-3 border-b border-gray-100">
                            <div class="w-8 h-8 bg-rose-50 text-rose-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="material-symbols-outlined text-lg">vpn_key</span>
                            </div>
                            <div>
                                <h3 class="text-md font-semibold text-gray-900">API Key & Security</h3>
                                <p class="text-xs text-gray-500">Secure mobile app API endpoints from unauthorized client requests</p>
                            </div>
                        </div>

                        <div class="form-control">
                            <label class="text-xs font-semibold text-gray-700 mb-1 block">Security API Key</label>
                            <div class="flex space-x-2">
                                <input type="text" name="app_settings[api_key]" id="appApiKey" value="<?= htmlspecialchars($appApiKey) ?>" class="input w-full font-mono text-sm" placeholder="Enter API Key or generate one">
                                <?php $webBrandColor = $settingsData['brand_color'] ?? '#007bff'; ?>
                                <button type="button" onclick="generateApiKey()" class="btn text-xs px-4 text-white rounded-lg font-bold transition-all hover:opacity-90 shadow-sm" style="background-color: <?= htmlspecialchars($webBrandColor) ?>; border-color: <?= htmlspecialchars($webBrandColor) ?>; color: #ffffff;">Generate Key</button>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-1">This key is returned by the theme settings API and must be sent in the <code>X-API-Key</code> header for all subsequent mobile requests.</p>
                        </div>

                        <div class="form-control mt-4">
                            <label class="text-xs font-semibold text-gray-700 mb-1 block">API Request Timeout (seconds)</label>
                            <input type="number" name="app_settings[api_timeout]" id="appApiTimeout" value="<?= htmlspecialchars((string) $appApiTimeout) ?>" min="5" max="300" step="1" class="input w-full text-sm" placeholder="30">
                            <p class="text-[10px] text-gray-400 mt-1">Maximum wait time for mobile API requests. Returned as <code>api_timeout</code> in seconds (5–300).</p>
                        </div>
                    </div>

                    <!-- Button Customization Section -->
                    <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm">
                        <div class="flex items-center mb-4 pb-3 border-b border-gray-100">
                            <div class="w-8 h-8 bg-indigo-50 text-indigo-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="material-symbols-outlined text-lg">smart_button</span>
                            </div>
                            <div>
                                <h3 class="text-md font-semibold text-gray-900">Button Styling & Colors</h3>
                                <p class="text-xs text-gray-500">Customize button background, text colors, hover states, and corner radiuses</p>
                            </div>
                        </div>



                        <!-- Secondary Button Customization -->
                        <div class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-100 space-y-3">
                            <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-slate-500"></span> Secondary Button
                            </h4>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">Secondary Button BG</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[btn_secondary_color]" id="appBtnSecondaryColor" value="<?= $appBtnSecondaryColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appBtnSecondaryColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appBtnSecondaryColor', this.value)">
                                    </div>
                                </div>

                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">Secondary Text Color</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[btn_secondary_text_color]" id="appBtnSecondaryTextColor" value="<?= $appBtnSecondaryTextColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appBtnSecondaryTextColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appBtnSecondaryTextColor', this.value)">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Button Border Radius -->
                        <div class="form-control mt-3">
                            <div class="flex justify-between items-center mb-1">
                                <label class="text-xs font-semibold text-gray-700">Button Corner Radius (Border Radius)</label>
                                <span id="appBtnRadiusVal" class="text-xs font-bold text-blue-600 font-mono"><?= $appBtnRadius ?>px</span>
                            </div>
                            <input type="range" name="app_settings[btn_radius]" id="appBtnRadius" min="0" max="30" value="<?= $appBtnRadius ?>" class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600" oninput="document.getElementById('appBtnRadiusVal').innerText = this.value + 'px'; updateAppPreview();">
                            <div class="flex justify-between text-[10px] text-gray-400 mt-1">
                                <span>0px (Square)</span>
                                <span>10px (Rounded)</span>
                                <span>30px (Pill)</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: LIGHT MODE THEME -->
                <div id="light-tab" class="admin-tab-content space-y-6 hidden">
                    <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm">
                        <div class="flex items-center mb-4 pb-3 border-b border-gray-100">
                            <div class="w-8 h-8 bg-amber-50 text-amber-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="material-symbols-outlined text-lg">light_mode</span>
                            </div>
                            <div>
                                <h3 class="text-md font-semibold text-gray-900">☀️ Light Mode Layout & Colors</h3>
                                <p class="text-xs text-gray-500">Configure visual colors and styles applied when the app is in Light Mode</p>
                            </div>
                        </div>

                        <div class="p-3 bg-amber-50/50 rounded-lg border border-amber-100 mb-4">
                            <label class="text-xs font-bold text-gray-800 block mb-1">Light Theme Background (App BG)</label>
                            <div class="flex items-center space-x-2">
                                <input type="color" name="app_settings[theme_primary_color]" id="themePrimaryColor" value="<?= $themePrimaryColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                <input type="text" value="<?= $themePrimaryColor ?>" class="input text-xs font-mono uppercase py-1 px-2 w-full" onchange="syncColorInput('themePrimaryColor', this.value)">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Header Background</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[header_bg]" id="appHeaderBg" value="<?= $appHeaderBg ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHeaderBg ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHeaderBg', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Header Text / Icon</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[header_text_color]" id="appHeaderTextColor" value="<?= $appHeaderTextColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHeaderTextColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHeaderTextColor', this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 mt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Card Background</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[card_bg]" id="appCardBg" value="<?= $appCardBg ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appCardBg ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appCardBg', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Card Border Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[card_border_color]" id="appCardBorderColor" value="<?= $appCardBorderColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appCardBorderColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appCardBorderColor', this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-3 mt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Button Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[btn_color]" id="appBtnColor" value="<?= $appBtnColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appBtnColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appBtnColor', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Button Text Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[btn_text_color]" id="appBtnTextColor" value="<?= $appBtnTextColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appBtnTextColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appBtnTextColor', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Button Border Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[btn_border_color]" id="appBtnBorderColor" value="<?= $appBtnBorderColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appBtnBorderColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appBtnBorderColor', this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 mt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Text Primary Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[text_primary_color]" id="textPrimaryColor" value="<?= $textPrimaryColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $textPrimaryColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('textPrimaryColor', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Text Secondary Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[text_secondary_color]" id="textSecondaryColor" value="<?= $textSecondaryColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $textSecondaryColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('textSecondaryColor', this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 mt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Home Card BG</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[home_card_bg]" id="appHomeCardBg" value="<?= $appHomeCardBg ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHomeCardBg ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHomeCardBg', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Home Card Border Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[home_card_border_color]" id="appHomeCardBorderColor" value="<?= $appHomeCardBorderColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHomeCardBorderColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHomeCardBorderColor', this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 mt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Home Card Icon Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[home_card_icon_color]" id="appHomeCardIconColor" value="<?= $appHomeCardIconColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHomeCardIconColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHomeCardIconColor', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Home Card Text Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[home_card_text_color]" id="appHomeCardTextColor" value="<?= $appHomeCardTextColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHomeCardTextColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHomeCardTextColor', this.value)">
                                </div>
                            </div>
                        </div>

                        <!-- All Tabs Styling Section (e.g. Flights, Stays scrollable tabs) -->
                        <div class="mt-4 pt-4 border-t-2 border-dashed border-gray-150">
                            <h4 class="text-xs font-bold text-gray-805 uppercase tracking-wider mb-3">All Tabs Styling (e.g. global category tabs switcher)</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">All Tab BG</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[all_tab_bg]" id="appAllTabBg" value="<?= $appAllTabBg ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appAllTabBg ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appAllTabBg', this.value)">
                                    </div>
                                </div>

                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">Active All Tab BG</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[all_tab_active_bg]" id="appAllTabActiveBg" value="<?= $appAllTabActiveBg ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appAllTabActiveBg ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appAllTabActiveBg', this.value)">
                                    </div>
                                </div>

                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">All Tab Border Color</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[all_tab_border_color]" id="appAllTabBorderColor" value="<?= $appAllTabBorderColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appAllTabBorderColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appAllTabBorderColor', this.value)">
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 mt-3 border-t border-gray-100">
                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">Active All Tab Text Color</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[all_tab_active_text_color]" id="appAllTabActiveTextColor" value="<?= $appAllTabActiveTextColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appAllTabActiveTextColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appAllTabActiveTextColor', this.value)">
                                    </div>
                                </div>

                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">Inactive All Tab Text Color</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[all_tab_inactive_text_color]" id="appAllTabInactiveTextColor" value="<?= $appAllTabInactiveTextColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appAllTabInactiveTextColor ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appAllTabInactiveTextColor', this.value)">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Snackbar Styling Section -->
                        <div class="mt-4 pt-4 border-t-2 border-dashed border-gray-150">
                            <h4 class="text-xs font-bold text-gray-805 uppercase tracking-wider mb-3">Snackbar / Toast Styling</h4>
                            <?php foreach ($snackbarThemeConfig as $type => $config): ?>
                                <?php $colors = $appSnackbarColors[$type]; ?>
                                <div class="mb-4 p-3 bg-gray-50 rounded-lg border border-gray-100">
                                    <h5 class="text-xs font-bold text-gray-800 mb-3 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-sm"><?= $config['icon'] ?></span>
                                        <?= $config['label'] ?> Snackbar
                                    </h5>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <div class="form-control">
                                            <label class="text-xs text-gray-600 block mb-1">Background</label>
                                            <div class="flex items-center space-x-2">
                                                <input type="color" name="app_settings[snackbar_<?= $type ?>_bg]" id="appSnackbar<?= ucfirst($type) ?>Bg" value="<?= $colors['bg'] ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                                <input type="text" value="<?= $colors['bg'] ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appSnackbar<?= ucfirst($type) ?>Bg', this.value)">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs text-gray-600 block mb-1">Text Color</label>
                                            <div class="flex items-center space-x-2">
                                                <input type="color" name="app_settings[snackbar_<?= $type ?>_text_color]" id="appSnackbar<?= ucfirst($type) ?>TextColor" value="<?= $colors['text_color'] ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                                <input type="text" value="<?= $colors['text_color'] ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appSnackbar<?= ucfirst($type) ?>TextColor', this.value)">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs text-gray-600 block mb-1">Border Color</label>
                                            <div class="flex items-center space-x-2">
                                                <input type="color" name="app_settings[snackbar_<?= $type ?>_border_color]" id="appSnackbar<?= ucfirst($type) ?>BorderColor" value="<?= $colors['border_color'] ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                                <input type="text" value="<?= $colors['border_color'] ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appSnackbar<?= ucfirst($type) ?>BorderColor', this.value)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: DARK MODE THEME -->
                <div id="dark-tab" class="admin-tab-content space-y-6 hidden">
                    <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm">
                        <div class="flex items-center mb-4 pb-3 border-b border-gray-100">
                            <div class="w-8 h-8 bg-blue-900 text-blue-200 rounded-lg flex items-center justify-center mr-3">
                                <span class="material-symbols-outlined text-lg">dark_mode</span>
                            </div>
                            <div>
                                <h3 class="text-md font-semibold text-gray-900">🌙 Dark Mode Layout & Colors</h3>
                                <p class="text-xs text-gray-500">Configure visual colors and styles applied when the app is in Dark Mode</p>
                            </div>
                        </div>

                        <div class="p-3 bg-blue-950/20 rounded-lg border border-blue-900/10 mb-4">
                            <label class="text-xs font-bold text-gray-800 block mb-1">Dark Theme Background (App BG)</label>
                            <div class="flex items-center space-x-2">
                                <input type="color" name="app_settings[theme_secondary_color]" id="themeSecondaryColor" value="<?= $themeSecondaryColor ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                <input type="text" value="<?= $themeSecondaryColor ?>" class="input text-xs font-mono uppercase py-1 px-2 w-full" onchange="syncColorInput('themeSecondaryColor', this.value)">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Header Background</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[header_bg_dark]" id="appHeaderBgDark" value="<?= $appHeaderBgDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHeaderBgDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHeaderBgDark', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Header Text / Icon</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[header_text_color_dark]" id="appHeaderTextColorDark" value="<?= $appHeaderTextColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHeaderTextColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHeaderTextColorDark', this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 mt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Card Background</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[card_bg_dark]" id="appCardBgDark" value="<?= $appCardBgDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appCardBgDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appCardBgDark', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Card Border Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[card_border_color_dark]" id="appCardBorderColorDark" value="<?= $appCardBorderColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appCardBorderColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appCardBorderColorDark', this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-3 mt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Button Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[btn_color_dark]" id="appBtnColorDark" value="<?= $appBtnColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appBtnColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appBtnColorDark', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Button Text Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[btn_text_color_dark]" id="appBtnTextColorDark" value="<?= $appBtnTextColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appBtnTextColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appBtnTextColorDark', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Button Border Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[btn_border_color_dark]" id="appBtnBorderColorDark" value="<?= $appBtnBorderColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appBtnBorderColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appBtnBorderColorDark', this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 mt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Text Primary Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[text_primary_color_dark]" id="textPrimaryColorDark" value="<?= $textPrimaryColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $textPrimaryColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('textPrimaryColorDark', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Text Secondary Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[text_secondary_color_dark]" id="textSecondaryColorDark" value="<?= $textSecondaryColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $textSecondaryColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('textSecondaryColorDark', this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 mt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Home Card BG</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[home_card_bg_dark]" id="appHomeCardBgDark" value="<?= $appHomeCardBgDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHomeCardBgDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHomeCardBgDark', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Home Card Border Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[home_card_border_color_dark]" id="appHomeCardBorderColorDark" value="<?= $appHomeCardBorderColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHomeCardBorderColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHomeCardBorderColorDark', this.value)">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 mt-3 border-t border-gray-100">
                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Home Card Icon Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[home_card_icon_color_dark]" id="appHomeCardIconColorDark" value="<?= $appHomeCardIconColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHomeCardIconColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHomeCardIconColorDark', this.value)">
                                </div>
                            </div>

                            <div class="form-control">
                                <label class="text-xs text-gray-600 block mb-1">Home Card Text Color</label>
                                <div class="flex items-center space-x-2">
                                    <input type="color" name="app_settings[home_card_text_color_dark]" id="appHomeCardTextColorDark" value="<?= $appHomeCardTextColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                    <input type="text" value="<?= $appHomeCardTextColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appHomeCardTextColorDark', this.value)">
                                </div>
                            </div>
                        </div>

                        <!-- All Tabs Styling Section (e.g. Flights, Stays scrollable tabs) -->
                        <div class="mt-4 pt-4 border-t-2 border-dashed border-gray-150">
                            <h4 class="text-xs font-bold text-gray-805 uppercase tracking-wider mb-3">All Tabs Styling (e.g. global category tabs switcher)</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">All Tab BG</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[all_tab_bg_dark]" id="appAllTabBgDark" value="<?= $appAllTabBgDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appAllTabBgDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appAllTabBgDark', this.value)">
                                    </div>
                                </div>

                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">Active All Tab BG</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[all_tab_active_bg_dark]" id="appAllTabActiveBgDark" value="<?= $appAllTabActiveBgDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appAllTabActiveBgDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appAllTabActiveBgDark', this.value)">
                                    </div>
                                </div>

                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">All Tab Border Color</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[all_tab_border_color_dark]" id="appAllTabBorderColorDark" value="<?= $appAllTabBorderColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appAllTabBorderColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appAllTabBorderColorDark', this.value)">
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 mt-3 border-t border-gray-100">
                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">Active All Tab Text Color</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[all_tab_active_text_color_dark]" id="appAllTabActiveTextColorDark" value="<?= $appAllTabActiveTextColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appAllTabActiveTextColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appAllTabActiveTextColorDark', this.value)">
                                    </div>
                                </div>

                                <div class="form-control">
                                    <label class="text-xs text-gray-600 block mb-1">Inactive All Tab Text Color</label>
                                    <div class="flex items-center space-x-2">
                                        <input type="color" name="app_settings[all_tab_inactive_text_color_dark]" id="appAllTabInactiveTextColorDark" value="<?= $appAllTabInactiveTextColorDark ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                        <input type="text" value="<?= $appAllTabInactiveTextColorDark ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appAllTabInactiveTextColorDark', this.value)">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Snackbar Styling Section -->
                        <div class="mt-4 pt-4 border-t-2 border-dashed border-gray-150">
                            <h4 class="text-xs font-bold text-gray-805 uppercase tracking-wider mb-3">Snackbar / Toast Styling</h4>
                            <?php foreach ($snackbarThemeConfig as $type => $config): ?>
                                <?php $colors = $appSnackbarColors[$type]; ?>
                                <div class="mb-4 p-3 bg-gray-50 rounded-lg border border-gray-100">
                                    <h5 class="text-xs font-bold text-gray-800 mb-3 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-sm"><?= $config['icon'] ?></span>
                                        <?= $config['label'] ?> Snackbar
                                    </h5>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <div class="form-control">
                                            <label class="text-xs text-gray-600 block mb-1">Background</label>
                                            <div class="flex items-center space-x-2">
                                                <input type="color" name="app_settings[snackbar_<?= $type ?>_bg_dark]" id="appSnackbar<?= ucfirst($type) ?>BgDark" value="<?= $colors['bg_dark'] ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                                <input type="text" value="<?= $colors['bg_dark'] ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appSnackbar<?= ucfirst($type) ?>BgDark', this.value)">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs text-gray-600 block mb-1">Text Color</label>
                                            <div class="flex items-center space-x-2">
                                                <input type="color" name="app_settings[snackbar_<?= $type ?>_text_color_dark]" id="appSnackbar<?= ucfirst($type) ?>TextColorDark" value="<?= $colors['text_color_dark'] ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                                <input type="text" value="<?= $colors['text_color_dark'] ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appSnackbar<?= ucfirst($type) ?>TextColorDark', this.value)">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs text-gray-600 block mb-1">Border Color</label>
                                            <div class="flex items-center space-x-2">
                                                <input type="color" name="app_settings[snackbar_<?= $type ?>_border_color_dark]" id="appSnackbar<?= ucfirst($type) ?>BorderColorDark" value="<?= $colors['border_color_dark'] ?>" class="w-10 h-9 p-0 border border-gray-300 rounded cursor-pointer shrink-0" oninput="updateAppPreview()">
                                                <input type="text" value="<?= $colors['border_color_dark'] ?>" class="input text-xs font-mono uppercase py-1 px-2" onchange="syncColorInput('appSnackbar<?= ucfirst($type) ?>BorderColorDark', this.value)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Save Action Buttons -->
                <div class="pt-2 pb-16 flex items-center gap-3">
                    <button type="submit" class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg font-medium">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span>Save App Settings</span>
                    </button>
                    
                    <button type="button" onclick="resetAppDefaults()" class="btn white text-gray-600 hover:text-gray-900 border border-gray-300 py-3 px-4 rounded-lg flex items-center space-x-1 text-sm">
                        <span class="material-symbols-outlined text-sm">restart_alt</span>
                        <span>Reset Defaults</span>
                    </button>
                </div> 
            </div>

            <!-- Live Interactive Smartphone Preview (5 Columns) -->
            <div class="lg:col-span-5">
                <div class="sticky top-6">
                    <div class="bg-gray-950 p-4 rounded-[40px] shadow-2xl border-4 border-gray-800 max-w-[340px] mx-auto overflow-hidden text-gray-800">
                        
                        <!-- Smartphone Notch & Status Bar -->
                        <div class="bg-black text-white text-[11px] px-6 py-2 flex justify-between items-center select-none">
                            <span class="font-bold">9:41</span>
                            <div class="w-16 h-3.5 bg-black rounded-full border border-gray-800 flex items-center justify-center">
                                <div class="w-2.5 h-2.5 bg-gray-900 rounded-full"></div>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">signal_cellular_4_bar</span>
                                <span class="material-symbols-outlined text-xs">wifi</span>
                                <span class="material-symbols-outlined text-xs">battery_full</span>
                            </div>
                        </div>

                        <!-- Mock Mobile Screen Body -->
                        <div id="mockAppScreen" class="min-h-[560px] flex flex-col justify-between transition-colors duration-200 font-sans" style="background-color: <?= $appThemeMode === 'dark' ? $themeSecondaryColor : $themePrimaryColor ?>;">
                            
                            <!-- App Bar Header -->
                            <div>
                                <div id="mockHeader" class="px-4 py-3 flex items-center justify-between shadow-sm transition-colors duration-200" style="background-color: <?= $appHeaderBg ?>; color: <?= $appHeaderTextColor ?>;">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-xl">menu</span>
                                        <span id="mockAppName" class="font-bold text-sm truncate max-w-[140px]"><?= htmlspecialchars($appName) ?></span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <!-- Interactive Mock Dark Light Toggle -->
                                        <button type="button" onclick="toggleMockPreviewMode()" class="p-1 rounded-full hover:bg-white/10 transition-colors" title="Toggle preview mode">
                                            <span id="mockModeIcon" class="material-symbols-outlined text-lg">
                                                <?= $appThemeMode === 'dark' ? 'light_mode' : 'dark_mode' ?>
                                            </span>
                                        </button>
                                        <span class="material-symbols-outlined text-xl">notifications</span>
                                    </div>
                                </div>
                                <!-- Category Tabs Scrollable Row (Right under Header) -->
                                <div id="mockCategoryTabs" class="px-3 py-2 flex items-center space-x-2 overflow-x-auto border-b transition-colors select-none scrollbar-none" style="background-color: <?= $appThemeMode === 'dark' ? $themeSecondaryColor : $themePrimaryColor ?>; border-color: <?= $appThemeMode === 'dark' ? $appCardBorderColorDark : $appCardBorderColor ?>;">
                                    <!-- Active Tab: Stays -->
                                    <div class="mock-all-tab-active px-3 py-1.5 rounded-full text-xs font-bold whitespace-nowrap flex items-center gap-1 transition-colors border" style="background-color: <?= $appThemeMode === 'dark' ? $appAllTabActiveBgDark : $appAllTabActiveBg ?>; color: <?= $appThemeMode === 'dark' ? $appAllTabActiveTextColorDark : $appAllTabActiveTextColor ?>; border-color: <?= $appThemeMode === 'dark' ? $appAllTabBorderColorDark : $appAllTabBorderColor ?>;">
                                        <span class="mock-all-tab-active-icon material-symbols-outlined text-xs" style="color: <?= $appThemeMode === 'dark' ? $appAllTabActiveTextColorDark : $appAllTabActiveTextColor ?>;">hotel</span> Stays
                                    </div>
                                    <!-- Inactive Tabs: Flights, Umrah, Tours, Visa, Cars -->
                                    <div class="mock-all-tab-inactive px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap flex items-center gap-1 transition-colors border" style="background-color: <?= $appThemeMode === 'dark' ? $appAllTabBgDark : $appAllTabBg ?>; color: <?= $appThemeMode === 'dark' ? $appAllTabInactiveTextColorDark : $appAllTabInactiveTextColor ?>; border-color: <?= $appThemeMode === 'dark' ? $appAllTabBorderColorDark : $appAllTabBorderColor ?>;">
                                        <span class="mock-all-tab-inactive-icon material-symbols-outlined text-xs" style="color: <?= $appThemeMode === 'dark' ? $appAllTabInactiveTextColorDark : $appAllTabInactiveTextColor ?>;">flight_takeoff</span> Flights
                                    </div>
                                    <div class="mock-all-tab-inactive px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap flex items-center gap-1 transition-colors border" style="background-color: <?= $appThemeMode === 'dark' ? $appAllTabBgDark : $appAllTabBg ?>; color: <?= $appThemeMode === 'dark' ? $appAllTabInactiveTextColorDark : $appAllTabInactiveTextColor ?>; border-color: <?= $appThemeMode === 'dark' ? $appAllTabBorderColorDark : $appAllTabBorderColor ?>;">
                                        <span class="mock-all-tab-inactive-icon material-symbols-outlined text-xs" style="color: <?= $appThemeMode === 'dark' ? $appAllTabInactiveTextColorDark : $appAllTabInactiveTextColor ?>;">mosque</span> Umrah
                                    </div>
                                    <div class="mock-all-tab-inactive px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap flex items-center gap-1 transition-colors border" style="background-color: <?= $appThemeMode === 'dark' ? $appAllTabBgDark : $appAllTabBg ?>; color: <?= $appThemeMode === 'dark' ? $appAllTabInactiveTextColorDark : $appAllTabInactiveTextColor ?>; border-color: <?= $appThemeMode === 'dark' ? $appAllTabBorderColorDark : $appAllTabBorderColor ?>;">
                                        <span class="mock-all-tab-inactive-icon material-symbols-outlined text-xs" style="color: <?= $appThemeMode === 'dark' ? $appAllTabInactiveTextColorDark : $appAllTabInactiveTextColor ?>;">explore</span> Tours
                                    </div>
                                    <div class="mock-all-tab-inactive px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap flex items-center gap-1 transition-colors border" style="background-color: <?= $appThemeMode === 'dark' ? $appAllTabBgDark : $appAllTabBg ?>; color: <?= $appThemeMode === 'dark' ? $appAllTabInactiveTextColorDark : $appAllTabInactiveTextColor ?>; border-color: <?= $appThemeMode === 'dark' ? $appAllTabBorderColorDark : $appAllTabBorderColor ?>;">
                                        <span class="mock-all-tab-inactive-icon material-symbols-outlined text-xs" style="color: <?= $appThemeMode === 'dark' ? $appAllTabInactiveTextColorDark : $appAllTabInactiveTextColor ?>;">description</span> Visa
                                    </div>
                                    <div class="mock-all-tab-inactive px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap flex items-center gap-1 transition-colors border" style="background-color: <?= $appThemeMode === 'dark' ? $appAllTabBgDark : $appAllTabBg ?>; color: <?= $appThemeMode === 'dark' ? $appAllTabInactiveTextColorDark : $appAllTabInactiveTextColor ?>; border-color: <?= $appThemeMode === 'dark' ? $appAllTabBorderColorDark : $appAllTabBorderColor ?>;">
                                        <span class="mock-all-tab-inactive-icon material-symbols-outlined text-xs" style="color: <?= $appThemeMode === 'dark' ? $appAllTabInactiveTextColorDark : $appAllTabInactiveTextColor ?>;">directions_car</span> Cars
                                    </div>
                                </div>

                                <!-- Mock Body Content -->
                                <div class="p-4 space-y-4">
                                    
                                    <!-- Search Banner -->
                                    <div id="mockCard" class="p-4 rounded-xl shadow-sm border border-gray-200/50 transition-all duration-200" style="background-color: <?= $appCardBg ?>; color: <?= $appThemeMode === 'dark' ? $textPrimaryColorDark : $textPrimaryColor ?>;">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="mock-text-secondary text-xs font-bold uppercase tracking-wider opacity-75" style="color: <?= $textSecondaryColor ?>;">Book Your Trip</span>
                                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-bold">Offer 20% OFF</span>
                                        </div>
                                        <p class="mock-text-primary text-xs mb-3 font-semibold" style="color: <?= $textPrimaryColor ?>;">Explore top hotels, flights & tours worldwide</p>
                                        
                                        <!-- Interactive Primary Button -->
                                        <button type="button" id="mockBtnPrimary" class="w-full py-2.5 px-4 font-semibold text-xs transition-all flex items-center justify-center gap-1.5 shadow-md hover:opacity-95" style="background-color: <?= $appThemeMode === 'dark' ? $appBtnColorDark : $appBtnColor ?>; color: <?= $appThemeMode === 'dark' ? $appBtnTextColorDark : $appBtnTextColor ?>; border: 1px solid <?= $appThemeMode === 'dark' ? $appBtnBorderColorDark : $appBtnBorderColor ?>; border-radius: <?= $appBtnRadius ?>px;">
                                            <span class="material-symbols-outlined text-sm">search</span>
                                            <span>Search Flights & Hotels</span>
                                        </button>
                                    </div>

                                    <!-- Secondary Action Card -->
                                    <div id="mockSecondaryCard" class="p-3.5 rounded-xl border border-gray-200/40 bg-opacity-60 transition-all" style="background-color: <?= $appCardBg ?>;">
                                        <div class="flex items-center justify-between mb-2">
                                            <div class="flex items-center gap-2">
                                                <span class="w-7 h-7 rounded-lg bg-orange-100 text-orange-600 flex items-center justify-center material-symbols-outlined text-sm">confirmation_number</span>
                                                <div>
                                                    <h5 class="mock-text-primary text-xs font-bold" style="color: <?= $textPrimaryColor ?>;">My Bookings</h5>
                                                    <p class="mock-text-secondary text-[10px] opacity-60" style="color: <?= $textSecondaryColor ?>;">1 upcoming travel</p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Interactive Secondary Button -->
                                        <button type="button" id="mockBtnSecondary" class="w-full py-2 px-3 font-medium text-xs transition-all flex items-center justify-center gap-1" style="background-color: <?= $appBtnSecondaryColor ?>; color: <?= $appBtnSecondaryTextColor ?>; border-radius: <?= $appBtnRadius ?>px;">
                                            <span>View Ticket Details</span>
                                            <span class="material-symbols-outlined text-xs">arrow_forward</span>
                                        </button>
                                        
                                        <!-- Home Category Cards Grid (3 Columns) -->
                                        <div class="grid grid-cols-3 gap-2 mt-3">
                                            <div class="mock-home-cat-card p-2 rounded-lg border text-center transition-colors" style="background-color: <?= $appHomeCardBg ?>; border-color: <?= $appThemeMode === 'dark' ? $appHomeCardBorderColorDark : $appHomeCardBorderColor ?>; color: <?= $appHomeCardTextColor ?>;">
                                                <span class="mock-home-tab-inactive-icon material-symbols-outlined text-lg mb-0.5" style="color: <?= $appHomeCardIconColor ?>;">hotel</span>
                                                <div class="text-[10px] font-bold">Stays</div>
                                            </div>
                                            <div class="mock-home-cat-card p-2 rounded-lg border text-center transition-colors" style="background-color: <?= $appHomeCardBg ?>; border-color: <?= $appThemeMode === 'dark' ? $appHomeCardBorderColorDark : $appHomeCardBorderColor ?>; color: <?= $appHomeCardTextColor ?>;">
                                                <span class="mock-home-tab-inactive-icon material-symbols-outlined text-lg mb-0.5" style="color: <?= $appHomeCardIconColor ?>;">flight_takeoff</span>
                                                <div class="text-[10px] font-bold">Flights</div>
                                            </div>
                                            <div class="mock-home-cat-card p-2 rounded-lg border text-center transition-colors" style="background-color: <?= $appHomeCardBg ?>; border-color: <?= $appThemeMode === 'dark' ? $appHomeCardBorderColorDark : $appHomeCardBorderColor ?>; color: <?= $appHomeCardTextColor ?>;">
                                                <span class="mock-home-tab-inactive-icon material-symbols-outlined text-lg mb-0.5" style="color: <?= $appHomeCardIconColor ?>;">mosque</span>
                                                <div class="text-[10px] font-bold">Umrah</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Mock Snackbar Preview -->
                            <div class="mx-4 mb-2 space-y-1">
                                <?php foreach ($snackbarThemeConfig as $type => $config): ?>
                                    <?php $colors = $appSnackbarColors[$type]; ?>
                                    <div id="mockSnackbar<?= ucfirst($type) ?>" class="px-2.5 py-1.5 rounded-md text-[10px] font-medium shadow border transition-colors duration-200 flex items-center gap-1.5" style="background-color: <?= $appThemeMode === 'dark' ? $colors['bg_dark'] : $colors['bg'] ?>; color: <?= $appThemeMode === 'dark' ? $colors['text_color_dark'] : $colors['text_color'] ?>; border-color: <?= $appThemeMode === 'dark' ? $colors['border_color_dark'] : $colors['border_color'] ?>;">
                                        <span class="material-symbols-outlined text-xs"><?= $config['icon'] ?></span>
                                        <span><?= $config['message'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                        <!-- Smartphone Bottom Home Bar Indicator -->
                        <div class="bg-black py-1.5 flex justify-center">
                            <div class="w-28 h-1 bg-gray-700 rounded-full"></div>
                        </div>

                    </div>

                    <div class="text-center mt-3">
                        <span class="text-xs font-medium text-gray-500 flex items-center justify-center gap-1">
                            <span class="material-symbols-outlined text-sm">visibility</span>
                            Live Interactive Mobile Preview
                        </span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</form>

</div>

<!-- JavaScript logic for Live App Settings Preview -->
<script>
let isMockDarkModeOverride = null;

function syncColorInput(inputId, hexValue) {
    if (/^#[0-9A-F]{6}$/i.test(hexValue)) {
        document.getElementById(inputId).value = hexValue;
        updateAppPreview();
    }
}

function switchAdminTab(tabId, btnEl) {
    // Hide all tab content containers
    document.querySelectorAll('.admin-tab-content').forEach(el => {
        el.classList.add('hidden');
    });
    // Show chosen tab
    document.getElementById(tabId).classList.remove('hidden');

    // Reset button designs to inactive
    document.querySelectorAll('.admin-tab-btn').forEach(btn => {
        btn.classList.remove('text-blue-600', 'border-blue-600', 'bg-blue-50/30', 'font-bold');
        btn.classList.add('text-gray-500', 'border-transparent');
    });

    // Mark clicked button as active
    btnEl.classList.remove('text-gray-500', 'border-transparent');
    btnEl.classList.add('text-blue-600', 'border-blue-600', 'bg-blue-50/30', 'font-bold');

    // Auto toggle preview mode based on selected tab
    if (tabId === 'dark-tab') {
        isMockDarkModeOverride = true;
    } else if (tabId === 'light-tab') {
        isMockDarkModeOverride = false;
    } else {
        isMockDarkModeOverride = null;
    }
    updateAppPreview();
}

function toggleMockPreviewMode() {
    const modeSelect = document.getElementById('appThemeMode').value;
    const currentMode = isMockDarkModeOverride !== null ? isMockDarkModeOverride : (modeSelect === 'dark');
    isMockDarkModeOverride = !currentMode;
    updateAppPreview();
}

function updateAppPreview() {
    const mode = document.getElementById('appThemeMode').value;
    const appName = document.getElementById('appNameInput').value || 'Mobile App';
    const btnColor = document.getElementById('appBtnColor').value;
    const btnTextColor = document.getElementById('appBtnTextColor').value;
    const btnBorderColor = document.getElementById('appBtnBorderColor').value;
    const btnColorDark = document.getElementById('appBtnColorDark').value;
    const btnTextColorDark = document.getElementById('appBtnTextColorDark').value;
    const btnBorderColorDark = document.getElementById('appBtnBorderColorDark').value;
    const btnSecondaryColor = document.getElementById('appBtnSecondaryColor').value;
    const btnSecondaryTextColor = document.getElementById('appBtnSecondaryTextColor').value;
    const btnRadius = document.getElementById('appBtnRadius').value + 'px';
    
    const themePrimaryColor = document.getElementById('themePrimaryColor').value;
    const themeSecondaryColor = document.getElementById('themeSecondaryColor').value;

    const headerBg = document.getElementById('appHeaderBg').value;
    const headerTextColor = document.getElementById('appHeaderTextColor').value;
    const headerBgDark = document.getElementById('appHeaderBgDark').value;
    const headerTextColorDark = document.getElementById('appHeaderTextColorDark').value;

    const cardBg = document.getElementById('appCardBg').value;
    const cardBgDark = document.getElementById('appCardBgDark').value;

    const textPrimaryColor = document.getElementById('textPrimaryColor').value;
    const textSecondaryColor = document.getElementById('textSecondaryColor').value;
    const textPrimaryColorDark = document.getElementById('textPrimaryColorDark').value;
    const textSecondaryColorDark = document.getElementById('textSecondaryColorDark').value;

    const homeCardBg = document.getElementById('appHomeCardBg').value;
    const homeCardIconColor = document.getElementById('appHomeCardIconColor').value;
    const homeCardTextColor = document.getElementById('appHomeCardTextColor').value;
    const homeCardBgDark = document.getElementById('appHomeCardBgDark').value;
    const homeCardIconColorDark = document.getElementById('appHomeCardIconColorDark').value;
    const homeCardTextColorDark = document.getElementById('appHomeCardTextColorDark').value;

    const cardBorderColor = document.getElementById('appCardBorderColor').value;
    const cardBorderColorDark = document.getElementById('appCardBorderColorDark').value;
    const homeCardBorderColor = document.getElementById('appHomeCardBorderColor').value;
    const homeCardBorderColorDark = document.getElementById('appHomeCardBorderColorDark').value;

    const allTabBg = document.getElementById('appAllTabBg').value;
    const allTabActiveBg = document.getElementById('appAllTabActiveBg').value;
    const allTabBorderColor = document.getElementById('appAllTabBorderColor').value;
    const allTabActiveTextColor = document.getElementById('appAllTabActiveTextColor').value;
    const allTabInactiveTextColor = document.getElementById('appAllTabInactiveTextColor').value;

    const allTabBgDark = document.getElementById('appAllTabBgDark').value;
    const allTabActiveBgDark = document.getElementById('appAllTabActiveBgDark').value;
    const allTabBorderColorDark = document.getElementById('appAllTabBorderColorDark').value;
    const allTabActiveTextColorDark = document.getElementById('appAllTabActiveTextColorDark').value;
    const allTabInactiveTextColorDark = document.getElementById('appAllTabInactiveTextColorDark').value;

    const snackbarTypes = ['success', 'warning', 'failure'];
    const snackbarColors = {};
    snackbarTypes.forEach(type => {
        const key = type.charAt(0).toUpperCase() + type.slice(1);
        snackbarColors[type] = {
            bg: document.getElementById(`appSnackbar${key}Bg`).value,
            text: document.getElementById(`appSnackbar${key}TextColor`).value,
            border: document.getElementById(`appSnackbar${key}BorderColor`).value,
            bgDark: document.getElementById(`appSnackbar${key}BgDark`).value,
            textDark: document.getElementById(`appSnackbar${key}TextColorDark`).value,
            borderDark: document.getElementById(`appSnackbar${key}BorderColorDark`).value,
        };
    });

    let isDark = (isMockDarkModeOverride !== null) ? isMockDarkModeOverride : (mode === 'dark');

    // Update Mock Screen background using Theme Primary (Light) and Theme Secondary (Dark)
    const mockScreen = document.getElementById('mockAppScreen');
    if (mockScreen) {
        mockScreen.style.backgroundColor = isDark ? themeSecondaryColor : themePrimaryColor;
    }

    const mockModeIcon = document.getElementById('mockModeIcon');
    if (mockModeIcon) {
        mockModeIcon.innerText = isDark ? 'light_mode' : 'dark_mode';
    }

    const mockAppName = document.getElementById('mockAppName');
    if (mockAppName) {
        mockAppName.innerText = appName;
    }

    // Update Mock Header
    const mockHeader = document.getElementById('mockHeader');
    if (mockHeader) {
        mockHeader.style.backgroundColor = isDark ? headerBgDark : headerBg;
        mockHeader.style.color = isDark ? headerTextColorDark : headerTextColor;
    }

    // Update Category Tabs Container underneath Mock Header
    const mockCategoryTabs = document.getElementById('mockCategoryTabs');
    if (mockCategoryTabs) {
        mockCategoryTabs.style.backgroundColor = isDark ? themeSecondaryColor : themePrimaryColor;
        mockCategoryTabs.style.borderColor = isDark ? cardBorderColorDark : cardBorderColor;
    }

    // Style active All Tab element (underneath header)
    document.querySelectorAll('.mock-all-tab-active').forEach(el => {
        el.style.backgroundColor = isDark ? allTabActiveBgDark : allTabActiveBg;
        el.style.color = isDark ? allTabActiveTextColorDark : allTabActiveTextColor;
        el.style.borderColor = isDark ? allTabBorderColorDark : allTabBorderColor;
    });

    document.querySelectorAll('.mock-all-tab-active-icon').forEach(el => {
        el.style.color = isDark ? allTabActiveTextColorDark : allTabActiveTextColor;
    });

    // Style inactive All Tab elements (underneath header)
    document.querySelectorAll('.mock-all-tab-inactive').forEach(el => {
        el.style.backgroundColor = isDark ? allTabBgDark : allTabBg;
        el.style.color = isDark ? allTabInactiveTextColorDark : allTabInactiveTextColor;
        el.style.borderColor = isDark ? allTabBorderColorDark : allTabBorderColor;
    });

    document.querySelectorAll('.mock-all-tab-inactive-icon').forEach(el => {
        el.style.color = isDark ? allTabInactiveTextColorDark : allTabInactiveTextColor;
    });

    // Style Home Category Cards Grid (3 columns)
    document.querySelectorAll('.mock-home-cat-card').forEach(el => {
        el.style.backgroundColor = isDark ? homeCardBgDark : homeCardBg;
        el.style.borderColor = isDark ? homeCardBorderColorDark : homeCardBorderColor;
        el.style.color = isDark ? homeCardTextColorDark : homeCardTextColor;
    });

    document.querySelectorAll('.mock-home-tab-inactive-icon').forEach(el => {
        el.style.color = isDark ? homeCardIconColorDark : homeCardIconColor;
    });

    // Update Cards
    const mockCard = document.getElementById('mockCard');
    if (mockCard) {
        mockCard.style.backgroundColor = isDark ? cardBgDark : cardBg;
        mockCard.style.color = isDark ? textPrimaryColorDark : textPrimaryColor;
        mockCard.style.borderColor = isDark ? cardBorderColorDark : cardBorderColor;
    }

    const mockSecondaryCard = document.getElementById('mockSecondaryCard');
    if (mockSecondaryCard) {
        mockSecondaryCard.style.backgroundColor = isDark ? cardBgDark : cardBg;
        mockSecondaryCard.style.borderColor = isDark ? cardBorderColorDark : cardBorderColor;
    }

    document.querySelectorAll('.mock-grid-card').forEach(card => {
        card.style.backgroundColor = isDark ? cardBgDark : cardBg;
        card.style.color = isDark ? textPrimaryColorDark : textPrimaryColor;
        card.style.borderColor = isDark ? cardBorderColorDark : cardBorderColor;
    });

    // Update Primary & Secondary Texts
    document.querySelectorAll('.mock-text-primary').forEach(el => {
        el.style.color = isDark ? textPrimaryColorDark : textPrimaryColor;
    });

    document.querySelectorAll('.mock-text-secondary').forEach(el => {
        el.style.color = isDark ? textSecondaryColorDark : textSecondaryColor;
    });

    // Update Primary Button
    const mockBtnPrimary = document.getElementById('mockBtnPrimary');
    if (mockBtnPrimary) {
        mockBtnPrimary.style.backgroundColor = isDark ? btnColorDark : btnColor;
        mockBtnPrimary.style.color = isDark ? btnTextColorDark : btnTextColor;
        mockBtnPrimary.style.borderColor = isDark ? btnBorderColorDark : btnBorderColor;
        mockBtnPrimary.style.borderRadius = btnRadius;
    }

    // Update Secondary Button
    const mockBtnSecondary = document.getElementById('mockBtnSecondary');
    if (mockBtnSecondary) {
        mockBtnSecondary.style.backgroundColor = btnSecondaryColor;
        mockBtnSecondary.style.color = btnSecondaryTextColor;
        mockBtnSecondary.style.borderRadius = btnRadius;
    }

    // Update Snackbar Preview
    snackbarTypes.forEach(type => {
        const mockSnackbar = document.getElementById(`mockSnackbar${type.charAt(0).toUpperCase() + type.slice(1)}`);
        if (mockSnackbar) {
            mockSnackbar.style.backgroundColor = isDark ? snackbarColors[type].bgDark : snackbarColors[type].bg;
            mockSnackbar.style.color = isDark ? snackbarColors[type].textDark : snackbarColors[type].text;
            mockSnackbar.style.borderColor = isDark ? snackbarColors[type].borderDark : snackbarColors[type].border;
        }
    });

    // Sync text inputs next to color pickers
    document.querySelectorAll('input[type="color"]').forEach(colorInput => {
        const textInput = colorInput.nextElementSibling;
        if (textInput && textInput.tagName === 'INPUT') {
            textInput.value = colorInput.value.toUpperCase();
        }
    });
}

function resetAppDefaults() {
    if (confirm('Reset App settings to default values?')) {
        const defaults = {
            'appThemeMode': 'light',
            'appNameInput': 'PHPTRAVELS',
            'themePrimaryColor': '#ffffff',
            'themeSecondaryColor': '#121212',
            'appBtnColor': '#2563eb',
            'appBtnTextColor': '#ffffff',
            'appBtnBorderColor': '#2563eb',
            'appBtnColorDark': '#2563eb',
            'appBtnTextColorDark': '#ffffff',
            'appBtnBorderColorDark': '#2563eb',
            'appBtnHoverColor': '#1d4ed8',
            'appBtnSecondaryColor': '#f1f5f9',
            'appBtnSecondaryTextColor': '#334155',
            'appBtnRadius': '10',
            'appHeaderBg': '#f9fafb',
            'appHeaderTextColor': '#111827',
            'appHeaderBgDark': '#1e1e1e',
            'appHeaderTextColorDark': '#f5f5f5',
            'appCardBg': '#ffffff',
            'appCardBgDark': '#1e1e1e',
            'textPrimaryColor': '#111827',
            'textSecondaryColor': '#6b7280',
            'textPrimaryColorDark': '#f5f5f5',
            'textSecondaryColorDark': '#9e9e9e',
            'appHomeCardBg': '#ffffff',
            'appHomeCardIconColor': '#2563eb',
            'appHomeCardTextColor': '#111827',
            'appHomeCardBgDark': '#1e1e1e',
            'appHomeCardIconColorDark': '#2563eb',
            'appHomeCardTextColorDark': '#ffffff',
            'appCardBorderColor': '#e4e6ec',
            'appCardBorderColorDark': '#1e1e1e',
            'appHomeCardBorderColor': '#e4e6ec',
            'appHomeCardBorderColorDark': '#1e1e1e',
            'appAllTabBg': '#f9fafb',
            'appAllTabActiveBg': '#2563eb',
            'appAllTabBorderColor': '#f9fafb',
            'appAllTabActiveTextColor': '#ffffff',
            'appAllTabInactiveTextColor': '#6b7280',
            'appAllTabBgDark': '#1e1e1e',
            'appAllTabActiveBgDark': '#2563eb',
            'appAllTabBorderColorDark': '#1e1e1e',
            'appAllTabActiveTextColorDark': '#ffffff',
            'appAllTabInactiveTextColorDark': '#9e9e9e',
            'appSnackbarSuccessBg': '#166534',
            'appSnackbarSuccessTextColor': '#ffffff',
            'appSnackbarSuccessBorderColor': '#15803d',
            'appSnackbarWarningBg': '#b45309',
            'appSnackbarWarningTextColor': '#ffffff',
            'appSnackbarWarningBorderColor': '#d97706',
            'appSnackbarFailureBg': '#b91c1c',
            'appSnackbarFailureTextColor': '#ffffff',
            'appSnackbarFailureBorderColor': '#dc2626',
            'appSnackbarSuccessBgDark': '#14532d',
            'appSnackbarSuccessTextColorDark': '#bbf7d0',
            'appSnackbarSuccessBorderColorDark': '#166534',
            'appSnackbarWarningBgDark': '#78350f',
            'appSnackbarWarningTextColorDark': '#fde68a',
            'appSnackbarWarningBorderColorDark': '#92400e',
            'appSnackbarFailureBgDark': '#7f1d1d',
            'appSnackbarFailureTextColorDark': '#fecaca',
            'appSnackbarFailureBorderColorDark': '#991b1b',
            'appApiKey': '',
            'appApiTimeout': '30'
        };

        for (const [id, val] of Object.entries(defaults)) {
            const el = document.getElementById(id);
            if (el) {
                el.value = val;
            }
        }

        const radiusValEl = document.getElementById('appBtnRadiusVal');
        if (radiusValEl) {
            radiusValEl.innerText = '10px';
        }

        isMockDarkModeOverride = null;
        updateAppPreview();
    }
}

function generateApiKey() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = 'pk_';
    for (let i = 0; i < 32; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById('appApiKey');
    if (input) {
        input.value = result;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateAppPreview();
});
</script>
