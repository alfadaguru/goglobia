<?php
// path: modules/stays/adivaha/index.php
// Adivaha B2C Hotels Module — Entry Point.
// Loads all Adivaha hotel routes into the existing $router. Included by
// modules/index.php. Order matters: creds.php first (defines the credential /
// token / HTTP helpers the others call), then search/details/rooms/manage, then
// the state-changing actions.

global $router;

include "creds.php";
include "search.php";
include "details.php";
include "rooms.php";
include "manage.php"; // booking-details + cancellation-quote

// ACTIONS
include "actions/issue.php";
include "actions/cancel.php";
include "actions/void.php";
include "actions/refund.php";
