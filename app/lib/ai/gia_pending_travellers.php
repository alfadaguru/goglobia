<?php
// ============================================================================
// FILE: app/lib/ai/gia_pending_travellers.php
// Per-conversation state for GIA's "collect pilgrim/passenger details BEFORE
// payment" step. When GIA creates an umrah booking on a channel, it records how
// many pilgrims are still needed for THIS conversation; each passport photo the
// traveller sends is attached to the booking until all slots are filled, and
// only then are the pay options shown. (Owner decision: complete the manifest
// before any money moves, so no booking is ever paid with missing visa data.)
//
// There is no free-form state column on gia_conversations and no existing
// per-conv state helper, so this is a small dedicated store keyed by conv_key.
// Self-healing schema (house pattern). Never stores secrets.
// ============================================================================

if (!function_exists('gia_pt_ensure_schema')) {
    /** Idempotently create gia_pending_travellers. */
    function gia_pt_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        try {
            $db->query(
                "CREATE TABLE IF NOT EXISTS `gia_pending_travellers` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `conv_key` VARCHAR(64) NOT NULL,
                    `module` VARCHAR(24) NOT NULL DEFAULT 'umrah',
                    `invoice_id` VARCHAR(64) NOT NULL DEFAULT '',
                    `umrah_booking_id` INT NOT NULL DEFAULT 0,
                    `pax` SMALLINT NOT NULL DEFAULT 1,
                    `collected` SMALLINT NOT NULL DEFAULT 0,
                    `pending_confirm` TEXT NULL DEFAULT NULL,
                    `status` VARCHAR(16) NOT NULL DEFAULT 'collecting',
                    `expires_at` DATETIME NULL DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_gpt_conv` (`conv_key`, `status`),
                    KEY `idx_gpt_invoice` (`invoice_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            error_log('[gia_pt] could not create gia_pending_travellers: ' . $e->getMessage());
        }
        $done = true;
    }
}

if (!function_exists('gia_pt_start')) {
    /**
     * Begin collecting pilgrims for a freshly-created umrah booking in THIS
     * conversation. Supersedes any older collecting row for the same conv_key
     * (one active collection per conversation). Window: 1 hour.
     */
    function gia_pt_start($db, string $convKey, string $module, string $invoiceId, int $umrahBookingId, int $pax): bool
    {
        if ($convKey === '' || $umrahBookingId <= 0 || $pax < 1) { return false; }
        gia_pt_ensure_schema($db);
        try {
            // Close any stale collecting rows for this conversation.
            $db->update('gia_pending_travellers',
                ['status' => 'superseded', 'updated_at' => date('Y-m-d H:i:s')],
                ['conv_key' => $convKey, 'status' => 'collecting']);
            $db->insert('gia_pending_travellers', [
                'conv_key'         => $convKey,
                'module'           => $module !== '' ? $module : 'umrah',
                'invoice_id'       => $invoiceId,
                'umrah_booking_id' => $umrahBookingId,
                'pax'              => $pax,
                'collected'        => 0,
                'status'           => 'collecting',
                'expires_at'       => date('Y-m-d H:i:s', time() + 3600),
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[gia_pt] start: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('gia_pt_active')) {
    /**
     * The active collection for this conversation, or null. A row past expiry is
     * NOT returned (so a long-abandoned collection stops intercepting messages).
     */
    function gia_pt_active($db, string $convKey): ?array
    {
        if ($convKey === '') { return null; }
        gia_pt_ensure_schema($db);
        try {
            $row = $db->get('gia_pending_travellers', '*',
                ['conv_key' => $convKey, 'status' => 'collecting', 'ORDER' => ['id' => 'DESC']]);
            if (!is_array($row)) { return null; }
            if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
                // Expired — mark it so and do not intercept.
                $db->update('gia_pending_travellers', ['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);
                return null;
            }
            return $row;
        } catch (\Throwable $e) {
            error_log('[gia_pt] active: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('gia_pt_set_pending_confirm')) {
    /** Store the last OCR-parsed pilgrim set awaiting yes/no confirmation. */
    function gia_pt_set_pending_confirm($db, int $id, ?array $data): bool
    {
        gia_pt_ensure_schema($db);
        try {
            $db->update('gia_pending_travellers', [
                'pending_confirm' => $data === null ? null : json_encode($data),
                'updated_at'      => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
            return true;
        } catch (\Throwable $e) {
            error_log('[gia_pt] set_pending_confirm: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('gia_pt_get_pending_confirm')) {
    /** The parsed pilgrim set awaiting confirmation, or null. */
    function gia_pt_get_pending_confirm(array $row): ?array
    {
        $raw = $row['pending_confirm'] ?? null;
        if (!is_string($raw) || trim($raw) === '') { return null; }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }
}

if (!function_exists('gia_pt_record_collected')) {
    /**
     * Advance after one pilgrim is successfully added: clear the pending-confirm,
     * bump the collected count, and mark the collection DONE when pax is reached.
     * Returns the updated row.
     */
    function gia_pt_record_collected($db, int $id): array
    {
        gia_pt_ensure_schema($db);
        try {
            $row = $db->get('gia_pending_travellers', '*', ['id' => $id]);
            if (!is_array($row)) { return []; }
            $collected = (int) $row['collected'] + 1;
            $done = $collected >= (int) $row['pax'];
            $db->update('gia_pending_travellers', [
                'collected'       => $collected,
                'pending_confirm' => null,
                'status'          => $done ? 'done' : 'collecting',
                'updated_at'      => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
            $row['collected'] = $collected;
            $row['status'] = $done ? 'done' : 'collecting';
            $row['pending_confirm'] = null;
            return $row;
        } catch (\Throwable $e) {
            error_log('[gia_pt] record_collected: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('gia_pt_map_ocr')) {
    /**
     * Map the passport OCR provider's output to the shape umrah_traveller_add()
     * expects. The provider returns given_names/first_name, surname/last_name,
     * gender (normalized male|female), date_of_birth, nationality, expiry_date,
     * passport_number/document_number. umrah_traveller_add wants first_name,
     * last_name, gender, dob, nationality, passport_number, passport_expiry.
     */
    function gia_pt_map_ocr(array $d): array
    {
        $first = trim((string) ($d['first_name'] ?? $d['given_names'] ?? ''));
        $last  = trim((string) ($d['last_name'] ?? $d['surname'] ?? ''));
        $gender = strtolower(trim((string) ($d['gender'] ?? $d['sex'] ?? '')));
        if (in_array($gender, ['m', 'male'], true)) { $gender = 'male'; }
        elseif (in_array($gender, ['f', 'female'], true)) { $gender = 'female'; }
        else { $gender = ''; }
        return [
            'first_name'      => $first,
            'last_name'       => $last,
            'gender'          => $gender,
            'dob'             => trim((string) ($d['dob'] ?? $d['date_of_birth'] ?? '')),
            'nationality'     => trim((string) ($d['nationality'] ?? '')),
            'passport_number' => trim((string) ($d['passport_number'] ?? $d['document_number'] ?? '')),
            'passport_expiry' => trim((string) ($d['passport_expiry'] ?? $d['expiry_date'] ?? '')),
        ];
    }
}

if (!function_exists('gia_pt_summarise')) {
    /** A human-readable summary of a parsed pilgrim, for the confirm prompt. */
    function gia_pt_summarise(array $t): string
    {
        $lines = [];
        $name = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
        if ($name !== '') { $lines[] = 'Name: ' . $name; }
        if (!empty($t['gender'])) { $lines[] = 'Gender: ' . ucfirst($t['gender']); }
        if (!empty($t['dob'])) { $lines[] = 'Date of birth: ' . $t['dob']; }
        if (!empty($t['nationality'])) { $lines[] = 'Nationality: ' . $t['nationality']; }
        if (!empty($t['passport_number'])) { $lines[] = 'Passport no: ' . $t['passport_number']; }
        if (!empty($t['passport_expiry'])) { $lines[] = 'Passport expiry: ' . $t['passport_expiry']; }
        return implode("\n", $lines);
    }
}

if (!function_exists('gia_pt_missing_required')) {
    /**
     * Which required identity fields are missing for a Saudi-umrah manifest.
     * (first/last name, gender, dob, nationality, passport number + expiry.)
     * @return string[] human labels of the missing fields
     */
    function gia_pt_missing_required(array $t): array
    {
        $req = [
            'first_name' => 'first name', 'last_name' => 'last name', 'gender' => 'gender',
            'dob' => 'date of birth', 'nationality' => 'nationality',
            'passport_number' => 'passport number', 'passport_expiry' => 'passport expiry',
        ];
        $missing = [];
        foreach ($req as $k => $label) {
            if (trim((string) ($t[$k] ?? '')) === '') { $missing[] = $label; }
        }
        return $missing;
    }
}

if (!function_exists('gia_pt_ocr_passport')) {
    /**
     * Run passport OCR on already-downloaded image bytes and return the mapped
     * traveller fields, or null if OCR is off/failed. Reuses the same provider
     * path gia_media uses — no new engine.
     * @return array|null mapped fields (gia_pt_map_ocr shape)
     */
    function gia_pt_ocr_passport($db, string $bytes, string $mime): ?array
    {
        if ($bytes === '' || strpos($mime, 'image/') !== 0) { return null; }
        if (!function_exists('passportAiIsEnabled') || !passportAiIsEnabled($db)) { return null; }
        $cfg = function_exists('passportAiActiveProviderConfig') ? passportAiActiveProviderConfig($db) : null;
        if (!is_array($cfg)) { return null; }
        if (!function_exists('App\\lib\\ai\\gia_ocr_make_provider')) {
            $mf = __DIR__ . '/gia_media.php';
            if (is_file($mf)) { require_once $mf; }
        }
        if (!function_exists('App\\lib\\ai\\gia_ocr_make_provider')) { return null; }
        $provider = \App\lib\ai\gia_ocr_make_provider((string) ($cfg['key'] ?? ''));
        if ($provider === null) { return null; }
        try {
            $out = $provider->extract($bytes, $mime, $cfg);
        } catch (\Throwable $e) {
            error_log('[gia_pt] ocr: ' . $e->getMessage());
            return null;
        }
        if (empty($out['status']) || empty($out['data'])) { return null; }
        return gia_pt_map_ocr((array) $out['data']);
    }
}

if (!function_exists('gia_pt_save_traveller')) {
    /**
     * Persist one confirmed pilgrim to the booking and advance the collection.
     * The FIRST pilgrim of a booking is marked is_lead. Returns the updated
     * collection row (with new collected/status) or [] on failure.
     */
    function gia_pt_save_traveller($db, array $collectionRow, array $traveller): array
    {
        $umrahBookingId = (int) ($collectionRow['umrah_booking_id'] ?? 0);
        if ($umrahBookingId <= 0) { return []; }
        if (!function_exists('umrah_traveller_add')) {
            $op = dirname(__DIR__) . '/umrah/operations.php';
            if (is_file($op)) { require_once $op; }
        }
        if (!function_exists('umrah_traveller_add')) { return []; }
        $data = [
            'first_name'      => $traveller['first_name'] ?? '',
            'last_name'       => $traveller['last_name'] ?? '',
            'gender'          => $traveller['gender'] ?? '',
            'dob'             => $traveller['dob'] ?? '',
            'nationality'     => $traveller['nationality'] ?? '',
            'passport_number' => $traveller['passport_number'] ?? '',
            'passport_expiry' => $traveller['passport_expiry'] ?? '',
            'is_lead'         => ((int) ($collectionRow['collected'] ?? 0) === 0) ? 1 : 0,
        ];
        $res = umrah_traveller_add($db, $umrahBookingId, $data);
        if (empty($res['ok'])) {
            error_log('[gia_pt] save_traveller: ' . ($res['message'] ?? '?'));
            return [];
        }

        // CAPTURE (GA2): remember this pilgrim on the booking owner's saved-traveller
        // roster so a future booking can reuse them. Resolve the user via the umrah
        // booking; fail-soft (never block pilgrim collection on a capture error).
        try {
            $owner = $db->get('umrah_bookings', ['user_id'], ['id' => $umrahBookingId]);
            $ownerId = (string) ($owner['user_id'] ?? '');
            if ($ownerId !== '') {
                if (!function_exists('gia_traveller_upsert')) {
                    require_once __DIR__ . '/gia_travellers.php';
                }
                if (function_exists('gia_traveller_upsert')) {
                    gia_traveller_upsert($db, $ownerId, $data, 'umrah');
                }
            }
        } catch (\Throwable $e) {
            error_log('[gia_pt] traveller capture: ' . $e->getMessage());
        }

        return gia_pt_record_collected($db, (int) $collectionRow['id']);
    }
}

if (!function_exists('gia_pt_prompt_next')) {
    /** Ask for the next pilgrim's passport (slot-aware). */
    function gia_pt_prompt_next(array $row): string
    {
        $next = (int) ($row['collected'] ?? 0) + 1;
        $pax = (int) ($row['pax'] ?? 1);
        $who = $next === 1 ? "the lead pilgrim" : ("pilgrim " . $next . " of " . $pax);
        return "Please send a clear PHOTO of " . $who . "'s passport (data page). "
            . "You can also type their details: full name, gender, date of birth, nationality, "
            . "passport number and passport expiry.";
    }
}

if (!function_exists('gia_pt_handle_channel_message')) {
    /**
     * ONE entry point for the pilgrim-collection step on channels. Call it FIRST
     * (before the normal agent/media handling). Returns a reply string to send if
     * it HANDLED the message (there is an active collection for this chat), or
     * NULL to let normal handling proceed (no active collection).
     *
     * @param string $kind 'image'|'text'
     * @param mixed  $payload image media ref (array) for 'image', or the text string
     * @return array|null ['reply'=>string, 'done'=>bool, 'invoice_id'=>string, 'umrah_booking_id'=>int] or null
     */
    function gia_pt_handle_channel_message($db, string $channel, string $channelUserId, string $kind, $payload): ?array
    {
        if (!function_exists('gia_conv_get_or_create_channel')) {
            $crm = __DIR__ . '/gia_crm.php';
            if (is_file($crm)) { require_once $crm; }
        }
        if (!function_exists('gia_conv_get_or_create_channel')) { return null; }
        $conv = gia_conv_get_or_create_channel($db, $channel, $channelUserId, '', '');
        $convKey = is_array($conv) ? (string) ($conv['conv_key'] ?? '') : '';
        if ($convKey === '') { return null; }

        $row = gia_pt_active($db, $convKey);
        if ($row === null) { return null; } // no pilgrim collection in progress -> not ours

        $done = function (array $r, string $reply) {
            return ['reply' => $reply, 'done' => ($r['status'] ?? '') === 'done',
                    'invoice_id' => (string) ($r['invoice_id'] ?? ''),
                    'umrah_booking_id' => (int) ($r['umrah_booking_id'] ?? 0)];
        };

        // ---------- IMAGE: run OCR, show parsed fields, await confirm ----------
        if ($kind === 'image') {
            if (!function_exists('App\\lib\\ai\\gia_media_resolve_url')) {
                $mf = __DIR__ . '/gia_media.php';
                if (is_file($mf)) { require_once $mf; }
            }
            $ref = is_array($payload) ? $payload : [];
            $parsed = null;
            if (function_exists('App\\lib\\ai\\gia_media_resolve_url') && function_exists('App\\lib\\ai\\gia_media_fetch')) {
                $res = \App\lib\ai\gia_media_resolve_url($db, $channel, $ref);
                if (!empty($res['ok'])) {
                    $dl = \App\lib\ai\gia_media_fetch($res['url'], $res['bearer'] ?? null);
                    if (!empty($dl['ok'])) {
                        $mime = ($dl['mime'] ?? '') !== '' ? $dl['mime'] : (string) ($res['mime'] ?? '');
                        $parsed = gia_pt_ocr_passport($db, (string) $dl['bytes'], (string) $mime);
                    }
                }
            }
            if ($parsed === null) {
                return $done($row, "I couldn't read that passport photo clearly. Please resend a sharper photo of the data page, "
                    . "or type the details: full name, gender, date of birth, nationality, passport number and expiry.");
            }
            // MERGE onto anything the traveller already typed for THIS pilgrim, so an
            // earlier typed field (e.g. passport number) isn't wiped by an OCR pass
            // that happened to miss it. OCR values win only where non-empty.
            $prior = gia_pt_get_pending_confirm($row);
            if (is_array($prior) && $prior !== []) {
                $merged = $prior;
                foreach ($parsed as $k => $v) { if ($v !== '' && $v !== null) { $merged[$k] = $v; } }
                $parsed = $merged;
            }
            $missing = gia_pt_missing_required($parsed);
            gia_pt_set_pending_confirm($db, (int) $row['id'], $parsed);
            $summary = gia_pt_summarise($parsed);
            if ($missing !== []) {
                return $done($row, "I read this from the passport:\n" . $summary
                    . "\n\nI still need: " . implode(', ', $missing) . ". Please type the missing detail(s), "
                    . "or resend a clearer photo.");
            }
            $slot = (int) $row['collected'] + 1;
            return $done($row, "Here's what I read for pilgrim " . $slot . ":\n" . $summary
                . "\n\nReply YES to confirm, or type a correction (e.g. 'surname is Bello').");
        }

        // ---------- TEXT: confirm / correct / provide missing fields ----------
        if ($kind === 'text') {
            $text = trim((string) $payload);
            $low = strtolower($text);
            $pending = gia_pt_get_pending_confirm($row);

            // Confirm a pending parsed pilgrim.
            if ($pending !== null && preg_match('/^(y|yes|yeah|yep|correct|confirm|ok|okay)\b/i', $low)) {
                $missing = gia_pt_missing_required($pending);
                if ($missing !== []) {
                    return $done($row, "Before I save this pilgrim I still need: " . implode(', ', $missing)
                        . ". Please type them (e.g. 'passport A1234567, expiry 2030-05-01').");
                }
                $updated = gia_pt_save_traveller($db, $row, $pending);
                if ($updated === []) {
                    return $done($row, "I couldn't save that pilgrim — please try again or resend the passport photo.");
                }
                if (($updated['status'] ?? '') === 'done') {
                    return $done($updated, "✅ All " . (int) $updated['pax'] . " pilgrims are saved. Now choose how to pay below.");
                }
                return $done($updated, "✅ Saved. " . gia_pt_prompt_next($updated));
            }

            // Provide/parse free-text details (either no photo, or filling gaps).
            $fields = gia_pt_parse_text_fields($text);
            if ($fields !== []) {
                $merged = array_merge(is_array($pending) ? $pending : [], $fields);
                gia_pt_set_pending_confirm($db, (int) $row['id'], $merged);
                $missing = gia_pt_missing_required($merged);
                $summary = gia_pt_summarise($merged);
                if ($missing !== []) {
                    return $done($row, "Got it:\n" . $summary . "\n\nStill need: " . implode(', ', $missing) . ".");
                }
                $slot = (int) $row['collected'] + 1;
                return $done($row, "Here's pilgrim " . $slot . ":\n" . $summary . "\n\nReply YES to confirm, or type a correction.");
            }

            // Couldn't parse anything useful.
            return $done($row, gia_pt_prompt_next($row));
        }

        return null;
    }
}

if (!function_exists('gia_pt_parse_text_fields')) {
    /**
     * Best-effort extraction of pilgrim fields from free text, so a traveller who
     * types instead of sending a photo (or corrects an OCR field) is understood.
     * Conservative: only sets a field when confident. Returns [] if nothing found.
     */
    function gia_pt_parse_text_fields(string $text): array
    {
        $out = [];
        $t = trim($text);

        // Explicit "key: value" / "key is value" forms.
        $patterns = [
            'passport_number' => '/\b(?:passport(?:\s*(?:no|number|#))?)\s*(?:is|:|=)?\s*([A-Z0-9]{5,15})\b/i',
            'passport_expiry' => '/\b(?:expiry|expires?|expiration)\s*(?:date)?\s*(?:is|:|=)?\s*([0-9]{1,4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,4})\b/i',
            'dob'             => '/\b(?:dob|date\s*of\s*birth|born)\s*(?:is|:|=)?\s*([0-9]{1,4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,4})\b/i',
            'nationality'     => '/\b(?:nationality|citizen(?:ship)?)\s*(?:is|:|=)?\s*([A-Za-z ]{2,40})\b/i',
            'first_name'      => '/\b(?:first\s*name|given\s*names?)\s*(?:is|:|=)?\s*([A-Za-z\'\- ]{2,40})\b/i',
            'last_name'       => '/\b(?:last\s*name|surname|family\s*name)\s*(?:is|:|=)?\s*([A-Za-z\'\- ]{2,40})\b/i',
        ];
        foreach ($patterns as $k => $re) {
            if (preg_match($re, $t, $m)) { $out[$k] = trim($m[1]); }
        }
        // Gender keyword.
        if (preg_match('/\b(male|female|man|woman|m|f)\b/i', $t, $g)) {
            $gl = strtolower($g[1]);
            if (in_array($gl, ['male', 'man', 'm'], true)) { $out['gender'] = 'male'; }
            elseif (in_array($gl, ['female', 'woman', 'f'], true)) { $out['gender'] = 'female'; }
        }
        // "full name: John Doe" / "name is John Doe" -> split to first/last if not already set.
        if (!isset($out['first_name']) && !isset($out['last_name'])
            && preg_match('/\b(?:full\s*name|name)\s*(?:is|:|=)\s*([A-Za-z\'\- ]{3,60})/i', $t, $nm)) {
            $parts = preg_split('/\s+/', trim($nm[1]));
            if (count($parts) >= 2) {
                $out['first_name'] = $parts[0];
                $out['last_name'] = implode(' ', array_slice($parts, 1));
            }
        }
        return $out;
    }
}

if (!function_exists('gia_pt_is_complete')) {
    /** True once every pilgrim slot for a booking has a traveller row. This is
     *  the authoritative gate for showing the pay options — it re-counts the real
     *  umrah_booking_travellers rows, not just the local counter. */
    function gia_pt_is_complete($db, int $umrahBookingId): bool
    {
        if ($umrahBookingId <= 0) { return false; }
        try {
            $ub = $db->get('umrah_bookings', ['pax'], ['id' => $umrahBookingId]);
            if (!is_array($ub)) { return false; }
            $pax = (int) ($ub['pax'] ?? 0);
            if ($pax < 1) { return false; }
            $have = (int) $db->count('umrah_booking_travellers', ['umrah_booking_id' => $umrahBookingId]);
            return $have >= $pax;
        } catch (\Throwable $e) {
            error_log('[gia_pt] is_complete: ' . $e->getMessage());
            return false;
        }
    }
}
