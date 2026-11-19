<?php
// FILE: app/lib/notifications_centre.php
// Lightweight in-app notification store for CUSTOMERS. This is the persisted
// "bell" inbox — distinct from sendNotification()/NOTIFY (email/SMS/WhatsApp
// delivery, which do not persist). Other code (price alerts, booking events)
// calls notify_user() to drop a row; the header bell + /notifications page read
// it. Self-heals its own table (same idempotent pattern as the other ensure_*).

if (!function_exists('ensureNotificationsCentreSchema')) {
    function ensureNotificationsCentreSchema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `user_notifications` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` VARCHAR(64) NOT NULL,
                `type` VARCHAR(40) NOT NULL DEFAULT 'general',
                `title` VARCHAR(255) NOT NULL,
                `body` TEXT NULL,
                `url` VARCHAR(512) NULL,
                `icon` VARCHAR(48) NULL,
                `ref_type` VARCHAR(40) NULL,
                `ref_id` VARCHAR(64) NULL,
                `dedupe_key` VARCHAR(191) NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `read_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_un_user` (`user_id`, `is_read`, `id`),
                KEY `idx_un_dedupe` (`user_id`, `dedupe_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('ensureNotificationsCentreSchema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('notify_user')) {
    /**
     * Drop an in-app notification for a customer. Idempotent when a dedupe_key is
     * given (a repeat with the same key for the same user is a no-op) so a cron
     * that re-runs can't spam the bell. Returns the new row id, or 0 on no-op/fail.
     *
     * @param array $opts type,title,body,url,icon,ref_type,ref_id,dedupe_key
     */
    function notify_user($db, string $userId, array $opts): int
    {
        $userId = trim($userId);
        $title  = trim((string) ($opts['title'] ?? ''));
        if ($userId === '' || $title === '') { return 0; }
        ensureNotificationsCentreSchema($db);

        $dedupe = isset($opts['dedupe_key']) ? substr(trim((string) $opts['dedupe_key']), 0, 191) : '';
        try {
            if ($dedupe !== '') {
                $exists = $db->get('user_notifications', 'id', ['user_id' => $userId, 'dedupe_key' => $dedupe]);
                if ($exists) { return 0; }
            }
            $db->insert('user_notifications', [
                'user_id'    => $userId,
                'type'       => substr((string) ($opts['type'] ?? 'general'), 0, 40),
                'title'      => substr($title, 0, 255),
                'body'       => isset($opts['body']) ? substr((string) $opts['body'], 0, 2000) : null,
                'url'        => isset($opts['url']) ? substr((string) $opts['url'], 0, 512) : null,
                'icon'       => isset($opts['icon']) ? substr((string) $opts['icon'], 0, 48) : null,
                'ref_type'   => isset($opts['ref_type']) ? substr((string) $opts['ref_type'], 0, 40) : null,
                'ref_id'     => isset($opts['ref_id']) ? substr((string) $opts['ref_id'], 0, 64) : null,
                'dedupe_key' => $dedupe !== '' ? $dedupe : null,
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) {
            error_log('notify_user: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('notifications_unread_count')) {
    function notifications_unread_count($db, string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') { return 0; }
        ensureNotificationsCentreSchema($db);
        try { return (int) $db->count('user_notifications', ['user_id' => $userId, 'is_read' => 0]); }
        catch (\Throwable $e) { return 0; }
    }
}

if (!function_exists('notifications_list')) {
    /** Most-recent first, paginated. Returns ['rows'=>, 'total'=>, 'unread'=>]. */
    function notifications_list($db, string $userId, int $limit = 20, int $offset = 0): array
    {
        $userId = trim($userId);
        if ($userId === '') { return ['rows' => [], 'total' => 0, 'unread' => 0]; }
        ensureNotificationsCentreSchema($db);
        try {
            $total  = (int) $db->count('user_notifications', ['user_id' => $userId]);
            $unread = (int) $db->count('user_notifications', ['user_id' => $userId, 'is_read' => 0]);
            $rows   = $db->select('user_notifications',
                ['id', 'type', 'title', 'body', 'url', 'icon', 'is_read', 'created_at'],
                ['user_id' => $userId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => [max(0, $offset), max(1, min(100, $limit))]]
            ) ?: [];
            return ['rows' => $rows, 'total' => $total, 'unread' => $unread];
        } catch (\Throwable $e) {
            error_log('notifications_list: ' . $e->getMessage());
            return ['rows' => [], 'total' => 0, 'unread' => 0];
        }
    }
}

if (!function_exists('notifications_mark_read')) {
    /** Mark one (id>0) or ALL ($id=0) of the user's notifications read. Scoped. */
    function notifications_mark_read($db, string $userId, int $id = 0): bool
    {
        $userId = trim($userId);
        if ($userId === '') { return false; }
        ensureNotificationsCentreSchema($db);
        try {
            $where = ['user_id' => $userId, 'is_read' => 0];
            if ($id > 0) { $where['id'] = $id; }
            $db->update('user_notifications', ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], $where);
            return true;
        } catch (\Throwable $e) {
            error_log('notifications_mark_read: ' . $e->getMessage());
            return false;
        }
    }
}
