<?php
// path: modules/flights/mystifly/index.php
// Mystifly Flights Module - Entry Point
// Loads all Mystifly routes into the existing $router instance

require_once 'creds.php';
require_once 'search.php';
require_once 'revalidate.php';
require_once 'farerules.php';
require_once 'ancillaries.php';
require_once 'tripdetails.php';

// ACTIONS
require_once 'actions/issue.php';
require_once 'actions/void.php';
require_once 'actions/cancel.php';
require_once 'actions/refund.php';
require_once 'actions/reissue.php';
