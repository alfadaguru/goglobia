<?php
require_once 'search.php';
require_once 'details.php';

// Load actions
if (file_exists(__DIR__ . '/actions/issue.php')) {
    require_once __DIR__ . '/actions/issue.php';
}