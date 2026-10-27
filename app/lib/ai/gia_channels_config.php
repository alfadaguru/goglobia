<?php
// ============================================================================
// FILE: app/lib/ai/gia_channels_config.php
// Admin-owned configuration for GIA's messaging channels (WhatsApp / Messenger /
// Telegram). Stored as ONE JSON blob in settings.gia_channels_config — same
// house pattern as settings.ai_configuration / whatsapp_providers_config.
//
// Admin has FULL control: per-channel enable kill-switch, credentials, the
// webhook secrets, and (surfaced in the admin UI) the webhook URL + verify
// token to paste into each provider console. Nothing here sends/receives — it
// is pure config read/normalize/write. Readers: the webhook adapters + the
// outbound senders. Writer: the admin GIA-Channels panel (ADMIN_AUTH + CSRF).
// ============================================================================

if (!function_exists('gia_channels_ensure_schema')) {
    /** Idempotently add settings.gia_channels_config (JSON, LONGTEXT fallback). */
    function gia_channels_ensure_schema($db): void
    {
        if (!function_exists('settingsHasColumn')) {
            return; // helper lives in functions.php; config.php loads it first
        }
        if (settingsHasColumn($db, 'gia_channels_config')) {
            return;
        }
        try {
            $db->query("ALTER TABLE `settings` ADD COLUMN `gia_channels_config` JSON NULL DEFAULT NULL");
        } catch (\Throwable $e) {
            try {
                $db->query("ALTER TABLE `settings` ADD COLUMN `gia_channels_config` LONGTEXT NULL DEFAULT NULL");
            } catch (\Throwable $e2) {
                error_log('[gia] could not add gia_channels_config column: ' . $e2->getMessage());
            }
        }
    }
}

if (!function_exists('gia_channels_defaults')) {
    /** The full, normalized shape with every channel present and OFF by default. */
    function gia_channels_defaults(): array
    {
        return [
            'whatsapp' => [
                'enabled'          => false,
                // 'greenapi' (uses settings.whatsapp_providers_config creds for SEND)
                // or 'meta' (Cloud API). Inbound adapter supports BOTH shapes.
                'mode'             => 'greenapi',
                // Green API interactive reply-buttons are BETA ("may work unstably"
                // per Green API) and capped at 3; its list method is dead (403). So
                // this is OFF by default — choices render as reliable NUMBERED TEXT.
                // Turn ON (admin) to try tappable buttons for <=3-choice moments;
                // >3 choices always stay numbered text. Meta mode ignores this (it
                // has stable native buttons+lists).
                'greenapi_interactive_buttons' => false,
                // Shared secret we require on the inbound URL (?secret=) for Green API,
                // since Green API does not sign its webhooks.
                'webhook_secret'   => '',
                // Meta Cloud API (mode=meta): verify token (GET handshake) + app secret
                // (X-Hub-Signature-256) + the phone-number creds for SEND.
                'meta_verify_token'  => '',
                'meta_app_secret'    => '',
                'meta_phone_number_id' => '',
                'meta_access_token'    => '',
            ],
            'messenger' => [
                'enabled'      => false,
                'page_id'      => '',
                'page_token'   => '',   // Page access token (SEND)
                'app_secret'   => '',   // X-Hub-Signature-256 verification
                'verify_token' => '',   // GET handshake
            ],
            'telegram' => [
                'enabled'        => false,
                'bot_token'      => '',   // BotFather token (SEND + identifies the bot)
                // Telegram signs webhooks via a secret header we set at setWebhook time.
                'webhook_secret' => '',
            ],
        ];
    }
}

if (!function_exists('gia_channels_config')) {
    /**
     * Read + normalize the channels config. Always returns the full shape (every
     * channel present) merged over defaults, so callers can read keys safely.
     */
    function gia_channels_config($db): array
    {
        gia_channels_ensure_schema($db);
        $raw = function_exists('settingsHasColumn') && settingsHasColumn($db, 'gia_channels_config')
            ? $db->get('settings', 'gia_channels_config', ['id' => 1])
            : null;

        $data = [];
        if (is_array($raw)) {
            $data = $raw; // Medoo may return JSON columns pre-decoded
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $defaults = gia_channels_defaults();
        $out = [];
        foreach ($defaults as $chan => $defFields) {
            $given = is_array($data[$chan] ?? null) ? $data[$chan] : [];
            $merged = array_merge($defFields, $given);
            // Normalize the enable flag to a real bool.
            $merged['enabled'] = !empty($given['enabled']) && filter_var($given['enabled'], FILTER_VALIDATE_BOOL);
            $out[$chan] = $merged;
        }
        return $out;
    }
}

if (!function_exists('gia_channel_enabled')) {
    /** True iff this channel is configured AND turned on by the admin. */
    function gia_channel_enabled($db, string $channel): bool
    {
        $cfg = gia_channels_config($db);
        $c = $cfg[strtolower(trim($channel))] ?? null;
        return is_array($c) && !empty($c['enabled']);
    }
}

if (!function_exists('gia_channels_save')) {
    /**
     * Persist the channels config (admin panel only — caller enforces ADMIN_AUTH
     * + CSRF). Merges the given per-channel fields over what is stored so a save
     * of one channel never wipes another. Returns true on success.
     */
    function gia_channels_save($db, array $incoming): bool
    {
        gia_channels_ensure_schema($db);
        try {
            $current = gia_channels_config($db);
            $defaults = gia_channels_defaults();
            $merged = [];
            foreach ($defaults as $chan => $defFields) {
                $base = $current[$chan] ?? $defFields;
                $in   = is_array($incoming[$chan] ?? null) ? $incoming[$chan] : [];
                // Only overwrite known fields; coerce the BOOLEAN fields to bool.
                // Checkboxes that are unchecked do NOT post, so for a known boolean
                // field we must treat its ABSENCE as false (otherwise it could never
                // be turned off). 'enabled' and 'greenapi_interactive_buttons' are
                // the boolean fields in the shape.
                $boolFields = ['enabled', 'greenapi_interactive_buttons'];
                $row = $base;
                foreach ($defFields as $k => $_) {
                    if (in_array($k, $boolFields, true)) {
                        // Only auto-default-to-false when the form actually submitted
                        // THIS channel's section (so a partial save of another channel
                        // doesn't silently clear this one). Presence of any key in $in
                        // signals this channel's form was posted.
                        if ($in !== []) {
                            $row[$k] = array_key_exists($k, $in)
                                ? (bool) filter_var($in[$k], FILTER_VALIDATE_BOOL)
                                : false;
                        }
                        continue;
                    }
                    if (array_key_exists($k, $in)) {
                        $row[$k] = is_string($in[$k]) ? trim($in[$k]) : $in[$k];
                    }
                }
                $merged[$chan] = $row;
            }
            $db->update('settings', ['gia_channels_config' => json_encode($merged)], ['id' => 1]);
            return true;
        } catch (\Throwable $e) {
            error_log('[gia] channels_save: ' . $e->getMessage());
            return false;
        }
    }
}
