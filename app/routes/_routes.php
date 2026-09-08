<?php

// app/routes/_routes.php
@$SECURE or die('Access Denied!');

// ==================================================
// LAZY ROUTE LOADING
// Admin and API route files are only parsed when the request actually targets
// them. This avoids loading ~90 unrelated files on every public page (matters
// most where OPcache is unavailable, i.e. most shared hosting). Route grouping
// is safe: all admin files register `admin/...` paths (the only exception,
// `/sitemap.xml`, is handled below) and all api files register `/api/...`.
$__uri = $_SERVER['REQUEST_URI'] ?? '/';
if (($__qp = strpos($__uri, '?')) !== false) {
    $__uri = substr($__uri, 0, $__qp);
}
$__base = implode('/', array_slice(explode('/', $_SERVER['SCRIPT_NAME'] ?? ''), 0, -1)) . '/';
$__path = '/' . trim(substr($__uri, strlen($__base)), '/');

$__isAdminRequest   = ($__path === '/admin' || strpos($__path, '/admin/') === 0);
$__isApiRequest     = ($__path === '/api'   || strpos($__path, '/api/')   === 0);
$__isSitemapRequest = ($__path === '/sitemap.xml'); // public route defined in settingsRoutes

require_once 'app/routes/mainRoutes.php';

// COMPONENTS ROUTES
require_once 'app/routes/components/dashboardRoutes.php';
require_once 'app/routes/components/alertsNotificationsRoutes.php';
require_once 'app/routes/components/badgesButtonsRoutes.php';
require_once 'app/routes/components/formInputsRoutes.php';
require_once 'app/routes/components/formSelectsRoutes.php';
require_once 'app/routes/components/cardsUiRoutes.php';
require_once 'app/routes/components/moduleComponentsRoutes.php';

// CMS ROUTES
require_once 'app/routes/cms/pageRoutes.php';

// USERS ROUTES
require_once 'app/routes/users/loginRoutes.php';
require_once 'app/routes/users/signupRoutes.php';
require_once 'app/routes/users/logoutRoutes.php';
require_once 'app/routes/users/emailVerificationRoutes.php';
require_once 'app/routes/users/passwordResetRoutes.php';
require_once 'app/routes/users/userDashboardRoutes.php';
require_once 'app/routes/users/profileRoutes.php';
require_once 'app/routes/users/bookingsRoutes.php';
require_once 'app/routes/users/favouritesRoutes.php';
require_once 'app/routes/users/supportRoutes.php';

// USERS AGENTS ROUTES
require_once 'app/routes/users/depositRoutes.php';
require_once 'app/routes/users/agencyRoutes.php';
require_once 'app/routes/users/agencySignupRoutes.php';
require_once 'app/routes/users/agentApiRoutes.php';

// FLIGHTS ROUTES
require_once 'app/routes/flights/bookingRoutes.php';
require_once 'app/routes/flights/invoiceRoutes.php';
require_once 'app/routes/flights/listingRoutes.php';
require_once 'app/routes/flights/homeRoutes.php';
require_once 'app/routes/flights/checkoutRoutes.php';

// STAYS ROUTES
require_once 'app/routes/stays/bookingRoutes.php';
require_once 'app/routes/stays/invoiceRoutes.php';
require_once 'app/routes/stays/homeRoutes.php';
require_once 'app/routes/stays/listingRoutes.php';
require_once 'app/routes/stays/detailRoutes.php';
require_once 'app/routes/stays/destinationSuggestionRoutes.php';

// TOURS ROUTES
require_once 'app/routes/tours/bookingRoutes.php';
require_once 'app/routes/tours/invoiceRoutes.php';
require_once 'app/routes/tours/homeRoutes.php';
require_once 'app/routes/tours/listingRoutes.php';
require_once 'app/routes/tours/detailRoutes.php';
require_once 'app/routes/tours/destinationSuggestionRoutes.php';

// Umrah Routes
require_once 'app/routes/umrah/homeRoutes.php';
require_once 'app/routes/umrah/bookingRoutes.php';
require_once 'app/routes/umrah/invoiceRoutes.php';
require_once 'app/routes/umrah/detailRoutes.php';
require_once 'app/routes/umrah/destinationSuggestionRoutes.php';
require_once 'app/routes/umrah/listingRoutes.php'; // Catch-all listing route should be last

// CARS ROUTES
require_once 'app/routes/cars/bookingRoutes.php';
require_once 'app/routes/cars/invoiceRoutes.php';
require_once 'app/routes/cars/mozioPaymentRoutes.php';
require_once 'app/routes/cars/homeRoutes.php';
require_once 'app/routes/cars/listingRoutes.php';
require_once 'app/routes/cars/detailRoutes.php';
require_once 'app/routes/cars/locationSuggestionRoutes.php';

// VISA ROUTES
require_once 'app/routes/visa/homeRoutes.php';
require_once 'app/routes/visa/bookingRoutes.php';
require_once 'app/routes/visa/invoiceRoutes.php';
require_once 'app/routes/visa/uploadRoutes.php';

// eSIM ROUTES
require_once 'app/routes/esim/homeRoutes.php';
require_once 'app/routes/esim/invoiceRoutes.php';

// INSURANCE ROUTES (flight-compensation claims — AirHelp)
require_once 'app/routes/insurance/homeRoutes.php';
require_once 'app/routes/insurance/bookingRoutes.php';
require_once 'app/routes/insurance/invoiceRoutes.php';

// FERRIES ROUTES
require_once 'app/routes/ferries/homeRoutes.php';
require_once 'app/routes/ferries/listingRoutes.php';
require_once 'app/routes/ferries/bookingRoutes.php';
require_once 'app/routes/ferries/invoiceRoutes.php';

// RAIL ROUTES
require_once 'app/routes/rail/homeRoutes.php';
require_once 'app/routes/rail/listingRoutes.php';
require_once 'app/routes/rail/bookingRoutes.php';
require_once 'app/routes/rail/invoiceRoutes.php';

// BUS ROUTES
require_once 'app/routes/bus/homeRoutes.php';
require_once 'app/routes/bus/locationSuggestionRoutes.php';
require_once 'app/routes/bus/bookingRoutes.php'; // '/bus/booking/{hash}' + cache-draft + submit — BEFORE catch-all
require_once 'app/routes/bus/invoiceRoutes.php'; // '/invoice/bus/{id}'
require_once 'app/routes/bus/listingRoutes.php'; // CATCH-ALL '/bus/(.*)' — AFTER homeRoutes so '/bus/' matches first

// ADMIN ROUTES — only parsed on admin requests (or /sitemap.xml, which lives in settingsRoutes)
if ($__isAdminRequest || $__isSitemapRequest):
require_once 'app/routes/admin/globalRoutes.php';
require_once 'app/routes/admin/dashboardRoutes.php';
require_once 'app/routes/admin/settingsRoutes.php';
require_once 'app/routes/admin/modulesRoutes.php';
require_once 'app/routes/admin/usersRoutes.php';
require_once 'app/routes/admin/agentApiRoutes.php';
require_once 'app/routes/admin/updatesRoutes.php';
require_once 'app/routes/admin/databaseRoutes.php';
require_once 'app/routes/admin/cmsRoutes.php';
require_once 'app/routes/admin/creditsRoutes.php';
require_once 'app/routes/admin/transactionsRoutes.php';
require_once 'app/routes/admin/notificationTemplatesRoutes.php';
require_once 'app/routes/admin/menusRoutes.php';
require_once 'app/routes/admin/staysRoutes.php';
require_once 'app/routes/admin/staysSettingsRoutes.php';
require_once 'app/routes/admin/flightsRoutes.php';
require_once 'app/routes/admin/airlinesRoutes.php';
require_once 'app/routes/admin/airportsRoutes.php';
require_once 'app/routes/admin/locationsRoutes.php';
require_once 'app/routes/admin/toursRoutes.php';
require_once 'app/routes/admin/umrahRoutes.php';
require_once 'app/routes/admin/carsRoutes.php';
require_once 'app/routes/admin/busRoutes.php';
require_once 'app/routes/admin/visaRoutes.php';
require_once 'app/routes/admin/bookingsRoutes.php';
require_once 'app/routes/admin/gatewaysRoutes.php';
require_once 'app/routes/admin/blogsRoutes.php';
require_once 'app/routes/admin/reportsRoutes.php';
require_once 'app/routes/admin/depositRoutes.php';
require_once 'app/routes/admin/supportticketRoutes.php';
require_once 'app/routes/admin/promoCodesRoutes.php';
endif; // admin routes

// API ROUTES — only parsed on /api requests
if ($__isApiRequest):
    // AGENT API (docs/AGENT-API.md) — on the agent-API host, authenticate the
    // request by its X-Agent-Key (sets the agent session context so the existing
    // pricing/agent logic is reused) and enforce the per-service gate. This is a
    // no-op on the normal site host. When it authenticates, it REPLACES the
    // global-key check below (the agent key is the credential).
    $__isAgentApi = false;
    if (function_exists('agent_api_authenticate')) {
        $__isAgentApi = agent_api_authenticate($db);
    }

    // API Key verification middleware (site/global key) — skipped for
    // authenticated agent-API requests, which carry their own key.
    $__apiKeyExempt = (
        $__path === '/api/app-settings'
        || $__path === '/api/app/settings'
    );
    if (!$__apiKeyExempt && !$__isAgentApi) {
        require_once 'modules/helpers.php';
        verifyApiKey($db);
    }

    // USER API ROUTES
require_once 'app/routes/api/index.php';
require_once 'app/routes/api/users/loginRoutes.php';
require_once 'app/routes/api/users/signupRoutes.php';
require_once 'app/routes/api/users/bookingRoutes.php';
require_once 'app/routes/api/users/dashboardRoutes.php';
require_once 'app/routes/api/users/logoutRoutes.php';
require_once 'app/routes/api/users/profileRoutes.php';
require_once 'app/routes/api/users/deleteAccountRoutes.php';
require_once 'app/routes/api/users/emailVerificationRoutes.php';
require_once 'app/routes/api/users/passwordResetRoutes.php';
require_once 'app/routes/api/users/agencyRoutes.php';
require_once 'app/routes/api/users/depositRoutes.php';
require_once 'app/routes/api/users/supportRoutes.php';
require_once 'app/routes/api/users/favouritesRoutes.php';

// USER AUTH ROUTES
require_once 'app/routes/api/auth/refreshRoutes.php';

// CRON API ROUTES
require_once 'app/routes/api/crons/creditsRemindersRoutes.php';

// FLIGHTS API ROUTES
require_once 'app/routes/api/flights/featuredRoutes.php';
require_once 'app/routes/api/flights/bookingRoutes.php';
require_once 'app/routes/api/flights/homeRoutes.php';
require_once 'app/routes/api/flights/invoiceRoutes.php';

// STAYS API ROUTES
require_once 'app/routes/api/stays/bookingRoutes.php';
require_once 'app/routes/api/stays/homeRoutes.php';
require_once 'app/routes/api/stays/invoiceRoutes.php';
require_once 'app/routes/api/stays/destinationSuggestionRoutes.php';

// CARS API ROUTES
require_once 'app/routes/api/cars/bookingRoutes.php';
require_once 'app/routes/api/cars/invoiceRoutes.php';
require_once 'app/routes/api/cars/featuredRoutes.php';

// BUS API ROUTES
require_once 'app/routes/api/bus/listingRoutes.php';
require_once 'app/routes/api/bus/invoiceRoutes.php';
require_once 'app/routes/api/bus/bookingRoutes.php';

// PROMO CODE API ROUTES
require_once 'app/routes/api/promoCodeRoutes.php';

// COUNTRY API ROUTES
require_once 'app/routes/api/countries/homeRoutes.php';

// TOUR API ROUTES
require_once 'app/routes/api/tours/featuredRoutes.php';
require_once 'app/routes/api/tours/homeRoutes.php';
require_once 'app/routes/api/tours/bookingRoutes.php';
require_once 'app/routes/api/tours/invoice.php';

// UMRAH API ROUTES
require_once 'app/routes/api/umrah/featuredRoutes.php';
require_once 'app/routes/api/umrah/homeRoutes.php';
require_once 'app/routes/api/umrah/bookingRoutes.php';

//VISA API ROUTES
require_once 'app/routes/api/visa/homeRoutes.php';
require_once 'app/routes/api/visa/bookingRoutes.php';
require_once 'app/routes/api/visa/uploadRoutes.php';

// eSIM API ROUTES
require_once 'app/routes/api/esim/featuredRoutes.php';
require_once 'app/routes/api/esim/packagesRoutes.php';
require_once 'app/routes/api/esim/bookingRoutes.php';

// FERRIES API ROUTES
require_once 'app/routes/api/ferries/searchRoutes.php';
require_once 'app/routes/api/ferries/bookingRoutes.php';
require_once 'app/routes/api/ferries/invoiceRoutes.php';

// RAIL API ROUTES
require_once 'app/routes/api/rail/homeRoutes.php';
require_once 'app/routes/api/rail/searchRoutes.php';
require_once 'app/routes/api/rail/bookingRoutes.php';
require_once 'app/routes/api/rail/invoiceRoutes.php';

// GLOBAL API ROUTES
require_once 'app/routes/api/globalApiRoutes.php';
require_once 'app/routes/api/themeRoutes.php';
require_once 'app/routes/api/blogsRoutes.php';
require_once 'app/routes/api/contactRoutes.php';

// CMS ROUTES
require_once 'app/routes/api/cms/pageRoutes.php';
endif; // api routes

// AI TRIP PLANNER (web pages + web JSON APIs — same pattern as routes/flights/)
require_once 'app/routes/ai/tripRoutes.php';
require_once 'app/routes/ai/searchRoutes.php';
require_once 'app/routes/ai/locationRoutes.php'; // reverse geocode for departure context (CSRF)
require_once 'app/routes/ai/tripBookingRoutes.php';
require_once 'app/routes/ai/visaListingRoutes.php'; // AI-only visa confirm card (CSRF)

// GLOBAL ROUTES
require_once 'app/routes/blogsRoutes.php';
require_once 'app/routes/globalRoutes.php';
require_once 'app/routes/ajaxRoutes.php';
require_once 'app/routes/demo-warning-route.php';

// PAYMENT GATEWAY WEBHOOKS (public, unauthenticated, signature-verified)
require_once 'app/routes/paymentAdyenRoutes.php';

// CRON ROUTES
require_once 'app/routes/crons/creditsRemindersRoutes.php';
require_once 'app/routes/crons/currencyRatesRoutes.php';
