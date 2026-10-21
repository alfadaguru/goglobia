<?php
// app/routes/admin/discountsRoutes.php
// Admin CRUD for the automatic discount engine (app/lib/discounts.php).
// Marketing group. Mirrors the promo-codes admin conventions (ADMIN_AUTH first,
// CSRF on writes, reuses the promo item-search endpoints for product targeting).
@$SECURE or die('Access Denied!');

// ---- helper: sanitize + build a discounts row from POST ----
if (!function_exists('_discount_row_from_post')) {
    function _discount_row_from_post($db): array
    {
        $type  = in_array($_POST['discount_type'] ?? '', ['percentage','fixed','tiered','override'], true) ? $_POST['discount_type'] : 'percentage';
        $scope = in_array($_POST['scope_type'] ?? '', ['all','service','product','provider'], true) ? $_POST['scope_type'] : 'all';

        // Tiers (only for tiered): [{min,type,value}] — accept posted arrays.
        $tiersJson = null;
        if ($type === 'tiered') {
            $mins   = (array) ($_POST['tier_min'] ?? []);
            $ttypes = (array) ($_POST['tier_type'] ?? []);
            $tvals  = (array) ($_POST['tier_value'] ?? []);
            $tiers  = [];
            foreach ($mins as $i => $m) {
                $m = (float) $m; $tv = (float) ($tvals[$i] ?? 0);
                $tt = in_array($ttypes[$i] ?? '', ['percentage','fixed'], true) ? $ttypes[$i] : 'percentage';
                if ($tv > 0) { $tiers[] = ['min' => $m, 'type' => $tt, 'value' => $tv]; }
            }
            $tiersJson = !empty($tiers) ? json_encode($tiers) : null;
        }

        // Product ids (only for product scope): CSV or array of ints.
        $productIds = null;
        if ($scope === 'product') {
            $raw = $_POST['scope_product_ids'] ?? '';
            $ids = is_array($raw) ? $raw : array_filter(array_map('trim', explode(',', (string) $raw)));
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $ids = array_filter($ids, fn($v) => $v > 0);
            $productIds = !empty($ids) ? json_encode(array_values($ids)) : null;
        }

        $scopeModule = in_array($scope, ['service','product'], true)
            ? trim((string) ($_POST['scope_module'] ?? '')) ?: null
            : null;

        // Provider scope: either an integration supplier name OR an own-inventory owner.
        $scopeProvider = ($scope === 'provider') ? (trim((string) ($_POST['scope_provider'] ?? '')) ?: null) : null;
        $scopeOwner    = ($scope === 'provider') ? (trim((string) ($_POST['scope_owner'] ?? ''))    ?: null) : null;

        $audience = trim((string) ($_POST['audience'] ?? ''));
        $audience = in_array($audience, ['customer','agent','supplier','staff'], true) ? $audience : null;

        return [
            'name'                => trim((string) ($_POST['name'] ?? '')) ?: 'Untitled discount',
            'description'         => trim((string) ($_POST['description'] ?? '')) ?: null,
            'discount_type'       => $type,
            'discount_value'      => ($type === 'tiered') ? 0 : max(0, (float) ($_POST['discount_value'] ?? 0)),
            'tiers_json'          => $tiersJson,
            'scope_type'          => $scope,
            'scope_module'        => $scopeModule,
            'scope_product_ids'   => $productIds,
            'scope_provider'      => $scopeProvider,
            'scope_owner'         => $scopeOwner,
            'audience'            => $audience,
            'currency'            => trim((string) ($_POST['currency'] ?? '')) ?: null,
            'min_order_amount'    => ($_POST['min_order_amount'] ?? '') !== '' ? (float) $_POST['min_order_amount'] : null,
            'max_discount_amount' => ($_POST['max_discount_amount'] ?? '') !== '' ? (float) $_POST['max_discount_amount'] : null,
            'margin_floor_pct'    => max(0, (float) ($_POST['margin_floor_pct'] ?? 0)),
            'priority'            => (int) ($_POST['priority'] ?? 0),
            'start_date'          => trim((string) ($_POST['start_date'] ?? '')) ?: null,
            'end_date'            => trim((string) ($_POST['end_date'] ?? '')) ?: null,
            'status'              => isset($_POST['status']) && $_POST['status'] == '1' ? 1 : 0,
        ];
    }
}

// ================================ LIST
$router->get(admin.'/discounts', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureDiscountSchema')) { ensureDiscountSchema($db); }
    $title = 'Discounts'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/discounts/discounts.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE / EDIT form
$router->get(admin.'/discounts/add', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureDiscountSchema')) { ensureDiscountSchema($db); }
    $mode = 'add'; $discount = null;
    $title = 'Add Discount'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/discounts/manage-discount.php";
    require_once views."includes/footer.php";
});

$router->get(admin.'/discounts/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureDiscountSchema')) { ensureDiscountSchema($db); }
    $discount = $db->get('discounts', '*', ['id' => (int) $id]);
    if (!$discount) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Discount not found.'];
        redirect(root . admin . '/discounts'); return;
    }
    $mode = 'edit';
    $title = 'Edit Discount'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/discounts/manage-discount.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (add or edit)
$router->post(admin.'/discounts/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    if (function_exists('ensureDiscountSchema')) { ensureDiscountSchema($db); }

    $id   = (int) ($_POST['id'] ?? 0);
    $data = _discount_row_from_post($db);

    try {
        if ($id > 0) {
            $db->update('discounts', $data, ['id' => $id]);
        } else {
            $data['created_by'] = (int) ($_SESSION['user_id'] ?? 0) ?: null;
            $db->insert('discounts', $data);
            $id = (int) $db->id();
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Discount saved.'];
        redirect(root . admin . '/discounts/edit/' . $id);
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/discounts');
    }
});

// ================================ DELETE
$router->post(admin.'/discounts/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($id > 0) { $db->delete('discounts', ['id' => $id]); }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Discount deleted.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    redirect(root . admin . '/discounts');
});

// ================================ TOGGLE STATUS (JSON)
$router->post(admin.'/discounts/toggle', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!CSRF::validateToken((string) $token)) {
        header('Content-Type: application/json'); http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Session expired.']); exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    header('Content-Type: application/json');
    try {
        $row = $db->get('discounts', ['id', 'status'], ['id' => $id]);
        if (!$row) { echo json_encode(['success' => false]); exit; }
        $new = ((int) $row['status'] === 1) ? 0 : 1;
        $db->update('discounts', ['status' => $new], ['id' => $id]);
        echo json_encode(['success' => true, 'status' => $new]);
    } catch (\Throwable $e) {
        http_response_code(500); echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});
