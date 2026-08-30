<?php
// ============================================================================
// KIKOTO FERRIES — MODULE ENTRY POINT
// ============================================================================
// Loads all route handlers in dependency order.
// api.php must come first — it defines all shared helper functions.
// ============================================================================

require_once __DIR__ . '/api.php';      // CORE: HTTP helpers, config, markup
require_once __DIR__ . '/creds.php';    // ADMIN: credential validation
require_once __DIR__ . '/search.php';   // SEARCH: ports, routes, sailings, prices
require_once __DIR__ . '/revalidate.php'; // REVALIDATE: re-price before booking
require_once __DIR__ . '/actions/issue.php';   // BOOKING: create + confirm
require_once __DIR__ . '/actions/cancel.php';  // BOOKING: cancel by locator
