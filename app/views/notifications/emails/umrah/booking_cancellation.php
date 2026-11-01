<?php
// app/views/notifications/emails/umrah/booking_cancellation.php
// Thin wrapper → the shared premium cancellation body.
@$SECURE or die('Access Denied!');
$cxServiceLabel = 'Umrah';
$cxModule = 'umrah';
require __DIR__ . '/../_cancellation_body.php';
