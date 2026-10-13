<?php
// ============================================================================
// STAYS SITE DEFAULTS - Domain fallback generation
// ============================================================================

if (!function_exists('stays_get_effective_domain_root')) {
    function stays_get_effective_domain_root(): string {
        $adminConfigured = trim((string) ($GLOBALS['app']['supplier_site_domain'] ?? ''));
        if ($adminConfigured !== '') {
            return $adminConfigured;
        }
        $platformDefault = trim((string) ($GLOBALS['app']['supplier_temp_domain'] ?? 'goglobia.app'));
        if ($platformDefault !== '') {
            return $platformDefault;
        }
        $requestHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        if (strpos($requestHost, ':') !== false) {
            $requestHost = substr($requestHost, 0, strpos($requestHost, ':'));
        }
        return $requestHost;
    }
}

if (!function_exists('stays_generate_default_hostname')) {
    function stays_generate_default_hostname(string $slug): string {
        $root = stays_get_effective_domain_root();
        $slug = strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $slug), '-'));
        if ($root === '' || $slug === '') {
            return '';
        }
        return $slug . '.' . $root;
    }
}

