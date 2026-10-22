<?php
// ============================================================================
// FILE: app/lib/ai/gia_pt_web.php
// WEB-native pilgrim collection for GIA (the chat WIDGET on goglobia.com).
//
// The channel entry point gia_pt_handle_channel_message() resolves its
// conversation via gia_conv_get_or_create_channel(channel, channelUserId) —
// which is messaging-specific and does NOT apply to the web widget (web keys a
// conversation by browser id, not a channel user id). So we CANNOT reuse that
// one function on web. BUT every building block it calls is channel-agnostic and
// takes a conv_key / collection-row directly:
//   gia_pt_active, gia_pt_set_pending_confirm, gia_pt_get_pending_confirm,
//   gia_pt_missing_required, gia_pt_summarise, gia_pt_save_traveller,
//   gia_pt_prompt_next, gia_pt_ocr_passport.
// So this file mirrors the channel text/image logic EXACTLY, but keyed on the
// WEB conversation's conv_key (which /api/gia/chat already resolves). This keeps
// web and channels behaving identically (owner: "mirror WhatsApp exactly") while
// touching NO channel code and leaking NO web assumption into the channel path.
//
// Pilgrim-step flow mirrored here (same as WhatsApp):
//   upload passport photo -> OCR -> show what was read -> user types YES ->
//   saved -> next pilgrim -> when all pax saved, caller shows the 2 pay options.
// ============================================================================

namespace App\lib\ai;

if (!function_exists('App\\lib\\ai\\gia_pt_web_boot')) {
    /** Ensure the shared pilgrim-collection engine is loaded. */
    function gia_pt_web_boot(): bool
    {
        if (!\function_exists('gia_pt_active')) {
            $f = __DIR__ . '/gia_pending_travellers.php';
            if (\is_file($f)) { require_once $f; }
        }
        return \function_exists('gia_pt_active');
    }
}

if (!function_exists('App\\lib\\ai\\gia_pt_web_active')) {
    /**
     * Is a pilgrim collection currently in progress for this WEB conversation?
     * Thin pass-through so the route can gate without pulling the engine in.
     *
     * @return array|null the collecting row, or null if none/expired.
     */
    function gia_pt_web_active($db, string $convKey): ?array
    {
        if ($convKey === '' || !gia_pt_web_boot()) { return null; }
        return \gia_pt_active($db, $convKey);
    }
}

if (!function_exists('App\\lib\\ai\\gia_pt_web_handle_text')) {
    /**
     * Handle a TYPED message during web pilgrim collection — the exact mirror of
     * the 'text' branch of gia_pt_handle_channel_message (confirm YES / correct /
     * provide missing fields), keyed on the web conv_key. Call this FIRST in
     * /api/gia/chat: if it returns non-null it HANDLED the turn (do not call the
     * model); null means no active collection -> normal agent handling proceeds.
     *
     * @return array|null ['reply'=>string,'done'=>bool,'invoice_id'=>string,'umrah_booking_id'=>int] or null
     */
    function gia_pt_web_handle_text($db, string $convKey, string $text): ?array
    {
        if ($convKey === '' || !gia_pt_web_boot()) { return null; }
        $row = \gia_pt_active($db, $convKey);
        if ($row === null) { return null; } // nothing to collect -> not ours

        $done = static function (array $r, string $reply): array {
            return [
                'reply'            => $reply,
                'done'             => ($r['status'] ?? '') === 'done',
                'invoice_id'       => (string) ($r['invoice_id'] ?? ''),
                'umrah_booking_id' => (int) ($r['umrah_booking_id'] ?? 0),
            ];
        };

        $text = \trim($text);
        $low  = \strtolower($text);
        $pending = \gia_pt_get_pending_confirm($row);

        // Confirm a pending parsed pilgrim (mirror channel: same regex + messages).
        if ($pending !== null && \preg_match('/^(y|yes|yeah|yep|correct|confirm|ok|okay)\b/i', $low)) {
            $missing = \gia_pt_missing_required($pending);
            if ($missing !== []) {
                return $done($row, "Before I save this pilgrim I still need: " . \implode(', ', $missing)
                    . ". Please type them (e.g. 'passport A1234567, expiry 2030-05-01').");
            }
            $updated = \gia_pt_save_traveller($db, $row, $pending);
            if ($updated === []) {
                return $done($row, "I couldn't save that pilgrim — please try again or upload the passport photo again.");
            }
            if (($updated['status'] ?? '') === 'done') {
                return $done($updated, "✅ All " . (int) $updated['pax'] . " pilgrims are saved. Now choose how to pay below.");
            }
            return $done($updated, "✅ Saved. " . \gia_pt_prompt_next($updated));
        }

        // Provide/parse free-text details (no photo, or filling gaps / corrections).
        $fields = \gia_pt_parse_text_fields($text);
        if ($fields !== []) {
            $merged = \array_merge(\is_array($pending) ? $pending : [], $fields);
            \gia_pt_set_pending_confirm($db, (int) $row['id'], $merged);
            $missing = \gia_pt_missing_required($merged);
            $summary = \gia_pt_summarise($merged);
            if ($missing !== []) {
                return $done($row, "Got it:\n" . $summary . "\n\nStill need: " . \implode(', ', $missing) . ".");
            }
            $slot = (int) $row['collected'] + 1;
            return $done($row, "Here's pilgrim " . $slot . ":\n" . $summary . "\n\nReply YES to confirm, or type a correction.");
        }

        // Nothing useful parsed — re-prompt for the current pilgrim.
        return $done($row, \gia_pt_prompt_next($row));
    }
}

if (!function_exists('App\\lib\\ai\\gia_pt_web_handle_image')) {
    /**
     * Handle an UPLOADED passport image during web pilgrim collection — the mirror
     * of the 'image' branch of gia_pt_handle_channel_message, but the bytes come
     * from a browser multipart upload (not a channel media ref), so we call
     * gia_pt_ocr_passport() on the raw bytes directly. Shows the parsed fields and
     * awaits a typed YES (same two-step confirm as WhatsApp).
     *
     * @return array ['ok'=>bool,'reply'=>string,'done'=>bool,'invoice_id'=>string,'umrah_booking_id'=>int,'error'?]
     */
    function gia_pt_web_handle_image($db, string $convKey, string $bytes, string $mime): array
    {
        if ($convKey === '' || !gia_pt_web_boot()) {
            return ['ok' => false, 'reply' => 'Passport upload is unavailable right now.', 'done' => false, 'error' => 'AI_PT_UNAVAILABLE'];
        }
        $row = \gia_pt_active($db, $convKey);
        if ($row === null) {
            return ['ok' => false, 'reply' => "There's no booking waiting for passports right now.", 'done' => false, 'error' => 'AI_PT_NONE'];
        }

        $base = [
            'invoice_id'       => (string) ($row['invoice_id'] ?? ''),
            'umrah_booking_id' => (int) ($row['umrah_booking_id'] ?? 0),
            'done'             => false,
        ];

        $parsed = \gia_pt_ocr_passport($db, $bytes, $mime);
        if ($parsed === null) {
            return $base + ['ok' => true, 'reply' =>
                "I couldn't read that passport photo clearly. Please upload a sharper photo of the data page, "
                . "or type the details: full name, gender, date of birth, nationality, passport number and expiry."];
        }

        $missing = \gia_pt_missing_required($parsed);
        \gia_pt_set_pending_confirm($db, (int) $row['id'], $parsed);
        $summary = \gia_pt_summarise($parsed);
        if ($missing !== []) {
            return $base + ['ok' => true, 'reply' =>
                "I read this from the passport:\n" . $summary
                . "\n\nI still need: " . \implode(', ', $missing) . ". Please type the missing detail(s), or upload a clearer photo."];
        }
        $slot = (int) $row['collected'] + 1;
        return $base + ['ok' => true, 'reply' =>
            "Here's what I read for pilgrim " . $slot . ":\n" . $summary
            . "\n\nReply YES to confirm, or type a correction (e.g. 'surname is Bello')."];
    }
}
