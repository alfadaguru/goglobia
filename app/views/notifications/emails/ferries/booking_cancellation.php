<?php
// app/views/notifications/emails/ferries/booking_cancellation.php
// Thin wrapper → the shared premium cancellation body.
@$SECURE or die('Access Denied!');
$cxServiceLabel = 'ferry';
$cxModule = 'ferries';
require __DIR__ . '/../_cancellation_body.php';
