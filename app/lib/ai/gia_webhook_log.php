<?php
// ============================================================================
// FILE: app/lib/ai/gia_webhook_log.php
// A lightweight, self-trimming log of INBOUND GIA channel webhooks (WhatsApp /
// Telegram / Messenger). Its whole job is to let the operator SEE what each
// provider actually sent and what we did with it — the missing visibility when
// "nothing happens" after a message.
//
// Each row: channel, outcome (ok|disabled|bad_secret|secret_required|ignored|
// bad_signature|error), the parsed sender/kind when known, and a short,
// redacted snippet of the raw body for debugging. Secrets/tokens never stored.
// Readers: the admin Channels panel. Writer: the webhook handlers.
// ============================================================================

if (!function_exists('gia_webhook_log_ensure_schema')) {
    /** Idempotently create the gia_webhook_log table (follows the house pattern). */
    function gia_webhook_log_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        try {
            $db->query(
                "CREATE TABLE IF NOT EXISTS `gia_webhook_log` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `channel` VARCHAR(20) NOT NULL DEFAULT '',
                    `outcome` VARCHAR(24) NOT NULL DEFAULT '',
                    `http_code` SMALLINT NOT NULL DEFAULT 0,
                    `event_type` VARCHAR(48) NOT NULL DEFAULT '',
                    `sender` VARCHAR(48) NOT NULL DEFAULT '',
                    `kind` VARCHAR(24) NOT NULL DEFAULT '',
                    `text_preview` VARCHAR(255) NOT NULL DEFAULT '',
                    `raw_preview` VARCHAR(1000) NOT NULL DEFAULT '',
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_gia_whlog_chan_time` (`channel`, `id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            error_log('[gia] could not create gia_webhook_log: ' . $e->getMessage());
        }
        $done = true;
    }
}

if (!function_exists('gia_webhook_log_record')) {
    /**
     * Record one inbound webhook hit. Best-effort — never throws, never blocks the
     * webhook response. `$meta` may carry: event_type, sender, kind, text, raw.
     * The raw body is truncated and lightly redacted (strips obvious token-like
     * query bits) before storage.
     */
    function gia_webhook_log_record($db, string $channel, string $outcome, int $httpCode, array $meta = []): void
    {
        try {
            gia_webhook_log_ensure_schema($db);

            $text = (string) ($meta['text'] ?? '');
            $raw  = (string) ($meta['raw'] ?? '');
            // Redact anything that looks like a secret/token in the snippet.
            $raw = preg_replace('/("?(?:token|secret|apiToken[A-Za-z]*|access_token)"?\s*[:=]\s*")[^"]*(")/i', '$1***$2', $raw);
            $raw = preg_replace('/([?&](?:secret|token)=)[^&\s"]+/i', '$1***', $raw);

            $db->insert('gia_webhook_log', [
                'channel'      => substr($channel, 0, 20),
                'outcome'      => substr($outcome, 0, 24),
                'http_code'    => $httpCode,
                'event_type'   => substr((string) ($meta['event_type'] ?? ''), 0, 48),
                'sender'       => substr((string) ($meta['sender'] ?? ''), 0, 48),
                'kind'         => substr((string) ($meta['kind'] ?? ''), 0, 24),
                'text_preview' => substr($text, 0, 255),
                'raw_preview'  => substr($raw, 0, 1000),
            ]);

            // Self-trim: keep the most recent ~500 rows so the table stays tiny.
            // Cheap and runs rarely enough; wrapped so a failure is harmless.
            $count = (int) $db->count('gia_webhook_log');
            if ($count > 600) {
                $cutoff = $db->get('gia_webhook_log', 'id', ['ORDER' => ['id' => 'DESC'], 'LIMIT' => [500, 1]]);
                if ($cutoff) {
                    $db->delete('gia_webhook_log', ['id[<]' => (int) $cutoff]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[gia] webhook log write failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_webhook_log_recent')) {
    /**
     * Return the most recent log rows (newest first), capped. Optional channel
     * filter. Returns [] on any error so the UI degrades gracefully.
     * @return array<int,array<string,mixed>>
     */
    function gia_webhook_log_recent($db, int $limit = 40, string $channel = ''): array
    {
        try {
            gia_webhook_log_ensure_schema($db);
            $where = ['ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(200, $limit))];
            if ($channel !== '') { $where['channel'] = $channel; }
            $rows = $db->select('gia_webhook_log',
                ['id', 'channel', 'outcome', 'http_code', 'event_type', 'sender', 'kind', 'text_preview', 'raw_preview', 'created_at'],
                $where
            );
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            error_log('[gia] webhook log read failed: ' . $e->getMessage());
            return [];
        }
    }
}
