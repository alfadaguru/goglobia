<?php
// app/views/notifications/emails/tours/booking_cancellation.php
// Thin wrapper → the shared premium cancellation body.
@$SECURE or die('Access Denied!');
$cxServiceLabel = 'tour';
$cxModule = 'tours';
require __DIR__ . '/../_cancellation_body.php';
