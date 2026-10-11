<?php
// FILE: app/routes/users/supplierChannelRoutes.php
// MODULE 12 (channel manager). A REAL iCal feed (public, token-gated, dates-only) +
// owner-facing channel page (feed URL + OTA channel map, with honest 'not connected'
// status for vendor-API sync). supplier_channel.php does the work.

@$SECURE or die('Access Denied!');

// PUBLIC iCal feed — OTAs / calendars subscribe by token. No session; token is the
// capability. Read-only, dates only (no guest PII). Matches the standard iCal-sync model.
$router->get('/ical/([a-f0-9]{32})\.ics', function ($token) use ($SECURE, $db) {
    $site = $db->get('stays_site', ['stay_id'], ['ical_token' => $token]);
    header('Content-Type: text/calendar; charset=utf-8');
    if (!$site) { echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"; exit; } // unknown token → empty cal
    $stayId = (int) $site['stay_id'];
    $stay = $db->get('stays', ['name'], ['id' => $stayId]);
    $events = function_exists('channel_ical_events_for_stay') ? channel_ical_events_for_stay($db, $stayId) : [];
    $ics = function_exists('ical_build')
        ? ical_build($events, (string) ($stay['name'] ?? 'Property') . ' — Availability', date('Y-m-d'))
        : "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
    header('Content-Disposition: inline; filename="availability.ics"');
    echo $ics; exit;
});

// OWNER channel-manager page for a property.
$router->get('/supplier/stays/([0-9]+)/channels', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rates', 'view', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }
    $stay = $db->get('stays', ['id', 'name'], ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }
    $stayId = (int) $id;
    $icalToken = function_exists('channel_ical_token_ensure') ? channel_ical_token_ensure($db, $stayId) : '';
    $icalUrl = $icalToken !== '' ? rtrim(root, '/') . '/ical/' . $icalToken . '.ics' : '';
    $maps = function_exists('channel_map_list') ? channel_map_list($db, $stayId) : [];
    // Room types for the mapping picker.
    $rooms = [];
    try { $rooms = $db->select('stays_rooms', ['id', 'room_type_id'], ['stay_id' => $stayId, 'status' => 1]) ?: []; } catch (\Throwable $e) {}
    $canEdit = supplier_can($db, 'rates', 'edit', $stayId);
    $title = ($stay['name'] ?? 'Property') . ' — Channels'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/channels.php";
    require_once views . "includes/footer.php";
});

$router->post('/supplier/stays/channels/map-add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/channels';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $org = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
    $id = function_exists('channel_map_add') ? channel_map_add($db, $org, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Channel mapping saved. Note: OTA sync activates once the platform connects the vendor adapter.' : 'Could not save (channel required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/stays/channels/map-delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/channels';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    if (function_exists('channel_map_delete')) { channel_map_delete($db, $stayId, (int) ($_POST['map_id'] ?? 0)); }
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Mapping removed.'];
    header('Location: ' . $back); exit;
});
