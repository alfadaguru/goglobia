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

// WHITE-LABEL Phase 2: when the inbound Host is an APPROVED tenant white-label site,
// register its clean multi-page routes ('/', '/rooms', '/about', '/{page}') BEFORE the
// primary routes so they win the router's first-match on that host. No-op (and no DB cost)
// on the primary host — the guard early-returns. Gated on a branded root being configured.
if (function_exists('supplier_site_branded_root') && supplier_site_branded_root() !== ''
    && function_exists('supplier_site_request_host')) {
    $__wlHost = supplier_site_request_host();
    if ($__wlHost !== '' && (!function_exists('supplier_site_primary_host') || $__wlHost !== supplier_site_primary_host())) {
        require_once 'app/routes/stays/brandedTenantRoutes.php';
    }
}

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
require_once 'app/routes/users/supplierSignupRoutes.php'; // supplier self-registration + approval gate
require_once 'app/routes/users/supplierServicesRoutes.php'; // per-service landing / read-more pages (inc S9)
require_once 'app/routes/users/supplierDashboardRoutes.php'; // supplier area (SUPPLIER_AUTH-gated)
require_once 'app/routes/users/supplierStaysRoutes.php'; // owner-scoped stays CRUD (Phase 1 inc 3)
require_once 'app/routes/users/supplierReservationsRoutes.php'; // reservations inbox (inc S11; reuses stays helpers)
require_once 'app/routes/users/supplierPayoutsRoutes.php'; // supplier payout request UI (inc S19)
require_once 'app/routes/users/supplierBankAccountsRoutes.php'; // multi-account bank management (Paystack bank list + resolve)
require_once 'app/routes/users/supplierWalletRoutes.php'; // supplier wallet surface (balances + statement + withdraw)
require_once 'app/routes/users/supplierHousekeepingRoutes.php'; // housekeeping board + physical rooms (inc S22) + maintenance (S23)
require_once 'app/routes/users/supplierPosRoutes.php'; // F&B / POS — outlets, menu, orders, charge-to-room (inc S24)
require_once 'app/routes/users/supplierOwnersRoutes.php'; // property owners + agreements + owner statements (inc S26)
require_once 'app/routes/users/supplierGuestsRoutes.php'; // guest CRM — profiles + history + notes (inc S29)
require_once 'app/routes/users/supplierReviewsRoutes.php'; // reviews — public capture + owner moderation (inc S31)
require_once 'app/routes/users/supplierProcurementRoutes.php'; // procurement — vendors/stock/PO/GRN (inc S32)
require_once 'app/routes/users/supplierEventsRoutes.php'; // events / MICE — spaces + event bookings + folio (inc S33)
require_once 'app/routes/users/supplierGroupsRoutes.php'; // group & block reservations (inc S38)
require_once 'app/routes/users/supplierFinanceRoutes.php'; // GL trial balance + P&L + balance sheet (PMS #34)
require_once 'app/routes/users/supplierCashierRoutes.php'; // cashier shifts + cash movements (PMS Phase 2)
require_once 'app/routes/users/supplierFacilitiesRoutes.php'; // lost&found + fixed assets + laundry + minibar (PMS Phase 3)
require_once 'app/routes/users/supplierGuestServiceRoutes.php'; // service tickets + guest messages (PMS Phase 3 #21/#19)
require_once 'app/routes/users/supplierHrRoutes.php'; // HR / workforce — employees + leave (PMS Phase 3 #44)
require_once 'app/routes/users/supplierLeaseRoutes.php'; // leases + utilities (PMS Phase 3 #49/#51)
require_once 'app/routes/users/supplierCorporateRoutes.php'; // corporate accounts + trust ledger (PMS Phase 3 #15/#50)
require_once 'app/routes/users/supplierAncillaryRoutes.php'; // services (spa/generic) + recipes (PMS Phase 3 #31/#32/#33)
require_once 'app/routes/users/supplierSafetyRoutes.php'; // packages + safety/compliance registers (PMS Phase 3 #17/#52-55)
require_once 'app/routes/users/supplierGrowthRoutes.php'; // brands (#03) + integrations channel-mgr/RMS/AI config stubs (PMS Phase 3 #12/#14/#57)
require_once 'app/routes/users/supplierChainRoutes.php'; // multi-property / chain portfolio dashboard (PMS #03 deepen)
require_once 'app/routes/users/supplierMarketplaceRoutes.php'; // marketplace performance scorecard (PMS 11 deepen)
require_once 'app/routes/users/supplierChannelRoutes.php'; // channel manager: real iCal feed + OTA map stubs (PMS 12 deepen)
require_once 'app/routes/users/supplierRmsRoutes.php'; // revenue management dashboard (PMS 14 deepen)
// === BATCH-1 DEEPEN routes (modules 16-33) ===
require_once 'app/routes/users/supplierMiceExtRoutes.php';
require_once 'app/routes/users/supplierPackagesExtRoutes.php';
require_once 'app/routes/users/supplierGuestCrmExtRoutes.php';
require_once 'app/routes/users/supplierCommsExtRoutes.php';
require_once 'app/routes/users/supplierJourneyExtRoutes.php';
require_once 'app/routes/users/supplierServiceExtRoutes.php';
require_once 'app/routes/users/supplierLoyaltyExtRoutes.php';
require_once 'app/routes/users/supplierReviewsExtRoutes.php';
require_once 'app/routes/users/supplierHkExtRoutes.php';
require_once 'app/routes/users/supplierLaundryExtRoutes.php';
require_once 'app/routes/users/supplierMinibarExtRoutes.php';
require_once 'app/routes/users/supplierMaintExtRoutes.php';
require_once 'app/routes/users/supplierAssetsExtRoutes.php';
require_once 'app/routes/users/supplierLostfoundExtRoutes.php';
require_once 'app/routes/users/supplierPosExtRoutes.php';
require_once 'app/routes/users/supplierKitchenExtRoutes.php';
require_once 'app/routes/users/supplierSpaExtRoutes.php';
require_once 'app/routes/users/supplierResourcesExtRoutes.php';
// === BATCH-2 DEEPEN routes (modules 34-61) ===
require_once 'app/routes/users/supplierAiExtRoutes.php';
require_once 'app/routes/users/supplierApExtRoutes.php';
require_once 'app/routes/users/supplierApartmentExtRoutes.php';
require_once 'app/routes/users/supplierApiExtRoutes.php';
require_once 'app/routes/users/supplierArExtRoutes.php';
require_once 'app/routes/users/supplierBiExtRoutes.php';
require_once 'app/routes/users/supplierDocsExtRoutes.php';
require_once 'app/routes/users/supplierGlExtRoutes.php';
require_once 'app/routes/users/supplierHrExtRoutes.php';
require_once 'app/routes/users/supplierIotExtRoutes.php';
require_once 'app/routes/users/supplierLeaseExtRoutes.php';
require_once 'app/routes/users/supplierLocksExtRoutes.php';
require_once 'app/routes/users/supplierMigrationExtRoutes.php';
require_once 'app/routes/users/supplierNotifyExtRoutes.php';
require_once 'app/routes/users/supplierOwnersExtRoutes.php';
require_once 'app/routes/users/supplierPaymentsExtRoutes.php';
require_once 'app/routes/users/supplierPlatformopsExtRoutes.php';
require_once 'app/routes/users/supplierProcurementExtRoutes.php';
require_once 'app/routes/users/supplierRbacExtRoutes.php';
require_once 'app/routes/users/supplierSafetyExtRoutes.php';
require_once 'app/routes/users/supplierSettlementExtRoutes.php';
require_once 'app/routes/users/supplierStoresExtRoutes.php';
require_once 'app/routes/users/supplierTaxExtRoutes.php';
require_once 'app/routes/users/supplierTreasuryExtRoutes.php';
require_once 'app/routes/users/supplierTrustExtRoutes.php';
require_once 'app/routes/users/supplierUtilitiesExtRoutes.php';
require_once 'app/routes/users/supplierVendorsExtRoutes.php';
require_once 'app/routes/users/supplierWorkflowExtRoutes.php';
require_once 'app/routes/users/supplierRatesRoutes.php'; // yield rate-rules + booking restrictions (PMS #06 deepen)
require_once 'app/routes/users/supplierCalendarRoutes.php'; // reservation calendar / tape-chart (inc S39)
require_once 'app/routes/users/supplierRequestRoutes.php'; // post-approval service/quota request flow (inc S34)
require_once 'app/routes/users/supplierRolesRoutes.php'; // supplier-defined roles + property scope (inc 4)
require_once 'app/routes/users/supplierStaffRoutes.php'; // staff invitations + accept flow (inc 5)
require_once 'app/routes/users/logoutRoutes.php';
require_once 'app/routes/users/emailVerificationRoutes.php';
require_once 'app/routes/users/passwordResetRoutes.php';
require_once 'app/routes/users/userDashboardRoutes.php';
require_once 'app/routes/users/profileRoutes.php';
require_once 'app/routes/users/cardRoutes.php';
require_once 'app/routes/users/bookingsRoutes.php';
require_once 'app/routes/users/favouritesRoutes.php';
require_once 'app/routes/users/supportRoutes.php';

// USERS AGENTS ROUTES
require_once 'app/routes/users/depositRoutes.php';
require_once 'app/routes/users/agencyRoutes.php';
require_once 'app/routes/users/agencySignupRoutes.php';
require_once 'app/routes/users/agentApiRoutes.php';

// SEO LANDING PAGES (evergreen route/city pages) — registered BEFORE the flight
// listing routes so single-segment /flights/{from}-to-{to} matches here first.
require_once 'app/routes/seo/landingRoutes.php';

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
require_once 'app/routes/stays/brandedSiteRoutes.php'; // PMS #13: public /book/{slug} branded page
require_once 'app/routes/stays/destinationSuggestionRoutes.php';

// TOURS ROUTES
require_once 'app/routes/tours/bookingRoutes.php';
require_once 'app/routes/tours/invoiceRoutes.php';
require_once 'app/routes/tours/homeRoutes.php';
require_once 'app/routes/tours/listingRoutes.php';
require_once 'app/routes/tours/detailRoutes.php';
require_once 'app/routes/tours/destinationSuggestionRoutes.php';

// Umrah Routes
require_once 'app/routes/umrah/umrahV2Routes.php'; // NEW redesign — must precede legacy so /umrah, /umrah/packages/*, /umrah/detail redirect win
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
require_once 'app/routes/admin/suppliersRoutes.php'; // supplier approval queue (approve/reject)
require_once 'app/routes/admin/supplierPayoutsRoutes.php'; // supplier payout queue — approve/send (inc S19)
require_once 'app/routes/admin/agentApiRoutes.php';
require_once 'app/routes/admin/updatesRoutes.php';
require_once 'app/routes/admin/databaseRoutes.php';
require_once 'app/routes/admin/cmsRoutes.php';
require_once 'app/routes/admin/creditsRoutes.php';
require_once 'app/routes/admin/transactionsRoutes.php';
require_once 'app/routes/admin/moneyRoutes.php'; // agent tiers + loyalty scheme (MONEY-WALLET-AUDIT §C.4 steps 4-5)
require_once 'app/routes/admin/notificationTemplatesRoutes.php';
require_once 'app/routes/admin/campaignTemplatesRoutes.php'; // campaign template library + designer (Phase 3)
require_once 'app/routes/admin/campaignsRoutes.php'; // email campaign manager (Phase 2)
require_once 'app/routes/admin/menusRoutes.php';
require_once 'app/routes/admin/staysRoutes.php';
require_once 'app/routes/admin/staysSettingsRoutes.php';
require_once 'app/routes/admin/flightsRoutes.php';
require_once 'app/routes/admin/flightsPopularRoutesRoutes.php';
require_once 'app/routes/admin/airlinesRoutes.php';
require_once 'app/routes/admin/airportsRoutes.php';
require_once 'app/routes/admin/locationsRoutes.php';
require_once 'app/routes/admin/toursRoutes.php';
require_once 'app/routes/admin/umrahRoutes.php';
require_once 'app/routes/admin/umrahV2Routes.php';
require_once 'app/routes/admin/carsRoutes.php';
require_once 'app/routes/admin/busRoutes.php';
require_once 'app/routes/admin/visaRoutes.php';
require_once 'app/routes/admin/bookingsRoutes.php';
require_once 'app/routes/admin/bookingManagerRoutes.php'; // staff book walk-ins (flights+hotels; live/manual; DVA/manual-transfer)
require_once 'app/routes/admin/gatewaysRoutes.php';
require_once 'app/routes/admin/blogsRoutes.php';
require_once 'app/routes/admin/reportsRoutes.php';
require_once 'app/routes/admin/giaRoutes.php'; // GIA assistant reports (conversations/leads/funnel/cost)
require_once 'app/routes/admin/depositRoutes.php';
require_once 'app/routes/admin/supportticketRoutes.php';
require_once 'app/routes/admin/supportRoutes.php';      // Customer Support — Phase 1 shell (landing + nav)
require_once 'app/routes/admin/supportRolesRoutes.php'; // Customer Support — RBAC roles CRUD
require_once 'app/routes/admin/supportStaffRoutes.php'; // Customer Support — staff/employee CRUD
require_once 'app/routes/admin/supportShiftsRoutes.php';// Customer Support — Phase 3 shift roster (assign/edit + nudge bookkeeping)
require_once 'app/routes/admin/supportDeskRoutes.php';  // Customer Support — Live Chat Desk (multi-channel takeover)
require_once 'app/routes/admin/supportAttendanceRoutes.php'; // Customer Support — attendance (clock/heartbeat/presence board)
require_once 'app/routes/admin/promoCodesRoutes.php';
require_once 'app/routes/admin/discountsRoutes.php'; // automatic discount engine (Marketing)
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
require_once 'app/routes/api/umrah/v1Routes.php';

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
require_once 'app/routes/ai/giaRoutes.php'; // GIA agent (Phase A): POST /api/gia/chat (read-only tools)

// GLOBAL ROUTES
require_once 'app/routes/blogsRoutes.php';
require_once 'app/routes/globalRoutes.php';
require_once 'app/routes/newsletterRoutes.php'; // newsletter double-opt-in: subscribe/confirm/unsubscribe
require_once 'app/routes/cartRoutes.php'; // general cart (tours/visa/esim) — step 6b
require_once 'app/routes/ajaxRoutes.php';
require_once 'app/routes/demo-warning-route.php';

// PAYMENT GATEWAY WEBHOOKS (public, unauthenticated, signature-verified)
require_once 'app/routes/paymentAdyenRoutes.php';
require_once 'app/routes/paymentPaystackRoutes.php'; // Paystack DVA (virtual account) deposits

// EMAIL DELIVERY WEBHOOKS (public, unauthenticated, Svix signature-verified)
require_once 'app/routes/webhooksResendRoutes.php'; // Resend campaign delivery events

// GIA MESSAGING CHANNELS (public, unauthenticated, per-channel signature/secret
// verified): inbound webhooks for WhatsApp / Messenger / Telegram -> GIA.
require_once 'app/routes/ai/giaChannelsRoutes.php';

// CRON ROUTES
require_once 'app/routes/crons/creditsRemindersRoutes.php';
require_once 'app/routes/crons/currencyRatesRoutes.php';
require_once 'app/routes/crons/umrahHoldsRoutes.php';
require_once 'app/routes/crons/payLaterRoutes.php';
require_once 'app/routes/crons/supplierEarningsRoutes.php'; // clearance sweep: pending -> available
require_once 'app/routes/crons/campaignsDispatchRoutes.php'; // scheduled email campaign dispatcher
require_once 'app/routes/crons/giaPaymentsRoutes.php'; // GIA per-booking payment reminders + expiry sweep
require_once 'app/routes/crons/supportShiftNudgeRoutes.php'; // GIA WhatsApps staff not clocked in at shift start, every 15m, capped at 3
