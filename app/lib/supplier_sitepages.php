<?php
// FILE: app/lib/supplier_sitepages.php
// MODULE 13 (website builder → custom pages). Supplier-authored pages (About / Contact /
// Policies / FAQ / arbitrary) for a property's branded site, served publicly at
// /book/{slug}/{page_slug}. Storage is the stays_site_pages table (see
// ensureSupplierStaysSchema). Content is stored RAW and ALWAYS rendered through
// htmlspecialchars by the view — this layer NEVER emits supplier HTML. All writes are
// authorized by the caller (supplier_can hotels.edit); this layer trusts the stay_id it
// is given and only sanitizes/shapes the data.

if (!function_exists('sitepages_slugify')) {
    /** Lowercase, hyphenated, ascii-only slug (<=60 chars). Empty on nothing usable. */
    function sitepages_slugify(string $in): string
    {
        $s = strtolower(trim($in));
        // transliterate if available, else non-ascii is stripped below. TRANSLIT can emit
        // combining marks (é -> 'e, ü -> "u); drop those apostrophe/quote artefacts so
        // "Héllo" becomes "hello", not "h-ello".
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) { $s = $t; }
        }
        $s = str_replace(['\'', '"', '`', '^', '~'], '', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string) $s, '-');
        return substr((string) $s, 0, 60);
    }
}

if (!function_exists('sitepages_reserved_slugs')) {
    /** Slugs the public branded router already owns — a custom page may NOT use them. */
    function sitepages_reserved_slugs(): array
    {
        // /book/{slug}/preview etc. are not routes today, but reserve the obvious ones so a
        // future route can't be shadowed by a custom page, and so the page index link is safe.
        return ['', 'book', 'rooms', 'preview', 'ical', 'checkin', 'stay-checkin'];
    }
}

if (!function_exists('sitepages_list')) {
    /**
     * All pages for a property, ordered (sort, then title). $publishedOnly=true for the
     * public site; false for the owner editor (shows drafts too).
     */
    function sitepages_list($db, int $stayId, bool $publishedOnly = false): array
    {
        if ($stayId <= 0) { return []; }
        try {
            $where = ['stay_id' => $stayId];
            if ($publishedOnly) { $where['published'] = 1; }
            $where['ORDER'] = ['sort' => 'ASC', 'title' => 'ASC'];
            return $db->select('stays_site_pages', ['id', 'stay_id', 'slug', 'title', 'body', 'sort', 'published'], $where) ?: [];
        } catch (\Throwable $e) { error_log('sitepages_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('sitepages_get')) {
    /** One page by (stay, slug). $publishedOnly=true for public reads. Null if absent. */
    function sitepages_get($db, int $stayId, string $slug, bool $publishedOnly = false): ?array
    {
        if ($stayId <= 0 || $slug === '') { return null; }
        try {
            $where = ['stay_id' => $stayId, 'slug' => $slug];
            if ($publishedOnly) { $where['published'] = 1; }
            $row = $db->get('stays_site_pages', ['id', 'stay_id', 'slug', 'title', 'body', 'sort', 'published'], $where);
            return $row ?: null;
        } catch (\Throwable $e) { error_log('sitepages_get: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('sitepages_save')) {
    /**
     * Create or update a custom page. Caller MUST have authorized $stayId (hotels.edit).
     * $in keys: id (0=new), title, slug (optional — derived from title if blank), body,
     * sort, published. Returns ['ok'=>bool,'message'=>string,'id'=>int].
     */
    function sitepages_save($db, int $stayId, array $in): array
    {
        if ($stayId <= 0) { return ['ok' => false, 'message' => 'Invalid property.']; }
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') { return ['ok' => false, 'message' => 'A page title is required.']; }
        $title = substr($title, 0, 160);

        // Slug: use supplied if present, else derive from title. Validate + fall back.
        $slug = sitepages_slugify((string) ($in['slug'] ?? ''));
        if ($slug === '') { $slug = sitepages_slugify($title); }
        if ($slug === '') { return ['ok' => false, 'message' => 'Could not build a page address from that title.']; }
        if (in_array($slug, sitepages_reserved_slugs(), true)) {
            return ['ok' => false, 'message' => 'That page address is reserved — choose another.'];
        }

        $body      = substr((string) ($in['body'] ?? ''), 0, 20000);
        $sort      = max(0, min(9999, (int) ($in['sort'] ?? 0)));
        $id        = (int) ($in['id'] ?? 0);
        // Published intent. An unchecked HTML checkbox submits NOTHING, so a bare
        // !empty($in['published']) check can't tell "unchecked" from "field omitted".
        // The form sends a hidden marker `published_submitted=1` whenever the checkbox
        // was on screen: present → honour the checkbox exactly (checked=1 / unchecked=0);
        // absent (non-form caller / quick-add with no flag) → default PUBLISHED, which
        // matches the UI's checked-by-default and the common "create it live" intent.
        if (array_key_exists('published_submitted', $in)) {
            $published = !empty($in['published']) ? 1 : 0;
        } elseif (array_key_exists('published', $in)) {
            $published = !empty($in['published']) ? 1 : 0;
        } else {
            $published = 1;
        }

        try {
            // Uniqueness of (stay_id, slug) — a different page can't already own this slug.
            $clash = $db->get('stays_site_pages', ['id'], ['stay_id' => $stayId, 'slug' => $slug]);
            if ($clash && (int) $clash['id'] !== $id) {
                return ['ok' => false, 'message' => 'You already have a page at that address.'];
            }

            if ($id > 0) {
                // Update only within THIS property (defence in depth — stay_id in WHERE).
                $own = $db->get('stays_site_pages', ['id'], ['id' => $id, 'stay_id' => $stayId]);
                if (!$own) { return ['ok' => false, 'message' => 'Page not found.']; }
                $db->update('stays_site_pages', [
                    'title' => $title, 'slug' => $slug, 'body' => $body,
                    'sort' => $sort, 'published' => $published,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $id, 'stay_id' => $stayId]);
                return ['ok' => true, 'message' => 'Page updated.', 'id' => $id];
            }

            $db->insert('stays_site_pages', [
                'stay_id' => $stayId, 'title' => $title, 'slug' => $slug, 'body' => $body,
                'sort' => $sort, 'published' => $published,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'message' => 'Page created.', 'id' => (int) $db->id()];
        } catch (\Throwable $e) {
            error_log('sitepages_save: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save the page.'];
        }
    }
}

if (!function_exists('sitepages_delete')) {
    /** Delete a page, scoped to the property. Caller authorized $stayId. Returns bool. */
    function sitepages_delete($db, int $stayId, int $id): bool
    {
        if ($stayId <= 0 || $id <= 0) { return false; }
        try {
            $db->delete('stays_site_pages', ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('sitepages_delete: ' . $e->getMessage()); return false; }
    }
}
