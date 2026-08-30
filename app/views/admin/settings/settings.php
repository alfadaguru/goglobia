<?php
// Get settings from database using Medoo - get the first row of settings
$settingsData = $db->get('settings', '*');

// Function to get setting with default value
function getSetting($settingsData, $key, $default = '')
{
    return isset($settingsData[$key]) && $settingsData[$key] !== '' ? $settingsData[$key] : $default;
}

function needswarnStyle($value, $fieldType = '') {
    $defaultValues = [
        'business_name' => ['phptarvels', 'PHPTARVELS', 'phptravels', 'PHPTRAVELS'],
        'site_url' => ['https://phptravels.net'],
        'contact_phone' => ['+1234567890', '+123456789'],
        'contact_email' => ['email@agency.com', 'admin@example.com'],
        'social_facebook' => ['https://facebook.com/phptravels'],
        'social_instagram' => ['https://instagram.com/phptravels'],
        'social_twitter' => ['https://twitter.com/phptravels'],
        'social_youtube' => ['https://youtube.com/@phptravels'],
        'social_linkedin' => ['https://linkedin.com/company/phptravels'],
        'social_whatsapp' => ['https://wa.me/1234567890'],

        'email_sender_name' => ['PHPTRAVELS'],
        'email_sender_email' => ['info@phptravels.com', 'noreply@phptravels.com'],
        'smtp_username' => ['info@phptravels.com'],
        'smtp_host' => ['smtp.phptravels.com'],
        'smtp_password' => ['default', 'password', '123456']
    ];

    if (in_array($fieldType, ['logo', 'favicon', 'cover'])) {
        $defaultProperties = [
            'logo' => [
                'size' => 19905,
                'width' => 250,
                'height' => 58
            ],
            'favicon' => [
                'size' => 4908,
                'width' => 128,
                'height' => 128
            ],
            'cover' => [
                'size' => 1058275,
                'width' => 1240,
                'height' => 500
            ]
        ];

        $imagePaths = [
            'logo' => __DIR__ . '/../../../../uploads/global/logo.png',
            'favicon' => __DIR__ . '/../../../../uploads/global/favicon.png',
            'cover' => __DIR__ . '/../../../../uploads/global/cover.png'
        ];

        if (!isset($defaultProperties[$fieldType]) || !file_exists($imagePaths[$fieldType])) {
            return false;
        }

        $currentSize = filesize($imagePaths[$fieldType]);
        $currentDimensions = getimagesize($imagePaths[$fieldType]);

        return ($currentSize == $defaultProperties[$fieldType]['size'] &&
                $currentDimensions[0] == $defaultProperties[$fieldType]['width'] &&
                $currentDimensions[1] == $defaultProperties[$fieldType]['height']);
    }

    // Trim and normalize the value for comparison
    $normalizedValue = trim(strtolower($value));

    foreach ($defaultValues as $field => $defaults) {
        foreach ($defaults as $defaultVal) {
            $normalizedDefault = trim(strtolower($defaultVal));

            // Use exact matching instead of partial matching
            if ($normalizedValue === $normalizedDefault) {
                return true;
            }
        }
    }

    return false;
}

// Function to get provider definition from directory
function getProviderDefinitionFromDir($type) {
    $providers = [];
    $providerDir = __DIR__ . '/../../../../app/lib/notifications/' . $type . '/';

    if (is_dir($providerDir)) {
        $files = scandir($providerDir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $providerKey = pathinfo($file, PATHINFO_FILENAME);
                $providerFile = $providerDir . $file;

                if (file_exists($providerFile)) {
                    $requirements = [];
                    include $providerFile;

                    if (isset($requirements['name']) && isset($requirements['fields'])) {
                        $providers[$providerKey] = $requirements;
                    }
                }
            }
        }
    }

    return $providers;
}

// Function to get provider config from database
function getProviderConfig($settingsData, $type, $providerKey = null)
{
    $configKey = $type . '_providers_config';
    $configData = json_decode(getSetting($settingsData, $configKey, '{}'), true);

    if ($providerKey) {
        return isset($configData[$providerKey]) ? $configData[$providerKey] : [];
    }

    return $configData;
}

// Prepare settings with defaults using the actual database column names
$settings = [
    'site_name' => getSetting($settingsData, 'business_name', 'My Website'),
    'site_url' => getSetting($settingsData, 'site_url', 'https://example.com'),
    'site_description' => getSetting($settingsData, 'tag_line', 'A great website'),
    'site_keywords' => getSetting($settingsData, 'site_keywords', 'website, business, service'),
    'meta_description' => getSetting($settingsData, 'meta_description', 'Website meta description'),
    'google_analytics' => getSetting($settingsData, 'javascript', ''),
    'logo_url' => getSetting($settingsData, 'header_logo_img', ''),
    'favicon_url' => getSetting($settingsData, 'favicon_img', ''),
    'brand_color' => getSetting($settingsData, 'brand_color', '#007bff'),
    'smtp_host' => getSetting($settingsData, 'smtp_host', ''),
    'smtp_port' => getSetting($settingsData, 'smtp_port', '587'),
    'smtp_username' => getSetting($settingsData, 'smtp_username', ''),
    'smtp_password' => getSetting($settingsData, 'smtp_password', ''),
    'contact_email' => getSetting($settingsData, 'contact_email', 'contact@example.com'),
    'contact_phone' => getSetting($settingsData, 'contact_phone', '+1234567890'),
    'contact_address' => getSetting($settingsData, 'address', '123 Main St, City, Country'),
    'facebook_url' => getSetting($settingsData, 'social_facebook', ''),
    'twitter_url' => getSetting($settingsData, 'social_twitter', ''),
    'linkedin_url' => getSetting($settingsData, 'social_linkedin', ''),
    'instagram_url' => getSetting($settingsData, 'social_instagram', ''),
];

// Get providers definition from directories
$emailProviders = getProviderDefinitionFromDir('email');
$whatsappProviders = getProviderDefinitionFromDir('whatsapp');
$smsProviders = getProviderDefinitionFromDir('sms');
$pushProviders = getProviderDefinitionFromDir('push');

// Get provider configurations
$emailConfig = getProviderConfig($settingsData, 'email');
$whatsappConfig = getProviderConfig($settingsData, 'whatsapp');
$smsConfig = getProviderConfig($settingsData, 'sms');
$pushConfig = getProviderConfig($settingsData, 'push');

// Calculate tab warnings
$tabWarnings = [
    'general' => false,
    'seo' => false,
    'branding' => false,
    'contact' => false,
    'notifications' => false,
    'social' => false,
    'apps' => false,
    'tracking' => false,
    'booking' => false
];

// Check General Tab
if (needswarnStyle($settings['site_name'], 'business_name') ||
    needswarnStyle($settings['site_url'], 'site_url')) {
    $tabWarnings['general'] = true;
}

// Check Contact Tab
if (needswarnStyle($settings['contact_email'], 'contact_email') ||
    needswarnStyle($settings['contact_phone'], 'contact_phone')) {
    $tabWarnings['contact'] = true;
}

// Check Branding Tab (Manual file checks)
if (needswarnStyle('', 'logo') ||
    needswarnStyle('', 'favicon') ||
    needswarnStyle('', 'cover')) {
    $tabWarnings['branding'] = true;
}

// Check Notifications Tab (Email settings)
if (needswarnStyle(getSetting($settingsData, 'email_sender_name', 'PHPTRAVELS'), 'email_sender_name') ||
    needswarnStyle(getSetting($settingsData, 'email_sender_email', 'noreply@phptravels.com'), 'email_sender_email')) {
    $tabWarnings['notifications'] = true;
}

// Check SMTP settings if SMTP is selected
$currentEmailProvider = getSetting($settingsData, 'email_provider', 'smtp');
if ($currentEmailProvider === 'smtp') {
    $smtpConfig = isset($emailConfig['smtp']) ? $emailConfig['smtp'] : [];
    if (needswarnStyle($smtpConfig['username'] ?? '', 'smtp_username') ||
        needswarnStyle($smtpConfig['host'] ?? '', 'smtp_host') ||
        needswarnStyle($smtpConfig['password'] ?? '', 'smtp_password')) {
        $tabWarnings['notifications'] = true;
    }
}

// Check Social Tab
$socialMediaJson = getSetting($settingsData, 'social_media', '{}');
$socialMedia = json_decode($socialMediaJson, true);
if (needswarnStyle($socialMedia['facebook'] ?? '', 'social_facebook') ||
    needswarnStyle($socialMedia['twitter'] ?? '', 'social_twitter') ||
    needswarnStyle($socialMedia['linkedin'] ?? '', 'social_linkedin') ||
    needswarnStyle($socialMedia['instagram'] ?? '', 'social_instagram') ||
    needswarnStyle($socialMedia['youtube'] ?? '', 'social_youtube') ||
    needswarnStyle($socialMedia['whatsapp'] ?? '', 'social_whatsapp')) {
    $tabWarnings['social'] = true;
}

// Check Apps Tab
if (needswarnStyle(getSetting($settingsData, 'android_store', 'https://play.google.com/store/apps/details?id=com.phptravels'), 'android_store') ||
    needswarnStyle(getSetting($settingsData, 'ios_store', 'https://apps.apple.com/app/phptravels/id123456789'), 'ios_store')) {
    $tabWarnings['apps'] = true;
}

// Check Tracking Tab
if (needswarnStyle($settings['google_analytics'] ?? '', 'google_analytics') ||
    needswarnStyle(getSetting($settingsData, 'facebook_pixel', ''), 'facebook_pixel') ||
    needswarnStyle(getSetting($settingsData, 'gtm_head', ''), 'gtm_head')) {
    $tabWarnings['tracking'] = true;
}

// Check Booking Tab
if (needswarnStyle(getSetting($settingsData, 'booking_expiry_time', '15'), 'booking_expiry_time')) {
    $tabWarnings['booking'] = true;
}

$message = '';
$messageSuccess = false;

?>

<!-- Clean Modern Settings Page -->
<div class="bg-white">

    <!-- Header -->
    <div class="container">
        <div class="flex items-center justify-between gap-4 pt-6 pb-4 mb-6 border-b border-gray-200">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined">settings</span>
                </div>
                <div>
                    <h1 class="text-xl font-semibold text-gray-900 leading-tight"><?=T::settings?></h1>
                    <p class="text-sm text-gray-500 mt-0.5"><?=T::configure_your_application?></p>
                </div>
            </div>
            <button onclick="history.back()" class="btn secondary shrink-0">
                <span class="material-symbols-outlined mr-1 text-sm">arrow_back</span>
                <?=T::back?>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container">

        <!-- Tab Navigation -->
        <!-- Settings layout: vertical sub-sidebar + content -->
        <div class="flex flex-col lg:flex-row gap-6 items-start">

            <!-- Sub-sidebar (vertical tabs) -->
            <aside class="w-full lg:w-60 shrink-0 lg:sticky lg:top-4 mb-[25px]">
                <nav class="flex lg:flex-col gap-1 overflow-x-auto lg:overflow-visible bg-white rounded-xl border border-gray-200 p-2">
                    <?php
                    // key => [material icon, label] — rendered as vertical nav items.
                    $navTabs = [
                        'general'       => ['tune', T::general],
                        'seo'           => ['search', T::seo],
                        'branding'      => ['palette', T::branding],
                        'themes'        => ['format_paint', T::themes],
                        'accounts'      => ['people', T::accounts],
                        'contact'       => ['contact_mail', T::contact],
                        'notifications' => ['notifications', T::notifications],
                        'social'        => ['share', T::social],
                        'apps'          => ['apps', T::apps],
                        'tracking'      => ['analytics', T::tracking],
                        'booking'       => ['event_available', T::booking],
                        'ai'            => ['smart_toy', 'AI'],
                    ];
                    foreach ($navTabs as $key => [$icon, $label]):
                        $active = $key === 'general';
                    ?>
                    <button type="button" onclick="switchTab('<?= $key ?>')" id="btn-<?= $key ?>"
                        class="tab-btn w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors whitespace-nowrap <?= $active ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-50' ?>">
                        <span class="material-symbols-outlined text-lg"><?= $icon ?></span>
                        <span><?= $label ?></span>
                        <span id="warning-dot-<?= $key ?>" class="ml-auto w-2 h-2 bg-yellow-400 rounded-full <?= !empty($tabWarnings[$key]) ? '' : 'hidden' ?>"></span>
                    </button>
                    <?php endforeach; ?>
                </nav>
            </aside>

            <!-- Settings content -->
            <div class="flex-1 min-w-0 w-full">

        <form id="settings-form" action="update-settings" method="post" enctype="multipart/form-data">
            <input type="hidden" name="form_token" value="<?= hash('sha256', session_id() . time()) ?>">
            <input type="hidden" name="current_tab" id="currentTabInput" value="general">
            <!-- General Tab — visible by default; JS hides it when another tab is selected -->
            <div id="tab-general" class="tab-content">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                    <!-- Application Settings -->
                    <div class="section mb-0">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-blue-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-blue-600 text-sm">settings_applications</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::application?></h3>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <div class="form-control">
                                    <label><?=T::business_name?></label>
                                    <input type="text" name="business_name" class="input <?= needswarnStyle($settings['site_name']) ? 'border-yellow-500' : '' ?>"
                                        value="<?= htmlspecialchars($settings['site_name']) ?>" required
                                        oninput="validateDefaultValue(this, 'business_name')">
                                    <div class="default-warning <?= needswarnStyle($settings['site_name']) ? '' : 'hidden' ?>">
                                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="form-control">
                                    <label><?=T::domain_name?></label>
                                    <input type="url" name="site_url" class="input <?= needswarnStyle($settings['site_url']) ? 'border-yellow-500' : '' ?>"
                                        value="<?= htmlspecialchars($settings['site_url']) ?>" required
                                        oninput="validateDefaultValue(this, 'site_url')">
                                    <div class="default-warning <?= needswarnStyle($settings['site_url']) ? '' : 'hidden' ?>">
                                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System Settings -->
                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-orange-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-orange-600 text-sm">computer</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::system?></h3>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <div class="form-control">
                                    <label><?=T::site_description?></label>
                                    <textarea name="tag_line" rows="2"
                                        class="input"><?= htmlspecialchars($settings['site_description']) ?></textarea>
                                </div>
                            </div>
                            <div>
                                <div class="form-control">
                                    <label><?=T::website_status?></label>
                                    <select name="site_offline" class="select" id="siteOffline">
                                        <option value="0" <?= getSetting($settingsData, 'site_offline', '0') == '0' ? 'selected' : '' ?>><?=T::online?></option>
                                        <option value="1" <?= getSetting($settingsData, 'site_offline', '0') == '1' ? 'selected' : '' ?>><?=T::offline?></option>
                                    </select>
                                </div>
                            </div>
                            <div id="offlineMessageContainer" style="display: <?= getSetting($settingsData, 'site_offline', '0') == '1' ? 'block' : 'none'; ?>;">
                                <div class="form-control">
                                    <label><?=T::offline_message?></label>
                                    <textarea name="offline_message" rows="2" class="input"
                                        <?= getSetting($settingsData, 'site_offline', '0') == '0' ? 'readonly' : '' ?>><?= htmlspecialchars(getSetting($settingsData, 'offline_message', 'Site is currently offline for maintenance.')) ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

            <!-- SEO Tab -->
            <div id="tab-seo" class="tab-content" style="display: none;">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-green-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-green-600 text-sm">search</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::seo_settings?></h3>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <div class="form-control">
                                    <label><?=T::meta?> <?=T::title?></label>
                                    <input type="text" name="home_title" class="input"
                                        value="<?= htmlspecialchars(getSetting($settingsData, 'home_title', $settings['site_name'])) ?>">
                                </div>
                            </div>
                            <div>
                                <div class="form-control">
                                    <label><?=T::meta_description?></label>
                                    <textarea name="meta_description" rows="2"
                                        class="input"><?= htmlspecialchars($settings['meta_description']) ?></textarea>
                                </div>
                            </div>
                            <div>
                                <div class="form-control">
                                    <label><?=T::meta_keywords?></label>
                                    <input type="text" name="site_keywords" class="input"
                                        value="<?= htmlspecialchars($settings['site_keywords']) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-blue-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-blue-600 text-sm">link</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::sitemap?></h3>
                        </div>
                        <div class="space-y-3">
                            <p class="text-xs text-gray-600"><?=T::sitemap_help_text?></p>
                            <div class="flex space-x-2">
                                <a href="<?= root ?>sitemap.xml" target="_blank" class="btn white flex-1">
                                    <span class="material-symbols-outlined mr-1 text-sm">link</span>
                                    <?=T::view_sitemap?>
                                </a>
                                <button type="button" id="generateSitemapBtn" class="btn flex-1">
                                    <span class="material-symbols-outlined mr-1 text-sm">refresh</span>
                                    <?=T::generate?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

            <!-- Branding Tab -->
            <div id="tab-branding" class="tab-content" style="display: none;">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Logo Upload -->
            <div class="section">
                <div class="flex items-center mb-3">
                    <div class="w-6 h-6 bg-indigo-50 rounded flex items-center justify-center mr-2">
                        <span class="material-symbols-outlined text-indigo-600 text-sm">image</span>
                    </div>
                    <h3 class="text-md font-medium text-gray-900"><?=T::logo?></h3>
                </div>
                <div class="text-center">
                    <!-- Live header preview: shows how the logo looks in the site navbar -->
                    <div class="mb-3 rounded-lg border border-gray-200 overflow-hidden shadow-sm text-left">
                        <!-- Navbar mockup -->
                        <div class="flex items-center justify-between px-4 py-3 bg-white">
                            <img id="logoPreview" src="../uploads/global/logo.png?<?= time() ?>"
                                class="max-h-8 max-w-[150px] object-contain">
                            <div class="hidden sm:flex items-center gap-3">
                                <span class="h-2 w-8 bg-gray-200 rounded-full"></span>
                                <span class="h-2 w-8 bg-gray-200 rounded-full"></span>
                                <span class="h-2 w-8 bg-gray-200 rounded-full"></span>
                                <span class="h-6 w-14 bg-blue-100 rounded-md"></span>
                            </div>
                        </div>
                        <!-- Page body hint -->
                        <div class="h-8 bg-gray-50 border-t border-gray-100"></div>
                    </div>
                    <label class="btn white cursor-pointer">
                        <span class="material-symbols-outlined mr-1 text-sm">upload</span>
                        <?=T::choose_logo?>
                        <input type="file" name="logo" id="logoInput" accept=".png,.jpg,.jpeg,.svg,.webp"
                        class="hidden"
                        onchange="validateLogoFile(this); validateDefaultValue(this, 'logo')">
                    </label>
                    <div class="default-warning <?= needswarnStyle('', 'logo') ? '' : 'hidden' ?>">
                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_logo?></p>
                    </div>
                    <p class="text-xs text-gray-500 mt-1"><?=T::logo_requirements?></p>
                    <div id="logoError" class="text-xs text-red-600 mt-1 hidden"></div>
                </div>
            </div>

            <!-- Favicon Upload -->
            <div class="section">
                <div class="flex items-center mb-3">
                    <div class="w-6 h-6 bg-pink-50 rounded flex items-center justify-center mr-2">
                        <span class="material-symbols-outlined text-pink-600 text-sm">web</span>
                    </div>
                    <h3 class="text-md font-medium text-gray-900"><?=T::favicon?></h3>
                </div>
                <div class="text-center">
                    <!-- Live browser preview: shows how the favicon looks in a browser tab + address bar -->
                    <div class="mb-3 rounded-lg border border-gray-200 overflow-hidden bg-gray-100 shadow-sm text-left">
                        <!-- Tab strip -->
                        <div class="flex items-end gap-1 px-2 pt-2 bg-gray-200/70">
                            <div class="flex items-center gap-2 bg-white rounded-t-lg px-3 py-1.5 max-w-[180px] shadow-sm">
                                <img id="faviconPreview" src="../uploads/global/favicon.png?<?= time() ?>"
                                    class="w-4 h-4 object-contain shrink-0">
                                <span class="text-xs text-gray-700 truncate"><?= htmlspecialchars($settings['site_name'] ?: 'Website') ?></span>
                                <span class="material-symbols-outlined text-gray-400 text-sm ml-1">close</span>
                            </div>
                            <span class="material-symbols-outlined text-gray-400 text-base mb-1">add</span>
                        </div>
                        <!-- Address bar -->
                        <div class="flex items-center gap-2 px-2 py-2 bg-white">
                            <span class="material-symbols-outlined text-gray-400 text-base">arrow_back</span>
                            <span class="material-symbols-outlined text-gray-400 text-base">arrow_forward</span>
                            <span class="material-symbols-outlined text-gray-400 text-base">refresh</span>
                            <div class="flex-1 flex items-center gap-1.5 bg-gray-100 rounded-full px-3 py-1 min-w-0">
                                <span class="material-symbols-outlined text-gray-400 text-sm">lock</span>
                                <span class="text-xs text-gray-500 truncate"><?= htmlspecialchars($settings['site_url'] ?: 'https://example.com') ?></span>
                            </div>
                        </div>
                    </div>
                    <label class="btn white cursor-pointer">
                        <span class="material-symbols-outlined mr-1 text-sm">upload</span>
                        <?=T::choose_favicon?>
                        <input type="file" name="favicon" id="faviconInput" accept=".png,.jpg,.jpeg,.svg"
                        class="hidden"
                        onchange="validateDefaultValue(this, 'favicon'); previewFavicon(this)">
                    </label>
                    <div class="default-warning <?= needswarnStyle('', 'favicon') ? '' : 'hidden' ?>">
                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_favicon?></p>
                    </div>
                    <p class="text-xs text-gray-500 mt-1"><?=T::favicon_requirements?></p>
                </div>
            </div>

            <!-- Cover Image Upload -->
            <div class="section lg:col-span-2">
                <div class="flex items-center mb-3">
                    <div class="w-6 h-6 bg-green-50 rounded flex items-center justify-center mr-2">
                        <span class="material-symbols-outlined text-green-600 text-sm">landscape</span>
                    </div>
                    <h3 class="text-md font-medium text-gray-900"><?=T::hero_image?></h3>
                </div>
                <div class="text-center">
                    <div class="mb-3 p-3 border-2 border-dashed <?= needswarnStyle('', 'cover') ? 'border-yellow-500 bg-yellow-50' : 'border-gray-200' ?> rounded-lg bg-gray-50">
                        <img id="coverPreview" src="../uploads/global/cover.png?<?= time() ?>"
                            class="h-48 w-full mx-auto object-cover rounded">
                    </div>
                    <label class="btn white cursor-pointer">
                        <span class="material-symbols-outlined mr-1 text-sm">upload</span>
                        <?=T::choose_image?>
                        <input type="file" name="coverimage" id="coverInput" accept=".png,.jpg,.jpeg"
                        class="hidden"
                        onchange="validateDefaultValue(this, 'cover')">

                    </label>
                    <div class="default-warning <?= needswarnStyle('', 'cover') ? '' : 'hidden' ?>">
                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_cover_image?></p>
                    </div>
                    <p class="text-xs text-gray-500 mt-1"><?=T::cover_image_requirements?></p>
                </div>
            </div>
                </div>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

            <?php require_once __DIR__ . '/themes-tab.php'; ?>

            <!-- Accounts Tab -->
            <div id="tab-accounts" class="tab-content" style="display: none;">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-blue-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-blue-600 text-sm">people</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::registration_settings?></h3>
                        </div>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <label class="text-xs font-medium text-gray-700"><?=T::guest_booking?></label>
                                    <p class="text-xs text-gray-500"><?=T::guest_booking_description?></p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="guest_booking" value="1" class="sr-only peer" <?= getSetting($settingsData, 'guest_booking', '1') ? 'checked' : '' ?>>
                                    <div
                                        class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600">
                                    </div>
                                </label>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <label class="text-xs font-medium text-gray-700"><?=T::user_registration?></label>
                                    <p class="text-xs text-gray-500"><?=T::user_registration_description?></p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="user_registration" value="1" class="sr-only peer"
                                        <?= getSetting($settingsData, 'user_registration', '1') ? 'checked' : '' ?>>
                                    <div
                                        class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600">
                                    </div>
                                </label>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <label class="text-xs font-medium text-gray-700"><?=T::agent_registration?></label>
                                    <p class="text-xs text-gray-500"><?=T::agent_registration_description?></p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="agent_registration" value="1" class="sr-only peer"
                                        <?= getSetting($settingsData, 'agent_registration', '1') ? 'checked' : '' ?>>
                                    <div
                                        class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600">
                                    </div>
                                </label>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <label class="text-xs font-medium text-gray-700"><?=T::supplier_registration?></label>
                                    <p class="text-xs text-gray-500"><?=T::supplier_registration_description?></p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="supplier_registration" value="1" class="sr-only peer"
                                        <?= getSetting($settingsData, 'supplier_registration', '1') ? 'checked' : '' ?>>
                                    <div
                                        class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600">
                                    </div>
                                </label>
                            </div>

                            <hr class="border-gray-200">

                            <!-- USER RESTRICTION: '1' locks the whole website behind login -->
                            <div x-data="{ restricted: <?= getSetting($settingsData, 'user_restriction', '0') ? 'true' : 'false' ?> }">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <label class="text-xs font-medium text-gray-700"><?=T::user_restriction?></label>
                                        <p class="text-xs text-gray-500"><?=T::user_restriction_description?></p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="user_restriction" value="1" class="sr-only peer" x-model="restricted"
                                            <?= getSetting($settingsData, 'user_restriction', '0') ? 'checked' : '' ?>>
                                        <div
                                            class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600">
                                        </div>
                                    </label>
                                </div>
                                <div x-show="restricted" x-transition
                                    style="<?= getSetting($settingsData, 'user_restriction', '0') ? '' : 'display: none;' ?>"
                                    class="mt-2 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-2.5">
                                    <span class="material-symbols-outlined text-amber-600 text-sm leading-5">warning</span>
                                    <p class="text-xs text-amber-800"><?=T::user_restriction_warning?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-purple-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-purple-600 text-sm">language</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::localization?></h3>
                        </div>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <label class="text-xs font-medium text-gray-700"><?=T::multi_language?></label>
                                    <p class="text-xs text-gray-500"><?=T::multi_language_description?></p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="multi_language" value="1" class="sr-only peer" <?= getSetting($settingsData, 'multi_language', '1') ? 'checked' : '' ?>>
                                    <div
                                        class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600">
                                    </div>
                                </label>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <label class="text-xs font-medium text-gray-700"><?=T::multi_currency?></label>
                                    <p class="text-xs text-gray-500"><?=T::multi_currency_description?></p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="multi_currency" value="1" class="sr-only peer" <?= getSetting($settingsData, 'multi_currency', '1') ? 'checked' : '' ?>>
                                    <div
                                        class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600">
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

            <!-- Contact Tab -->
            <div id="tab-contact" class="tab-content" style="display: none;">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-green-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-green-600 text-sm">contact_mail</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::contact_information?></h3>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <div class="form-control">
                                    <label><?=T::business_address?></label>
                                    <textarea name="address" rows="2"
                                        class="input"><?= htmlspecialchars($settings['contact_address']) ?></textarea>
                                </div>
                            </div>
                            <div>
                                <div class="form-control">
                                    <label><?=T::contact_email?></label>
                                    <input type="email" name="contact_email" class="input <?= needswarnStyle($settings['contact_email']) ? 'border-yellow-500' : '' ?>"
                                        value="<?= htmlspecialchars($settings['contact_email']) ?>"
                                        oninput="validateDefaultValue(this, 'contact_email')">
                                    <div class="default-warning <?= needswarnStyle($settings['contact_email']) ? '' : 'hidden' ?>">
                                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="form-control">
                                    <label><?=T::contact_phone?></label>
                                    <input type="tel" name="contact_phone" class="input <?= needswarnStyle($settings['contact_phone']) ? 'border-yellow-500' : '' ?>"
                                        value="<?= htmlspecialchars($settings['contact_phone']) ?>"
                                        oninput="validateDefaultValue(this, 'contact_phone')">
                                    <div class="default-warning <?= needswarnStyle($settings['contact_phone']) ? '' : 'hidden' ?>">
                                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-red-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-red-600 text-sm">location_on</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::map_integration?></h3>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <div class="form-control">
                                    <label><?=T::google_maps_embed_url?></label>
                                    <textarea name="map_address" rows="3"
                                        class="input text-xs"><?= htmlspecialchars(getSetting($settingsData, 'map_address', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3153.835...')) ?></textarea>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-2">
                                <p class="text-xs text-gray-600">
                                    <span class="material-symbols-outlined text-blue-600 mr-1"
                                        style="font-size: 12px;">info</span>
                                    <?=T::map_embed_help?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

            <!-- Notifications Tab -->
            <div id="tab-notifications" class="tab-content" style="display: none;">

                <!-- Email Notifications -->
                <div class="section my-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                        <div class="flex items-center">
                            <div class="w-6 h-6 bg-blue-50 rounded flex items-center justify-center mr-2 flex-shrink-0">
                                <span class="material-symbols-outlined text-blue-600 text-sm">email</span>
                            </div>
                            <h3 class="text-lg font-medium text-gray-900"><?=T::email_notifications?></h3>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="flex items-center mr-2">
                                <span class="text-xs font-medium text-gray-700 mr-2"><?=T::enable_for_booking?></span>
                                <label class="relative inline-flex items-center cursor-pointer opacity-60">
                                    <input type="checkbox" checked disabled class="sr-only peer">
                                    <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                                <input type="hidden" name="email_providers[booking_enabled]" value="1">
                            </div>
                            <button type="button" onclick="openTestModal('email')" class="btn white flex-shrink-0">
                                <span class="material-symbols-outlined mr-1 text-sm">send</span>
                                <?=T::test_email?>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <!-- Email Provider -->
                        <div class="form-control">
                            <label><?=T::email_provider?></label>
                            <select name="email_provider" id="emailProvider" class="select"
                                onchange="showEmailFields(this.value)">
                                <?php
                                $currentEmailProvider = getSetting($settingsData, 'email_provider', 'smtp');
                                foreach ($emailProviders as $providerKey => $provider): ?>
                                    <option value="<?= $providerKey ?>" <?= $currentEmailProvider === $providerKey ? 'selected' : '' ?>><?= $provider['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Sender Name -->
                        <div class="form-control">
                            <label><?=T::sender_name?></label>
                            <input type="text" name="email_sender_name" class="input <?= needswarnStyle(getSetting($settingsData, 'email_sender_name', 'PHPTRAVELS')) ? 'border-yellow-500' : '' ?>"
                                value="<?= htmlspecialchars(getSetting($settingsData, 'email_sender_name', 'PHPTRAVELS')) ?>"
                                placeholder="<?=T::your_company_name?>"
                                oninput="validateDefaultValue(this, 'email_sender_name')">
                            <div class="default-warning <?= needswarnStyle(getSetting($settingsData, 'email_sender_name', 'PHPTRAVELS')) ? '' : 'hidden' ?>">
                                <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                            </div>
                        </div>

                        <!-- Sender Email -->
                        <div class="form-control">
                            <label><?=T::sender_email?></label>
                            <input type="email" name="email_sender_email" class="input <?= needswarnStyle(getSetting($settingsData, 'email_sender_email', 'noreply@example.com')) ? 'border-yellow-500' : '' ?>"
                                value="<?= htmlspecialchars(getSetting($settingsData, 'email_sender_email', 'noreply@example.com')) ?>"
                                placeholder="noreply@yoursite.com"
                                oninput="validateDefaultValue(this, 'email_sender_email')">
                            <div class="default-warning <?= needswarnStyle(getSetting($settingsData, 'email_sender_email', 'noreply@example.com')) ? '' : 'hidden' ?>">
                                <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                            </div>
                        </div>

                        <!-- Booking Notification Email -->
                        <div class="form-control">
                            <label><?= T::booking_notification_email ?? 'Booking Notification Email' ?></label>
                            <input type="email" name="booking_notification_email" class="input"
                                value="<?= htmlspecialchars(getSetting($settingsData, 'booking_notification_email', '')) ?>"
                                placeholder="<?= T::booking_notification_email_placeholder ?? 'e.g. bookings@yourcompany.com (optional)' ?>">
                            <p class="text-xs text-gray-500 mt-1">
                                <?= T::booking_notification_email_hint ?? 'New booking/enquiry notifications go here instead of every admin account. Leave blank to notify all admin users (default).' ?>
                            </p>
                        </div>
                    </div>

                    <!-- Dynamic Email Provider Fields -->
                    <?php foreach ($emailProviders as $providerKey => $provider): ?>
                        <div id="email-<?= $providerKey ?>-fields"
                            class="email-fields mt-4 <?= $currentEmailProvider !== $providerKey ? 'hidden' : '' ?>">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php
                                $providerData = isset($emailConfig[$providerKey]) ? $emailConfig[$providerKey] : [];
                                $fields = $provider['fields'] ?? [];
                                ?>
                                <?php foreach ($fields as $field): ?>
                                    <div class="form-control">
                                        <?php
                                        $fieldValue = isset($providerData[$field]) ? $providerData[$field] : '';
                                        $isDefault = false;

                                        // SMTP specific fields check
                                        if ($providerKey === 'smtp') {
                                            if ($field === 'username' && needswarnStyle($fieldValue, 'smtp_username')) {
                                                $isDefault = true;
                                            } elseif ($field === 'host' && needswarnStyle($fieldValue, 'smtp_host')) {
                                                $isDefault = true;
                                            } elseif ($field === 'password' && needswarnStyle($fieldValue, 'smtp_password')) {
                                                $isDefault = true;
                                            }
                                        }
                                        ?>

                                        <?php if ($field === 'security'): ?>
                                            <label class="capitalize"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                                            <select name="email_providers[<?= $providerKey ?>][<?= $field ?>]" class="select">
                                                <option value="tls" <?= isset($providerData[$field]) && $providerData[$field] == 'tls' ? 'selected' : '' ?>><?=T::tls?></option>
                                                <option value="ssl" <?= isset($providerData[$field]) && $providerData[$field] == 'ssl' ? 'selected' : '' ?>><?=T::ssl?></option>
                                                <option value="none" <?= isset($providerData[$field]) && $providerData[$field] == 'none' ? 'selected' : '' ?>><?=T::none?></option>
                                            </select>
                                        <?php elseif ($field === 'region'): ?>
                                            <label class="capitalize"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                                            <select name="email_providers[<?= $providerKey ?>][<?= $field ?>]" class="select">
                                                <option value="us" <?= isset($providerData[$field]) && $providerData[$field] == 'us' ? 'selected' : '' ?>><?=T::us?></option>
                                                <option value="eu" <?= isset($providerData[$field]) && $providerData[$field] == 'eu' ? 'selected' : '' ?>><?=T::eu?></option>
                                                <option value="mailgun.us" <?= isset($providerData[$field]) && $providerData[$field] == 'mailgun.us' ? 'selected' : '' ?>><?=T::mailgun_us?></option>
                                                <option value="mailgun.eu" <?= isset($providerData[$field]) && $providerData[$field] == 'mailgun.eu' ? 'selected' : '' ?>><?=T::mailgun_eu?></option>
                                            </select>
                                        <?php elseif (strpos($field, 'password') !== false || strpos($field, 'key') !== false || strpos($field, 'token') !== false || strpos($field, 'secret') !== false): ?>
                                            <label class="capitalize"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                                            <input type="password" name="email_providers[<?= $providerKey ?>][<?= $field ?>]"
                                                class="input <?= $isDefault ? 'border-yellow-500' : '' ?>"
                                                value="<?= htmlspecialchars($fieldValue) ?>"
                                                placeholder="<?=T::enter?> <?= str_replace('_', ' ', $field) ?>"
                                                oninput="validateDefaultValue(this, 'smtp_<?= $field ?>')">
                                            <div class="default-warning <?= $isDefault ? '' : 'hidden' ?>">
                                                <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                            </div>
                                        <?php else: ?>
                                            <label class="capitalize"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                                            <input type="text" name="email_providers[<?= $providerKey ?>][<?= $field ?>]"
                                                class="input <?= $isDefault ? 'border-yellow-500' : '' ?>"
                                                value="<?= htmlspecialchars($fieldValue) ?>"
                                                placeholder="<?=T::enter?> <?= str_replace('_', ' ', $field) ?>"
                                                oninput="validateDefaultValue(this, 'smtp_<?= $field ?>')">
                                            <div class="default-warning <?= $isDefault ? '' : 'hidden' ?>">
                                                <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- WhatsApp Notifications -->
                <div class="section my-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                        <div class="flex items-center">
                            <div class="w-6 h-6 bg-green-50 rounded flex items-center justify-center mr-2 flex-shrink-0">
                                <span class="material-symbols-outlined text-green-600 text-sm">call</span>
                            </div>
                            <h3 class="text-lg font-medium text-gray-900"><?=T::whatsapp_notifications?></h3>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <?php
                                $waGlobalBooking = $whatsappConfig['booking_enabled'] ?? true;
                            ?>
                            <div class="flex items-center mr-2">
                                <span class="text-xs font-medium text-gray-700 mr-2"><?=T::enable_for_booking?></span>
                                <input type="hidden" name="whatsapp_providers[booking_enabled]" value="0">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="whatsapp_providers[booking_enabled]" value="1" class="sr-only peer" <?= $waGlobalBooking ? 'checked' : '' ?>>
                                    <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600"></div>
                                </label>
                            </div>
                            <button type="button" onclick="openTestModal('whatsapp')" class="btn white flex-shrink-0">
                                <span class="material-symbols-outlined mr-1 text-sm">send</span>
                                <?=T::test_whatsapp?>
                            </button>
                        </div>
                    </div>

                    <div class="form-control">
                        <label><?=T::whatsapp_provider?></label>
                        <select name="whatsapp_provider" id="whatsappProvider" class="select"
                            onchange="showWhatsappFields(this.value)">
                            <option value=""><?=T::none_of_these?></option>
                            <?php
                            $currentWhatsappProvider = getSetting($settingsData, 'whatsapp_provider', 'official');
                            foreach ($whatsappProviders as $key => $provider): ?>
                                <option value="<?= $key ?>" <?= $currentWhatsappProvider === $key ? 'selected' : '' ?>><?= $provider['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Dynamic WhatsApp Provider Fields -->
                    <?php foreach ($whatsappProviders as $providerKey => $provider): ?>
                        <div id="whatsapp-<?= $providerKey ?>-fields"
                            class="whatsapp-fields mt-4 <?= $currentWhatsappProvider !== $providerKey ? 'hidden' : '' ?>">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php
                                $providerData = isset($whatsappConfig[$providerKey]) ? $whatsappConfig[$providerKey] : [];
                                ?>
                                <?php foreach ($provider['fields'] as $field): ?>
                                    <div class="form-control">
                                        <?php if (strpos($field, 'token') !== false || strpos($field, 'secret') !== false || strpos($field, 'key') !== false): ?>
                                            <label class="capitalize"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                                            <input type="password" name="whatsapp_providers[<?= $providerKey ?>][<?= $field ?>]"
                                                class="input"
                                                value="<?= isset($providerData[$field]) ? htmlspecialchars($providerData[$field]) : '' ?>"
                                                placeholder="<?=T::enter?> <?= str_replace('_', ' ', $field) ?>">
                                        <?php else: ?>
                                            <label class="capitalize"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                                            <input type="text" name="whatsapp_providers[<?= $providerKey ?>][<?= $field ?>]" class="input"
                                                value="<?= isset($providerData[$field]) ? htmlspecialchars($providerData[$field]) : '' ?>"
                                                placeholder="<?=T::enter?> <?= str_replace('_', ' ', $field) ?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- SMS Notifications -->
                <div class="section my-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                        <div class="flex items-center">
                            <div class="w-6 h-6 bg-purple-50 rounded flex items-center justify-center mr-2 flex-shrink-0">
                                <span class="material-symbols-outlined text-purple-600 text-sm">sms</span>
                            </div>
                            <h3 class="text-lg font-medium text-gray-900"><?=T::sms_notifications?></h3>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <?php
                                $smsGlobalBooking = $smsConfig['booking_enabled'] ?? true;
                            ?>
                            <div class="flex items-center mr-2">
                                <span class="text-xs font-medium text-gray-700 mr-2"><?=T::enable_for_booking?></span>
                                <input type="hidden" name="sms_providers[booking_enabled]" value="0">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="sms_providers[booking_enabled]" value="1" class="sr-only peer" <?= $smsGlobalBooking ? 'checked' : '' ?>>
                                    <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-600"></div>
                                </label>
                            </div>
                            <button type="button" onclick="openTestModal('sms')" class="btn white flex-shrink-0">
                                <span class="material-symbols-outlined mr-1 text-sm">send</span>
                                <?=T::test_sms?>
                            </button>
                        </div>
                    </div>

                    <div class="form-control">
                        <label><?=T::sms_provider?></label>
                        <select name="sms_provider" id="smsProvider" class="select"
                            onchange="showSmsFields(this.value)">
                            <option value=""><?=T::none_of_these?></option>
                            <?php
                            $currentSmsProvider = getSetting($settingsData, 'sms_provider', 'twilio');
                            foreach ($smsProviders as $key => $provider): ?>
                                <option value="<?= $key ?>" <?= $currentSmsProvider === $key ? 'selected' : '' ?>><?= $provider['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Dynamic SMS Provider Fields -->
                    <?php foreach ($smsProviders as $providerKey => $provider): ?>
                        <div id="sms-<?= $providerKey ?>-fields"
                            class="sms-fields mt-4 <?= $currentSmsProvider !== $providerKey ? 'hidden' : '' ?>">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php
                                $providerData = isset($smsConfig[$providerKey]) ? $smsConfig[$providerKey] : [];
                                ?>
                                <?php foreach ($provider['fields'] as $field): ?>
                                    <div class="form-control">
                                        <?php if (strpos($field, 'key') !== false || strpos($field, 'secret') !== false || strpos($field, 'token') !== false): ?>
                                            <label class="capitalize"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                                            <input type="password" name="sms_providers[<?= $providerKey ?>][<?= $field ?>]" class="input"
                                                value="<?= isset($providerData[$field]) ? htmlspecialchars($providerData[$field]) : '' ?>"
                                                placeholder="<?=T::enter?> <?= str_replace('_', ' ', $field) ?>">
                                        <?php else: ?>
                                            <label class="capitalize"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                                            <input type="text" name="sms_providers[<?= $providerKey ?>][<?= $field ?>]" class="input"
                                                value="<?= isset($providerData[$field]) ? htmlspecialchars($providerData[$field]) : '' ?>"
                                                placeholder="<?=T::enter?> <?= str_replace('_', ' ', $field) ?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Push Notifications -->
                <?php
                /*

                <div class="section my-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="w-6 h-6 bg-red-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-red-600 text-sm">notifications_active</span>
                            </div>
                            <h3 class="text-lg font-medium text-gray-900"><?=T::push_notifications?></h3>
                        </div>
                        <div class="flex items-center space-x-3">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="push_notifications_enabled" value="1" class="sr-only peer"
                                    <?= getSetting($settingsData, 'push_notifications_enabled', '0') ? 'checked' : '' ?>>
                                <div
                                    class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-red-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-red-600">
                                    </div>
                            </label>
                            <button type="button" onclick="openTestModal('push')" class="btn white">
                                <span class="material-symbols-outlined mr-1 text-sm">send</span>
                                <?=T::test_push?>
                            </button>
                        </div>
                    </div>

                    <div class="form-control">
                        <label><?=T::push_provider?></label>
                        <select name="push_provider" id="pushProvider" class="select"
                            onchange="showPushFields(this.value)">
                            <?php
                            $currentPushProvider = getSetting($settingsData, 'push_provider', 'firebase');
                            foreach ($pushProviders as $key => $provider): ?>
                                <option value="<?= $key ?>" <?= $currentPushProvider === $key ? 'selected' : '' ?>><?= $provider['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php foreach ($pushProviders as $providerKey => $provider): ?>
                        <div id="push-<?= $providerKey ?>-fields"
                            class="push-fields <?= $currentPushProvider !== $providerKey ? 'hidden' : '' ?>">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
                                <?php
                                $providerData = isset($pushConfig[$providerKey]) ? $pushConfig[$providerKey] : [];
                                ?>
                                <?php foreach ($provider['fields'] as $field): ?>
                                    <div class="form-control">
                                        <?php if (strpos($field, 'key') !== false || strpos($field, 'secret') !== false || strpos($field, 'token') !== false): ?>
                                            <label class="capitalize"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                                            <input type="password" name="push_providers[<?= $providerKey ?>][<?= $field ?>]" class="input"
                                                value="<?= isset($providerData[$field]) ? htmlspecialchars($providerData[$field]) : '' ?>"
                                                placeholder="<?=T::enter?> <?= str_replace('_', ' ', $field) ?>">
                                        <?php else: ?>
                                            <label class="capitalize"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                                            <input type="text" name="push_providers[<?= $providerKey ?>][<?= $field ?>]" class="input"
                                                value="<?= isset($providerData[$field]) ? htmlspecialchars($providerData[$field]) : '' ?>"
                                                placeholder="<?=T::enter?> <?= str_replace('_', ' ', $field) ?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                */
                ?>

                <?php /*
                <!-- Notification Preferences Matrix -->
                <div class="section my-6">
                    <div class="border border-gray-200 rounded-lg overflow-hidden">
                        <table class="w-full border-collapse">
                            <thead class="sticky top-0 z-10">
                                <tr class="bg-gray-50 border-b border-gray-200">
                                    <th class="px-4 py-2 text-left">
                                        <div class="flex items-center">
                                            <div class="w-6 h-6 bg-indigo-50 rounded flex items-center justify-center mr-2">
                                                <span class="material-symbols-outlined text-indigo-600 text-sm">tune</span>
                                            </div>
                                            <span class="text-lg font-medium text-gray-900"><?=T::notification_preferences?></span>
                                        </div>
                                    </th>
                                    <?php
                                        $NotificationTypes = $db->select('notification_templates', ['type'], [
                                            'type[!]' => 'push',
                                            'GROUP' => 'type'
                                        ]);
                                        foreach ($NotificationTypes as $type):
                                    ?>
                                    <th class="px-4 py-2 text-center text-xs font-medium text-gray-700"><?=$type['type']?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $allTemplates = $db->select('notification_templates', '*', [
                                    'ORDER' => ['group' => 'ASC', 'name' => 'ASC']
                                ]);

                                $groupedTemplates = [];
                                foreach ($allTemplates as $template) {
                                    $groupedTemplates[$template['group']][] = $template;
                                }

                                $notificationPrefs = getSetting($settingsData, 'notification_preferences', '{}');
                                $notificationPrefs = json_decode($notificationPrefs, true);
                                if (!is_array($notificationPrefs)) {
                                    $notificationPrefs = [];
                                }

                                $requiredChannels = [
                                    'signup_welcome' => ['email'],
                                    'email_verified' => ['email'],
                                    'resend_verification' => ['email'],
                                    'forgot_password' => ['email'],
                                    'password_reset' => ['email']
                                ];

                                $groupIcons = [
                                    'user_auth' => 'lock',
                                    'flights' => 'flight',
                                    'stays' => 'hotel',
                                    'tours' => 'tour',
                                    'cars' => 'directions_car',
                                    'visa' => 'assignment',
                                    'newsletter' => 'campaign',
                                    'settings' => 'settings'
                                ];

                                $groupTranslations = [
                                    'user_auth' => T::user_auth,
                                    'flights' => T::flights,
                                    'stays' => T::stays,
                                    'tours' => T::tours,
                                    'cars' => T::cars,
                                    'visa' => T::visa,
                                    'newsletter' => T::newsletter,
                                    'settings' => T::settings
                                ];

                                foreach ($groupedTemplates as $group => $templates): ?>
                                    <tr class="bg-gray-50 border-t border-gray-100">
                                        <td colspan="4" class="px-4 py-2">
                                            <div class="flex items-center gap-2">
                                                <span class="material-symbols-outlined text-gray-600" style="font-size: 16px;"><?= $groupIcons[$group] ?? 'settings' ?></span>
                                                <span class="font-medium text-gray-800 text-sm"><?= $groupTranslations[$group] ?? ucfirst(str_replace('_', ' ', $group)) ?></span>
                                            </div>
                                        </td>
                                    </tr>

                                    <?php
                                    $templateNames = [];
                                    foreach ($templates as $template) {
                                        $templateName = $template['name'];
                                        if (!isset($templateNames[$templateName])) {
                                            $templateNames[$templateName] = [];
                                        }
                                        $templateNames[$templateName][$template['type']] = $template;
                                    }

                                    foreach ($templateNames as $templateName => $channels):
                                        $displayName = $templateName;
                                        $emailRequired = in_array($templateName, array_keys($requiredChannels)) && in_array('email', $requiredChannels[$templateName]);

                                        $currentSettings = [];
                                        if (isset($notificationPrefs[$group][$templateName])) {
                                            $currentSettings = $notificationPrefs[$group][$templateName];
                                        }
                                        $settingsJson = htmlspecialchars(json_encode($currentSettings), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <tr class="hover:bg-gray-50 bg-white border-t border-gray-100">
                                            <td class="px-4 py-2 pl-10 text-sm text-gray-700">
                                                <?= $displayName ?>
                                                <?php if ($emailRequired): ?>
                                                    <span class="text-xs text-blue-600 ml-1">(<?=T::required?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2 text-center">
                                                <?php if (isset($channels['email'])): ?>
                                                    <button type="button"
                                                            class="manage-notification-btn px-3 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50 rounded-md transition-colors"
                                                            data-template="<?= $templateName ?>"
                                                            data-group="<?= $group ?>"
                                                            data-channel="email"
                                                            data-display-name="<?= $displayName ?>"
                                                            data-required="<?= $emailRequired ? '1' : '0' ?>"
                                                            data-settings='<?= $settingsJson ?>'>
                                                        <?=T::manage?>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-xs text-gray-400">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2 text-center">
                                                <?php if (isset($channels['whatsapp'])): ?>
                                                    <button type="button"
                                                            class="manage-notification-btn px-3 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50 rounded-md transition-colors"
                                                            data-template="<?= $templateName ?>"
                                                            data-group="<?= $group ?>"
                                                            data-channel="whatsapp"
                                                            data-display-name="<?= $displayName ?>"
                                                            data-settings='<?= $settingsJson ?>'>
                                                        <?=T::manage?>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-xs text-gray-400">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2 text-center">
                                                <?php if (isset($channels['sms'])): ?>
                                                    <button type="button"
                                                            class="manage-notification-btn px-3 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50 rounded-md transition-colors"
                                                            data-template="<?= $templateName ?>"
                                                            data-group="<?= $group ?>"
                                                            data-channel="sms"
                                                            data-display-name="<?= $displayName ?>"
                                                            data-settings='<?= $settingsJson ?>'>
                                                        <?=T::manage?>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-xs text-gray-400">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                        <p class="text-xs text-blue-700 flex items-center">
                            <span class="material-symbols-outlined mr-2 text-sm">info</span>
                            <strong><?=T::note?>: </strong> <?=T::email_notifications_marked_as_required_cannot_be_disabled_as_they_are_essential_for_system_functionality?>
                        </p>
                    </div>
                </div>

                <!-- Notification Settings Modal -->
                <div x-data="notificationModal()"
                    x-show="isOpen"
                    x-cloak
                    @keydown.escape.window="closeModal()"
                    class="fixed inset-0 bg-black bg-opacity-50 z-50">
                    <div class="flex items-center justify-center min-h-full">
                        <div @click.away="closeModal()" class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900" x-text="channelTitle"></h3>
                                        <p class="text-sm text-gray-500" x-text="displayName"></p>
                                    </div>
                                    <button type="button" @click="closeModal()" class="text-gray-400 hover:text-gray-600">
                                        <span class="material-symbols-outlined">close</span>
                                    </button>
                                </div>

                                <div class="space-y-4">
                                    <div class="flex items-center justify-between py-2 border-b border-gray-200">
                                        <div>
                                            <h4 class="text-sm font-medium text-gray-900"><?=T::enable_for_users?></h4>
                                        </div>
                                        <label class="switch-container switch-sm switch-blue">
                                            <input type="checkbox"
                                                x-model="enabledForUsers"
                                                :disabled="isRequired"
                                                class="switch-input">
                                            <div class="switch-track">
                                                <div class="switch-thumb"></div>
                                            </div>
                                        </label>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2"><?=T::also_notify_these_roles?></label>
                                        <div class="space-y-2">
                                            <template x-for="role in availableRoles" :key="role.value">
                                                <label class="flex items-center justify-between py-2 px-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
                                                    <span class="text-sm text-gray-700" x-text="role.value"></span>
                                                    <div class="switch-container switch-sm switch-blue">
                                                        <input type="checkbox"
                                                            :value="role.value"
                                                            x-model="selectedRoles"
                                                            class="switch-input">
                                                        <div class="switch-track">
                                                            <div class="switch-thumb"></div>
                                                        </div>
                                                    </div>
                                                </label>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex justify-end space-x-2 mt-6">
                                    <button type="button"
                                            @click="closeModal()"
                                            class="btn white">
                                        <?=T::cancel?>
                                    </button>
                                    <button type="button"
                                            @click="applySettings()"
                                            class="btn primary">
                                        <?=T::apply_settings?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="notificationHiddenInputs"></div>

                <script>
                    // script for notification
                    function notificationModal() {
                        return {
                            isOpen: false,
                            currentTemplate: '',
                            currentGroup: '',
                            currentChannel: '',
                            isRequired: false,
                            channelTitle: '<?=T::email_settings?>',
                            displayName: '',
                            enabledForUsers: false,
                            selectedRoles: [],
                            availableRoles: [
                                <?php
                                if(!empty($userRoles = $db->select('users_roles', ['type_name'], ['type_name[!]' => ['customer']]))) {
                                    foreach ($userRoles as $role):
                                        $roleValue = is_array($role) ? $role['type_name'] : $role;
                                        echo "{ value: '" . htmlspecialchars($roleValue) . "' },";
                                    endforeach;
                                }
                                ?>
                            ],

                            channelTitles: {
                                'email': '<?=T::email_settings?>',
                                'whatsapp': '<?=T::whatsapp_settings?>',
                                'sms': '<?=T::sms_settings?>'
                            },

                            init() {
                                document.addEventListener('open-notification-modal', (event) => {
                                    this.openModal(event.detail);
                                });
                            },

                            openModal(data) {
                                this.currentTemplate = data.template;
                                this.currentGroup = data.group;
                                this.currentChannel = data.channel;
                                this.isRequired = data.required === '1';
                                this.displayName = data.displayName;

                                this.channelTitle = this.channelTitles[data.channel] || '<?=T::notification_settings?>';

                                const settings = JSON.parse(data.settings || '{}');
                                const channelSettings = settings[data.channel] || {};

                                this.enabledForUsers = channelSettings.enabled_for_users === true;

                                if (this.isRequired) {
                                    this.enabledForUsers = true;
                                }

                                this.selectedRoles = channelSettings.notify_roles || [];

                                this.isOpen = true;
                            },

                            closeModal() {
                                this.isOpen = false;
                            },

                            applySettings() {
                                const hiddenInputsContainer = document.getElementById('notificationHiddenInputs');

                                // Remove existing inputs for this specific group/template/channel combination
                                const existingInputs = hiddenInputsContainer.querySelectorAll(
                                    `[data-group="${this.currentGroup}"][data-template="${this.currentTemplate}"][data-channel="${this.currentChannel}"]`
                                );
                                existingInputs.forEach(input => input.remove());

                                // Create settings object for local state (button data)
                                const settings = {
                                    enabled_for_users: this.enabledForUsers ? '1' : '0',
                                    notify_roles: this.selectedRoles
                                };

                                // Helper to create hidden input
                                const createInput = (name, value) => {
                                    const input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = name;
                                    input.value = value;
                                    input.dataset.group = this.currentGroup;
                                    input.dataset.template = this.currentTemplate;
                                    input.dataset.channel = this.currentChannel;
                                    hiddenInputsContainer.appendChild(input);
                                };

                                // 1. Enabled for users input
                                createInput(
                                    `notification_prefs[${this.currentGroup}][${this.currentTemplate}][${this.currentChannel}][enabled_for_users]`,
                                    this.enabledForUsers ? '1' : '0'
                                );

                                // 2. Notify roles inputs (array)
                                if (Array.isArray(this.selectedRoles)) {
                                    this.selectedRoles.forEach((role, index) => {
                                        createInput(
                                            `notification_prefs[${this.currentGroup}][${this.currentTemplate}][${this.currentChannel}][notify_roles][${index}]`,
                                            role
                                        );
                                    });
                                }

                                // Update the button's data-settings so the modal retains state if re-opened
                                // IMPORTANT: Include data-group in selector to target the correct button!
                                const button = document.querySelector(
                                    `.manage-notification-btn[data-group="${this.currentGroup}"][data-template="${this.currentTemplate}"][data-channel="${this.currentChannel}"]`
                                );

                                if (button) {
                                    button.classList.add('bg-indigo-100');
                                    let currentSettings = {};
                                    try {
                                        currentSettings = JSON.parse(button.dataset.settings || '{}');
                                    } catch (e) {
                                        currentSettings = {};
                                    }

                                    // Update only the specific channel configuration
                                    currentSettings[this.currentChannel] = settings;
                                    button.dataset.settings = JSON.stringify(currentSettings);
                                }

                                this.closeModal();
                            }
                        }
                    }
                </script>
                     document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('.manage-notification-btn').forEach(button => {
                            button.addEventListener('click', function() {
                                const event = new CustomEvent('open-notification-modal', {
                                    detail: {
                                        template: this.dataset.template,
                                        group: this.dataset.group,
                                        channel: this.dataset.channel,
                                        required: this.dataset.required,
                                        displayName: this.dataset.displayName,
                                        settings: this.dataset.settings
                                    }
                                });
                                document.dispatchEvent(event);
                            });
                        });
                    });

                    // script for notification
                </script>
                */ ?>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

            <!-- Social Tab -->
            <div id="tab-social" class="tab-content" style="display: none;">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-blue-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-blue-600 text-sm">share</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::social_media_links?></h3>
                        </div>
                        <div class="space-y-3">
                            <?php
                            // Get existing social media data from JSON
                            $socialMediaJson = getSetting($settingsData, 'social_media', '{}');
                            $socialMedia = json_decode($socialMediaJson, true);
                            if (!is_array($socialMedia)) {
                                $socialMedia = [];
                            }
                            ?>
                            <div>
                                <div class="form-control">
                                    <label class="flex items-center">
                                        <span class="material-symbols-outlined text-blue-600 mr-1 text-sm">book</span>
                                        <?=T::facebook?>
                                    </label>
                                    <input type="url" name="social_media[facebook]" class="input <?= needswarnStyle($socialMedia['facebook'] ?? '') ? 'border-yellow-500' : '' ?>"
                                        value="<?= htmlspecialchars($socialMedia['facebook'] ?? '') ?>"
                                        placeholder="https://facebook.com/yourpage"
                                        oninput="validateDefaultValue(this, 'social_facebook')">
                                    <div class="default-warning <?= needswarnStyle($socialMedia['facebook'] ?? '') ? '' : 'hidden' ?>">
                                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="form-control">
                                    <label class="flex items-center">
                                        <span class="material-symbols-outlined text-sky-500 mr-1 text-sm"></span>
                                        <?=T::twitter_x?>
                                    </label>
                                    <input type="url" name="social_media[twitter]" class="input <?= needswarnStyle($socialMedia['twitter'] ?? '') ? 'border-yellow-500' : '' ?>"
                                        value="<?= htmlspecialchars($socialMedia['twitter'] ?? '') ?>"
                                        placeholder="https://twitter.com/yourhandle"
                                        oninput="validateDefaultValue(this, 'social_twitter')">
                                    <div class="default-warning <?= needswarnStyle($socialMedia['twitter'] ?? '') ? '' : 'hidden' ?>">
                                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="form-control">
                                    <label class="flex items-center">
                                        <span class="material-symbols-outlined text-blue-700 mr-1 text-sm">work</span>
                                        <?=T::linkedin?>
                                    </label>
                                    <input type="url" name="social_media[linkedin]" class="input <?= needswarnStyle($socialMedia['linkedin'] ?? '') ? 'border-yellow-500' : '' ?>"
                                        value="<?= htmlspecialchars($socialMedia['linkedin'] ?? '') ?>"
                                        placeholder="https://linkedin.com/company/yourcompany"
                                        oninput="validateDefaultValue(this, 'social_linkedin')">
                                    <div class="default-warning <?= needswarnStyle($socialMedia['linkedin'] ?? '') ? '' : 'hidden' ?>">
                                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-pink-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-pink-600 text-sm">photo_camera</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::visual_platforms?></h3>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <div class="form-control">
                                    <label class="flex items-center">
                                        <span class="material-symbols-outlined text-pink-500 mr-1 text-sm">photo_camera</span>
                                        <?=T::instagram?>
                                    </label>
                                    <input type="url" name="social_media[instagram]" class="input <?= needswarnStyle($socialMedia['instagram'] ?? '') ? 'border-yellow-500' : '' ?>"
                                        value="<?= htmlspecialchars($socialMedia['instagram'] ?? '') ?>"
                                        placeholder="https://instagram.com/yourhandle"
                                        oninput="validateDefaultValue(this, 'social_instagram')">
                                    <div class="default-warning <?= needswarnStyle($socialMedia['instagram'] ?? '') ? '' : 'hidden' ?>">
                                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="form-control">
                                    <label class="flex items-center">
                                        <span class="material-symbols-outlined text-red-600 mr-1 text-sm">play_circle</span>
                                        <?=T::youtube?>
                                    </label>
                                    <input type="url" name="social_media[youtube]" class="input <?= needswarnStyle($socialMedia['youtube'] ?? '') ? 'border-yellow-500' : '' ?>"
                                        value="<?= htmlspecialchars($socialMedia['youtube'] ?? '') ?>"
                                        placeholder="https://youtube.com/@yourchannel"
                                        oninput="validateDefaultValue(this, 'social_youtube')">
                                    <div class="default-warning <?= needswarnStyle($socialMedia['youtube'] ?? '') ? '' : 'hidden' ?>">
                                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="form-control">
                                    <label class="flex items-center">
                                        <span class="material-symbols-outlined text-green-600 mr-1 text-sm">call</span>
                                        <?=T::whatsapp_business?>
                                    </label>
                                    <input type="url" name="social_media[whatsapp]" class="input <?= needswarnStyle($socialMedia['whatsapp'] ?? '') ? 'border-yellow-500' : '' ?>"
                                        value="<?= htmlspecialchars($socialMedia['whatsapp'] ?? '') ?>"
                                        placeholder="https://wa.me/1234567890"
                                        oninput="validateDefaultValue(this, 'social_whatsapp')">
                                    <div class="default-warning <?= needswarnStyle($socialMedia['whatsapp'] ?? '') ? '' : 'hidden' ?>">
                                        <p class="text-xs text-yellow-600 mt-1"><?=T::please_change_this_default_value?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

            <!-- Apps Tab -->
            <div id="tab-apps" class="tab-content" style="display: none;">
                <div class="section">
                    <div class="flex items-center mb-3">
                        <div class="w-6 h-6 bg-blue-50 rounded flex items-center justify-center mr-2">
                            <span class="material-symbols-outlined text-blue-600 text-sm">smartphone</span>
                        </div>
                        <h3 class="text-md font-medium text-gray-900"><?=T::mobile_apps?></h3>
                    </div>
                    <div class="grid grid-cols-1 gap-3">
                        <div>
                            <div class="form-control">
                                <label><?=T::google_play_store?></label>
                                <input type="url" name="android_store" id="androidStore" class="input"
                                    value="<?= htmlspecialchars(getSetting($settingsData, 'android_store', 'https://play.google.com/store/apps/details?id=com.phptravels')) ?>">
                            </div>
                        </div>
                        <div>
                            <div class="form-control">
                                <label><?=T::apple_app_store?></label>
                                <input type="url" name="ios_store" id="iosStore" class="input" value="<?= htmlspecialchars(getSetting($settingsData, 'ios_store', 'https://apps.apple.com/app/phptravels/id123456789')) ?>">
                            </div>
                        </div>
                        <div>
                            <div class="form-control">
                                <label><?=T::show_app_links?></label>
                                <select name="show_apps" id="showApps" class="select" onchange="toggleAppLinks()">
                                    <option value="1" <?= getSetting($settingsData, 'show_apps', '1') == '1' ? 'selected' : '' ?>><?=T::show_on_website?></option>
                                    <option value="0" <?= getSetting($settingsData, 'show_apps', '1') == '0' ? 'selected' : '' ?>><?=T::hide_from_website?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

            <!-- Tracking Tab -->
            <div id="tab-tracking" class="tab-content" style="display: none;">
                <div class="grid grid-cols-1">

                    <!-- Google Analytics -->
                    <div class="mb-6">
                    <div class="section mb-6">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-orange-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-orange-600 text-sm">analytics</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::google_analytics?></h3>
                        </div>
                        <div>
                            <div class="form-control">
                                <label><?=T::google_analytics_tracking_code?></label>
                                <textarea name="javascript" rows="4" class="input font-mono text-xs h-32" placeholder="<!-- Google tag (gtag.js) -->
                                    <script async src=&quot;https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX&quot;></script>
                                    <script>
                                    window.dataLayer = window.dataLayer || [];
                                    function gtag(){dataLayer.push(arguments);}
                                    gtag('js', new Date());
                                    gtag('config', 'G-XXXXXXXXXX');
                                    </script>"><?= htmlspecialchars($settings['google_analytics']) ?>
                                </textarea>
                                <p class="text-xs text-gray-500 mt-1"><?=T::google_analytics_help?></p>
                            </div>
                        </div>
                    </div>
                    </div>

                    <!-- Facebook Pixel -->
                    <div class="mb-6">
                    <div class="section mb-6">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-blue-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-blue-600 text-sm">ads_click</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::facebook_pixel?></h3>
                        </div>
                        <div>
                            <div class="form-control">
                                <label><?=T::facebook_pixel_code?></label>
                                <textarea name="facebook_pixel" rows="4" class="input font-mono text-xs h-32" placeholder="<!-- Facebook Pixel Code -->
                                    <script>
                                    !function(f,b,e,v,n,t,s)
                                    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                                    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
                                    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
                                    n.queue=[];t=b.createElement(e);t.async=!0;
                                    t.src=v;s=b.getElementsByTagName(e)[0];
                                    s.parentNode.insertBefore(t,s)}(window, document,'script',
                                    'https://connect.facebook.net/en_US/fbevents.js');
                                    fbq('init', 'YOUR_PIXEL_ID');
                                    fbq('track', 'PageView');
                                    </script>
                                    <noscript><img height=&quot;1&quot; width=&quot;1&quot; style=&quot;display:none&quot;
                                    src=&quot;https://www.facebook.com/tr?id=YOUR_PIXEL_ID&ev=PageView&noscript=1&quot;
                                    /></noscript>
                                    <!-- End Facebook Pixel Code -->"><?= htmlspecialchars(getSetting($settingsData, 'facebook_pixel', '')) ?>
                                </textarea>
                                <p class="text-xs text-gray-500 mt-1"><?=T::facebook_pixel_help?></p>
                            </div>
                        </div>
                    </div>
                    </div>

                    <!-- Google Tag Manager -->
                    <div class="mb-6">
                    <div class="section mb-6">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-green-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-green-600 text-sm">label</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::google_tag_manager?></h3>
                        </div>
                        <div>
                            <div class="form-control">
                                <label><?=T::gtm_container_code_head?></label>
                                <textarea name="gtm_head" rows="3" class="input font-mono text-xs h-32" placeholder="<!-- Google Tag Manager -->
                                    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
                                    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
                                    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
                                    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
                                    })(window,document,'script','dataLayer','GTM-XXXXXXX');</script>
                                    <!-- End Google Tag Manager -->"><?= htmlspecialchars(getSetting($settingsData, 'gtm_head', '')) ?>
                                </textarea>
                                <p class="text-xs text-gray-500 mt-1"><?=T::gtm_head_help?></p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="form-control">
                                <label><?=T::gtm_container_code_body?></label>
                                <textarea name="gtm_body" rows="2" class="input font-mono text-xs h-32" placeholder="<!-- Google Tag Manager (noscript) -->
                                    <noscript><iframe src=&quot;https://www.googletagmanager.com/ns.html?id=GTM-XXXXXXX&quot;
                                    height=&quot;0&quot; width=&quot;0&quot; style=&quot;display:none;visibility:hidden&quot;></iframe></noscript>
                                    <!-- End Google Tag Manager (noscript) -->"><?= htmlspecialchars(getSetting($settingsData, 'gtm_body', '')) ?>
                                </textarea>
                                <p class="text-xs text-gray-500 mt-1"><?=T::gtm_body_help?></p>
                            </div>
                        </div>
                    </div>
                    </div>

                    <!-- Custom Tracking Scripts -->
                    <div class="mb-0">
                    <div class="section mb-6">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-purple-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-purple-600 text-sm">code</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::custom_tracking_scripts?></h3>
                        </div>
                        <div>
                            <div class="form-control">
                                <label><?=T::additional_tracking_codes?></label>
                                <textarea name="custom_tracking" rows="4" class="input font-mono text-xs h-32"
                                    placeholder="<!-- Add any other tracking codes here (e.g., Hotjar, Microsoft Clarity, LinkedIn Insight, etc.) -->"><?= htmlspecialchars(getSetting($settingsData, 'custom_tracking', '')) ?></textarea>
                                <p class="text-xs text-gray-500 mt-1"><?=T::custom_tracking_help?></p>
                            </div>
                        </div>
                    </div>
                    </div>

                </div>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

            <!-- Booking Tab -->
            <div id="tab-booking" class="tab-content" style="display: none;">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <!-- Booking Expiry Time Card -->
                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-blue-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-blue-600 text-sm">schedule</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900"><?=T::booking?> <?=T::expiry?> <?=T::time?></h3>
                        </div>
                        <div class="space-y-3">
                            <div class="form-control">
                                <label for="bookingExpiryTime"><?=T::booking?> <?=T::expiry?> <?=T::time?> (<?=T::minutes?>)</label>
                                <input type="number"
                                       name="booking_expiry_time"
                                       id="bookingExpiryTime"
                                       class="input"
                                       value="<?= getSetting($settingsData, 'booking_expiry_time', '15') ?>"
                                       min="1"
                                       max="120"
                                       placeholder="15">
                                <p class="text-xs text-gray-500 mt-1"><?=T::how_long_booking_session_should_last?> (<?=T::default?>: 30 <?=T::minutes?>)</p>
                            </div>

                            <!-- Information Section -->
                            <div class="mt-4 p-4 bg-blue-50 rounded-lg border border-blue-200">
                                <h4 class="font-medium text-blue-900 mb-2"><?=T::information?></h4>
                                <p class="text-sm text-blue-800 mb-2"><?=T::this_setting_controls_how_long_users_have_to_complete_booking?></p>
                                <ul class="text-sm text-blue-700 space-y-1 list-disc list-inside">
                                    <li><?=T::prevents_abandoned_bookings?></li>
                                    <li><?=T::releases_inventory_after_timeout?></li>
                                    <li><?=T::recommended_range?>: 10-30 <?=T::minutes?></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Booking Payment Issue Card -->
                    <div class="section">
                        <div class="flex items-center mb-3">
                            <div class="w-6 h-6 bg-purple-50 rounded flex items-center justify-center mr-2">
                                <span class="material-symbols-outlined text-purple-600 text-sm">payment</span>
                            </div>
                            <h3 class="text-md font-medium text-gray-900">Booking Payment Issue</h3>
                        </div>
                        <div class="space-y-3">
                            <div class="form-control">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <label class="font-medium text-gray-900"><?=T::auto_issue_after_payment?></label>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?php if (getSetting($settingsData, 'booking_payment_issue', '0') == '1'): ?>
                                                <?=T::when_enabled_bookings_auto_issued?>
                                            <?php else: ?>
                                                <?=T::when_disabled_bookings_manual_issue?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center ml-4">
                                        <label class="switch-container switch-md switch-blue">
                                            <input type="checkbox"
                                                   name="booking_payment_issue"
                                                   class="switch-input"
                                                   value="1"
                                                   <?= getSetting($settingsData, 'booking_payment_issue', '0') == '1' ? 'checked' : '' ?>
                                                   onchange="updatePaymentIssueText(this)">
                                            <div class="switch-track">
                                                <div class="switch-thumb"></div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Information Section -->
                            <div class="mt-4 p-4 bg-purple-50 rounded-lg border border-purple-200">
                                <h4 class="font-medium text-purple-900 mb-2"><?=T::information?></h4>
                                <p class="text-sm text-purple-800 mb-2"><?=T::this_setting_controls_auto_issue?></p>
                                <ul class="text-sm text-purple-700 space-y-1 list-disc list-inside">
                                    <li><strong><?=T::enabled?>:</strong> <?=T::bookings_auto_issue_after_payment?></li>
                                    <li><strong><?=T::disabled?>:</strong> <?=T::admin_must_manually_issue?></li>
                                    <li><?=T::useful_for_manual_verification?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

            <!-- AI Tab -->
            <div id="tab-ai" class="tab-content" style="display: none;">
                <?php require views . 'admin/settings/partials/ai-tab.php'; ?>

                <div class="my-4 flex justify-start">
                    <button type="submit" form="settings-form"
                        class="btn bg-blue-600 hover:bg-blue-700 text-white shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium"><?=T::save_settings?></span>
                    </button>
                </div>
            </div>

        </form>

                <div id="tab-ai-suggestions" class="tab-content mb-8 pb-8" style="display: none;">
                    <div class="mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">AI Suggestions</h3>
                    </div>
                    <?php
                    echo crud()->table('ai_suggestions')
                        ->col('suggestions')
                        ->title('')
                        ->list_url(root . admin . '/settings#ai')
                        ->perPage(10)
                        ->order('id', 'DESC')
                        ->actions([
                            'view' => false,
                            'delete' => true,
                            'edit' => true,
                            'status' => false,
                            'search' => true,
                        ])
                        ->action_urls([
                            'add' => admin.'/settings/ai-suggestions/add',
                            'edit' => root.admin.'/settings/ai-suggestions/edit/{id}',
                        ])
                        ->id_column('id')
                        ->row([
                            'suggestions' => function ($row) {
                                $text = htmlspecialchars((string) ($row['suggestions'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $html = preg_replace_callback(
                                    '/:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?/i',
                                    static function ($m) {
                                        $icon = $m[1];
                                        $color = !empty($m[2]) ? '#' . $m[2] : '#0058E6';
                                        $colorEsc = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
                                        return '<span class="material-symbols-outlined text-base align-middle" style="color:' . $colorEsc . '">' . $icon . '</span>';
                                    },
                                    $text
                                );
                                return '<span class="inline-flex flex-wrap items-center gap-1">' . $html . '</span>';
                            },
                        ])
                        ->render();
                    ?>
                </div>

            </div><!-- /Settings content -->
        </div><!-- /Settings layout -->
    </div>

    <!-- Test Modal -->
    <div id="testModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
        <div class="flex items-center justify-center min-h-full">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 id="testModalTitle" class="text-lg font-semibold text-gray-900"><?=T::test_notification?></h3>
                        <button type="button" onclick="closeTestModal()" class="text-gray-400 hover:text-gray-600">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                    <div id="testModalContent">
                        <!-- Dynamic content will be inserted here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Load Alpine.js for tab switching -->
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<style>
    /* Hide all scrollbars on settings page - show only when scrolling */
    body, html {
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
    }
    
    body::-webkit-scrollbar,
    html::-webkit-scrollbar {
        display: none !important; /* Chrome, Safari, Opera */
    }

    /* Ensure general tab is visible before Alpine.js loads */
    [x-cloak] {
        display: none !important;
    }

    /* Section styling */
    .section {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 1.25rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    /* Form control styling */
    .form-control {
        margin-bottom: 0;
    }

    .form-control label {
        display: block;
        font-size: 0.75rem;
        font-weight: 500;
        color: #374151;
        /* margin-bottom: 0.375rem; */
    }


</style>

<script>
// Real-time validation function
function validateDefaultValue(input, fieldType) {
    // Text fields validation
    if (['business_name', 'site_url', 'contact_phone', 'contact_email',
         'social_facebook', 'social_twitter', 'social_linkedin',
         'social_instagram', 'social_youtube', 'social_whatsapp',
         'android_store', 'ios_store', 'booking_expiry_time'].includes(fieldType)) {

        const value = input.value.trim();
        let hasDefaultValue = false;

        const defaultValues = {
            'business_name': ['phptarvels', 'PHPTARVELS', 'phptravels', 'PHPTRAVELS'],
            'site_url': ['https://phptravels.net'],
            'contact_phone': ['+1234567890', '+123456789'],
            'contact_email': ['email@agency.com', 'admin@example.com'],
            'social_facebook': ['https://facebook.com/phptravels', 'https://facebook.com/yourpage'],
            'social_twitter': ['https://twitter.com/phptravels', 'https://twitter.com/yourhandle'],
            'social_linkedin': ['https://linkedin.com/company/phptravels', 'https://linkedin.com/company/yourcompany'],
            'social_instagram': ['https://instagram.com/phptravels', 'https://instagram.com/yourhandle'],
            'social_youtube': ['https://youtube.com/@phptravels', 'https://youtube.com/@yourchannel'],
            'social_whatsapp': ['https://wa.me/1234567890'],
            'android_store': ['https://play.google.com/store/apps/details?id=com.phptravels'],
            'ios_store': ['https://apps.apple.com/app/phptravels/id123456789'],
            'booking_expiry_time': ['15', '30']
        };

        if (defaultValues[fieldType]) {
            defaultValues[fieldType].forEach(defaultVal => {
                if (value.toLowerCase() === defaultVal.toLowerCase()) {
                    hasDefaultValue = true;
                }
            });
        }

        const warningElement = input.parentNode.querySelector('.default-warning');

        if (hasDefaultValue) {
            input.classList.add('border-yellow-500');
            if (warningElement) warningElement.classList.remove('hidden');
        } else {
            input.classList.remove('border-yellow-500');
            if (warningElement) warningElement.classList.add('hidden');
        }
    }

    // Image fields validation
    else if (['logo', 'favicon', 'cover'].includes(fieldType)) {
        const defaultProperties = {
            'logo': { size: 19905, width: 250, height: 58 },
            'favicon': { size: 4908, width: 128, height: 128 },
            'cover': { size: 1058275, width: 1240, height: 500 }
        };

        const container = input.closest('.text-center');
        const preview = container.querySelector('img');
        const warningDiv = container.querySelector('.default-warning');
        const dashedDiv = container.querySelector('.border-dashed');

        if (input.files && input.files[0]) {
            const file = input.files[0];
            const reader = new FileReader();

            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    const isDefault = (file.size === defaultProperties[fieldType].size &&
                                     img.naturalWidth === defaultProperties[fieldType].width &&
                                     img.naturalHeight === defaultProperties[fieldType].height);

                    preview.src = e.target.result;

                    if (isDefault) {
                        warningDiv.classList.remove('hidden');
                        dashedDiv.classList.add('border-yellow-500', 'bg-yellow-50');
                        dashedDiv.classList.remove('border-gray-200');
                    } else {
                        warningDiv.classList.add('hidden');
                        dashedDiv.classList.remove('border-yellow-500', 'bg-yellow-50');
                        dashedDiv.classList.add('border-gray-200');
                    }

                    // Update branding tab warning
                    updateTabWarning('branding');
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }

        // Logo file specific validation
        if (fieldType === 'logo') {
            validateLogoFile(input);
        }
    }

    // Email provider fields validation
    const emailProviderFields = [
        'email_sender_name', 'email_sender_email',
        'smtp_username', 'smtp_host', 'smtp_password'
    ];

    if (emailProviderFields.includes(fieldType)) {
        const value = input.value.trim();
        let hasDefaultValue = false;

        const defaultValues = {
            'email_sender_name': ['PHPTRAVELS'],
            'email_sender_email': ['info@phptravels.com', 'noreply@phptravels.com'],
            'smtp_username': ['info@phptravels.com'],
            'smtp_host': ['smtp.phptravels.com'],
            'smtp_password': ['default', 'password', '123456']
        };

        if (defaultValues[fieldType]) {
            defaultValues[fieldType].forEach(defaultVal => {
                if (value.toLowerCase() === defaultVal.toLowerCase()) {
                    hasDefaultValue = true;
                }
            });
        }

        const warningElement = input.parentNode.querySelector('.default-warning');

        if (hasDefaultValue) {
            input.classList.add('border-yellow-500');
            if (warningElement) warningElement.classList.remove('hidden');
        } else {
            input.classList.remove('border-yellow-500');
            if (warningElement) warningElement.classList.add('hidden');
        }
    }
}

// Tab Switching Function
let currentActiveTab = 'general';
const SETTINGS_VALID_TABS = ['general', 'seo', 'branding', 'themes', 'accounts', 'contact', 'notifications', 'social', 'apps', 'tracking', 'booking', 'ai'];

function switchTab(tabName) {
    if (!tabName || !SETTINGS_VALID_TABS.includes(tabName)) {
        tabName = 'general';
    }
    currentActiveTab = tabName;

    // Update hidden field with current tab (guard if form not ready)
    const tabInput = document.getElementById('currentTabInput');
    if (tabInput) {
        tabInput.value = tabName;
    }

    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.style.display = 'none';
    });

    // Remove active classes from all buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('bg-blue-50', 'text-blue-700');
        btn.classList.add('text-gray-600', 'hover:bg-gray-50');
    });

    // Show active tab
    const activeTab = document.getElementById('tab-' + tabName);
    if (activeTab) {
        activeTab.style.display = 'block';
    }

    // AI Suggestions lives outside the settings form (CRUD needs its own forms)
    const aiSuggestions = document.getElementById('tab-ai-suggestions');
    if (aiSuggestions) {
        aiSuggestions.style.display = tabName === 'ai' ? 'block' : 'none';
    }

    // Highlight active button
    const activeBtn = document.getElementById('btn-' + tabName);
    if (activeBtn) {
        activeBtn.classList.remove('text-gray-600', 'hover:bg-gray-50');
        activeBtn.classList.add('bg-blue-50', 'text-blue-700');
    }

    // Update URL hash without scrolling (keep path/query intact)
    try {
        const url = window.location.pathname + window.location.search + '#' + tabName;
        history.replaceState(null, '', url);
    } catch (e) {
        window.location.hash = tabName;
    }

    // Smoothly scroll back to the top of the page
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/** Apply tab from URL hash (#seo). Safe to call multiple times. */
function applySettingsTabFromHash() {
    const hash = (window.location.hash || '').replace(/^#/, '').split(/[?&]/)[0].trim().toLowerCase();
    if (hash && SETTINGS_VALID_TABS.includes(hash)) {
        switchTab(hash);
        return true;
    }
    return false;
}

// App links visibility toggle
function toggleAppLinks() {
    const showApps = document.getElementById('showApps');
    const androidStore = document.getElementById('androidStore');
    const iosStore = document.getElementById('iosStore');

    if (showApps && androidStore && iosStore) {
        if (showApps.value === '0') {
            // Hide from website selected - disable inputs
            androidStore.disabled = true;
            iosStore.disabled = true;
            androidStore.classList.add('bg-gray-100', 'cursor-not-allowed');
            iosStore.classList.add('bg-gray-100', 'cursor-not-allowed');
        } else {
            // Show on website selected - enable inputs
            androidStore.disabled = false;
            iosStore.disabled = false;
            androidStore.classList.remove('bg-gray-100', 'cursor-not-allowed');
            iosStore.classList.remove('bg-gray-100', 'cursor-not-allowed');
        }
    }
}

// Live-update the favicon browser-tab preview when a new file is chosen
function previewFavicon(input) {
    if (input.files && input.files[0]) {
        const preview = document.getElementById('faviconPreview');
        const reader = new FileReader();
        reader.onload = function(e) { preview.src = e.target.result; };
        reader.readAsDataURL(input.files[0]);
    }
}

// File validation functions
function validateLogoFile(input) {
    const errorDiv = document.getElementById('logoError');
    const maxSize = 1 * 1024 * 1024; // 1MB in bytes
    const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml', 'image/webp'];

    errorDiv.classList.add('hidden');

    if (input.files && input.files[0]) {
        const file = input.files[0];

        // File size validation
        if (file.size > maxSize) {
            errorDiv.textContent = '<?=T::file_too_large?> (<?=T::max_size_1mb?>)';
            errorDiv.classList.remove('hidden');
            input.value = ''; // Clear the file input
            return false;
        }

        // File type validation
        if (!allowedTypes.includes(file.type)) {
            errorDiv.textContent = '<?=T::invalid_file_type?>';
            errorDiv.classList.remove('hidden');
            input.value = ''; // Clear the file input
            return false;
        }

        // Update preview
        const preview = document.getElementById('logoPreview');
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    return true;
}

// Load tab from URL hash on page load (and again after other scripts / demo modal)
function bootSettingsHashTab() {
    try {
        if (typeof toggleAppLinks === 'function') {
            toggleAppLinks();
        }
        applySettingsTabFromHash();
    } catch (e) {
        console.error('Settings hash tab failed:', e);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootSettingsHashTab);
} else {
    bootSettingsHashTab();
}
// Late pass: other DOMContentLoaded handlers / modals can run after the first pass
window.addEventListener('load', function () {
    setTimeout(bootSettingsHashTab, 0);
    setTimeout(bootSettingsHashTab, 300);
});

// Listen for hash changes (browser back/forward / manual hash edit)
window.addEventListener('hashchange', function () {
    bootSettingsHashTab();
});

// Form submission
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('settings-form');
    if (form) {
        form.addEventListener('submit', function() {
            document.getElementById('currentTabInput').value = currentActiveTab;
        });

        // Form submission
        form.addEventListener('submit', function(e) {
            const logoInput = document.getElementById('logoInput');

            if (logoInput.files && logoInput.files[0]) {
                if (!validateLogoFile(logoInput)) {
                    e.preventDefault(); // Form submit prevented due to validation error
                    return false;
                }
            }
        });
    }
});

// Provider field visibility functions
function showEmailFields(provider) {
    document.querySelectorAll('.email-fields').forEach(field => field.classList.add('hidden'));
    const selectedField = document.getElementById(`email-${provider}-fields`);
    if (selectedField) selectedField.classList.remove('hidden');
}

function showWhatsappFields(provider) {
    document.querySelectorAll('.whatsapp-fields').forEach(field => field.classList.add('hidden'));
    const selectedField = document.getElementById(`whatsapp-${provider}-fields`);
    if (selectedField) selectedField.classList.remove('hidden');
}

function showSmsFields(provider) {
    document.querySelectorAll('.sms-fields').forEach(field => field.classList.add('hidden'));
    const selectedField = document.getElementById(`sms-${provider}-fields`);
    if (selectedField) selectedField.classList.remove('hidden');
}

function showPushFields(provider) {
    document.querySelectorAll('.push-fields').forEach(field => field.classList.add('hidden'));
    const selectedField = document.getElementById(`push-${provider}-fields`);
    if (selectedField) selectedField.classList.remove('hidden');
}

// Test modal functions
function openTestModal(type) {
    const modal = document.getElementById('testModal');
    const title = document.getElementById('testModalTitle');
    const content = document.getElementById('testModalContent');
    title.textContent = `<?=T::test?> ${type.charAt(0).toUpperCase() + type.slice(1)} <?=T::notification?>`;
    let formContent = '';
    switch (type) {
        case 'email':
            formContent = `
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::to_email?></label>
                    <input type="email" id="testEmail" class="input w-full" placeholder="test@example.com">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::subject?></label>
                    <input type="text" id="testSubject" class="input w-full" value="<?=T::test_email_from_settings?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::message?></label>
                    <textarea id="testMessage" class="input w-full h-20" placeholder="<?=T::this_is_a_test_email?>"><?=T::this_is_a_test_email_from_your_settings_configuration?></textarea>
                </div>
            </div>
        `;
            break;
        case 'whatsapp':
            formContent = `
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::to_whatsapp_number?></label>
                    <input type="tel" id="testWhatsapp" class="input w-full" placeholder="923001234567">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::message?></label>
                    <textarea id="testMessage" class="input w-full h-20" placeholder="<?=T::test_whatsapp_message?>"><?=T::this_is_a_test_whatsapp_message_from_your_settings_configuration?></textarea>
                </div>
            </div>
        `;
            break;
        case 'sms':
            formContent = `
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::to_phone_number?></label>
                    <input type="tel" id="testSms" class="input w-full" placeholder="923001234567">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::message?></label>
                    <textarea id="testMessage" class="input w-full h-20" placeholder="<?=T::test_sms_message?>" maxlength="160"><?=T::this_is_a_test_sms_from_your_settings?></textarea>
                </div>
            </div>
        `;
            break;
        case 'push':
        // Updated for browser notifications: Only title and message
        formContent = `
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::title?></label>
                    <input type="text" id="testPushTitle" class="input w-full" value="<?=T::test_push_notification?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::message?></label>
                    <textarea id="testMessage" class="input w-full h-20" placeholder="<?=T::test_push_message?>"><?=T::this_is_a_test_push_notification_from_your_settings?></textarea>
                </div>
            </div>
            `;
        break;
    }
    content.innerHTML = formContent + `
    <div class="flex justify-end space-x-2 mt-4">
        <button type="button" onclick="closeTestModal()" class="btn white"><?=T::cancel?></button>
        <button type="button" onclick="sendTestNotification('${type}')" class="btn primary"><?=T::send_test?></button>
    </div>
    <div id="testResponse" class="mt-3 p-3 rounded-lg text-sm"></div>
`;
    modal.classList.remove('hidden');
}

async function sendTestNotification(type) {
    const sendBtn = document.querySelector('#testModal .btn.primary');
    const originalText = sendBtn.innerHTML;

    if (type === 'push') {
        const permissionResult = await ensureNotificationPermission();

        if (!permissionResult.success) {
            const responseDiv = document.getElementById('testResponse');
            responseDiv.innerHTML = `<div class="text-yellow-600 bg-yellow-50 p-2 rounded">${permissionResult.message}</div>`;

            if (permissionResult.message.includes("blocked") || permissionResult.message.includes("not granted")) {
                sendBtn.innerHTML = originalText;
                sendBtn.disabled = false;
                return;
            }
        }
    }

    function getProviderConfig(providerType, providerName) {
        const providerConfig = {};

        const providerFields = document.querySelectorAll(`#${providerType}-${providerName}-fields input, #${providerType}-${providerName}-fields select`);

        providerFields.forEach(field => {
            if (field.name.startsWith(`${providerType}_providers[`)) {
                const matches = field.name.match(/\[([^\]]+)\]/g);
                if (matches && matches.length >= 2) {
                    const fieldName = matches[1].replace(/[\[\]]/g, '');
                    providerConfig[fieldName] = field.value;
                }
            }
        });

        return providerConfig;
    }

    sendBtn.innerHTML = '<span class="material-symbols-outlined animate-spin mr-1 text-sm">refresh</span><?=T::sending?>...';
    sendBtn.disabled = true;

    const responseDiv = document.getElementById('testResponse');
    responseDiv.innerHTML = '';

    const testSessionId = 'test-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);

    const testChannelName = 'test-channel-' + testSessionId;
    const testChannel = (typeof pusher !== 'undefined') ? pusher.subscribe(testChannelName) : null;


    let requestData = {
        message: document.getElementById('testMessage').value,
        csrf_token: '<?= CSRF::getToken() ?>'
    };

    if (type === 'whatsapp') {
        const phone = document.getElementById('testWhatsapp').value;
        if (!phone) {
            responseDiv.innerHTML = '<div class="text-red-600 bg-red-50 p-2 rounded"><?=T::phone_number_is_required?></div>';
            sendBtn.innerHTML = originalText;
            sendBtn.disabled = false;
            return;
        }

        requestData.phone = phone;
        requestData.whatsapp_provider = document.getElementById('whatsappProvider').value;
        requestData.provider_config = getProviderConfig('whatsapp', requestData.whatsapp_provider);

    } else if (type === 'email') {
        const email = document.getElementById('testEmail').value;
        if (!email) {
            responseDiv.innerHTML = '<div class="text-red-600 bg-red-50 p-2 rounded"><?=T::email_address_is_required?></div>';
            sendBtn.innerHTML = originalText;
            sendBtn.disabled = false;
            return;
        }

        requestData.email = email;
        requestData.subject = document.getElementById('testSubject').value;
        requestData.email_provider = document.getElementById('emailProvider').value;
        requestData.provider_config = getProviderConfig('email', requestData.email_provider);
        requestData.sender_name = document.querySelector('input[name="email_sender_name"]').value;
        requestData.sender_email = document.querySelector('input[name="email_sender_email"]').value;

    } else if (type === 'sms') {
        const phone = document.getElementById('testSms').value;
        if (!phone) {
            responseDiv.innerHTML = '<div class="text-red-600 bg-red-50 p-2 rounded"><?=T::phone_number_is_required?></div>';
            sendBtn.innerHTML = originalText;
            sendBtn.disabled = false;
            return;
        }

        requestData.phone = phone;
        requestData.sms_provider = document.getElementById('smsProvider').value;
        requestData.provider_config = getProviderConfig('sms', requestData.sms_provider);

    } else if (type === 'push') {
        requestData.title = document.getElementById('testPushTitle').value;
        requestData.push_provider = document.getElementById('pushProvider').value;
        requestData.provider_config = getProviderConfig('push', requestData.push_provider);
        requestData.session_id = testSessionId;
    }

    fetch(`<?= root ?>admin/test-${type}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(requestData)
    })
    .then(response => response.text().then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            return { success: false, message: '<?=T::invalid_response_from_server?>' };
        }
    }))
    .then(data => {
        if (data.success) {
            responseDiv.innerHTML = '<div class="text-green-600 bg-green-50 p-2 rounded">' + data.message + '</div>';

            if (type === 'push' && Notification.permission === "granted") {
                showNotification(
                    document.getElementById('testPushTitle').value ,
                    document.getElementById('testMessage').value
                );
            }

            setTimeout(() => {
                closeTestModal();
            }, 5000);
        } else {
            responseDiv.innerHTML = '<div class="text-red-600 bg-red-50 p-2 rounded">' + data.message + '</div>';

            setTimeout(() => {
                if (typeof pusher !== 'undefined') {
                    pusher.unsubscribe(testChannelName);
                }
            }, 3000);
        }
    })
    .catch(error => {
        responseDiv.innerHTML = '<div class="text-red-600 bg-red-50 p-2 rounded"><?=T::error?>: ' + error.message + '</div>';

        setTimeout(() => {
            if (typeof pusher !== 'undefined') {
                pusher.unsubscribe(testChannelName);
            }
        }, 3000);
    })
    .finally(() => {
        sendBtn.innerHTML = originalText;
        sendBtn.disabled = false;
    });
}

function closeTestModal() {
    document.getElementById('testModal').classList.add('hidden');
    // Clear response when closing modal
    const responseDiv = document.getElementById('testResponse');
    if (responseDiv) {
        responseDiv.innerHTML = '';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Site offline toggle
    const siteOfflineSelect = document.getElementById('siteOffline');
    const offlineMessageContainer = document.getElementById('offlineMessageContainer');

    if (siteOfflineSelect && offlineMessageContainer) {
        function toggleOfflineMessage() {
            if (siteOfflineSelect.value === '1') {
                offlineMessageContainer.style.display = 'block';
            } else {
                offlineMessageContainer.style.display = 'none';
            }
        }

        siteOfflineSelect.addEventListener('change', toggleOfflineMessage);
        toggleOfflineMessage();
    }

    // Notification toggles - WhatsApp, SMS (Email is always enabled)
    const whatsappToggle = document.querySelector('input[name="whatsapp_providers[booking_enabled]"][type="checkbox"]');
    const smsToggle = document.querySelector('input[name="sms_providers[booking_enabled]"][type="checkbox"]');

    function saveNotificationToggle(type, isEnabled, notificationLabel) {
        fetch('<?= root ?>admin/save-notification-toggle', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                type: type,
                enabled: isEnabled
            })
        })
        .then(response => response.json())
        .then(data => {
            if (typeof vt !== 'undefined') {
                const statusText = isEnabled ? '<?=T::enabled?>' : '<?=T::disabled?>';
                const message = `${notificationLabel} <?=T::notifications?> ${statusText}`;
                if (data.success) {
                    vt.success(message);
                } else {
                    vt.error('<?=T::error?>: ' + data.message);
                }
            }
        })
        .catch(error => {
            if (typeof vt !== 'undefined') {
                vt.error('<?=T::error?>: ' + error.message);
            }
        });
    }

    if (whatsappToggle) {
        whatsappToggle.addEventListener('change', function() {
            saveNotificationToggle('whatsapp', this.checked, '<?=T::whatsapp?>');
        });
    }

    if (smsToggle) {
        smsToggle.addEventListener('change', function() {
            saveNotificationToggle('sms', this.checked, '<?=T::sms?>');
        });
    }

    // Image preview functionality with logo validation
    function setupImagePreview(inputId, previewId) {
        const input = document.getElementById(inputId);
        const preview = document.getElementById(previewId);

        if (input && preview) {
            input.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (file) {
                    // Logo file validation call karein
                    if (inputId === 'logoInput') {
                        if (!validateLogoFile(input)) {
                            return;
                        }
                    }

                    const reader = new FileReader();
                    reader.onload = function (e) {
                        preview.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
    }

    // Setup image previews
    setupImagePreview('logoInput', 'logoPreview');
    setupImagePreview('faviconInput', 'faviconPreview');
    setupImagePreview('coverInput', 'coverPreview');

    // Form submission
    document.querySelector('#settings-form').addEventListener('submit', function (e) {
        const submitButtons = document.querySelectorAll('button[type="submit"]');
        submitButtons.forEach(button => {
            button.disabled = true;
            const originalText = button.innerHTML;
            button.innerHTML = '<span class="material-symbols-outlined animate-spin mr-1 text-sm">refresh</span><?=T::saving?>';

            setTimeout(() => {
                button.disabled = false;
                button.innerHTML = originalText;
            }, 3000);
        });
    });

    // Color picker enhancement
    const colorPicker = document.querySelector('input[type="color"]');
    if (colorPicker) {
        const colorDisplay = colorPicker.nextElementSibling;
        colorPicker.addEventListener('input', function (e) {
            colorDisplay.textContent = e.target.value;
        });
    }
});

// script for notification

function updatePaymentIssueText(checkbox) {
    const textContainer = checkbox.closest('.form-control').querySelector('p.text-xs');
    if (checkbox.checked) {
        textContainer.textContent = '<?=T::when_enabled_bookings_auto_issued?>';
    } else {
        textContainer.textContent = '<?=T::when_disabled_bookings_manual_issue?>';
    }
}

// script for sitemap generation
document.getElementById('generateSitemapBtn')?.addEventListener('click', function() {
    const btn = this;
    const originalContent = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined animate-spin mr-1 text-sm">refresh</span>Generating...';

    fetch('<?= root ?>ajax', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'generate_sitemap'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (typeof vt !== 'undefined') {
                vt.success(data.message);
            } else {
                alert(data.message);
            }
        } else {
            if (typeof vt !== 'undefined') {
                vt.error(data.message);
            } else {
                alert(data.message);
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (typeof vt !== 'undefined') {
            vt.error('An error occurred while generating the sitemap.');
        }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalContent;
    });
});
</script>
<!-- Success/Error Messages -->
<?php if (isset($_SESSION['message'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const message = <?= json_encode($_SESSION['message']) ?>;

    if (typeof vt !== 'undefined') {
        if (message.type === 'success') {
            vt.success(message.text, {
            });
        } else if (message.type === 'error') {
            vt.error(message.text, {
            });
        }
    } else {
        // Fallback alert
        alert(message.text);
    }

    // Clear the session message
    <?php unset($_SESSION['message']); ?>
});
</script>
<?php endif; ?>

<style>
    .section {
        margin-bottom: 0px;
    }
</style>