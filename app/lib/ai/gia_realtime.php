<?php

namespace App\lib\ai;

// ============================================================================
// FILE: app/lib/ai/gia_realtime.php
// GIA realtime (Pusher) for human handoff — pushes a staff reply to the exact
// traveller's chat instantly, instead of waiting for the widget's 6s poll.
//
// Reuses the platform's existing Pusher credentials (settings.push_providers_config,
// the same ones the admin "push notifications" provider uses). It is PURELY an
// enhancement: if Pusher isn't configured or the trigger fails, nothing breaks —
// the widget's polling fallback still delivers the reply within a few seconds.
//
// Channel per conversation: "gia-conv-{conv_key}" (conv_key is an opaque 32-hex
// token, so it's unguessable — fine as a public channel for a staff reply that
// the traveller is already seeing in their own chat). Event: "gia-staff-reply".
// ============================================================================

if (!function_exists('App\\lib\\ai\\gia_pusher_public_config')) {
    /**
     * The PUBLIC Pusher bits the widget needs to subscribe (key + cluster only —
     * never the secret/app_id). Returns [] when Pusher isn't configured, so the
     * widget simply keeps polling.
     *
     * @return array{key?:string, cluster?:string}
     */
    function gia_pusher_public_config($db): array
    {
        try {
            $row = $db->get('settings', ['push_providers_config'], ['id' => 1]);
            $cfg = json_decode((string) ($row['push_providers_config'] ?? '{}'), true);
            if (!is_array($cfg)) {
                return [];
            }
            // The admin stores providers keyed by name (e.g. 'pusher' => {...}),
            // or sometimes flat. Handle both without assuming a single shape.
            $p = is_array($cfg['pusher'] ?? null) ? $cfg['pusher'] : $cfg;
            $key = trim((string) ($p['key'] ?? ''));
            $cluster = trim((string) ($p['cluster'] ?? 'us2'));
            if ($key === '') {
                return [];
            }
            return ['key' => $key, 'cluster' => $cluster !== '' ? $cluster : 'us2'];
        } catch (\Throwable $e) {
            error_log('[gia] pusher public config: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_realtime_staff_reply')) {
    /**
     * Push a staff reply to a conversation's channel. Best-effort / fail-soft.
     *
     * @param object $db
     * @param string $convKey  the conversation's opaque key (channel suffix)
     * @param string $text     the staff message text
     * @return bool            true if the event was triggered
     */
    function gia_realtime_staff_reply($db, string $convKey, string $text): bool
    {
        if ($convKey === '') {
            return false;
        }
        try {
            $row = $db->get('settings', ['push_providers_config'], ['id' => 1]);
            $cfg = json_decode((string) ($row['push_providers_config'] ?? '{}'), true);
            if (!is_array($cfg)) {
                return false;
            }
            $p = is_array($cfg['pusher'] ?? null) ? $cfg['pusher'] : $cfg;
            $key = trim((string) ($p['key'] ?? ''));
            $secret = trim((string) ($p['secret'] ?? ''));
            $appId = trim((string) ($p['app_id'] ?? ''));
            $cluster = trim((string) ($p['cluster'] ?? 'us2')) ?: 'us2';
            if ($key === '' || $secret === '' || $appId === '') {
                return false; // not configured -> polling fallback handles it
            }

            $channel = 'gia-conv-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $convKey);
            return gia_pusher_rest_trigger($key, $secret, $appId, $cluster, $channel, 'gia-staff-reply', [
                'text' => $text,
                'ts'   => time(),
            ]);
        } catch (\Throwable $e) {
            error_log('[gia] realtime staff reply: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_pusher_rest_trigger')) {
    /**
     * Trigger a Pusher event via its REST API directly — no SDK required (the SDK
     * is just a wrapper around this signed HTTP POST). Keeps realtime working on
     * this install, which has no composer.json to add pusher-php-server.
     *
     * Signing spec (Pusher): POST to https://api-{cluster}.pusher.com/apps/{app_id}/events
     * with query params auth_key, auth_timestamp, auth_version=1.0, body_md5; the
     * auth_signature is HMAC-SHA256(secret) over "POST\n{path}\n{sorted-query}".
     *
     * @param array<string,mixed> $data  event payload (json-encoded as the body)
     * @return bool true on a 2xx from Pusher
     */
    function gia_pusher_rest_trigger(string $key, string $secret, string $appId, string $cluster, string $channel, string $event, array $data): bool
    {
        $cluster = $cluster !== '' ? $cluster : 'us2';
        $body = json_encode([
            'name'     => $event,
            'channel'  => $channel,
            'data'     => json_encode($data), // Pusher expects the data as a STRING
        ]);
        if ($body === false) {
            return false;
        }

        $path = '/apps/' . rawurlencode($appId) . '/events';
        $params = [
            'auth_key'       => $key,
            'auth_timestamp' => (string) time(),
            'auth_version'   => '1.0',
            'body_md5'       => md5($body),
        ];
        ksort($params);
        $queryParts = [];
        foreach ($params as $k => $v) {
            $queryParts[] = $k . '=' . $v; // Pusher signs the UN-encoded query string
        }
        $queryString = implode('&', $queryParts);

        $toSign = "POST\n" . $path . "\n" . $queryString;
        $signature = hash_hmac('sha256', $toSign, $secret);

        $url = 'https://api-' . rawurlencode($cluster) . '.pusher.com' . $path
            . '?' . $queryString . '&auth_signature=' . $signature;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0) {
            error_log('[gia] pusher rest curl errno=' . $errno);
            return false;
        }
        return $code >= 200 && $code < 300;
    }
}
