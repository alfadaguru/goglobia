<?php

global $router;

// Include credentials testing
include "creds.php";

// Include search endpoint
include "search.php";

// Shared API helpers + post-payment reservation issue
require_once __DIR__ . '/lib.php';
include "issue.php";

// Admin "Cancel Booking" action
include "cancel.php";
