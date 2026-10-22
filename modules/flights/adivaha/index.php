<?php
// path: modules/flights/adivaha/index.php
// Adivaha B2C Flights Module — Entry Point.
// Loads all Adivaha routes into the existing $router instance. Included by
// modules/index.php. Order matters: creds.php first (defines the credential /
// token / HTTP helpers the others call), then search/revalidate/extras, then the
// state-changing actions.

require_once 'creds.php';
require_once 'search.php';
require_once 'revalidate.php';
require_once 'ancillaries.php'; // ssr + fare-rule + calendar + series + booking-details

// ACTIONS
require_once 'actions/issue.php';
require_once 'actions/cancel.php';
require_once 'actions/void.php';
require_once 'actions/refund.php';
