<?php

/**
 * Updates System Configuration
 *
 * Single centralized configuration file for the entire updates system.
 * GitHub credentials are NOT stored here - they are fetched at runtime from
 * the remote credentials endpoint (see $credentialsUrl below) so they can be
 * rotated without shipping an update to every installation.
 *
 * @package v10
 * @version 1.1.0
 */

// Prevent direct access
if (!defined('SECURE') && basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    die('Access Denied!');
}

// GET VERSION FROM DATABASE
$v = $db->get("settings", "version", ["id" => 1]);

// ==================================================
// REMOTE CREDENTIALS
// ==================================================

/**
 * Remote JSON holding github_repo / github_token.
 * Fetched live over cURL on every load - nothing is cached locally, so a
 * rotated token on phptravels.com takes effect immediately.
 */
$credentialsUrl = 'https://update.goglobia.com/updates.json';

if (!function_exists('v10_updates_credentials')) {
    /**
     * Fetch update credentials from the remote endpoint.
     *
     * @return array{github_repo?:string, github_token?:string, default_branch?:string, allowed_repos?:array}
     */
    function v10_updates_credentials($url)
    {
        if (!function_exists('curl_init')) {
            return [];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'v10-Updates-Client',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            return [];
        }

        $decoded = json_decode($body, true);

        // Accept both flat JSON and a { "credentials": {...} } wrapper
        if (is_array($decoded) && isset($decoded['credentials']) && is_array($decoded['credentials'])) {
            $decoded = $decoded['credentials'];
        }

        if (!is_array($decoded) || empty($decoded['github_token']) || empty($decoded['github_repo'])) {
            return [];
        }

        return $decoded;
    }
}

$credentials = v10_updates_credentials($credentialsUrl);

return [

    // ==================================================
    // GITHUB REPOSITORY SETTINGS (REMOTE)
    // ==================================================

    /**
     * Pulled from https://update.goglobia.com/updates.json - never hardcoded here.
     */
    'github_repo'  => $credentials['github_repo']  ?? '',
    'github_token' => $credentials['github_token'] ?? '',

    /**
     * Endpoint, exposed for diagnostics
     */
    'credentials_url' => $credentialsUrl,

    /**
     * Default branch to check for updates
     * Usually: 'main' or 'master'
     * Remote value wins so the branch can be switched centrally.
     */
    'default_branch' => $credentials['default_branch'] ?? 'main',

    // ==================================================
    // UPDATE FILTERING & BEHAVIOR
    // ==================================================

    /**
     * Only show updates after this date
     * Format: 'YYYY-MM-DD' or 'YYYY-MM-DD HH:MM:SS'
     * Set to null to show all updates
     * Example: '2025-01-01' will only show updates from Jan 1, 2025 onwards
     */
    'updates_start_date' => '2026-08-31',

    /**
     * Sequential installation mode
     * When true: Only allows installing updates in chronological order (oldest first)
     * When false: Allows installing any update regardless of order
     */
    'sequential_installation' => true,

    /**
     * Hide commit body/description for these SHAs (subject/first line still shown).
     * Use when a commit accidentally has a long or unwanted description.
     * Full SHA or short SHA both work.
     */
    'hide_commit_descriptions' => [
        '744ad8a', // RateHawk Certification – mistaken long description
    ],

    // ==================================================
    // VERSION & SYSTEM INFO
    // ==================================================

    /**
     * Current application version
     * Use semantic versioning: MAJOR.MINOR.PATCH
     */

    'current_version' => $v,

    // ==================================================
    // BACKUP SETTINGS
    // ==================================================

    /**
     * Automatically backup files before update
     */
    'auto_backup' => true,

    /**
     * Backup directory path (relative to root)
     */
    'backup_dir' => 'backups/updates',

    /**
     * Maximum number of backups to keep
     * Older backups will be automatically deleted
     */
    'max_backups' => 10,

    // ==================================================
    // SECURITY & EXCLUSIONS
    // ==================================================

    /**
     * Require administrator confirmation before installation
     */
    'require_confirmation' => true,

    /**
     * Files/directories to exclude from updates
     * These will never be replaced during updates
     */
    'excluded_files' => [
        'app/views/admin/updates-config.php',
        'app/updates.json',
        '.env',
        'uploads/',
        'cache/',
        'backups/',
    ],

    /**
     * Verify SSL certificates when connecting to GitHub
     */
    'verify_ssl' => true,

    /**
     * Allowed update sources
     * Only updates from these repositories will be accepted
     */
    'allowed_repos' => $credentials['allowed_repos'] ?? array_filter([$credentials['github_repo'] ?? '']),

    // ==================================================
    // NOTIFICATIONS & AUTOMATION
    // ==================================================

    /**
     * Enable update notifications
     */
    'notifications_enabled' => true,

    /**
     * Check for updates automatically (requires cron setup)
     */
    'auto_check' => false,

    /**
     * Auto-check interval in seconds
     * Default: 86400 (24 hours)
     */
    'check_interval' => 86400,

    /**
     * Require signature verification (future feature)
     */
    'require_signature' => false,

];
