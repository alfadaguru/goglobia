<?php
// UMRAH MODULE INDEX
@$SECURE or die('Access Denied!');
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/details.php';

require_once 'actions/issue.php';
require_once 'actions/void.php';
require_once 'actions/cancel.php';
require_once 'actions/refund.php';
