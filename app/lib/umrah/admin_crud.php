<?php
// ============================================================================
// UMRAH admin CRUD service layer — full create/edit/archive/restore for the
// four product building blocks the v2 model uses:
//   - umrah_package_templates (packages)
//   - umrah_tiers
//   - umrah_payment_plans
//   - umrah_departures  (edit + archive/restore; create/clone live in the route)
//
// Design rules (owner decisions):
//   * DELETE = ARCHIVE (soft-delete). archived=1 hides a record from the public
//     site + default admin lists but never removes data; restore sets it to 0.
//   * Server is authoritative; every mutation validates + returns
//     ['ok'=>bool,'message'=>?,'id'=>?]. Codes are unique (uq_code) so we guard
//     duplicates explicitly for a clean message instead of a raw SQL error.
//   * All writes audited via umrah_audit() when available.
// ============================================================================

if (!function_exists('umrah_admin_slugify')) {
    function umrah_admin_slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim((string) $s, '-') ?: ('item-' . substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
if (!function_exists('umrah_admin_code')) {
    /** Normalise a user code to [a-z0-9_-]; fall back to a random one. */
    function umrah_admin_code(string $s, string $prefix = 'code'): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9_\-]+/', '-', $s);
        $s = trim((string) $s, '-_');
        return $s !== '' ? $s : ($prefix . '-' . substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
if (!function_exists('umrah_admin_audit')) {
    function umrah_admin_audit($db, string $entity, string $id, string $action, array $meta = []): void
    {
        if (function_exists('umrah_audit')) {
            $actor = (string) ($_SESSION['user_id'] ?? '');
            umrah_audit($db, $entity, $id, $action, null, $meta, $actor, 'admin');
        }
    }
}

// ---------------------------------------------------------------------------
// PACKAGES (umrah_package_templates)
// ---------------------------------------------------------------------------
if (!function_exists('umrah_admin_template_save')) {
    /**
     * Create (id<=0) or update (id>0) a package template.
     * $in keys: id, code, slug, name, season, marketing_duration, madinah_nights,
     * makkah_nights, rooming_note, meta_title, meta_description, status,
     * inclusions (array|csv), itinerary_order (array|csv).
     */
    function umrah_admin_template_save($db, array $in): array
    {
        $id   = (int) ($in['id'] ?? 0);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') { return ['ok' => false, 'message' => 'Package name is required']; }

        $slug = trim((string) ($in['slug'] ?? ''));
        $slug = $slug !== '' ? umrah_admin_slugify($slug) : umrah_admin_slugify($name);
        $code = umrah_admin_code((string) ($in['code'] ?? $slug), 'tpl');

        // Uniqueness guards on code + slug (excluding self on edit).
        $dupCode = $db->get('umrah_package_templates', 'id', ['code' => $code]);
        if ($dupCode && (int) $dupCode !== $id) { return ['ok' => false, 'message' => 'That code is already used by another package']; }
        $dupSlug = $db->get('umrah_package_templates', 'id', ['slug' => $slug]);
        if ($dupSlug && (int) $dupSlug !== $id) { return ['ok' => false, 'message' => 'That slug is already used by another package']; }

        $incl = $in['inclusions'] ?? [];
        if (!is_array($incl)) { $incl = array_values(array_filter(array_map('trim', explode(',', (string) $incl)))); }
        $itin = $in['itinerary_order'] ?? [];
        if (!is_array($itin)) { $itin = array_values(array_filter(array_map('trim', explode(',', (string) $itin)))); }

        $fields = [
            'code'               => $code,
            'slug'               => $slug,
            'name'               => $name,
            'season'             => trim((string) ($in['season'] ?? 'normal')) ?: 'normal',
            'marketing_duration' => trim((string) ($in['marketing_duration'] ?? '')) ?: null,
            'madinah_nights'     => max(0, (int) ($in['madinah_nights'] ?? 0)),
            'makkah_nights'      => max(0, (int) ($in['makkah_nights'] ?? 0)),
            'inclusions'         => json_encode(array_values($incl)),
            'itinerary_order'    => json_encode(array_values($itin)),
            'rooming_note'       => trim((string) ($in['rooming_note'] ?? '')) ?: null,
            'meta_title'         => trim((string) ($in['meta_title'] ?? '')) ?: null,
            'meta_description'   => trim((string) ($in['meta_description'] ?? '')) ?: null,
            'status'             => !empty($in['status']) ? 1 : 0,
        ];

        if ($id > 0) {
            if (!$db->get('umrah_package_templates', 'id', ['id' => $id])) { return ['ok' => false, 'message' => 'Package not found']; }
            $fields['updated_at'] = date('Y-m-d H:i:s');
            $db->update('umrah_package_templates', $fields, ['id' => $id]);
            umrah_admin_audit($db, 'umrah_template', (string) $id, 'updated', ['name' => $name]);
            return ['ok' => true, 'id' => $id];
        }
        $fields['created_at'] = date('Y-m-d H:i:s');
        $db->insert('umrah_package_templates', $fields);
        $newId = (int) $db->id();
        umrah_admin_audit($db, 'umrah_template', (string) $newId, 'created', ['name' => $name]);
        return ['ok' => true, 'id' => $newId];
    }
}

// ---------------------------------------------------------------------------
// TIERS (umrah_tiers)
// ---------------------------------------------------------------------------
if (!function_exists('umrah_admin_tier_save')) {
    /**
     * $in keys: id, code, name, public_label, sort_order, default_occupancy,
     * room_sharing, min_group_same_gender, bookable, status.
     */
    function umrah_admin_tier_save($db, array $in): array
    {
        $id   = (int) ($in['id'] ?? 0);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') { return ['ok' => false, 'message' => 'Tier name is required']; }
        $code = umrah_admin_code((string) ($in['code'] ?? $name), 'tier');

        $dup = $db->get('umrah_tiers', 'id', ['code' => $code]);
        if ($dup && (int) $dup !== $id) { return ['ok' => false, 'message' => 'That tier code is already used']; }

        $fields = [
            'code'                  => $code,
            'name'                  => $name,
            'public_label'          => trim((string) ($in['public_label'] ?? '')) ?: null,
            'sort_order'            => (int) ($in['sort_order'] ?? 0),
            'default_occupancy'     => max(1, (int) ($in['default_occupancy'] ?? 1)),
            'room_sharing'          => trim((string) ($in['room_sharing'] ?? '')) ?: null,
            'min_group_same_gender' => max(0, (int) ($in['min_group_same_gender'] ?? 0)),
            'bookable'              => !empty($in['bookable']) ? 1 : 0,
            'status'                => !empty($in['status']) ? 1 : 0,
        ];

        if ($id > 0) {
            if (!$db->get('umrah_tiers', 'id', ['id' => $id])) { return ['ok' => false, 'message' => 'Tier not found']; }
            $db->update('umrah_tiers', $fields, ['id' => $id]);
            umrah_admin_audit($db, 'umrah_tier', (string) $id, 'updated', ['name' => $name]);
            return ['ok' => true, 'id' => $id];
        }
        $fields['created_at'] = date('Y-m-d H:i:s');
        $db->insert('umrah_tiers', $fields);
        $newId = (int) $db->id();
        umrah_admin_audit($db, 'umrah_tier', (string) $newId, 'created', ['name' => $name]);
        return ['ok' => true, 'id' => $newId];
    }
}

// ---------------------------------------------------------------------------
// PAYMENT PLANS (umrah_payment_plans)
// ---------------------------------------------------------------------------
if (!function_exists('umrah_admin_plan_save')) {
    /**
     * $in keys: id, code, name, deposit_percent, second_percent, final_percent,
     * second_due_days_before, final_due_days_before, grace_hours,
     * price_lock_on_cleared_deposit, active.
     * Percents must sum to 100 (±0.01) so an installment plan is coherent.
     */
    function umrah_admin_plan_save($db, array $in): array
    {
        $id   = (int) ($in['id'] ?? 0);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') { return ['ok' => false, 'message' => 'Plan name is required']; }
        $code = umrah_admin_code((string) ($in['code'] ?? $name), 'pp');

        $dep = round((float) ($in['deposit_percent'] ?? 0), 2);
        $sec = round((float) ($in['second_percent'] ?? 0), 2);
        $fin = round((float) ($in['final_percent'] ?? 0), 2);
        if ($dep <= 0) { return ['ok' => false, 'message' => 'Deposit percent must be greater than 0']; }
        if (abs(($dep + $sec + $fin) - 100.0) > 0.01) {
            return ['ok' => false, 'message' => 'Deposit + second + final must add up to 100% (currently ' . rtrim(rtrim(number_format($dep + $sec + $fin, 2), '0'), '.') . '%)'];
        }

        $dup = $db->get('umrah_payment_plans', 'id', ['code' => $code]);
        if ($dup && (int) $dup !== $id) { return ['ok' => false, 'message' => 'That plan code is already used']; }

        $fields = [
            'code'                          => $code,
            'name'                          => $name,
            'deposit_percent'               => $dep,
            'second_percent'                => $sec,
            'final_percent'                 => $fin,
            'second_due_days_before'        => ($sec > 0) ? max(0, (int) ($in['second_due_days_before'] ?? 45)) : null,
            'final_due_days_before'         => ($fin > 0) ? max(0, (int) ($in['final_due_days_before'] ?? 21)) : null,
            'grace_hours'                   => max(0, (int) ($in['grace_hours'] ?? 72)),
            'price_lock_on_cleared_deposit' => !empty($in['price_lock_on_cleared_deposit']) ? 1 : 0,
            'active'                        => !empty($in['active']) ? 1 : 0,
        ];

        if ($id > 0) {
            if (!$db->get('umrah_payment_plans', 'id', ['id' => $id])) { return ['ok' => false, 'message' => 'Plan not found']; }
            $db->update('umrah_payment_plans', $fields, ['id' => $id]);
            umrah_admin_audit($db, 'umrah_plan', (string) $id, 'updated', ['name' => $name]);
            return ['ok' => true, 'id' => $id];
        }
        $fields['created_at'] = date('Y-m-d H:i:s');
        $db->insert('umrah_payment_plans', $fields);
        $newId = (int) $db->id();
        umrah_admin_audit($db, 'umrah_plan', (string) $newId, 'created', ['name' => $name]);
        return ['ok' => true, 'id' => $newId];
    }
}

// ---------------------------------------------------------------------------
// DEPARTURE edit (create/clone/status/pricing/images live in the admin route)
// ---------------------------------------------------------------------------
if (!function_exists('umrah_admin_departure_update')) {
    /** $in keys: id, departure_date, return_date, origin_city, capacity,
     *  low_stock_threshold, booking_close_at, display_inventory_count. */
    function umrah_admin_departure_update($db, array $in): array
    {
        $id = (int) ($in['id'] ?? 0);
        $dep = $id > 0 ? $db->get('umrah_departures', '*', ['id' => $id]) : null;
        if (!$dep) { return ['ok' => false, 'message' => 'Departure not found']; }

        $depDate = trim((string) ($in['departure_date'] ?? $dep['departure_date']));
        if ($depDate === '' || !strtotime($depDate)) { return ['ok' => false, 'message' => 'A valid departure date is required']; }
        $retDate = trim((string) ($in['return_date'] ?? (string) $dep['return_date']));
        $retDate = ($retDate !== '' && strtotime($retDate)) ? date('Y-m-d', strtotime($retDate)) : null;

        $fields = [
            'departure_date'          => date('Y-m-d', strtotime($depDate)),
            'return_date'             => $retDate,
            'month_bucket'            => date('F Y', strtotime($depDate)),
            'origin_city'             => trim((string) ($in['origin_city'] ?? $dep['origin_city'])) ?: null,
            'capacity'                => max(0, (int) ($in['capacity'] ?? $dep['capacity'])),
            'low_stock_threshold'     => max(0, (int) ($in['low_stock_threshold'] ?? $dep['low_stock_threshold'])),
            'display_inventory_count' => !empty($in['display_inventory_count']) ? 1 : 0,
            'updated_at'              => date('Y-m-d H:i:s'),
        ];
        $bca = trim((string) ($in['booking_close_at'] ?? ''));
        if ($bca !== '') { $fields['booking_close_at'] = date('Y-m-d H:i:s', strtotime($bca)); }

        $db->update('umrah_departures', $fields, ['id' => $id]);
        umrah_admin_audit($db, 'umrah_departure', (string) $id, 'updated', ['date' => $fields['departure_date']]);
        return ['ok' => true, 'id' => $id];
    }
}

// ---------------------------------------------------------------------------
// IMAGES: copy one departure's hero+gallery to EVERY other departure so a good
// image set only has to be uploaded once. Non-destructive to the source.
// ---------------------------------------------------------------------------
if (!function_exists('umrah_admin_images_apply_to_all')) {
    function umrah_admin_images_apply_to_all($db, int $sourceDepartureId, bool $onlyEmpty = false): array
    {
        $src = $db->get('umrah_departures', ['id', 'hero_image', 'gallery'], ['id' => $sourceDepartureId]);
        if (!$src) { return ['ok' => false, 'message' => 'Source departure not found']; }
        $hero = (string) ($src['hero_image'] ?? '');
        $gallery = (string) ($src['gallery'] ?? '');
        if ($hero === '' && ($gallery === '' || $gallery === '[]')) {
            return ['ok' => false, 'message' => 'Upload a hero/gallery on this departure first, then apply to all'];
        }
        $targets = $db->select('umrah_departures', ['id', 'hero_image'], ['id[!]' => $sourceDepartureId]) ?: [];
        $applied = 0;
        foreach ($targets as $t) {
            if ($onlyEmpty && trim((string) ($t['hero_image'] ?? '')) !== '') { continue; }
            $db->update('umrah_departures', ['hero_image' => $hero, 'gallery' => $gallery, 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $t['id']]);
            $applied++;
        }
        umrah_admin_audit($db, 'umrah_departure', (string) $sourceDepartureId, 'images_applied_to_all', ['applied' => $applied]);
        return ['ok' => true, 'applied' => $applied];
    }
}

// ---------------------------------------------------------------------------
// MEDIA LIBRARY — reusable image bank, tagged by service. Admin uploads once,
// reuses everywhere via the picker.
// ---------------------------------------------------------------------------
if (!function_exists('umrah_media_list')) {
    function umrah_media_list($db, string $service = 'umrah', bool $includeArchived = false): array
    {
        $where = ['service' => $service];
        if (!$includeArchived) { $where['archived'] = 0; }
        $where['ORDER'] = ['id' => 'DESC'];
        return $db->select('media_library', '*', $where) ?: [];
    }
}
if (!function_exists('umrah_media_add')) {
    /** Register a URL in the library (used after an upload, or to add an external URL). */
    function umrah_media_add($db, string $url, string $service = 'umrah', ?string $label = null, bool $external = false): array
    {
        $url = trim($url);
        if ($url === '') { return ['ok' => false, 'message' => 'Image URL required']; }
        $service = preg_replace('/[^a-z0-9_\-]/', '', strtolower($service)) ?: 'umrah';
        $db->insert('media_library', [
            'url'         => $url,
            'service'     => $service,
            'label'       => ($label !== null && trim($label) !== '') ? trim($label) : null,
            'is_external' => $external ? 1 : 0,
            'created_by'  => (string) ($_SESSION['user_id'] ?? ''),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->id();
        umrah_admin_audit($db, 'media_library', (string) $id, 'added', ['service' => $service]);
        return ['ok' => true, 'id' => $id, 'url' => $url];
    }
}
if (!function_exists('umrah_media_set_archived')) {
    function umrah_media_set_archived($db, int $id, bool $archive): array
    {
        if ($id <= 0 || !$db->get('media_library', 'id', ['id' => $id])) { return ['ok' => false, 'message' => 'Image not found']; }
        $db->update('media_library', ['archived' => $archive ? 1 : 0], ['id' => $id]);
        umrah_admin_audit($db, 'media_library', (string) $id, $archive ? 'archived' : 'restored', []);
        return ['ok' => true];
    }
}

// ---------------------------------------------------------------------------
// ARCHIVE / RESTORE (generic, whitelisted table + entity name)
// ---------------------------------------------------------------------------
if (!function_exists('umrah_admin_set_archived')) {
    /**
     * Soft-delete (archive=1) or restore (archive=0) a record. Only the four
     * whitelisted product tables are allowed — never an arbitrary table.
     * @param bool $archive true = archive, false = restore
     */
    function umrah_admin_set_archived($db, string $table, int $id, bool $archive): array
    {
        $allowed = [
            'umrah_package_templates' => 'umrah_template',
            'umrah_tiers'             => 'umrah_tier',
            'umrah_payment_plans'     => 'umrah_plan',
            'umrah_departures'        => 'umrah_departure',
        ];
        if (!isset($allowed[$table])) { return ['ok' => false, 'message' => 'Unknown record type']; }
        if ($id <= 0 || !$db->get($table, 'id', ['id' => $id])) { return ['ok' => false, 'message' => 'Record not found']; }

        $db->update($table, ['archived' => $archive ? 1 : 0], ['id' => $id]);
        umrah_admin_audit($db, $allowed[$table], (string) $id, $archive ? 'archived' : 'restored', []);
        return ['ok' => true, 'id' => $id, 'archived' => $archive ? 1 : 0];
    }
}
