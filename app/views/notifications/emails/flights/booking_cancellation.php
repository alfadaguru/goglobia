<?php
// app/views/notifications/emails/flights/booking_cancellation.php
// Thin wrapper → the shared premium cancellation body.
@$SECURE or die('Access Denied!');
$cxServiceLabel = 'flight';
$cxModule = 'flights';
require __DIR__ . '/../_cancellation_body.php';
