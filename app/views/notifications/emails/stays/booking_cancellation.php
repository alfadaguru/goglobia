<?php
// app/views/notifications/emails/stays/booking_cancellation.php
// Thin wrapper → the shared premium cancellation body.
@$SECURE or die('Access Denied!');
$cxServiceLabel = 'hotel';
$cxModule = 'stays';
require __DIR__ . '/../_cancellation_body.php';
