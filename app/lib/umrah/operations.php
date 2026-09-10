<?php
// ============================================================================
// UMRAH REDESIGN — Phase 2 operations service layer.
// Travellers, documents (secure upload + verification), visa/ticket/rooming
// state, readiness roll-up, addons, waitlist, notifications queue. Reuses the
// shared platform (NOTIFY, finfo MIME checks). Server-authoritative; all writes
// audited via umrah_audit().
// ============================================================================

if (!function_exists('umrah_traveller_add')) {
    /**
     * Add or update a traveller (pilgrim) on a booking. If $data['id'] is set and
     * belongs to the booking, updates it; otherwise inserts. Enforces the booking
     * pax cap (cannot add more travellers than booked).
     * @return array ['ok'=>bool,'traveller_id'=>int,'message'=>?]
     */
    function umrah_traveller_add($db, int $umrahBookingId, array $data): array
    {
        $ub = $db->get('umrah_bookings', ['id', 'pax'], ['id' => $umrahBookingId]);
        if (!$ub) { return ['ok' => false, 'message' => 'Booking not found']; }

        $fields = [
            'title' => $data['title'] ?? null,
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'gender' => in_array(($data['gender'] ?? ''), ['male', 'female'], true) ? $data['gender'] : null,
            'dob' => !empty($data['dob']) ? date('Y-m-d', strtotime((string) $data['dob'])) : null,
            'nationality' => $data['nationality'] ?? null,
            'passport_number' => $data['passport_number'] ?? null,
            'passport_issue' => !empty($data['passport_issue']) ? date('Y-m-d', strtotime((string) $data['passport_issue'])) : null,
            'passport_expiry' => !empty($data['passport_expiry']) ? date('Y-m-d', strtotime((string) $data['passport_expiry'])) : null,
            'emergency_contact' => $data['emergency_contact'] ?? null,
            'family_group' => $data['family_group'] ?? null,
            'room_group' => $data['room_group'] ?? null,
            'room_preference' => $data['room_preference'] ?? null,
            'ring_size' => $data['ring_size'] ?? null,
            'is_lead' => !empty($data['is_lead']) ? 1 : 0,
        ];

        $existingId = (int) ($data['id'] ?? 0);
        if ($existingId > 0) {
            $owned = $db->get('umrah_booking_travellers', 'id', ['id' => $existingId, 'umrah_booking_id' => $umrahBookingId]);
            if (!$owned) { return ['ok' => false, 'message' => 'Traveller not found for this booking']; }
            $fields['doc_status'] = umrah_traveller_doc_status_value($fields); // recompute readiness of identity fields
            $fields['updated_at'] = date('Y-m-d H:i:s');
            $db->update('umrah_booking_travellers', $fields, ['id' => $existingId]);
            $tid = $existingId;
        } else {
            // Enforce pax cap.
            $count = (int) $db->count('umrah_booking_travellers', ['umrah_booking_id' => $umrahBookingId]);
            if ($count >= (int) $ub['pax']) {
                return ['ok' => false, 'message' => 'All ' . (int) $ub['pax'] . ' traveller slots are already filled'];
            }
            $fields['umrah_booking_id'] = $umrahBookingId;
            $fields['doc_status'] = umrah_traveller_doc_status_value($fields);
            $fields['created_at'] = date('Y-m-d H:i:s');
            $db->insert('umrah_booking_travellers', $fields);
            $tid = (int) $db->id();
        }
        if (function_exists('umrah_audit')) {
            umrah_audit($db, 'umrah_traveller', (string) $tid, $existingId ? 'updated' : 'created', null, ['booking' => $umrahBookingId, 'name' => trim($fields['first_name'] . ' ' . $fields['last_name'])]);
        }
        return ['ok' => true, 'traveller_id' => $tid];
    }
}

if (!function_exists('umrah_traveller_doc_status_value')) {
    /** Identity-completeness → doc_status (before file verification). */
    function umrah_traveller_doc_status_value(array $t): string
    {
        $required = ['first_name', 'last_name', 'gender', 'dob', 'nationality', 'passport_number', 'passport_expiry'];
        $missing = 0;
        foreach ($required as $r) { if (empty($t[$r])) { $missing++; } }
        if ($missing === count($required)) { return 'not_started'; }
        return $missing === 0 ? 'ready' : 'incomplete';
    }
}

if (!function_exists('umrah_document_upload')) {
    /**
     * Securely store a passport/photo/etc. document for a traveller.
     * Private path (uploads/umrah/documents/) — not linked publicly; served via
     * an authorised route only. MIME-checked (jpg/png/pdf), size-limited,
     * PHP-injection guarded. Does NOT log the file bytes.
     * @param string $fileKey  $_FILES key
     * @return array ['ok'=>bool,'document_id'=>int,'message'=>?]
     */
    function umrah_document_upload($db, int $travellerId, string $docType, string $fileKey, int $maxBytes = 8388608): array
    {
        $tr = $db->get('umrah_booking_travellers', ['id', 'umrah_booking_id'], ['id' => $travellerId]);
        if (!$tr) { return ['ok' => false, 'message' => 'Traveller not found']; }
        if (!isset($_FILES[$fileKey]) || ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'No file uploaded'];
        }
        $file = $_FILES[$fileKey];
        if (!function_exists('finfo_open')) { return ['ok' => false, 'message' => 'Server fileinfo missing']; }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
        if (!isset($allowed[$mime])) { return ['ok' => false, 'message' => 'Only JPG, PNG or PDF allowed']; }
        if ($file['size'] > $maxBytes) { return ['ok' => false, 'message' => 'File too large (max ' . ($maxBytes / 1048576) . 'MB)']; }
        // PHP-injection guard for image-typed uploads (audit low): catch the
        // full <?php opener, the <?= short-echo tag, a bare <? opener, and the
        // <script language="php"> form — not just literal "<?php".
        if ($mime !== 'application/pdf') {
            $head = (string) file_get_contents($file['tmp_name']);
            if (preg_match('/<\?(php|=)?\s/i', $head) || preg_match('/<\?(php|=)/i', $head) || preg_match('/<script[^>]*language\s*=\s*["\']?php/i', $head)) {
                return ['ok' => false, 'message' => 'Invalid file content'];
            }
        }

        // Project-root uploads dir. Use the `uploads` constant (config.php) when
        // available; fall back to dirname(__DIR__,3) (umrah→lib→app→root).
        $uploadsBase = defined('uploads') ? rtrim(uploads, '/') : dirname(__DIR__, 3) . '/uploads';
        $dir = $uploadsBase . '/umrah/documents/' . (int) $tr['umrah_booking_id'];
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'Storage unavailable'];
        }
        // Drop a hardening .htaccess so the directory is not directly web-served.
        $ht = $uploadsBase . '/umrah/documents/.htaccess';
        if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\nDeny from all\n"); }

        try { $rand = bin2hex(random_bytes(8)); } catch (\Throwable $e) { $rand = substr(md5(uniqid('', true)), 0, 16); }
        $ext = $allowed[$mime];
        $safeType = preg_replace('/[^a-z0-9_]/i', '', $docType) ?: 'doc';
        $fname = $safeType . '_' . $travellerId . '_' . $rand . '.' . $ext;
        $dest = $dir . '/' . $fname;
        if (!@move_uploaded_file($file['tmp_name'], $dest) && !@rename($file['tmp_name'], $dest)) {
            return ['ok' => false, 'message' => 'Failed to store file'];
        }
        $relPath = 'uploads/umrah/documents/' . (int) $tr['umrah_booking_id'] . '/' . $fname;

        $db->insert('umrah_documents', [
            'traveller_id' => $travellerId,
            'umrah_booking_id' => (int) $tr['umrah_booking_id'],
            'doc_type' => $safeType,
            'file_path' => $relPath,
            'original_name' => mb_substr((string) ($file['name'] ?? ''), 0, 255),
            'mime' => $mime,
            'verify_status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $docId = (int) $db->id();
        // Uploading a passport bumps identity readiness towards 'ready' handling in verify.
        if (function_exists('umrah_audit')) {
            umrah_audit($db, 'umrah_document', (string) $docId, 'uploaded', null, ['traveller' => $travellerId, 'type' => $safeType]); // NB: no file bytes logged
        }
        return ['ok' => true, 'document_id' => $docId];
    }
}

if (!function_exists('umrah_document_verify')) {
    /** Admin: verify/reject a document; recompute the traveller doc_status. */
    function umrah_document_verify($db, int $documentId, string $decision, ?string $by = null, ?string $note = null): array
    {
        if (!in_array($decision, ['verified', 'rejected'], true)) { return ['ok' => false, 'message' => 'Invalid decision']; }
        $doc = $db->get('umrah_documents', '*', ['id' => $documentId]);
        if (!$doc) { return ['ok' => false, 'message' => 'Document not found']; }
        $db->update('umrah_documents', [
            'verify_status' => $decision,
            'verified_by' => $by ?? ($_SESSION['user_id'] ?? null),
            'verified_at' => date('Y-m-d H:i:s'),
            'note' => $note,
        ], ['id' => $documentId]);
        umrah_traveller_recompute_doc_status($db, (int) $doc['traveller_id']);
        if (function_exists('umrah_audit')) {
            umrah_audit($db, 'umrah_document', (string) $documentId, 'verify_' . $decision, null, ['traveller' => $doc['traveller_id']]);
        }
        return ['ok' => true];
    }
}

if (!function_exists('umrah_traveller_recompute_doc_status')) {
    /** doc_status = verified (all req docs verified) / ready (uploaded) / incomplete / action_required (a reject). */
    function umrah_traveller_recompute_doc_status($db, int $travellerId): void
    {
        $tr = $db->get('umrah_booking_travellers', '*', ['id' => $travellerId]);
        if (!$tr) { return; }
        $identity = umrah_traveller_doc_status_value($tr); // not_started / incomplete / ready
        $docs = $db->select('umrah_documents', ['verify_status'], ['traveller_id' => $travellerId]) ?: [];
        $status = $identity;
        if ($docs) {
            $hasReject = false; $allVerified = true; $hasPassport = false;
            foreach ($docs as $d) {
                if ($d['verify_status'] === 'rejected') { $hasReject = true; }
                if ($d['verify_status'] !== 'verified') { $allVerified = false; }
            }
            if ($hasReject) { $status = 'action_required'; }
            elseif ($allVerified && $identity === 'ready') { $status = 'verified'; }
            elseif ($identity === 'ready') { $status = 'ready'; }
        }
        $db->update('umrah_booking_travellers', ['doc_status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $travellerId]);
    }
}

if (!function_exists('umrah_traveller_set_status')) {
    /** Admin: transition a traveller's visa/ticket/rooming status (validated). */
    function umrah_traveller_set_status($db, int $travellerId, string $domain, string $value): array
    {
        $maps = [
            'visa'    => ['not_started','ready_to_submit','submitted','approved','rejected','action_required'],
            'ticket'  => ['not_started','reserved','ticketed','changed','cancelled'],
            'rooming' => ['unassigned','requested','assigned','confirmed'],
        ];
        if (!isset($maps[$domain]) || !in_array($value, $maps[$domain], true)) {
            return ['ok' => false, 'message' => 'Invalid status'];
        }
        $col = $domain . '_status';
        $old = $db->get('umrah_booking_travellers', [$col], ['id' => $travellerId]);
        if (!$old) { return ['ok' => false, 'message' => 'Traveller not found']; }
        $db->update('umrah_booking_travellers', [$col => $value, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $travellerId]);
        if (function_exists('umrah_audit')) {
            umrah_audit($db, 'umrah_traveller', (string) $travellerId, $domain . '_' . $value, [$col => $old[$col]], [$col => $value]);
        }
        return ['ok' => true];
    }
}

if (!function_exists('umrah_notify')) {
    /**
     * Queue (and best-effort send) an umrah notification. Records to
     * umrah_notifications; if the shared NOTIFY class is available it also sends
     * via the configured channel. Non-fatal. Templates: payment_confirmed,
     * traveller_incomplete, visa_approved, ticket_issued, etc.
     */
    function umrah_notify($db, ?int $umrahBookingId, string $template, string $subject, string $body, string $channel = 'email', ?array $customer = null): int
    {
        $id = 0;
        try {
            $db->insert('umrah_notifications', [
                'umrah_booking_id' => $umrahBookingId,
                'channel' => $channel, 'template' => $template,
                'subject' => mb_substr($subject, 0, 200), 'body' => $body,
                'status' => 'queued', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->id();
            $sent = false;
            if ($customer && class_exists('NOTIFY') && method_exists('NOTIFY', 'payment')) {
                // Reuse the platform email path where a customer contact exists.
                try {
                    if (function_exists('SENDEMAIL') && !empty($customer['email'])) {
                        SENDEMAIL($customer['email'], $customer['name'] ?? '', $subject, $body);
                        $sent = true;
                    }
                } catch (\Throwable $e) { /* fall through to queued */ }
            }
            $db->update('umrah_notifications', [
                'status' => $sent ? 'sent' : 'queued',
                'sent_at' => $sent ? date('Y-m-d H:i:s') : null,
            ], ['id' => $id]);
        } catch (\Throwable $e) { error_log('umrah_notify: ' . $e->getMessage()); }
        return $id;
    }
}

if (!function_exists('umrah_booking_readiness')) {
    /** Roll-up readiness for a booking: counts per traveller domain. */
    function umrah_booking_readiness($db, int $umrahBookingId): array
    {
        $trav = $db->select('umrah_booking_travellers', ['doc_status', 'visa_status', 'ticket_status', 'rooming_status'], ['umrah_booking_id' => $umrahBookingId]) ?: [];
        $ub = $db->get('umrah_bookings', ['pax'], ['id' => $umrahBookingId]);
        $pax = (int) ($ub['pax'] ?? 0);
        $r = ['pax' => $pax, 'travellers_added' => count($trav),
              'docs_verified' => 0, 'visa_approved' => 0, 'ticketed' => 0, 'rooming_confirmed' => 0];
        foreach ($trav as $t) {
            if ($t['doc_status'] === 'verified') { $r['docs_verified']++; }
            if ($t['visa_status'] === 'approved') { $r['visa_approved']++; }
            if ($t['ticket_status'] === 'ticketed') { $r['ticketed']++; }
            if ($t['rooming_status'] === 'confirmed') { $r['rooming_confirmed']++; }
        }
        return $r;
    }
}
