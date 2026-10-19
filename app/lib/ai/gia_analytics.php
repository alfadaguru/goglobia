<?php
// ============================================================================
// FILE: app/lib/ai/gia_analytics.php
// GIA analytics + CRM foundation: persistent conversations, messages, events,
// and captured leads. This is the data layer the admin GIA reports read and the
// cross-tab/device chat resume relies on.
//
// Design:
//  - gia_conversations : one row per ongoing chat, keyed by a stable browser_id
//    cookie; linked to a users.user_id (string) and/or a gia_leads row once known.
//  - gia_messages      : every turn (user + assistant), for resume + transcript.
//  - gia_events        : structured outcomes (search / view / checkout / approve /
//    pay / book / lead_capture) so reports can build a real conversion funnel.
//  - gia_leads         : captured name/email/phone; match-or-create against users.
//
// All writes are FAIL-SOFT: logging must NEVER break the chat. Self-healing via
// ensureGiaAnalyticsSchema() (mirrors ensureGiaCheckoutSchema). Also reflected in
// install/db.sql.
// ============================================================================

if (!function_exists('ensureGiaAnalyticsSchema')) {
    /**
     * Idempotent — creates the GIA analytics/CRM tables if missing. House
     * `CREATE TABLE IF NOT EXISTS` pattern. Update install/db.sql when this ships.
     */
    function ensureGiaAnalyticsSchema($db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `gia_leads` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(160) DEFAULT NULL,
                `email` varchar(255) DEFAULT NULL,
                `phone` varchar(40) DEFAULT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `status` enum('lead','linked','converted') NOT NULL DEFAULT 'lead',
                `source` varchar(40) NOT NULL DEFAULT 'gia',
                `context` varchar(40) DEFAULT NULL,
                `note` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `email` (`email`),
                KEY `phone` (`phone`),
                KEY `user_id` (`user_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `gia_conversations` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `conv_key` varchar(64) NOT NULL,
                `browser_id` varchar(64) DEFAULT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `lead_id` int(11) DEFAULT NULL,
                `context` varchar(40) DEFAULT NULL,
                `surface` varchar(20) NOT NULL DEFAULT 'widget',
                `msg_count` int(11) NOT NULL DEFAULT 0,
                `token_in` int(11) NOT NULL DEFAULT 0,
                `token_out` int(11) NOT NULL DEFAULT 0,
                `search_count` int(11) NOT NULL DEFAULT 0,
                `booking_count` int(11) NOT NULL DEFAULT 0,
                `revenue` decimal(14,2) NOT NULL DEFAULT 0.00,
                `revenue_currency` varchar(10) DEFAULT NULL,
                `last_message` varchar(500) DEFAULT NULL,
                `started_at` datetime NOT NULL DEFAULT current_timestamp(),
                `last_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `conv_key` (`conv_key`),
                KEY `browser_id` (`browser_id`),
                KEY `user_id` (`user_id`),
                KEY `lead_id` (`lead_id`),
                KEY `last_at` (`last_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `gia_messages` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `conv_id` int(11) NOT NULL,
                `role` enum('user','assistant') NOT NULL,
                `text` mediumtext DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `conv_id` (`conv_id`),
                KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `gia_events` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `conv_id` int(11) DEFAULT NULL,
                `type` varchar(24) NOT NULL,
                `module` varchar(24) DEFAULT NULL,
                `ref` varchar(120) DEFAULT NULL,
                `amount` decimal(14,2) DEFAULT NULL,
                `currency` varchar(10) DEFAULT NULL,
                `meta` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `conv_id` (`conv_id`),
                KEY `type` (`type`),
                KEY `module` (`module`),
                KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (\Throwable $e) {
            error_log('[gia] ensureGiaAnalyticsSchema: ' . $e->getMessage());
        }
        $done = true;
    }
}
