<?php

global $router;

// Include credentials testing
include "creds.php";

// Include search endpoint
include "search.php";

// Additional CarTrawler endpoints will go here
// e.g., availability.php, booking.php, etc.

include "issue.php";
include "cancel.php";
include "refund.php";
include "void.php";
