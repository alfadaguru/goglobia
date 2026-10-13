<?php
@$SECURE or die('Access Denied!');
require_once 'app/lib/stays_booking_functions.php';

$router->post('/book/([a-z0-9][a-z0-9-]*)/checkout', function ($slug) use ($SECURE, $db) {
    try {
        $stay = $db->get('stays', ['id', 'org_id', 'name'], 
            ['slug' => $slug, 'status' => 1, 'listing_status' => 'approved']);
        
        if (!$stay) throw new Exception('Property not found');
        
        $stayId = (int) $stay['id'];
        $orgId = (int) $stay['org_id'];
        
        $bookingResult = stays_booking_create($db, [
            'org_id' => $orgId,
            'stay_id' => $stayId,
            'room_id' => (int) ($_POST['room_id'] ?? 0),
            'option_id' => !empty($_POST['option_id']) ? (int) $_POST['option_id'] : null,
            'check_in_date' => trim((string) ($_POST['check_in_date'] ?? '')),
            'check_out_date' => trim((string) ($_POST['check_out_date'] ?? '')),
            'guest_email' => trim((string) ($_POST['guest_email'] ?? '')),
            'guest_name_first' => trim((string) ($_POST['guest_name_first'] ?? '')),
            'guest_name_last' => trim((string) ($_POST['guest_name_last'] ?? '')),
            'guest_phone' => trim((string) ($_POST['guest_phone'] ?? '')),
            'total_price' => (float) ($_POST['total_price'] ?? 0),
            'currency' => trim((string) ($_POST['currency'] ?? 'USD')),
            'guest_special_requests' => trim((string) ($_POST['special_requests'] ?? '')),
        ]);
        
        if (!$bookingResult['ok']) throw new Exception($bookingResult['message']);
        
        $staysBookingId = (int) $bookingResult['data']['stays_booking_id'];
        $invoiceId = 'stays_' . bin2hex(random_bytes(6));
        $paymentIntent = $bookingResult['data']['reference'] . '_' . bin2hex(random_bytes(4));
        
        $bookingId = $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',
            'price_original' => $_POST['total_price'] ?? 0,
            'currency_markup' => $_POST['currency'] ?? 'USD',
            'first_name' => $_POST['guest_name_first'] ?? '',
            'last_name' => $_POST['guest_name_last'] ?? '',
            'email' => $_POST['guest_email'] ?? '',
            'phone' => $_POST['guest_phone'] ?? '',
            'module_type' => 'stays',
            'module' => 'stays',
            'white_label_booking_id' => $staysBookingId,
            'supplier_org_id' => $orgId,
            'supplier_booking_reference' => $bookingResult['data']['reference'],
            'payment_intent' => $paymentIntent,
            'special_requests' => $_POST['special_requests'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        if (!$bookingId) throw new Exception('Failed to create booking');
        
        $db->update('stays_bookings', ['guest_booking_id' => (int) $bookingId], ['id' => $staysBookingId]);
        header('Location: ' . root . 'invoice/stays/' . $invoiceId . '?token=' . urlencode($paymentIntent));
        exit;
        
    } catch (\Throwable $e) {
        error_log('Checkout error: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        header('Location: ' . root . 'book/' . $slug);
        exit;
    }
});
