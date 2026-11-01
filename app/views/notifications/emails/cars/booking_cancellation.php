<?php
// app/views/notifications/emails/cars/booking_cancellation.php
// Thin wrapper → the shared premium cancellation body.
@$SECURE or die('Access Denied!');
$cxServiceLabel = 'car rental';
$cxModule = 'cars';
require __DIR__ . '/../_cancellation_body.php';
