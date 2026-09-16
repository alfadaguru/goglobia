<?php

// app/routes/admin/settingsRoutes.php
@$SECURE or die('Access Denied!');

/**
 * Ensure app_settings exists and has usable JSON defaults.
 * Also migrates legacy typo column app_settions when present.
 */
function ensureAppSettingsColumn($db): void
{
    $defaults = json_encode([
        'theme_mode' => 'light',
        'app_name' => 'PHPTRAVELS',
        'api_key' => '',
        'api_timeout' => '30',
        'btn_secondary_color' => '#f1f5f9',
        'btn_secondary_text_color' => '#334155',
        'btn_radius' => '10',
        'theme_primary_color' => '#ffffff',
        'header_bg' => '#f9fafb',
        'header_text_color' => '#111827',
        'card_bg' => '#ffffff',
        'card_border_color' => '#e4e6ec',
        'btn_color' => '#2563eb',
        'btn_text_color' => '#ffffff',
        'btn_border_color' => '#2563eb',
        'text_primary_color' => '#111827',
        'text_secondary_color' => '#6b7280',
        'home_card_bg' => '#ffffff',
        'home_card_border_color' => '#e4e6ec',
        'home_card_icon_color' => '#2563eb',
        'home_card_text_color' => '#111827',
        'all_tab_bg' => '#f9fafb',
        'all_tab_active_bg' => '#2563eb',
        'all_tab_border_color' => '#f9fafb',
        'all_tab_active_text_color' => '#ffffff',
        'all_tab_inactive_text_color' => '#6b7280',
        'theme_secondary_color' => '#121212',
        'header_bg_dark' => '#1e1e1e',
        'header_text_color_dark' => '#f5f5f5',
        'card_bg_dark' => '#1e1e1e',
        'card_border_color_dark' => '#1e1e1e',
        'btn_color_dark' => '#2563eb',
        'btn_text_color_dark' => '#ffffff',
        'btn_border_color_dark' => '#2563eb',
        'text_primary_color_dark' => '#f5f5f5',
        'text_secondary_color_dark' => '#9e9e9e',
        'home_card_bg_dark' => '#1e1e1e',
        'home_card_border_color_dark' => '#1e1e1e',
        'home_card_icon_color_dark' => '#2563eb',
        'home_card_text_color_dark' => '#ffffff',
        'all_tab_bg_dark' => '#1e1e1e',
        'all_tab_active_bg_dark' => '#2563eb',
        'all_tab_border_color_dark' => '#1e1e1e',
        'all_tab_active_text_color_dark' => '#ffffff',
        'all_tab_inactive_text_color_dark' => '#9e9e9e',
        'snackbar_success_bg' => '#166534',
        'snackbar_success_text_color' => '#ffffff',
        'snackbar_success_border_color' => '#15803d',
        'snackbar_warning_bg' => '#b45309',
        'snackbar_warning_text_color' => '#ffffff',
        'snackbar_warning_border_color' => '#d97706',
        'snackbar_failure_bg' => '#b91c1c',
        'snackbar_failure_text_color' => '#ffffff',
        'snackbar_failure_border_color' => '#dc2626',
        'snackbar_success_bg_dark' => '#14532d',
        'snackbar_success_text_color_dark' => '#bbf7d0',
        'snackbar_success_border_color_dark' => '#166534',
        'snackbar_warning_bg_dark' => '#78350f',
        'snackbar_warning_text_color_dark' => '#fde68a',
        'snackbar_warning_border_color_dark' => '#92400e',
        'snackbar_failure_bg_dark' => '#7f1d1d',
        'snackbar_failure_text_color_dark' => '#fecaca',
        'snackbar_failure_border_color_dark' => '#991b1b',
    ], JSON_UNESCAPED_SLASHES);

    $columns = [];
    $columnRows = $db->query('SHOW COLUMNS FROM `settings`');
    if ($columnRows) {
        foreach ($columnRows as $row) {
            if (!empty($row['Field'])) {
                $columns[] = $row['Field'];
            }
        }
    }

    $hasAppSettings = in_array('app_settings', $columns, true);
    $hasLegacyTypo = in_array('app_settions', $columns, true);

    if (!$hasAppSettings) {
        $db->query('ALTER TABLE `settings` ADD COLUMN `app_settings` LONGTEXT NULL');
        $hasAppSettings = true;
    }

    if ($hasAppSettings && $hasLegacyTypo) {
        $db->query("UPDATE `settings` SET `app_settings` = CASE WHEN (`app_settings` IS NULL OR TRIM(`app_settings`) = '') AND `app_settions` IS NOT NULL AND TRIM(`app_settions`) <> '' THEN `app_settions` ELSE `app_settings` END");
    }

    if ($hasAppSettings) {
        $stmt = $db->pdo->prepare("UPDATE `settings` SET `app_settings` = :defaults WHERE `app_settings` IS NULL OR TRIM(`app_settings`) = ''");
        $stmt->execute([':defaults' => $defaults]);
    }
}

// ======================================= SETTINGS


$router->get(admin.'/settings', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // Include ThemeManager for theme operations
    require_once __DIR__ . '/../../lib/ThemeManager.php';

    try {
        // Handle theme switching
        if (isset($_GET['switch_theme'])) {
            ThemeManager::setActiveTheme($_GET['switch_theme']);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Theme switched successfully!'];
            redirect(root . admin . '/settings#themes');
            exit;
        }

        // Handle theme creation
        if (isset($_GET['create_theme'])) {
            $newName = $_GET['create_theme'];
            $basedOn = $_GET['based_on'] ?? 'default';
            ThemeManager::createTheme($newName, $basedOn);
            ThemeManager::setActiveTheme($newName);
            $_SESSION['message'] = ['type' => 'success', 'text' => "Theme '{$newName}' created successfully!"];
            redirect(root . admin . '/settings#themes');
            exit;
        }

        // Handle theme deletion
        if (isset($_GET['delete_theme'])) {
            $themeName = $_GET['delete_theme'];
            ThemeManager::deleteTheme($themeName);
            $_SESSION['message'] = ['type' => 'success', 'text' => "Theme '{$themeName}' deleted successfully!"];
            redirect(root . admin . '/settings#themes');
            exit;
        }

        // Handle theme reset
        if (isset($_GET['reset_theme'])) {
            $themeName = $_GET['reset_theme'];
            ThemeManager::resetTheme($themeName);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Theme reset to default values successfully!'];
            redirect(root . admin . '/settings#themes');
            exit;
        }
    } catch (Exception $e) {
        error_log("Theme operation error: " . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Theme operation failed: ' . $e->getMessage()];
        redirect(root . admin . '/settings#themes');
        exit;
    }

    // META DATA
    $title = 'Settings';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/settings.php";
    require_once views."includes/footer.php";

});

$router->post(admin.'/update-settings', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    try {
        ensureAppSettingsColumn($db);

        // Include ThemeManager for theme saving
        require_once __DIR__ . '/../../lib/ThemeManager.php';

        // Handle theme saving - check for theme data and active_theme
        if (isset($_POST['theme']) && isset($_POST['active_theme']) && is_array($_POST['theme'])) {

            $activeThemeName = $_POST['active_theme'] ?? ThemeManager::getActiveTheme();

            // Load existing theme to preserve name property
            $existingTheme = ThemeManager::loadTheme($activeThemeName);
            $themeConfig = $_POST['theme'];
            $themeConfig['name'] = $existingTheme['name'] ?? ucfirst(str_replace('-', ' ', $activeThemeName));

            // Save the theme
            $result = ThemeManager::saveTheme($activeThemeName, $themeConfig);

            if ($result === false) {
                throw new Exception('Failed to save theme configuration');
            }

            $_SESSION['message'] = ['type' => 'success', 'text' => 'Theme settings saved successfully!'];

        }

        $existingSettings = getSettings($db);

        $validatedData = [];

        $textFields = [
            'business_name' => ['required' => true, 'max_length' => 255],
            'site_url' => ['required' => true, 'type' => 'url'],
            'tag_line' => ['required' => false, 'max_length' => 500],
            'home_title' => ['required' => false, 'max_length' => 255],
            'meta_description' => ['required' => false, 'max_length' => 500],
            'site_keywords' => ['required' => false, 'max_length' => 500],
            'contact_email' => ['required' => false, 'type' => 'email'],
            'contact_phone' => ['required' => false, 'max_length' => 20],
            'email_sender_name' => ['required' => false, 'max_length' => 100],
            'email_sender_email' => ['required' => false, 'type' => 'email'],
            'booking_notification_email' => ['required' => false, 'type' => 'email']
        ];

        foreach ($textFields as $field => $rules) {
            $value = $_POST[$field] ?? ($existingSettings[$field] ?? '');

            if ($rules['required'] && empty(trim($value))) {
                throw new Exception(T::field_required . ": " . $field);
            }

            if (!empty($value)) {
                if (isset($rules['max_length']) && strlen($value) > $rules['max_length']) {
                    throw new Exception(T::field_too_long . ": " . $field);
                }

                if (isset($rules['type'])) {
                    if ($rules['type'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception(T::invalid_email_format);
                    }
                    if ($rules['type'] === 'url' && !filter_var($value, FILTER_VALIDATE_URL)) {
                        throw new Exception(T::invalid_url_format);
                    }
                }

                if (isset($rules['pattern']) && !preg_match($rules['pattern'], $value)) {
                    throw new Exception(T::invalid_format . ": " . $field);
                }
            }

            $validatedData[$field] = trim($value);
        }

        $socialMediaData = [];
        if (isset($_POST['social_media']) && is_array($_POST['social_media'])) {
            foreach ($_POST['social_media'] as $platform => $url) {
                $url = trim($url);
                if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $socialMediaData[$platform] = $url;
                }
            }
        }

        /*
        $existingNotificationPrefs = [];
        if (!empty($existingSettings['notification_preferences'])) {
            $existingNotificationPrefs = json_decode($existingSettings['notification_preferences'], true);
            if (!is_array($existingNotificationPrefs)) {
                $existingNotificationPrefs = [];
            }
        }

        $notificationPrefs = $existingNotificationPrefs;

        if (isset($_POST['notification_prefs']) && is_array($_POST['notification_prefs'])) {
            foreach ($_POST['notification_prefs'] as $group => $templates) {
                if (!is_array($templates)) {
                    continue;
                }

                // Ensure the group exists in the preferences array
                if (!isset($notificationPrefs[$group])) {
                    $notificationPrefs[$group] = [];
                }

                foreach ($templates as $templateName => $channels) {
                    if (!is_array($channels)) {
                        continue;
                    }

                    // Ensure the template exists in the group array
                    if (!isset($notificationPrefs[$group][$templateName])) {
                        $notificationPrefs[$group][$templateName] = [];
                    }

                    foreach ($channels as $channel => $settings) {
                        // Handle legacy JSON string if present (though frontend should send array now)
                        if (!is_array($settings)) {
                            $decoded = json_decode($settings, true);
                            if ($decoded && is_array($decoded)) {
                                $settings = $decoded;
                            } else {
                                // Skip invalid data
                                continue;
                            }
                        }

                        // Sanitize and save settings
                        $notificationPrefs[$group][$templateName][$channel] = [
                            'enabled_for_users' => isset($settings['enabled_for_users']) &&
                                                ($settings['enabled_for_users'] == '1' || $settings['enabled_for_users'] === true),
                            'notify_roles' => isset($settings['notify_roles']) && is_array($settings['notify_roles']) ?
                                            array_filter($settings['notify_roles']) : []
                        ];
                    }
                }
            }
        }
        */

        $providerConfigs = [
            'email_providers_config' => $_POST['email_providers'] ?? [],
            'whatsapp_providers_config' => $_POST['whatsapp_providers'] ?? [],
            'sms_providers_config' => $_POST['sms_providers'] ?? [],
            'push_providers_config' => $_POST['push_providers'] ?? []
        ];

        foreach ($providerConfigs as $configKey => $configData) {
            if (!is_array($configData)) {
                $configData = [];
            }
            $validatedData[$configKey] = json_encode($configData);
        }

        $settingsData = array_merge($validatedData, [
            'site_offline' => isset($_POST['site_offline']) && $_POST['site_offline'] == '1' ? '1' : '0',
            'offline_message' => $_POST['offline_message'] ?? ($existingSettings['offline_message'] ?? ''),
            'address' => $_POST['address'] ?? ($existingSettings['address'] ?? ''),
            'map_address' => $_POST['map_address'] ?? ($existingSettings['map_address'] ?? ''),
            'guest_booking' => isset($_POST['guest_booking']) ? '1' : '0',
            'user_registration' => isset($_POST['user_registration']) ? '1' : '0',
            'agent_registration' => isset($_POST['agent_registration']) ? '1' : '0',
            'supplier_registration' => isset($_POST['supplier_registration']) ? '1' : '0',
            // '1' = website is restricted to logged in users, '0' = open to everyone
            'user_restriction' => isset($_POST['user_restriction']) ? '1' : '0',
            'multi_language' => isset($_POST['multi_language']) ? '1' : '0',
            'multi_currency' => isset($_POST['multi_currency']) ? '1' : '0',
            'social_media' => json_encode($socialMediaData),
            'android_store' => $_POST['android_store'] ?? ($existingSettings['android_store'] ?? ''),
            'ios_store' => $_POST['ios_store'] ?? ($existingSettings['ios_store'] ?? ''),
            'show_apps' => $_POST['show_apps'] ?? ($existingSettings['show_apps'] ?? '1'),
            'javascript' => $_POST['javascript'] ?? ($existingSettings['javascript'] ?? ''),
            'facebook_pixel' => $_POST['facebook_pixel'] ?? ($existingSettings['facebook_pixel'] ?? ''),
            'gtm_head' => $_POST['gtm_head'] ?? ($existingSettings['gtm_head'] ?? ''),
            'gtm_body' => $_POST['gtm_body'] ?? ($existingSettings['gtm_body'] ?? ''),
            'custom_tracking' => $_POST['custom_tracking'] ?? ($existingSettings['custom_tracking'] ?? ''),
            'email_provider' => $_POST['email_provider'] ?? ($existingSettings['email_provider'] ?? 'smtp'),
            'whatsapp_provider' => $_POST['whatsapp_provider'] ?? ($existingSettings['whatsapp_provider'] ?? 'official'),
            'sms_provider' => $_POST['sms_provider'] ?? ($existingSettings['sms_provider'] ?? 'twilio'),
            'push_provider' => $_POST['push_provider'] ?? ($existingSettings['push_provider'] ?? 'firebase'),
            'booking_expiry_time' => isset($_POST['booking_expiry_time']) && is_numeric($_POST['booking_expiry_time']) ? max(1, min(120, (int)$_POST['booking_expiry_time'])) : ($existingSettings['booking_expiry_time'] ?? 15),
            'booking_payment_issue' => isset($_POST['booking_payment_issue']) && $_POST['booking_payment_issue'] == '1' ? '1' : '0',
            'app_settings' => isset($_POST['app_settings']) && is_array($_POST['app_settings']) ? json_encode($_POST['app_settings']) : ($existingSettings['app_settings'] ?? '{}'),
        ]);

        // AI settings (same form / settings#ai tab)
        ensurePassportAiSchema($db);
        $aiExisting = passportAiSettings($db);
        $aiRegistry = passportAiProvidersRegistry();
        $postedProviders = $_POST['providers'] ?? [];
        if (!is_array($postedProviders)) {
            $postedProviders = [];
        }
        $providersConfig = passportAiEmptyProvidersConfig();
        foreach (array_keys($aiRegistry) as $providerKey) {
            $posted = is_array($postedProviders[$providerKey] ?? null) ? $postedProviders[$providerKey] : [];
            $current = $aiExisting['providers_config'][$providerKey] ?? [
                'api_key' => '',
                'api_secret' => '',
                'endpoint' => '',
                'model' => '',
            ];
            $apiKey = trim((string) ($posted['api_key'] ?? ''));
            $apiSecret = trim((string) ($posted['api_secret'] ?? ''));
            if (passportAiIsMaskedSecret($apiKey)) {
                $apiKey = $current['api_key'];
            }
            if (passportAiIsMaskedSecret($apiSecret)) {
                $apiSecret = $current['api_secret'];
            }
            $providersConfig[$providerKey] = [
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
                'endpoint' => trim((string) ($posted['endpoint'] ?? '')),
                'model' => trim((string) ($posted['model'] ?? '')),
            ];
        }
        $activeProvider = trim((string) ($_POST['ai_provider'] ?? ''));
        if ($activeProvider !== '' && !isset($aiRegistry[$activeProvider])) {
            $activeProvider = '';
        }
        $timeout = (int) ($_POST['ai_timeout'] ?? 0);
        $maxFileSizeMb = (float) ($_POST['ai_max_file_size_mb'] ?? 0);
        $maxFileSize = (int) round($maxFileSizeMb * 1048576);
        if ($maxFileSize <= 0) {
            $maxFileSize = null;
        }
        $allowedTypes = trim((string) ($_POST['ai_allowed_file_types'] ?? ''));
        $allowedTypes = strtolower(preg_replace('/\s+/', '', $allowedTypes) ?? '');

        $passportOn = isset($_POST['passport_ai_enabled']);
        $passportLocalOn = isset($_POST['passport_local_enabled']);
        $tripOn = isset($_POST['ai_trip_enabled']);
        $activeProviderHasKey = $activeProvider !== ''
            && trim((string) ($providersConfig[$activeProvider]['api_key'] ?? '')) !== '';
        if (!$activeProviderHasKey) {
            // AI features must never be persisted as active without credentials,
            // even if a crafted request bypasses the disabled admin toggles.
            $passportOn = false;
            $tripOn = false;
        }
        // AI and local passport are mutually exclusive; prefer AI when both are posted.
        if ($passportOn && $passportLocalOn) {
            $passportLocalOn = false;
        }

        // Single JSON column for all AI settings (legacy flat AI columns are obsolete)
        $settingsData['ai_configuration'] = aiConfigurationEncode([
            'passport_enabled' => $passportOn,
            'passport_local_enabled' => $passportLocalOn,
            'trip_enabled' => $tripOn,
            'provider' => $activeProvider,
            'timeout' => $timeout > 0 ? $timeout : 30,
            'max_file_size' => $maxFileSize !== null ? $maxFileSize : 5242880,
            'allowed_file_types' => $allowedTypes !== '' ? $allowedTypes : 'jpg,jpeg,png,webp',
            'providers' => $providersConfig,
        ]);

        $uploadErrors = [];
        $uploadDir = __DIR__ . '/../../../uploads/global/';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception(T::failed_create_upload_directory);
            }
        }

        $uploadConfig = [
            'logo' => [
                'target' => 'logo.png',
                'max_size' => 1 * 1024 * 1024,
                'allowed_types' => ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml', 'image/webp']
            ],
            'favicon' => [
                'target' => 'favicon.png',
                'max_size' => 1 * 1024 * 1024,
                'allowed_types' => ['image/png', 'image/jpeg', 'image/jpg', 'image/x-icon', 'image/webp']
            ],
            'coverimage' => [
                'target' => 'cover.png',
                'max_size' => 5 * 1024 * 1024,
                'allowed_types' => ['image/png', 'image/jpeg', 'image/jpg', 'image/webp']
            ]
        ];

        foreach ($uploadConfig as $fileKey => $config) {
            if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $result = handleFileUpload(
                    $fileKey,
                    $uploadDir . $config['target'],
                    $config['allowed_types'],
                    $config['max_size'],
                    true
                );

                if (!$result['success']) {
                    $uploadErrors[] = T::upload_failed . " " . $fileKey . ": " . $result['error'];
                }
            }
        }

        if (empty($existingSettings)) {
            $result = $db->insert('settings', $settingsData);
            $action = 'created';
        } else {
            $result = $db->update('settings', $settingsData);
            $action = 'updated';
        }

        // Warn (don't block) when the ACTIVE email provider is obviously
        // incomplete — saves still succeed so an admin can fill in credentials
        // across multiple visits, but the UI must not silently claim success
        // for a provider that cannot actually send mail.
        $emailProviderWarning = '';
        $activeEmailProvider = $settingsData['email_provider'] ?? '';
        if ($activeEmailProvider !== '') {
            $providerFile = __DIR__ . '/../../lib/notifications/email/' . $activeEmailProvider . '.php';
            if (file_exists($providerFile)) {
                require_once $providerFile;
                $providerClass = ucfirst($activeEmailProvider) . 'Provider';
                if (class_exists($providerClass)) {
                    $activeProviderConfig = $providerConfigs['email_providers_config'][$activeEmailProvider] ?? [];
                    $providerInstance = new $providerClass($activeProviderConfig);
                    if (method_exists($providerInstance, 'isConfigured') && !$providerInstance->isConfigured()) {
                        $emailProviderWarning = T::email_provider_incomplete_warning
                            ?? 'The selected email provider is missing required credentials — booking, signup and password-reset emails will not send until this is completed.';
                    }
                }
            }
        }

        if ($result) {
            if (!empty($uploadErrors)) {
                $_SESSION['message'] = [
                    'type' => 'warning',
                    'text' => T::settings_updated_with_upload_errors . ' ' . implode(', ', $uploadErrors)
                ];
            } elseif ($emailProviderWarning !== '') {
                $_SESSION['message'] = [
                    'type' => 'warning',
                    'text' => T::settings_updated_successfully . ' ' . $emailProviderWarning
                ];
            } else {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => T::settings_updated_successfully
                ];
            }
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::no_changes_made
            ];
        }

    } catch (Throwable $e) {
        error_log("Settings update error: " . $e->getMessage());
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::error_updating_settings . ': ' . $e->getMessage()
        ];
    }

    $currentTab = $_POST['current_tab'] ?? 'general';
    redirect(root.'admin/settings#' . $currentTab);
});

// AJAX route for saving notification toggle settings
$router->post('/admin/save-notification-toggle', function() use ($db) {
    try {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');

        // Check admin auth
        if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        // CSRF: cookie-session admin action (toggles notification providers).
        // Was authenticated but had no CSRF token — a logged-in admin could be
        // forced to flip these cross-site. Validate the token the admin UI sends.
        $csrfTok = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!CSRF::validateToken((string) $csrfTok)) {
            echo json_encode(['success' => false, 'message' => 'Invalid security token']);
            exit;
        }

        $type = $input['type'] ?? ''; // whatsapp, email, sms
        $enabled = $input['enabled'] ?? false;

        if (!in_array($type, ['whatsapp', 'email', 'sms'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid notification type']);
            exit;
        }

        // Get existing settings
        $existingSettings = $db->get('settings', '*');
        
        // Determine the config key
        $configKey = $type . '_providers_config';
        
        // Get existing config
        $existingConfig = [];
        if (!empty($existingSettings[$configKey])) {
            $existingConfig = json_decode($existingSettings[$configKey], true) ?: [];
        }

        // Update the booking_enabled setting
        $existingConfig['booking_enabled'] = $enabled ? '1' : '0';

        // Save back to database
        $result = $db->update('settings', [
            $configKey => json_encode($existingConfig)
        ]);

        if ($result !== false) {
            echo json_encode(['success' => true, 'message' => 'Setting saved']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save setting']);
        }

    } catch (Exception $e) {
        error_log("Save notification toggle error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

$router->post('/admin/test-email', function() use ($db) {
    try {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }
        if (!CSRF::validateToken($input['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh the page and try again.']);
            exit;
        }

        $email = $input['email'] ?? '';
        $subject = $input['subject'] ?? 'Test Email';
        $message = $input['message'] ?? 'This is a test email';
        $emailProvider = $input['email_provider'] ?? 'smtp';
        $providerConfig = $input['provider_config'] ?? [];
        $senderName = $input['sender_name'] ?? 'PHPTRAVELS';
        $senderEmail = $input['sender_email'] ?? 'noreply@example.com';

        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Email address is required']);
            exit;
        }

        $providerFile = __DIR__ . '/../../lib/notifications/email/' . $emailProvider . '.php';

        if (!file_exists($providerFile)) {
            echo json_encode(['success' => false, 'message' => 'Email provider not found: ' . $emailProvider]);
            exit;
        }

        require_once $providerFile;
        $providerClass = ucfirst($emailProvider) . 'Provider';

        if (!class_exists($providerClass)) {
            echo json_encode(['success' => false, 'message' => 'Email provider class not found: ' . $providerClass]);
            exit;
        }

        $mailer = new $providerClass($providerConfig);

        $result = $mailer->send(
            $email,
            'Test User',
            $subject,
            $message,
            strip_tags($message),
            $senderEmail,
            $senderName
        );

        if ($result) {
            $providerResponse = method_exists($mailer, 'getLastResponse') ? $mailer->getLastResponse() : ['message' => 'Email sent successfully'];
            echo json_encode(array_merge(['success' => true], $providerResponse));
        } else {
            $error = method_exists($mailer, 'getLastError') ? $mailer->getLastError() : 'Failed to send email';
            echo json_encode([
                'success' => false,
                'message' => $error
            ]);
        }

    } catch (Exception $e) {
        error_log("Test email error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

$router->post('/admin/test-whatsapp', function() use ($db) {
    try {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }
        if (!CSRF::validateToken($input['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh the page and try again.']);
            exit;
        }

        $phone = $input['phone'] ?? '';
        $message = $input['message'] ?? 'Test WhatsApp message';
        $whatsappProvider = $input['whatsapp_provider'] ?? 'greenapi';
        $providerConfig = $input['provider_config'] ?? [];

        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required']);
            exit;
        }

        $providerFile = __DIR__ . '/../../lib/notifications/whatsapp/' . $whatsappProvider . '.php';

        if (!file_exists($providerFile)) {
            echo json_encode(['success' => false, 'message' => 'WhatsApp provider not found: ' . $whatsappProvider]);
            exit;
        }

        require_once $providerFile;
        $providerClass = ucfirst($whatsappProvider) . 'Provider';

        if (!class_exists($providerClass)) {
            echo json_encode(['success' => false, 'message' => 'WhatsApp provider class not found: ' . $providerClass]);
            exit;
        }

        $whatsapp = new $providerClass($providerConfig);

        $connectionTest = $whatsapp->testConnection();
        if (!$connectionTest['success']) {
            echo json_encode([
                'success' => false,
                'message' => $connectionTest['message']
            ]);
            exit;
        }

        $sent = $whatsapp->send(
            $phone,
            'Test User',
            $message
        );

        if ($sent) {
            echo json_encode([
                'success' => true,
                'message' => 'Test WhatsApp message sent successfully to ' . $phone
            ]);
        } else {
            $error = $whatsapp->getLastError();
            echo json_encode([
                'success' => false,
                'message' => $error
            ]);
        }

    } catch (Exception $e) {
        error_log("Test WhatsApp error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

$router->post('/admin/test-sms', function() use ($db) {
    try {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }
        if (!CSRF::validateToken($input['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh the page and try again.']);
            exit;
        }

        $phone = $input['phone'] ?? '';
        $message = $input['message'] ?? 'Test SMS message';
        $smsProvider = $input['sms_provider'] ?? 'twilio';
        $providerConfig = $input['provider_config'] ?? [];

        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required']);
            exit;
        }

        $providerFile = __DIR__ . '/../../lib/notifications/sms/' . $smsProvider . '.php';

        if (!file_exists($providerFile)) {
            echo json_encode(['success' => false, 'message' => 'SMS provider not found: ' . $smsProvider]);
            exit;
        }

        require_once $providerFile;
        $providerClass = ucfirst($smsProvider) . 'Provider';

        if (!class_exists($providerClass)) {
            echo json_encode(['success' => false, 'message' => 'SMS provider class not found: ' . $providerClass]);
            exit;
        }

        $sms = new $providerClass($providerConfig);

        if (method_exists($sms, 'testConnection')) {
            $connectionTest = $sms->testConnection();
            if (!$connectionTest['success']) {
                echo json_encode([
                    'success' => false,
                    'message' => $connectionTest['message']
                ]);
                exit;
            }
        }

        $sent = $sms->send(
            $phone,
            'Test User',
            $message
        );

        if ($sent) {
            echo json_encode([
                'success' => true,
                'message' => 'Test SMS sent successfully to ' . $phone
            ]);
        } else {
            $error = method_exists($sms, 'getLastError') ? $sms->getLastError() : 'Unknown error';
            echo json_encode([
                'success' => false,
                'message' => $error
            ]);
        }

    } catch (Exception $e) {
        error_log("Test SMS error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

$router->post('/admin/test-push', function() use ($db) {
    try {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }
        if (!CSRF::validateToken($input['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh the page and try again.']);
            exit;
        }

        $pushProvider = $input['push_provider'] ?? 'pusher';
        $providerConfig = $input['provider_config'] ?? [];

        $providerFile = __DIR__ . '/../../lib/notifications/push/' . $pushProvider . '.php';

        if (!file_exists($providerFile)) {
            echo json_encode(['success' => false, 'message' => 'Push provider not found']);
            exit;
        }

        require_once $providerFile;

        $push = new PusherProvider($providerConfig);

        $sessionId = $input['session_id'] ?? 'test-' . uniqid();

        $sent = $push->send(
            $sessionId,
            'Test User',
            $input['title'] ?? 'Test Notification',
            $input['message'] ?? 'Test message'
        );

        if ($sent) {
            echo json_encode([
                'success' => true,
                'message' => 'Test notification sent successfully! Check your notifications.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $push->getLastError()
            ]);
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// ======================================= AI TEST
$router->post(admin.'/settings/ai/test', function() use ($db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');

    $provider = $_POST['provider'] ?? '';
    $key = $_POST['api_key'] ?? '';
    $secret = $_POST['api_secret'] ?? ''; // Some providers might use this

    if ($key === 'saved_key') {
        $aiExisting = passportAiSettings($db);
        if (isset($aiExisting['providers_config'][$provider]['api_key'])) {
            $key = $aiExisting['providers_config'][$provider]['api_key'];
        }
    }

    if (empty($provider) || empty($key)) {
        echo json_encode([
            'success' => false,
            'statusText' => 'Missing provider or API key'
        ]);
        exit;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $success = false;
    $statusText = '';
    
    switch ($provider) {
        case 'openai':
            curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/models');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $key
            ]);
            break;
            
        case 'gemini':
            curl_setopt($ch, CURLOPT_URL, 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($key));
            break;
            
        case 'claude':
            curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/models');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01'
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'statusText' => 'Unsupported provider']);
            exit;
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        $statusText = 'Connection error: ' . $curlError;
    } else {
        $responseData = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $success = true;
            $statusText = 'API connection successful (HTTP ' . $httpCode . ')';
        } else {
            $errorMsg = 'Unknown error';
            if (isset($responseData['error']['message'])) {
                $errorMsg = $responseData['error']['message'];
            } elseif (isset($responseData['error']['type'])) {
                $errorMsg = $responseData['error']['type'];
            }
            $statusText = 'API Error (' . $httpCode . '): ' . $errorMsg;
        }
    }

    echo json_encode([
        'success' => $success,
        'statusText' => $statusText
    ]);
    exit;
});

// ======================================= COUNTRIES
$router->get(admin.'/settings/countries', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Countries';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/countries.php";
    require_once views."includes/footer.php";

});

$router->get(admin.'/settings/countries/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Add Country';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/countries-manage.php";
    require_once views."includes/footer.php";

});

$router->get(admin.'/settings/countries/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Edit Country';
    $description = '';
    $header = true;
    $footer = true;

    // Pass ID to the view via GET parameter
    $_GET['id'] = (int)$id;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/countries-manage.php";
    require_once views."includes/footer.php";

});

// POST route for adding new country
$router->post(admin.'/settings/countries/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_country') {
        // CSRF token validation
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::invalid_request ?? 'Invalid request. Please try again.'
            ];
            redirect(root . admin . '/settings/countries/add');
        }

        try {
            // Validate and sanitize input
            $data = [
                'iso' => strtoupper(trim($_POST['iso'] ?? '')),
                'name' => strtoupper(trim($_POST['name'] ?? '')),
                'nicename' => trim($_POST['nicename'] ?? ''),
                'iso3' => strtoupper(trim($_POST['iso3'] ?? '')),
                'numcode' => trim($_POST['numcode'] ?? ''),
                'phonecode' => trim($_POST['phonecode'] ?? ''),
                'status' => trim($_POST['status'] ?? 'active')
            ];

            // Detailed Validation
            $errors = [];

            if (empty($data['name'])) {
                $errors[] = T::country_name_required ?? 'Country name is required';
            } elseif (strlen($data['name']) > 20) {
                $errors[] = 'Country name must not exceed 20 characters';
            }

            if (empty($data['nicename'])) {
                $errors[] = T::country_nicename_required ?? 'Display name is required';
            } elseif (strlen($data['nicename']) > 200) {
                $errors[] = 'Display name must not exceed 200 characters';
            }

            if (empty($data['iso'])) {
                $errors[] = 'ISO code is required';
            } elseif (strlen($data['iso']) !== 2) {
                $errors[] = T::invalid_iso_code ?? 'ISO code must be exactly 2 characters';
            } elseif (!preg_match('/^[A-Z]{2}$/', $data['iso'])) {
                $errors[] = 'ISO code must contain only uppercase letters (A-Z)';
            }

            if (empty($data['iso3'])) {
                $errors[] = 'ISO3 code is required';
            } elseif (strlen($data['iso3']) !== 3) {
                $errors[] = T::invalid_iso3_code ?? 'ISO3 code must be exactly 3 characters';
            } elseif (!preg_match('/^[A-Z]{3}$/', $data['iso3'])) {
                $errors[] = 'ISO3 code must contain only uppercase letters (A-Z)';
            }

            if (!empty($data['numcode']) && !is_numeric($data['numcode'])) {
                $errors[] = 'Numeric code must be a number';
            }

            if (!empty($data['phonecode']) && !is_numeric($data['phonecode'])) {
                $errors[] = 'Phone code must be a number';
            }

            // If there are validation errors, throw them
            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }

            // Check for duplicate ISO code
            $duplicateISO = $db->get('countries', 'id', ['iso' => $data['iso']]);
            if ($duplicateISO) {
                throw new Exception('ISO code "' . $data['iso'] . '" already exists in the database');
            }

            // Check for duplicate ISO3 code
            $duplicateISO3 = $db->get('countries', 'id', ['iso3' => $data['iso3']]);
            if ($duplicateISO3) {
                throw new Exception('ISO3 code "' . $data['iso3'] . '" already exists in the database');
            }

            // Check for duplicate country name
            $duplicateName = $db->get('countries', 'id', ['name' => $data['name']]);
            if ($duplicateName) {
                throw new Exception('Country name "' . $data['name'] . '" already exists in the database');
            }

            // Insert
            $result = $db->insert('countries', $data);
            $insertId = $db->id();

            if ($result && $insertId) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => T::country_added_successfully ?? 'Country added successfully'
                ];
                redirect(root . admin . '/settings/countries');
            } else {
                // Get database error if available
                $error = $db->error();
                if (is_array($error) && isset($error[2]) && !empty($error[2])) {
                    throw new Exception('Database error: ' . $error[2]);
                } else {
                    throw new Exception('Failed to add country. Please check all fields and try again.');
                }
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            // Redirect back to add page to preserve form data
            redirect(root . admin . '/settings/countries/add');
        }
    }

    // META DATA
    $title = 'Add Country';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/countries-manage.php";
    require_once views."includes/footer.php";

});

// POST route for editing existing country
$router->post(admin.'/settings/countries/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $countryId = (int)$id;

    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_country') {
        // CSRF token validation
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::invalid_request ?? 'Invalid request. Please try again.'
            ];
            redirect(root . admin . '/settings/countries/edit/' . $countryId);
        }

        try {
            // Validate and sanitize input
            $data = [
                'iso' => strtoupper(trim($_POST['iso'] ?? '')),
                'name' => strtoupper(trim($_POST['name'] ?? '')),
                'nicename' => trim($_POST['nicename'] ?? ''),
                'iso3' => strtoupper(trim($_POST['iso3'] ?? '')),
                'numcode' => trim($_POST['numcode'] ?? ''),
                'phonecode' => trim($_POST['phonecode'] ?? ''),
                'status' => trim($_POST['status'] ?? 'active')
            ];

            // Detailed Validation
            $errors = [];

            if (empty($data['name'])) {
                $errors[] = T::country_name_required ?? 'Country name is required';
            } elseif (strlen($data['name']) > 20) {
                $errors[] = 'Country name must not exceed 20 characters';
            }

            if (empty($data['nicename'])) {
                $errors[] = T::country_nicename_required ?? 'Display name is required';
            } elseif (strlen($data['nicename']) > 200) {
                $errors[] = 'Display name must not exceed 200 characters';
            }

            if (empty($data['iso'])) {
                $errors[] = 'ISO code is required';
            } elseif (strlen($data['iso']) !== 2) {
                $errors[] = T::invalid_iso_code ?? 'ISO code must be exactly 2 characters';
            } elseif (!preg_match('/^[A-Z]{2}$/', $data['iso'])) {
                $errors[] = 'ISO code must contain only uppercase letters (A-Z)';
            }

            if (empty($data['iso3'])) {
                $errors[] = 'ISO3 code is required';
            } elseif (strlen($data['iso3']) !== 3) {
                $errors[] = T::invalid_iso3_code ?? 'ISO3 code must be exactly 3 characters';
            } elseif (!preg_match('/^[A-Z]{3}$/', $data['iso3'])) {
                $errors[] = 'ISO3 code must contain only uppercase letters (A-Z)';
            }

            if (!empty($data['numcode']) && !is_numeric($data['numcode'])) {
                $errors[] = 'Numeric code must be a number';
            }

            if (!empty($data['phonecode']) && !is_numeric($data['phonecode'])) {
                $errors[] = 'Phone code must be a number';
            }

            // If there are validation errors, throw them
            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }

            // Check for duplicate ISO code (excluding current record)
            $duplicateISO = $db->get('countries', 'id', [
                'iso' => $data['iso'],
                'id[!]' => $countryId
            ]);
            if ($duplicateISO) {
                throw new Exception('ISO code "' . $data['iso'] . '" already exists in another country');
            }

            // Check for duplicate ISO3 code (excluding current record)
            $duplicateISO3 = $db->get('countries', 'id', [
                'iso3' => $data['iso3'],
                'id[!]' => $countryId
            ]);
            if ($duplicateISO3) {
                throw new Exception('ISO3 code "' . $data['iso3'] . '" already exists in another country');
            }

            // Check for duplicate country name (excluding current record)
            $duplicateName = $db->get('countries', 'id', [
                'name' => $data['name'],
                'id[!]' => $countryId
            ]);
            if ($duplicateName) {
                throw new Exception('Country name "' . $data['name'] . '" already exists in the database');
            }

            // Update
            $result = $db->update('countries', $data, ['id' => $countryId]);

            if ($result !== false) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => T::country_updated_successfully ?? 'Country updated successfully'
                ];
                redirect(root . admin . '/settings/countries');
            } else {
                // Get database error if available
                $error = $db();
                if (is_array($error) && isset($error[2]) && !empty($error[2])) {
                    throw new Exception('Database error: ' . $error[2]);
                } else {
                    throw new Exception('Failed to update country. Please check all fields and try again.');
                }
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            // Redirect back to edit page
            redirect(root . admin . '/settings/countries/edit/' . $countryId);
        }
    }

    // META DATA
    $title = 'Edit Country';
    $description = '';
    $header = true;
    $footer = true;

    // Pass ID to the view via GET parameter
    $_GET['id'] = $countryId;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/countries-manage.php";
    require_once views."includes/footer.php";

});

// ======================================= MOBILE APP SETTINGS
$router->get(admin.'/settings/app', function () use ($SECURE, $db) {
    ADMIN_AUTH();

    $title = 'Mobile App Settings';
    $description = 'Configure mobile app theme, colors, and button styles';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/app.php";
    require_once views."includes/footer.php";
});

$router->post(admin.'/settings/app', function () use ($SECURE, $db) {
    ADMIN_AUTH();

    try {
        ensureAppSettingsColumn($db);

        $appSettings = $_POST['app_settings'] ?? [];
        if (!is_array($appSettings)) {
            $appSettings = [];
        }

        if (isset($appSettings['api_timeout'])) {
            $appSettings['api_timeout'] = (string) max(5, min(300, (int) $appSettings['api_timeout']));
        }

        $existingSettings = $db->get('settings', '*');
        $updatedData = [
            'app_settings' => json_encode($appSettings)
        ];

        if (empty($existingSettings)) {
            $result = $db->insert('settings', $updatedData);
        } else {
            $result = $db->update('settings', $updatedData);
        }

        $_SESSION['message'] = [
            'type' => 'success',
            'text' => 'Mobile App settings saved successfully!'
        ];
    } catch (Exception $e) {
        error_log("Mobile app settings error: " . $e->getMessage());
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Failed to save app settings: ' . $e->getMessage()
        ];
    }

    redirect(root . admin . '/settings/app');
});

// ======================================= AI SETTINGS (redirect to main settings tab)
$router->get(admin.'/settings/ai', function () {
    redirect(root . admin . '/settings#ai');
});
$router->post(admin.'/settings/ai', function () {
    redirect(root . admin . '/settings#ai');
});
$router->get(admin.'/settings/passport-ai', function () {
    redirect(root . admin . '/settings#ai');
});
$router->post(admin.'/settings/passport-ai', function () {
    redirect(root . admin . '/settings#ai');
});

// ======================================= CURRENCIES
$router->get(admin.'/settings/currencies', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Currencies';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/currencies.php";
    require_once views."includes/footer.php";

});

// ======================================= CURRENCIES
$router->get(admin.'/settings/system-info', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'System Info';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/system-info.php";
    require_once views."includes/footer.php";

});

// GET route for adding new currency
$router->get(admin.'/settings/currencies/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Add Currency';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/currencies-manage.php";
    require_once views."includes/footer.php";

});

// GET route for editing existing currency
$router->get(admin.'/settings/currencies/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Edit Currency';
    $description = '';
    $header = true;
    $footer = true;

    // Pass ID to the view via GET parameter
    $_GET['id'] = (int)$id;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/currencies-manage.php";
    require_once views."includes/footer.php";

});

// POST route for adding new currency
$router->post(admin.'/settings/currencies/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_currency') {
        // CSRF token validation
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::invalid_request ?? 'Invalid request. Please try again.'
            ];
            redirect(root . admin . '/settings/currencies/add');
        }

        try {
            // Validate and sanitize input
            $data = [
                'name' => strtoupper(trim($_POST['name'] ?? '')),
                'country' => trim($_POST['country'] ?? ''),
                'rate' => trim($_POST['rate'] ?? ''),
                'default' => trim($_POST['default'] ?? '0'),
                'status' => trim($_POST['status'] ?? '1')
            ];

            // Detailed Validation
            $errors = [];

            if (empty($data['name'])) {
                $errors[] = T::currency_code_required ?? 'Currency code is required';
            } elseif (strlen($data['name']) !== 3) {
                $errors[] = T::invalid_currency_code ?? 'Currency code must be exactly 3 characters';
            } elseif (!preg_match('/^[A-Z]{3}$/', $data['name'])) {
                $errors[] = 'Currency code must contain only uppercase letters (A-Z)';
            }

            if (empty($data['country'])) {
                $errors[] = T::country_name_required ?? 'Country name is required';
            } elseif (strlen($data['country']) > 255) {
                $errors[] = 'Country name must not exceed 255 characters';
            }

            // if (empty($data['rate'])) {
            //     $errors[] = T::exchange_rate_required ?? 'Exchange rate is required';
            // } elseif (!is_numeric($data['rate']) || $data['rate'] <= 0) {
            //     $errors[] = T::invalid_exchange_rate ?? 'Exchange rate must be a valid positive number';
            // }

            // If there are validation errors, throw them
            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }

            // Validate: Cannot set inactive currency as default
            if ($data['default'] === '1' && $data['status'] === '0') {
                throw new Exception('Cannot set an inactive currency as default. Please activate the currency first.');
            }

            // Check for duplicate currency code
            $duplicateCode = $db->get('currencies', 'id', ['name' => $data['name']]);
            if ($duplicateCode) {
                throw new Exception('Currency code "' . $data['name'] . '" already exists in the database');
            }

            // If this currency is set as default, remove default from other currencies
            if ($data['default'] === '1') {
                $db->update('currencies', ['default' => '0'], ['default' => '1']);
            }

            // Insert
            $result = $db->insert('currencies', $data);
            $insertId = $db->id();

            if ($result && $insertId) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => T::currency_added_successfully ?? 'Currency added successfully'
                ];
                redirect(root . admin . '/settings/currencies');
            } else {
                // Get database error if available
                $error = $db();
                if (is_array($error) && isset($error[2]) && !empty($error[2])) {
                    throw new Exception('Database error: ' . $error[2]);
                } else {
                    throw new Exception('Failed to add currency. Please check all fields and try again.');
                }
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            // Redirect back to add page to preserve form data
            redirect(root . admin . '/settings/currencies/add');
        }
    }

    // META DATA
    $title = 'Add Currency';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/currencies-manage.php";
    require_once views."includes/footer.php";

});

// POST route for editing existing currency
$router->post(admin.'/settings/currencies/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $currencyId = (int)$id;

    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_currency') {
        // CSRF token validation
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::invalid_request ?? 'Invalid request. Please try again.'
            ];
            redirect(root . admin . '/settings/currencies/edit/' . $currencyId);
        }

        try {
            // Validate and sanitize input
            $data = [
                'name' => strtoupper(trim($_POST['name'] ?? '')),
                'country' => trim($_POST['country'] ?? ''),
                'rate' => trim($_POST['rate'] ?? ''),
                'default' => trim($_POST['default'] ?? '0'),
                'status' => trim($_POST['status'] ?? '1')
            ];

            // Detailed Validation
            $errors = [];

            if (empty($data['name'])) {
                $errors[] = T::currency_code_required ?? 'Currency code is required';
            } elseif (strlen($data['name']) !== 3) {
                $errors[] = T::invalid_currency_code ?? 'Currency code must be exactly 3 characters';
            } elseif (!preg_match('/^[A-Z]{3}$/', $data['name'])) {
                $errors[] = 'Currency code must contain only uppercase letters (A-Z)';
            }

            if (empty($data['country'])) {
                $errors[] = T::country_name_required ?? 'Country name is required';
            } elseif (strlen($data['country']) > 255) {
                $errors[] = 'Country name must not exceed 255 characters';
            }

            // if (empty($data['rate'])) {
            //     $errors[] = T::exchange_rate_required ?? 'Exchange rate is required';
            // } elseif (!is_numeric($data['rate']) || $data['rate'] <= 0) {
            //     $errors[] = T::invalid_exchange_rate ?? 'Exchange rate must be a valid positive number';
            // }

            // If there are validation errors, throw them
            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }

            // Validate: Cannot set inactive currency as default
            if ($data['default'] === '1' && $data['status'] === '0') {
                throw new Exception('Cannot set an inactive currency as default. Please activate the currency first.');
            }

            // Check for duplicate currency code (excluding current record)
            $duplicateCode = $db->get('currencies', 'id', [
                'name' => $data['name'],
                'id[!]' => $currencyId
            ]);
            if ($duplicateCode) {
                throw new Exception('Currency code "' . $data['name'] . '" already exists in another currency');
            }

            // Validate: Cannot disable a currency that is currently default
            if ($data['status'] === '0') {
                // Check if this currency is currently the default
                $currentDefault = $db->get('currencies', 'default', ['id' => $currencyId]);

                if ($currentDefault == '1') {
                    throw new Exception('Cannot deactivate the default currency. Please set another currency as default first.');
                }
            }

            // If this currency is set as default, remove default from other currencies
            if ($data['default'] === '1') {
                $db->update('currencies', ['default' => '0'], ['default' => '1']);
            }

            // If trying to set default to 0, check if this is the only default currency
            if ($data['default'] === '0') {
                // Check if this currency is currently the default
                $currentDefault = $db->get('currencies', 'default', ['id' => $currencyId]);

                if ($currentDefault == '1') {
                    // This is currently default, check if there are other currencies
                    $totalCurrencies = $db->count('currencies');

                    if ($totalCurrencies <= 1) {
                        throw new Exception('Cannot disable default. At least one currency must be set as default.');
                    }

                    // Check if there's another default currency
                    $otherDefaults = $db->count('currencies', ['default' => '1', 'id[!]' => $currencyId]);

                    if ($otherDefaults == 0) {
                        throw new Exception('Cannot disable the current default. Please set another currency as default first.');
                    }
                }
            }

            // Update
            $result = $db->update('currencies', $data, ['id' => $currencyId]);

            if ($result !== false) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => T::currency_updated_successfully ?? 'Currency updated successfully'
                ];
                redirect(root . admin . '/settings/currencies');
            } else {
                // Get database error if available
                $error = $db->error();
                if (is_array($error) && isset($error[2]) && !empty($error[2])) {
                    throw new Exception('Database error: ' . $error[2]);
                } else {
                    throw new Exception('Failed to update currency. Please check all fields and try again.');
                }
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            // Redirect back to edit page
            redirect(root . admin . '/settings/currencies/edit/' . $currencyId);
        }
    }

    // META DATA
    $title = 'Edit Currency';
    $description = '';
    $header = true;
    $footer = true;

    // Pass ID to the view via GET parameter
    $_GET['id'] = $currencyId;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/currencies-manage.php";
    require_once views."includes/footer.php";

});

// Auto update exchange rates
$router->post(admin.'/settings/currencies/update-rates', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    CSRF::guard();

    @set_time_limit(0);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    // Shared with the /update_currency_rates cron: fetches rates from currencylayer,
    // updates the currencies table, and logs a snapshot into currency_updates.
    $result = updateCurrencyRatesFromApi($db);

    echo json_encode([
        'success' => $result['success'],
        'status'  => $result['success'] ? 'success' : 'error',
        'message' => $result['message'],
        'updated' => $result['updated'],
        'errors'  => $result['errors'],
    ]);
    exit;
});

// Save the currency exchange-rate API key into the settings table
$router->post(admin.'/settings/currencies/save-api-key', function () use ($SECURE,$db) {

    ADMIN_AUTH();
    CSRF::guard();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    try {
        ensureCurrencyUpdateSchema($db);
        $key = trim((string) ($_POST['currency_api_key'] ?? ''));
        $db->update('settings', ['currency_api_key' => $key], ['id' => 1]);
        echo json_encode(['success' => true, 'message' => 'API key saved successfully.']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// ======================================= LANGUAGES
$router->get(admin.'/settings/languages', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Languages';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/languages.php";
    require_once views."includes/footer.php";

});

// GET route for adding new language
$router->get(admin.'/settings/languages/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Add Language';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/languages-manage.php";
    require_once views."includes/footer.php";

});

// GET route for editing existing language
$router->get(admin.'/settings/languages/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Edit Language';
    $description = '';
    $header = true;
    $footer = true;

    // Pass ID to the view via GET parameter
    $_GET['id'] = (int)$id;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/languages-manage.php";
    require_once views."includes/footer.php";

});

// POST route for adding new language
$router->post(admin.'/settings/languages/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_language') {
        // CSRF token validation
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::invalid_request ?? 'Invalid request. Please try again.'
            ];
            redirect(root . admin . '/settings/languages/add');
        }

        try {
            // Validate and sanitize input
            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'type' => trim($_POST['type'] ?? 'LTR'),
                'country' => strtoupper(trim($_POST['country'] ?? '')),
                'lang_code' => strtolower(trim($_POST['lang_code'] ?? '')),
                'default' => trim($_POST['default'] ?? '0'),
                'status' => trim($_POST['status'] ?? '1')
            ];

            // Detailed Validation
            $errors = [];

            if (empty($data['name'])) {
                $errors[] = T::language_name_required ?? 'Language name is required';
            } elseif (strlen($data['name']) > 100) {
                $errors[] = 'Language name must not exceed 100 characters';
            }

            if (empty($data['type'])) {
                $errors[] = T::language_type_required ?? 'Language type is required';
            } elseif (!in_array($data['type'], ['LTR', 'RTL'])) {
                $errors[] = T::invalid_language_type ?? 'Language type must be either LTR or RTL';
            }

            if (empty($data['country'])) {
                $errors[] = T::country_required ?? 'Country is required';
            } elseif (strlen($data['country']) !== 2) {
                $errors[] = T::invalid_country_code ?? 'Country code must be exactly 2 characters';
            } elseif (!preg_match('/^[A-Z]{2}$/', $data['country'])) {
                $errors[] = 'Country code must contain only uppercase letters (A-Z)';
            }

            if (empty($data['lang_code'])) {
                $errors[] = T::language_code_required ?? 'Language code is required';
            } elseif (strlen($data['lang_code']) !== 2) {
                $errors[] = T::invalid_language_code ?? 'Language code must be exactly 2 characters';
            } elseif (!preg_match('/^[a-z]{2}$/', $data['lang_code'])) {
                $errors[] = 'Language code must contain only lowercase letters (a-z)';
            }

            // Prevent setting inactive language as default
            if ($data['default'] === '1' && $data['status'] === '0') {
                $errors[] = T::inactive_language_cannot_be_default ?? 'An inactive language cannot be set as default';
            }

            // If there are validation errors, throw them
            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }

            // Check for duplicate language code
            $duplicateCode = $db->get('languages', 'id', ['lang_code' => $data['lang_code']]);
            if ($duplicateCode) {
                throw new Exception('Language code "' . $data['lang_code'] . '" already exists in the database');
            }

            // Check for duplicate language name
            $duplicateName = $db->get('languages', 'id', ['name' => $data['name']]);
            if ($duplicateName) {
                throw new Exception('Language name "' . $data['name'] . '" already exists in the database');
            }

            // If this language is set as default, remove default from other languages
            if ($data['default'] === '1') {
                $db->update('languages', ['default' => '0'], ['default' => '1']);
            }

            // Insert
            $result = $db->insert('languages', $data);
            $insertId = $db->id();

            if ($result && $insertId) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => T::language_added_successfully ?? 'Language added successfully'
                ];
                redirect(root . admin . '/settings/languages');
            } else {
                // Get database error if available
                $error = $db->error();
                if (is_array($error) && isset($error[2]) && !empty($error[2])) {
                    throw new Exception('Database error: ' . $error[2]);
                } else {
                    throw new Exception('Failed to add language. Please check all fields and try again.');
                }
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            // Redirect back to add page to preserve form data
            redirect(root . admin . '/settings/languages/add');
        }
    }

    // META DATA
    $title = 'Add Language';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/languages-manage.php";
    require_once views."includes/footer.php";

});

// POST route for editing existing language
$router->post(admin.'/settings/languages/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $languageId = (int)$id;

    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_language') {
        // CSRF token validation
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::invalid_request ?? 'Invalid request. Please try again.'
            ];
            redirect(root . admin . '/settings/languages/edit/' . $languageId);
        }

        try {
            // Validate and sanitize input
            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'type' => trim($_POST['type'] ?? 'LTR'),
                'country' => strtoupper(trim($_POST['country'] ?? '')),
                'lang_code' => strtolower(trim($_POST['lang_code'] ?? '')),
                'default' => trim($_POST['default'] ?? '0'),
                'status' => trim($_POST['status'] ?? '1')
            ];

            // Detailed Validation
            $errors = [];

            if (empty($data['name'])) {
                $errors[] = T::language_name_required ?? 'Language name is required';
            } elseif (strlen($data['name']) > 100) {
                $errors[] = 'Language name must not exceed 100 characters';
            }

            if (empty($data['type'])) {
                $errors[] = T::language_type_required ?? 'Language type is required';
            } elseif (!in_array($data['type'], ['LTR', 'RTL'])) {
                $errors[] = T::invalid_language_type ?? 'Language type must be either LTR or RTL';
            }

            if (empty($data['country'])) {
                $errors[] = T::country_required ?? 'Country is required';
            } elseif (strlen($data['country']) !== 2) {
                $errors[] = T::invalid_country_code ?? 'Country code must be exactly 2 characters';
            } elseif (!preg_match('/^[A-Z]{2}$/', $data['country'])) {
                $errors[] = 'Country code must contain only uppercase letters (A-Z)';
            }

            if (empty($data['lang_code'])) {
                $errors[] = T::language_code_required ?? 'Language code is required';
            } elseif (strlen($data['lang_code']) !== 2) {
                $errors[] = T::invalid_language_code ?? 'Language code must be exactly 2 characters';
            } elseif (!preg_match('/^[a-z]{2}$/', $data['lang_code'])) {
                $errors[] = 'Language code must contain only lowercase letters (a-z)';
            }

            // Get current language to check if it's the default
            $currentLanguage = $db->get('languages', ['default', 'status'], ['id' => $languageId]);

            // Prevent setting inactive language as default
            if ($data['default'] === '1' && $data['status'] === '0') {
                $errors[] = T::inactive_language_cannot_be_default ?? 'An inactive language cannot be set as default';
            }

            // Prevent deactivating the default language
            if ($currentLanguage['default'] === '1' && $data['status'] === '0') {
                $errors[] = T::cannot_deactivate_default ?? 'Cannot deactivate the default language. Please set another language as default first.';
            }

            // Prevent disabling the last default language
            if ($currentLanguage['default'] === '1' && $data['default'] === '0') {
                $totalCount = $db->count('languages');
                if ($totalCount <= 1) {
                    $errors[] = T::cannot_disable_only_default ?? 'Cannot disable the only default language. At least one language must be set as default.';
                }
            }

            // If there are validation errors, throw them
            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }

            // Check for duplicate language code (excluding current record)
            $duplicateCode = $db->get('languages', 'id', [
                'lang_code' => $data['lang_code'],
                'id[!]' => $languageId
            ]);
            if ($duplicateCode) {
                throw new Exception('Language code "' . $data['lang_code'] . '" already exists in another language');
            }

            // Check for duplicate language name (excluding current record)
            $duplicateName = $db->get('languages', 'id', [
                'name' => $data['name'],
                'id[!]' => $languageId
            ]);
            if ($duplicateName) {
                throw new Exception('Language name "' . $data['name'] . '" already exists in the database');
            }

            // If this language is set as default, remove default from other languages
            if ($data['default'] === '1') {
                $db->update('languages', ['default' => '0'], ['default' => '1']);
            }

            // Update
            $result = $db->update('languages', $data, ['id' => $languageId]);

            if ($result !== false) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => T::language_updated_successfully ?? 'Language updated successfully'
                ];
                redirect(root . admin . '/settings/languages');
            } else {
                // Get database error if available
                $error = $db->error();
                if (is_array($error) && isset($error[2]) && !empty($error[2])) {
                    throw new Exception('Database error: ' . $error[2]);
                } else {
                    throw new Exception('Failed to update language. Please check all fields and try again.');
                }
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            // Redirect back to edit page
            redirect(root . admin . '/settings/languages/edit/' . $languageId);
        }
    }

    // META DATA
    $title = 'Edit Language';
    $description = '';
    $header = true;
    $footer = true;

    // Pass ID to the view via GET parameter
    $_GET['id'] = $languageId;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/languages-manage.php";
    require_once views."includes/footer.php";

});

// ======================================= AI SUGGESTIONS
$router->get(admin.'/settings/ai-suggestions', function () use ($SECURE,$db) {
    // Suggestions list lives on Settings → AI
    ADMIN_AUTH();
    redirect(root . admin . '/settings#ai');
});

// GET route for adding new suggestion
$router->get(admin.'/settings/ai-suggestions/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Add AI Suggestion';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/ai_suggestions-manage.php";
    require_once views."includes/footer.php";

});

// GET route for editing existing suggestion
$router->get(admin.'/settings/ai-suggestions/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = 'Edit AI Suggestion';
    $description = '';
    $header = true;
    $footer = true;

    // Pass ID to the view via GET parameter
    $_GET['id'] = (int)$id;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/ai_suggestions-manage.php";
    require_once views."includes/footer.php";

});

// POST route for adding new suggestion
$router->post(admin.'/settings/ai-suggestions/add', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_ai_suggestion') {
        // CSRF token validation
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::invalid_request ?? 'Invalid request. Please try again.'
            ];
            redirect(root . admin . '/settings/ai-suggestions/add');
        }

        try {
            // Validate and sanitize input
            $data = [
                'suggestions' => trim($_POST['suggestions'] ?? ''),
            ];

            // Detailed Validation
            $errors = [];

            if (empty($data['suggestions'])) {
                $errors[] = 'Suggestions text is required';
            } elseif (strlen($data['suggestions']) > 255) {
                $errors[] = 'Suggestions must not exceed 255 characters';
            }

            // If there are validation errors, throw them
            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }

            // Insert
            $result = $db->insert('ai_suggestions', $data);
            $insertId = $db->id();

            if ($result && $insertId) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => 'AI Suggestion added successfully'
                ];
                redirect(root . admin . '/settings#ai');
            } else {
                // Get database error if available
                $error = $db->error();
                if (is_array($error) && isset($error[2]) && !empty($error[2])) {
                    throw new Exception('Database error: ' . $error[2]);
                } else {
                    throw new Exception('Failed to add AI Suggestion. Please check all fields and try again.');
                }
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            // Redirect back to add page to preserve form data
            redirect(root . admin . '/settings/ai-suggestions/add');
        }
    }

    // META DATA
    $title = 'Add AI Suggestion';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/ai_suggestions-manage.php";
    require_once views."includes/footer.php";

});

// POST route for editing existing suggestion
$router->post(admin.'/settings/ai-suggestions/edit/(.*)', function ($id) use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $suggestionId = (int)$id;

    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_ai_suggestion') {
        // CSRF token validation
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::invalid_request ?? 'Invalid request. Please try again.'
            ];
            redirect(root . admin . '/settings/ai-suggestions/edit/' . $suggestionId);
        }

        try {
            // Validate and sanitize input
            $data = [
                'suggestions' => trim($_POST['suggestions'] ?? ''),
            ];

            // Detailed Validation
            $errors = [];

            if (empty($data['suggestions'])) {
                $errors[] = 'Suggestions text is required';
            } elseif (strlen($data['suggestions']) > 255) {
                $errors[] = 'Suggestions must not exceed 255 characters';
            }

            // If there are validation errors, throw them
            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }

            // Update
            $result = $db->update('ai_suggestions', $data, ['id' => $suggestionId]);

            if ($result !== false) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => 'AI Suggestion updated successfully'
                ];
                redirect(root . admin . '/settings#ai');
            } else {
                // Get database error if available
                $error = $db->error();
                if (is_array($error) && isset($error[2]) && !empty($error[2])) {
                    throw new Exception('Database error: ' . $error[2]);
                } else {
                    throw new Exception('Failed to update AI Suggestion. Please check all fields and try again.');
                }
            }

        } catch (Exception $e) {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            // Redirect back to edit page
            redirect(root . admin . '/settings/ai-suggestions/edit/' . $suggestionId);
        }
    }

    // META DATA
    $title = 'Edit AI Suggestion';
    $description = '';
    $header = true;
    $footer = true;

    // Pass ID to the view via GET parameter
    $_GET['id'] = $suggestionId;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/ai_suggestions-manage.php";
    require_once views."includes/footer.php";

});

// Legacy underscore URLs → dashed (bookmarks / old links)
$router->get(admin.'/settings/ai_suggestions(.*)', function ($rest) {
    ADMIN_AUTH();
    header('Location: ' . root . admin . '/settings/ai-suggestions' . (string)$rest, true, 301);
    exit;
});
$router->post(admin.'/settings/ai_suggestions(.*)', function ($rest) {
    ADMIN_AUTH();
    header('Location: ' . root . admin . '/settings/ai-suggestions' . (string)$rest, true, 307);
    exit;
});

// ============================================
// LOGS MANAGEMENT ROUTES
// ============================================

// POST route for toggling logging on/off
$router->post(admin.'/settings/modules/toggle-logging', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    CSRF::guard();

    // Set JSON header
    header('Content-Type: application/json');

    try {
        // Get POST data
        $moduleId = $_POST['module_id'] ?? null;
        $moduleName = $_POST['module_name'] ?? null;
        $moduleType = $_POST['module_type'] ?? null;
        $settingKey = $_POST['setting_key'] ?? null;
        $settingValue = $_POST['setting_value'] ?? '0';

        // Validate required parameters
        if (!$moduleId || !$moduleName || !$moduleType || !$settingKey) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid parameters. Module ID, name, type, and setting key are required.'
            ]);
            return;
        }

        // Validate setting key
        if ($settingKey !== 'logging_enabled') {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid setting key. Only logging_enabled is allowed.'
            ]);
            return;
        }

        // Validate setting value
        if (!in_array($settingValue, ['0', '1'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid setting value. Must be 0 or 1.'
            ]);
            return;
        }

        // Check if module exists
        $module = $db->get('modules', ['id', 'name', 'type'], ['id' => $moduleId]);
        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'Module not found'
            ]);
            return;
        }

        // Update database
        $updateData = [$settingKey => $settingValue];
        $result = $db->update('modules', $updateData, ['id' => $moduleId]);

        if ($result === false) {
            throw new Exception('Database update failed');
        }

        $logsDeleted = false;
        $deletedCount = 0;

        // If logging disabled, delete all log files
        if ($settingValue == '0') {
            // Try multiple possible paths
            $possibleLogPaths = [
                __DIR__ . '/../../modules/' . $moduleType . '/' . $moduleName . '/logs/',
                $_SERVER['DOCUMENT_ROOT'] . '/v10/modules/' . $moduleType . '/' . $moduleName . '/logs/',
                $_SERVER['DOCUMENT_ROOT'] . '/modules/' . $moduleType . '/' . $moduleName . '/logs/',
                dirname(__FILE__) . '/../../modules/' . $moduleType . '/' . $moduleName . '/logs/'
            ];

            foreach ($possibleLogPaths as $logsPath) {
                if (file_exists($logsPath) && is_dir($logsPath)) {
                    $files = glob($logsPath . '*.json');

                    if (!empty($files)) {
                        foreach ($files as $file) {
                            if (is_file($file) && unlink($file)) {
                                $deletedCount++;
                            }
                        }
                        $logsDeleted = true;
                    }
                    break;
                }
            }
        }

        // Success response
        $message = $settingValue == '1'
            ? 'Logging enabled successfully'
            : ($logsDeleted
                ? "Logging disabled successfully. Deleted $deletedCount log file(s)."
                : 'Logging disabled successfully');

        echo json_encode([
            'success' => true,
            'message' => $message,
            'logs_deleted' => $logsDeleted,
            'deleted_count' => $deletedCount
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
});

// POST route for log file actions (view, download, delete, delete_all)
$router->post(admin.'/settings/modules/log-action', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    try {
        // Get parameters from POST
        $moduleId = $_POST['module_id'] ?? null;
        $moduleName = $_POST['module_name'] ?? null;
        $moduleType = $_POST['module_type'] ?? null;
        $filename = $_POST['filename'] ?? null;
        $action = $_POST['action'] ?? 'view';

        // Validate required parameters
        if (!$moduleId || !$moduleName || !$moduleType) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Invalid parameters. Module ID, name, and type are required.'
            ]);
            return;
        }

        // Validate action
        if (!in_array($action, ['view', 'download', 'delete', 'delete_all', 'download_all'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action. Allowed: view, download, delete, delete_all, download_all'
            ]);
            return;
        }

        // Check if module exists
        $module = $db->get('modules', ['id', 'name', 'type'], ['id' => $moduleId]);
        if (!$module) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Module not found'
            ]);
            return;
        }

        // Find logs directory
        $logsPath = null;
        $possibleLogPaths = [
            __DIR__ . '/../../modules/' . $moduleType . '/' . $moduleName . '/logs/',
            $_SERVER['DOCUMENT_ROOT'] . '/v10/modules/' . $moduleType . '/' . $moduleName . '/logs/',
            $_SERVER['DOCUMENT_ROOT'] . '/modules/' . $moduleType . '/' . $moduleName . '/logs/',
            dirname(__FILE__) . '/../../modules/' . $moduleType . '/' . $moduleName . '/logs/'
        ];

        foreach ($possibleLogPaths as $path) {
            if (file_exists($path) && is_dir($path)) {
                $logsPath = $path;
                break;
            }
        }

        if (!$logsPath) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Logs directory not found'
            ]);
            return;
        }

        // Handle different actions
        switch ($action) {
            case 'view':
                header('Content-Type: application/json');

                if (!$filename) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Filename is required for view action'
                    ]);
                    return;
                }

                // Security: prevent directory traversal
                $filename = basename($filename);
                $filePath = $logsPath . $filename;

                if (!file_exists($filePath) || !is_file($filePath)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Log file not found: ' . $filename
                    ]);
                    return;
                }

                // Read file content
                $content = file_get_contents($filePath);

                if ($content === false) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Failed to read log file'
                    ]);
                    return;
                }

                echo json_encode([
                    'success' => true,
                    'content' => $content,
                    'filename' => $filename,
                    'size' => filesize($filePath),
                    'date' => date('Y-m-d H:i:s', filemtime($filePath))
                ]);
                break;

            case 'download':
                if (!$filename) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'Filename is required for download action'
                    ]);
                    return;
                }

                // Security: prevent directory traversal
                $filename = basename($filename);
                $filePath = $logsPath . $filename;

                if (!file_exists($filePath) || !is_file($filePath)) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'Log file not found: ' . $filename
                    ]);
                    return;
                }

                // Set headers for download
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($filePath));
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: 0');

                // Output file
                readfile($filePath);
                exit;
                break;

            case 'download_all':
                // Get all log files
                $files = [];
                if (is_dir($logsPath)) {
                    $allFiles = scandir($logsPath);
                    foreach ($allFiles as $file) {
                        if ($file !== '.' && $file !== '..' && is_file($logsPath . $file)) {
                            $files[] = $file;
                        }
                    }
                }

                if (empty($files)) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'No log files found'
                    ]);
                    return;
                }

                // Return list of files to download
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'files' => $files
                ]);
                return;
                break;

            case 'delete':
                header('Content-Type: application/json');

                if (!$filename) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Filename is required for delete action'
                    ]);
                    return;
                }

                // Security: prevent directory traversal
                $filename = basename($filename);
                $filePath = $logsPath . $filename;

                if (!file_exists($filePath) || !is_file($filePath)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Log file not found: ' . $filename
                    ]);
                    return;
                }

                // Delete file
                if (unlink($filePath)) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Log file deleted successfully: ' . $filename
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Failed to delete log file: ' . $filename
                    ]);
                }
                break;

            case 'delete_all':
                header('Content-Type: application/json');

                // Get all JSON files
                $files = glob($logsPath . '*.json');

                if (empty($files)) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'No log files to delete',
                        'deleted_count' => 0
                    ]);
                    return;
                }

                $deletedCount = 0;
                $failedCount = 0;

                foreach ($files as $file) {
                    if (is_file($file)) {
                        if (unlink($file)) {
                            $deletedCount++;
                        } else {
                            $failedCount++;
                        }
                    }
                }

                if ($failedCount > 0) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Deleted $deletedCount file(s), but failed to delete $failedCount file(s)",
                        'deleted_count' => $deletedCount,
                        'failed_count' => $failedCount
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'message' => "Successfully deleted all $deletedCount log file(s)",
                        'deleted_count' => $deletedCount
                    ]);
                }
                break;

            default:
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid action'
                ]);
        }

    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
});

// GET route for log file actions (primarily for download)
$router->get(admin.'/settings/modules/log-action', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    try {
        // Get parameters from GET
        $moduleId = $_GET['module_id'] ?? null;
        $moduleName = $_GET['module_name'] ?? null;
        $moduleType = $_GET['module_type'] ?? null;
        $filename = $_GET['filename'] ?? null;
        $action = $_GET['action'] ?? 'view';

        // Validate required parameters
        if (!$moduleId || !$moduleName || !$moduleType) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Invalid parameters. Module ID, name, and type are required.'
            ]);
            return;
        }

        // Validate action
        if (!in_array($action, ['view', 'download', 'delete', 'delete_all'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action. Allowed: view, download, delete, delete_all'
            ]);
            return;
        }

        // Check if module exists
        $module = $db->get('modules', ['id', 'name', 'type'], ['id' => $moduleId]);
        if (!$module) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Module not found'
            ]);
            return;
        }

        // Find logs directory
        $logsPath = null;
        $possibleLogPaths = [
            __DIR__ . '/../../modules/' . $moduleType . '/' . $moduleName . '/logs/',
            $_SERVER['DOCUMENT_ROOT'] . '/v10/modules/' . $moduleType . '/' . $moduleName . '/logs/',
            $_SERVER['DOCUMENT_ROOT'] . '/modules/' . $moduleType . '/' . $moduleName . '/logs/',
            dirname(__FILE__) . '/../../modules/' . $moduleType . '/' . $moduleName . '/logs/'
        ];

        foreach ($possibleLogPaths as $path) {
            if (file_exists($path) && is_dir($path)) {
                $logsPath = $path;
                break;
            }
        }

        if (!$logsPath) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Logs directory not found'
            ]);
            return;
        }

        // Handle different actions
        switch ($action) {
            case 'view':
                header('Content-Type: application/json');

                if (!$filename) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Filename is required for view action'
                    ]);
                    return;
                }

                // Security: prevent directory traversal
                $filename = basename($filename);
                $filePath = $logsPath . $filename;

                if (!file_exists($filePath) || !is_file($filePath)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Log file not found: ' . $filename
                    ]);
                    return;
                }

                // Read file content
                $content = file_get_contents($filePath);

                if ($content === false) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Failed to read log file'
                    ]);
                    return;
                }

                echo json_encode([
                    'success' => true,
                    'content' => $content,
                    'filename' => $filename,
                    'size' => filesize($filePath),
                    'date' => date('Y-m-d H:i:s', filemtime($filePath))
                ]);
                break;

            case 'download':
                if (!$filename) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'Filename is required for download action'
                    ]);
                    return;
                }

                // Security: prevent directory traversal
                $filename = basename($filename);
                $filePath = $logsPath . $filename;

                if (!file_exists($filePath) || !is_file($filePath)) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'Log file not found: ' . $filename
                    ]);
                    return;
                }

                // Set headers for download
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($filePath));
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: 0');

                // Output file
                readfile($filePath);
                exit;
                break;

            case 'delete':
                header('Content-Type: application/json');

                if (!$filename) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Filename is required for delete action'
                    ]);
                    return;
                }

                // Security: prevent directory traversal
                $filename = basename($filename);
                $filePath = $logsPath . $filename;

                if (!file_exists($filePath) || !is_file($filePath)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Log file not found: ' . $filename
                    ]);
                    return;
                }

                // Delete file
                if (unlink($filePath)) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Log file deleted successfully: ' . $filename
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Failed to delete log file: ' . $filename
                    ]);
                }
                break;

            case 'delete_all':
                header('Content-Type: application/json');

                // Get all JSON files
                $files = glob($logsPath . '*.json');

                if (empty($files)) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'No log files to delete',
                        'deleted_count' => 0
                    ]);
                    return;
                }

                $deletedCount = 0;
                $failedCount = 0;

                foreach ($files as $file) {
                    if (is_file($file)) {
                        if (unlink($file)) {
                            $deletedCount++;
                        } else {
                            $failedCount++;
                        }
                    }
                }

                if ($failedCount > 0) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Deleted $deletedCount file(s), but failed to delete $failedCount file(s)",
                        'deleted_count' => $deletedCount,
                        'failed_count' => $failedCount
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'message' => "Successfully deleted all $deletedCount log file(s)",
                        'deleted_count' => $deletedCount
                    ]);
                }
                break;

            default:
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid action'
                ]);
        }

    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
});

// POST route for Hotelbeds-specific settings update (for mTLS toggle)
$router->post(admin.'/settings/modules/hotelbeds/update-setting', function () use ($SECURE,$db) {

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    CSRF::guard();

    // Set JSON header
    header('Content-Type: application/json');

    try {
        // Get POST data
        $moduleId = $_POST['module_id'] ?? null;
        $settingKey = $_POST['setting_key'] ?? null;
        $settingValue = $_POST['setting_value'] ?? null;

        // Validate required parameters
        if (!$moduleId || !$settingKey || $settingValue === null) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid parameters. Module ID, setting key, and value are required.'
            ]);
            return;
        }

        // Check if module exists and is Hotelbeds
        $module = $db->get('modules', ['id', 'name', 'type'], ['id' => $moduleId]);
        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'Module not found'
            ]);
            return;
        }

        if (strtolower($module['name']) !== 'hotelbeds') {
            echo json_encode([
                'success' => false,
                'message' => 'This endpoint is only for Hotelbeds module'
            ]);
            return;
        }

        // Validate setting key (whitelist allowed settings)
        $fileSettings = ['use_mtls', 'allow_packaging_rates'];
        $allowedSettings = array_merge($fileSettings, ['logging_enabled']);
        if (!in_array($settingKey, $allowedSettings, true)) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid setting key'
            ]);
            return;
        }

        // Hotelbeds file-based flags live in settings.json (runtime source of truth)
        if (in_array($settingKey, $fileSettings, true)) {
            if (!function_exists('updateHotelbedsSetting')) {
                throw new Exception('Hotelbeds settings helper unavailable');
            }
            $result = updateHotelbedsSetting($settingKey, (int) $settingValue);
            if ($result === false) {
                throw new Exception('Failed to write Hotelbeds settings file');
            }
        } else {
            // Update database (e.g. logging_enabled)
            $updateData = [$settingKey => $settingValue];
            $result = $db->update('modules', $updateData, ['id' => $moduleId]);

            if ($result === false) {
                throw new Exception('Database update failed');
            }
        }

        echo json_encode([
            'success' => true,
            'message' => ucfirst(str_replace('_', ' ', $settingKey)) . ' updated successfully'
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
});

$router->get('/sitemap.xml', function () use ($db) {
    $sitemap_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'global' . DIRECTORY_SEPARATOR . 'sitemap.xml';

    // Auto-generate if not exists
    if (!file_exists($sitemap_path)) {
        $result = generateSitemap($db);
        $xml = $result['xml'] ?? '';
    } else {
        $xml = file_get_contents($sitemap_path);
        // Ensure stylesheet exists in loaded file too (backward compat)
        if (strpos($xml, 'sitemap.xsl') === false) {
             $style_line = '<?xml-stylesheet type="text/xsl" href="' . root . 'uploads/global/sitemap.xsl"?>' . PHP_EOL;
             $xml = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . $style_line, $xml);
        }
    }

    header('Content-Type: application/xml');
    echo $xml;
    exit;
});