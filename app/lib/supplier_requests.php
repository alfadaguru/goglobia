<?php
// FILE: app/lib/supplier_requests.php
// SUPPLIER SERVICE / QUOTA REQUESTS (Phase 1 inc S34).
//
// Lets an APPROVED supplier ask for (a) a brand-new service they weren't granted at
// signup, or (b) a higher listing quota on a service they already have. Requests go
// to an admin queue; approval applies the change to supplier_services (the live grant
// table) WITHOUT disturbing the supplier's existing access while a request is pending
// — the request lives in its own stays... no, supplier_quota_requests table.
//
// kind:
//   'new'      — the owner has no supplier_services row for this service yet; approval
//                INSERTS an approved row with max_listings = requested_count.
//   'increase' — the owner already has an (approved) service; approval RAISES
//                supplier_services.max_listings to the requested_count.
//
// All server-validated: the service must be a real first-class service; counts are
// clamped; one PENDING request per (owner, service) at a time. Non-fatal.

if (!function_exists('supplier_request_create')) {
    /**
     * Create a service/quota request for the owner. Returns ['ok','kind','message'].
     * Refuses a second pending request for the same service.
     */
    function supplier_request_create($db, string $owner, int $orgId, string $service, int $count): array
    {
        $owner = trim($owner);
        $service = strtolower(trim($service));
        $count = max(1, min(500, (int) $count));
        if ($owner === '') { return ['ok' => false, 'message' => 'No owner']; }

        $catalogue = supplier_first_class_services($db);
        if (!isset($catalogue[$service])) { return ['ok' => false, 'message' => 'Unknown service.']; }

        try {
            // Already a pending request for this service?
            if ($db->has('supplier_quota_requests', ['owner_user_id' => $owner, 'service' => $service, 'status' => 'pending'])) {
                return ['ok' => false, 'message' => 'You already have a pending request for this service.'];
            }
            // Determine kind from the current grant.
            $existing = $db->get('supplier_services', ['status', 'max_listings'], ['user_id' => $owner, 'service' => $service]);
            $kind = ($existing && ($existing['status'] ?? '') === 'approved') ? 'increase' : 'new';
            // For an increase, the requested_count must exceed the current cap.
            if ($kind === 'increase') {
                $curMax = isset($existing['max_listings']) ? (int) $existing['max_listings'] : 0;
                if ($count <= $curMax) { return ['ok' => false, 'message' => 'Request a quota higher than your current ' . $curMax . '.']; }
            }
            $db->insert('supplier_quota_requests', [
                'org_id'          => $orgId > 0 ? $orgId : null,
                'owner_user_id'   => $owner,
                'service'         => $service,
                'kind'            => $kind,
                'requested_count' => $count,
                'status'          => 'pending',
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
            if (function_exists('emit_event')) {
                emit_event($db, 'supplier.service_requested', ['service' => $service, 'kind' => $kind, 'count' => $count], 'user', $owner, $orgId > 0 ? $orgId : null);
            }
            return ['ok' => true, 'kind' => $kind, 'message' => ($kind === 'new' ? 'Service request' : 'Quota increase request') . ' submitted for review.'];
        } catch (\Throwable $e) {
            error_log('supplier_request_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not submit the request.'];
        }
    }
}

if (!function_exists('supplier_request_decide')) {
    /**
     * Admin decision on a request. $decision ∈ approve|reject. On approve, apply the
     * change to supplier_services (insert-approved for 'new', raise max_listings for
     * 'increase'), atomically + idempotently (status guard on the request). Returns
     * ['ok','message']. Caller must be admin (route enforces ADMIN_AUTH).
     */
    function supplier_request_decide($db, int $requestId, string $decision, string $comment = ''): array
    {
        $decision = $decision === 'approve' ? 'approve' : ($decision === 'reject' ? 'reject' : '');
        if ($requestId <= 0 || $decision === '') { return ['ok' => false, 'message' => 'Bad input']; }

        $result = ['ok' => false, 'message' => 'Decision failed'];
        try {
            $db->action(function ($db) use ($requestId, $decision, $comment, &$result) {
                $locked = $db->query('SELECT * FROM supplier_quota_requests WHERE id=:r FOR UPDATE', [':r' => $requestId]);
                $req = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$req || $req['status'] !== 'pending') { $result = ['ok' => false, 'message' => 'Request already decided.']; return false; }

                $owner = (string) $req['owner_user_id'];
                $service = (string) $req['service'];
                $count = (int) $req['requested_count'];
                $now = date('Y-m-d H:i:s');
                $by = (string) ($_SESSION['user_id'] ?? '');

                if ($decision === 'approve') {
                    $existing = $db->get('supplier_services', ['id', 'max_listings'], ['user_id' => $owner, 'service' => $service]);
                    if ($existing) {
                        // Increase (or re-approve): raise the cap to the requested count.
                        $newMax = max((int) ($existing['max_listings'] ?? 0), $count);
                        $db->update('supplier_services',
                            ['status' => 'approved', 'max_listings' => $newMax, 'reviewed_by' => $by, 'reviewed_at' => $now],
                            ['id' => (int) $existing['id']]);
                    } else {
                        // New grant.
                        $db->insert('supplier_services', [
                            'user_id'         => $owner,
                            'service'         => $service,
                            'requested_count' => $count,
                            'status'          => 'approved',
                            'max_listings'    => $count,
                            'reviewed_by'     => $by,
                            'requested_at'    => $now,
                            'reviewed_at'     => $now,
                        ]);
                    }
                }

                $db->update('supplier_quota_requests', [
                    'status'        => $decision === 'approve' ? 'approved' : 'rejected',
                    'review_comment'=> substr(trim($comment), 0, 255) ?: null,
                    'reviewed_by'   => $by,
                    'reviewed_at'   => $now,
                ], ['id' => $requestId, 'status' => 'pending']);
                $result = ['ok' => true, 'message' => $decision === 'approve' ? 'Request approved and quota applied.' : 'Request rejected.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('supplier_request_decide: ' . $e->getMessage());
        }
        return $result;
    }
}

if (!function_exists('supplier_requests_for_owner')) {
    /** An owner's own requests (read-only). */
    function supplier_requests_for_owner($db, string $owner, int $limit = 50): array
    {
        $owner = trim($owner);
        if ($owner === '') { return []; }
        try {
            return $db->select('supplier_quota_requests',
                ['id', 'service', 'kind', 'requested_count', 'status', 'review_comment', 'created_at'],
                ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('supplier_requests_for_owner: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('supplier_requests_pending')) {
    /** The admin queue: all pending requests (+ owner display). Read-only. */
    function supplier_requests_pending($db, int $limit = 300): array
    {
        try {
            return $db->select('supplier_quota_requests',
                ['id', 'owner_user_id', 'service', 'kind', 'requested_count', 'status', 'created_at'],
                ['status' => 'pending', 'ORDER' => ['id' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('supplier_requests_pending: ' . $e->getMessage()); return []; }
    }
}
