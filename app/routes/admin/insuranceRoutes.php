<?php
// app/routes/admin/insuranceRoutes.php
// Admin CRUD for own-inventory travel-insurance plans (customer-flow audit #12).
// Manages the `insurance_plans` table the public /insurance/plans storefront sells.
// Mirrors the tours admin screen (CRUD list + add/edit form + save/delete), gated
// by ADMIN_AUTH() + admin_require($db,'insurance',...).
@$SECURE or die('Access Denied!');

// ===== LIST =====
$router->get(admin . '/insurance', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'insurance', 'view');
    if (function_exists('ensureInsurancePlansSchema')) { ensureInsurancePlansSchema($db); }
    $title = 'Insurance Plans';
    $description = '';
    $header = true;
    $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/insurance/plans.php";
    require_once views . "includes/footer.php";
});

// ===== ADD FORM =====
$router->get(admin . '/insurance/add', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'insurance', 'add');
    if (function_exists('ensureInsurancePlansSchema')) { ensureInsurancePlansSchema($db); }
    $mode = 'add';
    $plan = [];
    $currencies = $GLOBALS['currencies'] ?? ['NGN' => 'NGN'];
    $title = 'Add Insurance Plan';
    $description = '';
    $header = true;
    $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/insurance/manage.php";
    require_once views . "includes/footer.php";
});

// ===== EDIT FORM =====
$router->get(admin . '/insurance/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'insurance', 'edit');
    $plan = $db->get('insurance_plans', '*', ['id' => (int) $id]);
    if (!$plan) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Plan not found.'];
        header('Location: ' . root . admin . '/insurance');
        exit;
    }
    $mode = 'edit';
    $currencies = $GLOBALS['currencies'] ?? ['NGN' => 'NGN'];
    $title = 'Edit Insurance Plan';
    $description = '';
    $header = true;
    $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/insurance/manage.php";
    require_once views . "includes/footer.php";
});

// ===== SAVE (add + edit) =====
$router->post(admin . '/insurance/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $id = (int) ($_POST['id'] ?? 0);
    admin_require($db, 'insurance', $id > 0 ? 'edit' : 'add');
    CSRF::guard();

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Plan name is required.'];
        header('Location: ' . root . admin . '/insurance/' . ($id > 0 ? 'edit/' . $id : 'add'));
        exit;
    }

    // Slug: use provided, else derive from name; ensure uniqueness (append -id).
    $slug = trim($_POST['slug'] ?? '');
    if ($slug === '') {
        $slug = function_exists('slugify') ? slugify($name) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
    }
    $slug = trim($slug, '-') ?: ('plan-' . substr(md5($name), 0, 6));

    $data = [
        'name'             => $name,
        'slug'             => $slug,
        'provider_label'   => trim($_POST['provider_label'] ?? ''),
        'description'      => trim($_POST['description'] ?? ''),
        'coverage_details' => trim($_POST['coverage_details'] ?? ''),
        'currency'         => strtoupper(trim($_POST['currency'] ?? 'NGN')),
        'price'            => round((float) ($_POST['price'] ?? 0), 2),
        'coverage_amount'  => ($_POST['coverage_amount'] ?? '') !== '' ? round((float) $_POST['coverage_amount'], 2) : null,
        'duration_days'    => ($_POST['duration_days'] ?? '') !== '' ? (int) $_POST['duration_days'] : null,
        'min_age'          => ($_POST['min_age'] ?? '') !== '' ? (int) $_POST['min_age'] : null,
        'max_age'          => ($_POST['max_age'] ?? '') !== '' ? (int) $_POST['max_age'] : null,
        'region'           => trim($_POST['region'] ?? ''),
        'featured'         => !empty($_POST['featured']) ? 1 : 0,
        'status'           => isset($_POST['status']) ? (int) !empty($_POST['status']) : 1,
        'order'            => (int) ($_POST['order'] ?? 0),
        'updated_at'       => date('Y-m-d H:i:s'),
    ];

    try {
        if ($id > 0) {
            // Keep slug unique excluding self.
            $clash = $db->get('insurance_plans', ['id'], ['slug' => $slug, 'id[!]' => $id]);
            if ($clash) { $data['slug'] = $slug . '-' . $id; }
            $db->update('insurance_plans', $data, ['id' => $id]);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Insurance plan updated.'];
        } else {
            $clash = $db->get('insurance_plans', ['id'], ['slug' => $slug]);
            $data['created_at'] = date('Y-m-d H:i:s');
            if ($clash) { $data['slug'] = $slug . '-' . substr(md5(uniqid('', true)), 0, 4); }
            $db->insert('insurance_plans', $data);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Insurance plan created.'];
        }
    } catch (\Throwable $e) {
        error_log('insurance plan save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save the plan: ' . $e->getMessage()];
        header('Location: ' . root . admin . '/insurance/' . ($id > 0 ? 'edit/' . $id : 'add'));
        exit;
    }

    header('Location: ' . root . admin . '/insurance');
    exit;
});

// ===== DELETE (non-ajax fallback; the list also deletes via CRUD ajax) =====
$router->get(admin . '/insurance/delete/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'insurance', 'delete');
    try {
        $db->delete('insurance_plans', ['id' => (int) $id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Insurance plan deleted.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not delete the plan.'];
    }
    header('Location: ' . root . admin . '/insurance');
    exit;
});
