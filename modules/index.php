<?php
/**
 * ============================================================================
 * MODULES API GATEWAY - Main Entry Point
 * ============================================================================
 *
 * PURPOSE:
 * Central router for all travel API modules (Flights, Hotels, Tours, Cars)
 * Handles authentication, rate limiting, routing, and supplier integration
 *
 * SECURITY LAYERS:
 * 1. Rate Limiting    - Prevents brute force and DDoS attacks (60 req/min)
 * 2. Origin Check     - Blocks external tools (Postman, cURL, scripts)
 * 3. Pattern Blocking - Detects SQL injection, XSS, code injection
 * 4. Session Control  - Manages user authentication and permissions
 *
 * ARCHITECTURE:
 * - Router: Custom AppRouter for RESTful routing
 * - Database: Medoo ORM for MySQL queries
 * - Modules: Lazy-loaded supplier integrations
 *
 * SUPPORTED SUPPLIERS:
 * - Flights: Amadeus, Duffel, Kiwi, Sabre, Seeru, Travelport, PKFare, TBO
 * - Hotels: Stuba, Hotelston, Hotelbeds, Agoda, Travelport, Amadeus, RateHawk, TBO Holidays, Custom
 * - Tours: Viator, Tiqets
 * - Cars: DiscoverCars, CarTrawler
 *
 * ============================================================================
 */

// ============================================================================
// DEBUGGING: Log incoming request data for troubleshooting
// ============================================================================
// $json = json_encode($_REQUEST, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// if ($json === false) {
//     error_log("JSON encode failed: " . json_last_error_msg());
//     @file_put_contents("../_REQUEST.json", "Error encoding request data");
// } else {
//     // Use file_put_contents with LOCK_EX to prevent corruption
//     @file_put_contents("../_REQUEST.json", $json, LOCK_EX);
// }

// ============================================================================
// DEVELOPMENT ERROR REPORTING - Log errors but don't display (JSON API)
ini_set('display_errors', 0);           // Don't display errors in response
ini_set('display_startup_errors', 0);   // Don't display startup errors
ini_set('log_errors', 1);               // Log errors to file
ini_set('error_log', __DIR__ . '/../_error.log');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE); // Log all except deprecated/notices

// ============================================================================
// BOOTSTRAP - Load dependencies and configuration
// ============================================================================

// Core dependencies (order matters for proper initialization)
require_once __DIR__ . '/router.php';      // RESTful routing engine
require_once __DIR__ . '/helpers.php';     // Global utility functions
require_once __DIR__ . '/RateLimiter.php'; // Security & rate limiting
require_once '../vendor/autoload.php';     // Composer dependencies

// Load environment configuration
$env = parse_ini_file('../.env');

// Import required namespaces
use Medoo\Medoo;
use AppRouter\Router;

// ============================================================================
// SESSION INITIALIZATION - Start session if not already active
// ============================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('SUPPLIER_CONNECT_TIMEOUT')) {
    define('SUPPLIER_CONNECT_TIMEOUT', 10);
}

if (!defined('SUPPLIER_REQUEST_TIMEOUT')) {
    define('SUPPLIER_REQUEST_TIMEOUT', 30);
}

// ============================================================================
// DATABASE CONNECTION - Medoo ORM Initialization (Initialized early for API key checking)
// ============================================================================
$db = new Medoo([
    'type'     => $env['DB_TYPE'] ?? 'mysql',
    'host'     => $env['DB_HOST'] ?? 'localhost',
    'database' => $env['DB_DATABASE'],
    'username' => $env['DB_USERNAME'],
    'password' => $env['DB_PASSWORD'],
    'charset'  => 'utf8mb4',                        // Full Unicode support (emojis, symbols)
    'collation' => 'utf8mb4_unicode_ci',            // Case-insensitive sorting
    'option' => [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,  // Throw exceptions on errors
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, // Return associative arrays
    ]
]);

// Initialize PDO globally for modules using direct PDO queries
$pdo = $db->pdo;

// ============================================================================
// SECURITY LAYER 1: RATE LIMITER & ORIGIN VALIDATION
// ============================================================================
// Protects against:
// - Brute force attacks
// - DDoS attempts
// - External API abuse (Postman, cURL)
// - Automated scraping bots
// ============================================================================

$rateLimiter = new RateLimiter(__DIR__ . '/../app/cache/rate_limiter');

// Load server configured API key
$settingsData = $db->get('settings', 'app_settings');
$appSettings = json_decode($settingsData ?: '{}', true) ?: [];
$serverApiKey = $appSettings['api_key'] ?? '';
$isApiKeyRequired = !empty($serverApiKey);

// Check if client provided an API key in the headers/request (with support for redirected headers)
$headers = function_exists('getallheaders') ? getallheaders() : [];
$normalizedHeaders = array_change_key_case($headers, CASE_LOWER);
$clientApiKey = $normalizedHeaders['x-api-key'] ?? '';

if (empty($clientApiKey)) {
    foreach ($_SERVER as $key => $val) {
        $cleanKey = strtoupper($key);
        if ($cleanKey === 'HTTP_X_API_KEY' || 
            $cleanKey === 'REDIRECT_HTTP_X_API_KEY' || 
            $cleanKey === 'HTTP_X_APIKEY' || 
            $cleanKey === 'REDIRECT_HTTP_X_APIKEY') {
            $clientApiKey = $val;
            break;
        }
    }
}
if (empty($clientApiKey)) {
    $clientApiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
}

// Block requests from external sources (non-browser clients)
// Validates Referer and Origin headers, blocks suspicious User-Agents
// BYPASS on localhost, if server has no key configured, or if any API key is supplied
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);
$hasApiKeyHeader = !empty($clientApiKey);

if ($isApiKeyRequired && !$isLocalhost && !$hasApiKeyHeader && !$rateLimiter->checkOrigin()) {
    $rateLimiter->sendForbiddenResponse(); // 403 Forbidden + exit
}

// Enforce rate limit: 60 requests per minute per IP address
// Prevents single IP from overwhelming the server
$clientIp = $rateLimiter->getClientIdentifier();

// Get request URI and user agent
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// HIGHER LIMIT FOR SEARCH ENDPOINTS (300 requests per minute)
$isSearchEndpoint = strpos($requestUri, '/search') !== false;
$rateLimit = $isSearchEndpoint ? 300 : 60;
$timeWindow = 60;

if (!$rateLimiter->attempt($clientIp, $rateLimit, $timeWindow)) {
    $rateLimiter->sendRateLimitResponse($timeWindow); // 429 Too Many Requests + exit
}

// ============================================================================
// SECURITY LAYER 2: ATTACK PATTERN DETECTION
// ============================================================================
// Scans URI and User-Agent for common exploit attempts
// Blocks requests before they reach application logic
// ============================================================================

// Define malicious patterns to block
$suspiciousPatterns = [
    '/\.\.\//',           // Directory traversal (../../etc/passwd)
    '/union.*select/i',   // SQL injection (UNION SELECT attacks)
    '/<script/i',         // XSS attempts (<script>alert(1)</script>)
    '/eval\(/i',          // Code injection (eval, exec, system calls)
    '/base64_decode/i',   // Obfuscation attempts (encoded payloads)
    '/\x00/',             // Null byte injection
    '/etc\/passwd/i',     // System file access attempts
];

foreach ($suspiciousPatterns as $pattern) {
    if (preg_match($pattern, $requestUri) || preg_match($pattern, $userAgent)) {
        http_response_code(403);
        exit('Forbidden'); // Immediate termination, no data leaked
    }
}

// API Key verification middleware for modules
verifyApiKey($db);



// ============================================================================
// ROOT URL DEFINITION - Dynamic base URL for asset paths
// ============================================================================
// Detects HTTPS protocol and constructs root URL for consistent asset loading
// Used throughout application for absolute URLs (images, CSS, JS)
// ============================================================================

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$root = $protocol . $_SERVER['HTTP_HOST'];
$root .= str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
define('root', $root);

// ============================================================================
// ROUTER INITIALIZATION - RESTful API routing engine
// ============================================================================
// Handles HTTP method routing (GET, POST, PUT, DELETE)
// Error handler returns JSON responses for consistent API behavior
// ============================================================================

$router = new Router(function ($method, $path, $statusCode, $exception) {
    http_response_code($statusCode);
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    // Return structured error response
    echo json_encode([
        'error' => true,
        'code' => $statusCode,
        'message' => $statusCode === 404 ? 'Endpoint not found' : 'Request failed',
        'path' => $path,
        'method' => $method
    ]);
});

// Expose router globally so module files using `global $router` (e.g. revalidate.php) can register routes
$GLOBALS['router'] = $router;

// ============================================================================
// HEALTH CHECK ENDPOINT - API status verification
// ============================================================================
// POST / - Returns success message to confirm API is operational
// Used for monitoring, load balancer health checks, uptime tracking
// ============================================================================

$router->post('/', function() {
    echo json_encode([
        'status' => 'success',
        'message' => 'MODULES API WORKING',
        'timestamp' => time()
    ]);
});

$router->get('/', function() {
    echo json_encode([
        'status' => 'success',
        'message' => 'MODULES API WORKING',
        'timestamp' => time()
    ]);
});

// ============================================================================
// HTTP HEADERS - API response configuration
// ============================================================================
// Sets standard headers for all API responses
// CORS enabled for cross-origin requests (adjust in production)
// ============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // TODO: Restrict to specific domains in production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-cache, must-revalidate'); // Prevent response caching
header('Pragma: no-cache');                          // HTTP/1.0 compatibility

// ============================================================================
// MODULE LOADING - Lazy load supplier integrations
// ============================================================================
// Each module registers its own routes with $router
// Modules are loaded on-demand to minimize memory footprint
// All modules share the same $db and $router instances
// ============================================================================

// ==================================================
// FILE SECURITY FLAG
// ==================================================

$SECURE = true;

// ----------------------------------------
// FLIGHT MODULES - Search and book flights
// ----------------------------------------
include __DIR__ . '/flights/amadeus/index.php';            // Amadeus GDS (Global)
include __DIR__ . '/flights/amadeus_enterprise/index.php'; // Amadeus Enterprise API
include __DIR__ . '/flights/duffel/index.php';             // Duffel (Modern NDC)
include __DIR__ . '/flights/mystifly/index.php';           // Mystifly (MyFareBox)
include __DIR__ . '/flights/kiwi/index.php';               // Kiwi.com (Budget flights)
include __DIR__ . '/flights/sabre/index.php';              // Sabre GDS
include __DIR__ . '/flights/seeru/index.php';              // Seeru (Regional)
include __DIR__ . '/flights/travelport/index.php';         // Travelport GDS
include __DIR__ . '/flights/pkfare/index.php';             // PKFare (Asian markets)
include __DIR__ . '/flights/travelpayouts/index.php';      // TravelPayouts aggregator
include __DIR__ . '/flights/tbo/index.php';                // TBO Air
include __DIR__ . '/flights/flights/index.php';            // Internal flights inventory
include __DIR__ . '/flights/kayak/index.php';                // Kayak price comparison
include __DIR__ . '/flights/googleflights/index.php';        // Google Flights (RapidAPI)

// ----------------------------------------
// TOUR MODULES - Activities and experiences
// ----------------------------------------
include __DIR__ . '/tours/tours/index.php';               // Tours
include __DIR__ . '/tours/viator/index.php';               // Viator (TripAdvisor)
include __DIR__ . '/tours/viator_merchant/index.php';      // Viator Merchant API
include __DIR__ . '/tours/tiqets/index.php';               // Tiqets (Museums, attractions)
include __DIR__ . '/tours/toursbms/index.php';             // ToursBMS (product catalogue + live pricing)

// ----------------------------------------
// CAR RENTAL MODULES - Vehicle bookings
// ----------------------------------------
include __DIR__ . '/cars/discover_cars/index.php';         // DiscoverCars aggregator
include __DIR__ . '/cars/cartrawler/index.php';            // CarTrawler network
include __DIR__ . '/cars/kiwitaxi/index.php';              // KiwiTaxi transfers
include __DIR__ . '/cars/mozio/index.php';                 // Mozio ground transportation
include __DIR__ . '/cars/cars/index.php';                  // Local cars rental

// ----------------------------------------
// BUS MODULES - Intercity coach booking
// ----------------------------------------
include __DIR__ . '/bus/bus/index.php';                    // Local bus (admin actions)

// ----------------------------------------
// ESIM MODULES - Digital SIM integrations
// ----------------------------------------
include __DIR__ . '/esim/index.php';                       // Airalo eSIM

// ----------------------------------------
// STAYS MODULES - Accommodation search
// ----------------------------------------
include __DIR__ . '/stays/stuba/index.php';               // Stuba (European hotels)
include __DIR__ . '/stays/hotelston/index.php';           // Hotelston aggregator
include __DIR__ . '/stays/hotelbeds/index.php';           // Hotelbeds (Bedbank)
include __DIR__ . '/stays/agoda/index.php';               // Agoda (Asian markets)
include __DIR__ . '/stays/booking/index.php';             // Booking.com (RapidAPI)
include __DIR__ . '/stays/travelport/index.php';          // Travelport GDS hotels
include __DIR__ . '/stays/amadeus/index.php';             // Amadeus GDS hotels
include __DIR__ . '/stays/hotels/index.php';              // Custom hotel database
include __DIR__ . '/stays/ratehawk/index.php';              // Custom hotel database
include __DIR__ . '/stays/tbo-holidays/index.php';         // TBO Holidays Hotel API
include __DIR__ . '/stays/wanderbeds/index.php';           // Wanderbeds Hotel API

// ----------------------------------------
// FERRIES MODULES - Ferry bookings
// ----------------------------------------
include __DIR__ . '/ferries/kikoto/index.php';            // Kikoto B2B Ferry API

// ----------------------------------------
// RAIL MODULES - Train bookings
// ----------------------------------------
include __DIR__ . '/rail/train/index.php';


// ----------------------------------------
// AI TRIP MODULES - Combined package issue
// ----------------------------------------
include __DIR__ . '/ai_trip/ai_trip/index.php';

// ----------------------------------------
// UMRAH MODULES - Religious travel
// ----------------------------------------
include __DIR__ . '/umrah/umrah/index.php';               // Umrah

// ----------------------------------------
// INSURANCE MODULES - Flight compensation claims
// ----------------------------------------
include __DIR__ . '/insurance/airhelp/index.php';         // AirHelp (flight-compensation claims)

// ============================================================================
// DISPATCH REQUEST - Route to appropriate handler
// ============================================================================
// Matches incoming request to registered routes
// Calls corresponding handler function with parameters
// Falls back to 404 error handler if no route matches
// ============================================================================

$router->dispatchGlobal();