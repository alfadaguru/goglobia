<?php
// app/routes/admin/flightShellCarriersRoutes.php
// Admin CRUD for flight_shell_carriers — the DB-driven, per-supplier + per-country
// shell-carrier correction rules (e.g. Adivaha "A1"/"Atifly" -> real operating
// carrier on Nigerian flights). Engine + table: app/lib/functions.php
// (ensureFlightShellCarriers / flight_shell_rule_matches).
@$SECURE or die('Access Denied!');

if (!function_exists('_fsc_match_modes')) {
    /** Allowed match_mode values + human labels (how the country binds to the flight). */
    function _fsc_match_modes(): array
    {
        return [
            'either' => 'Either endpoint in country (from OR to)',
            'from'   => 'Departs from the country',
            'to'     => 'Arrives in the country',
            'both'   => 'Both endpoints in country (domestic)',
        ];
    }
}

// ================================ LIST
$router->get(admin.'/flight-shell-carriers', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'flight_shell_carriers', 'view');
    if (function_exists('ensureFlightShellCarriers')) ensureFlightShellCarriers($db);

    $title = 'Flight Shell-Carrier Rules';
    $description = '';
    $header = true;
    $footer = true;

    require_once views . "includes/header.php";
    require_once "app/views/admin/flights/shell-carriers.php";
    require_once views . "includes/footer.php";
});

// ================================ ADD (form)
$router->get(admin.'/flight-shell-carriers/add', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'flight_shell_carriers', 'add');

    $mode = 'add';
    $rule = [
        'id' => 0, 'supplier' => '', 'shell_code' => '', 'country' => '',
        'match_mode' => 'either', 'active' => 1, 'note' => '',
    ];
    $title = 'Add Shell-Carrier Rule';
    $header = true; $footer = true;

    require_once views . "includes/header.php";
    require_once "app/views/admin/flights/manage-shell-carrier.php";
    require_once views . "includes/footer.php";
});

// ================================ ADD (save)
$router->post(admin.'/flight-shell-carriers/add', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'flight_shell_carriers', 'add');
    CSRF::guard();
    if (function_exists('ensureFlightShellCarriers')) ensureFlightShellCarriers($db);

    $data = _fsc_sanitize_post();
    if ($data['error']) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $data['error']];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flight-shell-carriers/add');
        return;
    }
    try {
        $db->insert('flight_shell_carriers', $data['row']);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Shell-carrier rule added.'];
        redirect(root . admin . '/flight-shell-carriers');
    } catch (\Throwable $e) {
        // Most likely the UNIQUE(supplier,shell_code,country) constraint.
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not add rule — a rule for that supplier + code + country may already exist.'];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flight-shell-carriers/add');
    }
});

// ================================ EDIT (form)
$router->get(admin.'/flight-shell-carriers/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'flight_shell_carriers', 'edit');

    $rule = $db->get('flight_shell_carriers', '*', ['id' => (int) $id]);
    if (!$rule) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Rule not found.'];
        redirect(root . admin . '/flight-shell-carriers');
        return;
    }
    $mode = 'edit';
    $title = 'Edit Shell-Carrier Rule';
    $header = true; $footer = true;

    require_once views . "includes/header.php";
    require_once "app/views/admin/flights/manage-shell-carrier.php";
    require_once views . "includes/footer.php";
});

// ================================ EDIT (save)
$router->post(admin.'/flight-shell-carriers/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'flight_shell_carriers', 'edit');
    CSRF::guard();

    $id = (int) $id;
    if (!$db->get('flight_shell_carriers', ['id'], ['id' => $id])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Rule not found.'];
        redirect(root . admin . '/flight-shell-carriers');
        return;
    }
    $data = _fsc_sanitize_post();
    if ($data['error']) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $data['error']];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flight-shell-carriers/edit/' . $id);
        return;
    }
    try {
        $db->update('flight_shell_carriers', $data['row'], ['id' => $id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Shell-carrier rule updated.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not update rule — a duplicate supplier + code + country rule may exist.'];
    }
    redirect(root . admin . '/flight-shell-carriers');
});

// ================================ DELETE
$router->post(admin.'/flight-shell-carriers/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'flight_shell_carriers', 'delete');
    CSRF::guard();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->delete('flight_shell_carriers', ['id' => $id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Rule deleted.'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid rule id.'];
    }
    redirect(root . admin . '/flight-shell-carriers');
});

if (!function_exists('_fsc_sanitize_post')) {
    /** Validate + normalise the add/edit form POST into a row array. */
    function _fsc_sanitize_post(): array
    {
        $supplier   = strtolower(trim((string) ($_POST['supplier'] ?? '')));      // '' = any provider
        $shell_code = strtoupper(trim((string) ($_POST['shell_code'] ?? '')));
        $country    = trim((string) ($_POST['country'] ?? ''));                   // '' = any country
        $match_mode = strtolower(trim((string) ($_POST['match_mode'] ?? 'either')));
        $active     = isset($_POST['active']) && (string) $_POST['active'] === '1' ? 1 : 0;
        $note       = trim((string) ($_POST['note'] ?? ''));

        if ($shell_code === '') {
            return ['error' => 'Shell code is required (e.g. A1).', 'row' => null];
        }
        if (strlen($shell_code) > 8) {
            return ['error' => 'Shell code must be 8 characters or fewer.', 'row' => null];
        }
        if (!array_key_exists($match_mode, _fsc_match_modes())) {
            $match_mode = 'either';
        }
        return ['error' => null, 'row' => [
            'supplier'   => $supplier,
            'shell_code' => $shell_code,
            'country'    => $country,
            'match_mode' => $match_mode,
            'active'     => $active,
            'note'       => ($note !== '' ? $note : null),
        ]];
    }
}
