<?php
// ============================================================================
// ToursBMS tours supplier — module entry point.
// Loaded by modules/index.php. Registers all ToursBMS endpoints on the
// module sub-app router.
// ============================================================================
require_once __DIR__ . '/api.php';       // token + signed request helpers
require_once __DIR__ . '/creds.php';     // POST tours/toursbms/creds
require_once __DIR__ . '/import.php';    // POST tours/toursbms/import  (content sync)
require_once __DIR__ . '/search.php';    // POST tours/toursbms/search
require_once __DIR__ . '/details.php';   // POST tours/toursbms/details

// Local booking actions (no supplier booking API)
if (file_exists(__DIR__ . '/actions/issue.php'))  require_once __DIR__ . '/actions/issue.php';
if (file_exists(__DIR__ . '/actions/cancel.php')) require_once __DIR__ . '/actions/cancel.php';
