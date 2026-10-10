<?php
// FILE: app/lib/supplier_reviews.php
// REVIEWS / REPUTATION (Phase 1 inc S31; docs 01 §4.6). Post-stay guest reviews that
// roll up to the property's PUBLIC rating (stays.rating) — the column the public
// listing/detail already reads, so a published review is immediately reflected.
//
// Flow: a checked-out/paid booking yields a tokened review link; the guest submits a
// 1-5 rating + comment (one review per booking, UNIQUE stay+invoice). New reviews
// start 'pending'; the owner publishes or hides them. Only PUBLISHED reviews count
// toward stays.rating + stays.rating_count (recomputed on every status change).
//
// The ONLY write to the live stays row is rating/rating_count (the rollup) — exactly
// the field the public path already consumes. Everything else is in stays_reviews.

if (!function_exists('review_token')) {
    /** A review token binding the invoice (so a link can only review that booking).
     *  Not secret — the submit path re-validates the booking is checked-out + unreviewed. */
    function review_token(string $invoiceId): string { return rtrim(strtr(base64_encode('rv:' . $invoiceId), '+/', '-_'), '='); }
}
if (!function_exists('review_token_decode')) {
    function review_token_decode(string $token): string
    {
        $b64 = strtr($token, '-_', '+/'); $pad = strlen($b64) % 4; if ($pad) { $b64 .= str_repeat('=', 4 - $pad); }
        $raw = base64_decode($b64, true);
        return (is_string($raw) && strpos($raw, 'rv:') === 0) ? substr($raw, 3) : '';
    }
}

if (!function_exists('review_rollup')) {
    /** Recompute stays.rating (avg of PUBLISHED reviews) + stays.rating_count. The only
     *  live-stays write. Non-fatal. Called after any review status change. */
    function review_rollup($db, int $stayId): void
    {
        if ($stayId <= 0) { return; }
        try {
            $count = (int) $db->count('stays_reviews', ['stay_id' => $stayId, 'status' => 'published']);
            $avg = 0.0;
            if ($count > 0) {
                $sum = (float) $db->sum('stays_reviews', 'rating', ['stay_id' => $stayId, 'status' => 'published']);
                $avg = round($sum / $count, 2);
            }
            $data = ['rating' => $avg];
            // rating_count exists on stays after ensureSupplierStaysSchema; guard it.
            try { $data['rating_count'] = $count; $db->update('stays', $data, ['id' => $stayId]); }
            catch (\Throwable $e2) { $db->update('stays', ['rating' => $avg], ['id' => $stayId]); }
        } catch (\Throwable $e) {
            error_log('review_rollup: ' . $e->getMessage());
        }
    }
}

if (!function_exists('review_eligible_booking')) {
    /**
     * Resolve a reviewable booking from an invoice: it must be an own-inventory stay,
     * checked_out (or at least paid), and not already reviewed. Returns
     * ['ok','stay_id','email','guest_name','already','message'].
     */
    function review_eligible_booking($db, string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return ['ok' => false, 'message' => 'No invoice']; }
        try {
            $b = $db->get('bookings', ['invoice_id', 'module_type', 'payment_status', 'booking_data', 'email', 'first_name', 'last_name'],
                ['invoice_id' => $invoiceId]);
            if (!$b) { return ['ok' => false, 'message' => 'Booking not found']; }
            if (!in_array(strtolower((string) $b['module_type']), ['stays', 'hotels'], true)) { return ['ok' => false, 'message' => 'Not a stay']; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            $hid = is_array($bd) ? (int) ($bd['hotel_id'] ?? 0) : 0;
            if ($hid <= 0) { return ['ok' => false, 'message' => 'No property']; }
            $state = function_exists('folio_stay_state') ? folio_stay_state($b['booking_data'] ?? null) : 'confirmed';
            $paid = strtolower((string) $b['payment_status']) === 'paid';
            if ($state !== 'checked_out' && !$paid) { return ['ok' => false, 'message' => 'You can review after your stay.']; }
            $already = $db->has('stays_reviews', ['stay_id' => $hid, 'invoice_id' => $invoiceId]);
            return [
                'ok' => true, 'stay_id' => $hid,
                'email' => function_exists('guest_email_key') ? guest_email_key($b['email'] ?? '') : strtolower(trim((string) ($b['email'] ?? ''))),
                'guest_name' => trim((string) ($b['first_name'] ?? '') . ' ' . (string) ($b['last_name'] ?? '')),
                'already' => (bool) $already,
            ];
        } catch (\Throwable $e) {
            error_log('review_eligible_booking: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Error'];
        }
    }
}

if (!function_exists('review_submit')) {
    /**
     * Submit a guest review for an invoice. One per (stay, invoice) — UNIQUE + a
     * locked pre-check. Clamps rating to 1-5. New review starts 'pending' (owner must
     * publish before it affects the public rating). Returns ['ok','message'].
     */
    function review_submit($db, string $invoiceId, int $rating, string $comment): array
    {
        $elig = review_eligible_booking($db, $invoiceId);
        if (empty($elig['ok'])) { return ['ok' => false, 'message' => $elig['message'] ?? 'Not reviewable']; }
        if (!empty($elig['already'])) { return ['ok' => false, 'message' => 'This stay has already been reviewed.']; }
        $rating = max(1, min(5, (int) $rating));
        $stayId = (int) $elig['stay_id'];

        $result = ['ok' => false, 'message' => 'Could not submit review'];
        try {
            $db->action(function ($db) use ($stayId, $invoiceId, $rating, $comment, $elig, &$result) {
                $lock = $db->query('SELECT id FROM stays_reviews WHERE stay_id=:s AND invoice_id=:i FOR UPDATE',
                    [':s' => $stayId, ':i' => $invoiceId]);
                if ($lock && $lock->fetch(\PDO::FETCH_ASSOC)) { $result = ['ok' => false, 'message' => 'Already reviewed']; return false; }
                $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
                $db->insert('stays_reviews', [
                    'org_id'      => $orgId > 0 ? $orgId : null,
                    'stay_id'     => $stayId,
                    'invoice_id'  => $invoiceId,
                    'guest_email' => (string) ($elig['email'] ?? ''),
                    'guest_name'  => substr((string) ($elig['guest_name'] ?? ''), 0, 120) ?: null,
                    'rating'      => $rating,
                    'comment'     => substr(trim($comment), 0, 2000) ?: null,
                    'status'      => 'pending',
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'message' => 'Thank you — your review has been submitted for moderation.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('review_submit: ' . $e->getMessage());
        }
        return $result;
    }
}

if (!function_exists('review_set_status')) {
    /** Owner moderation: set a review's status (published|hidden|pending) and re-roll
     *  the property rating. Validates the review belongs to $stayId. Returns bool. */
    function review_set_status($db, int $reviewId, int $stayId, string $status): bool
    {
        if (!in_array($status, ['published', 'hidden', 'pending'], true)) { return false; }
        try {
            $r = $db->get('stays_reviews', ['id', 'stay_id'], ['id' => $reviewId, 'stay_id' => $stayId]);
            if (!$r) { return false; }
            $db->update('stays_reviews', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $reviewId, 'stay_id' => $stayId]);
            review_rollup($db, $stayId); // published set changed → recompute public rating
            return true;
        } catch (\Throwable $e) {
            error_log('review_set_status: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('review_list_for_org')) {
    /** Reviews across the owner's properties (optional status filter). Read-only. */
    function review_list_for_org($db, array $stayIds, string $status = '', int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        $where = ['stay_id' => $stayIds];
        if (in_array($status, ['pending', 'published', 'hidden'], true)) { $where['status'] = $status; }
        $where['ORDER'] = ['id' => 'DESC'];
        $where['LIMIT'] = max(1, $limit);
        try {
            return $db->select('stays_reviews',
                ['id', 'stay_id', 'invoice_id', 'guest_name', 'rating', 'comment', 'status', 'created_at'],
                $where) ?: [];
        } catch (\Throwable $e) {
            error_log('review_list_for_org: ' . $e->getMessage());
            return [];
        }
    }
}
