<?php
// path: modules/flights/tbo/index.php
// TBO Air Flights Module - Entry Point
// Loads all TBO routes into the existing $router instance

require_once 'creds.php';
require_once 'search.php';
require_once 'revalidate.php';

// ACTIONS
require_once 'actions/issue.php';
require_once 'actions/cancel.php';
require_once 'actions/void.php';
require_once 'actions/refund.php';
