<?php

global $router;

// Rate-comments resolve + Content helpers (load before search/rooms/checkrates)
require_once __DIR__ . '/content/reference_import.php';

// Include credentials testing

include "creds.php";
include "search.php";
include "details.php";
include "rooms.php";
include "checkrates.php"; // after rooms.php — uses checkRatesBatch()

include "content/content.php";
include "actions/issue.php";
include "actions/cancel.php";
include "actions/reconcile.php";
include "actions/void.php";
include "actions/refund.php";
