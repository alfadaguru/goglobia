<?php
@$SECURE or die('Access Denied!');
// Back-compat shim — AI trip UI lives under app/views/ai/
require_once views . 'ai/trip.php';
