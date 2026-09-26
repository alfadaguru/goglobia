<?php
// ============================================================================
// FILE: app/routes/ai/searchRoutes.php
// AI Trip Planner web search API (same pattern as routes/flights → /api/flight/...)
// GET /api/ai/search — structured multi-module live search intents
// Optional departure context (NOT part of the user prompt):
//   departure_city, departure_country, departure_airport, departure_source
// Optional stays nationality (NOT part of the user prompt):
//   nationality (ISO-2), nationality_source (manual|geolocation|last_used|session)
// Not part of mobile routes/api/ (JWT) layer.
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get('/api/ai/search', function () use ($db) {
    header('Content-Type: application/json');

    try {
        if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
            http_response_code(403);
            echo json_encode([
                'status' => false,
                'message' => 'AI search is disabled.',
                'error_code' => 'AI_DISABLED',
                'result' => '',
                'module' => '',
                'modules' => [],
                'hint' => '',
                'searches' => [],
            ]);
            return;
        }

        // COST-ABUSE GUARD (audit H5): every call below makes a real, PAID LLM
        // request (aiSearchService->search). This route's only gate is the AI
        // feature flag + the /api/* key check, which is bypassed for the site's own
        // web client (is_web_client, set on any homepage visit) and permits all
        // when no server API key is configured — so anyone could script thousands
        // of calls and run up an unbounded bill on the owner's Claude/OpenAI/Gemini
        // key. Throttle per client IP (file-backed, same limiter the modules gateway
        // uses): a short burst cap AND a wider hourly ceiling, BEFORE any provider
        // call. Fails closed with 429.
        if (!class_exists('RateLimiter')) {
            $rlFile = dirname(__DIR__, 3) . '/modules/RateLimiter.php';
            if (is_file($rlFile)) { require_once $rlFile; }
        }
        if (class_exists('RateLimiter')) {
            $aiRl = new RateLimiter(dirname(__DIR__, 3) . '/app/cache/rate_limiter');
            $rlId = 'ai_search:' . $aiRl->getClientIdentifier();
            // Burst: 15 searches / minute. Sustained: 120 searches / hour.
            $burstOk = $aiRl->attempt($rlId . ':burst', 15, 60);
            $hourOk  = $aiRl->attempt($rlId . ':hour', 120, 3600);
            if (!$burstOk || !$hourOk) {
                http_response_code(429);
                header('Retry-After: 60');
                echo json_encode([
                    'status' => false,
                    'message' => 'Too many AI searches. Please wait a moment and try again.',
                    'error_code' => 'AI_RATE_LIMITED',
                    'result' => '',
                    'module' => '',
                    'modules' => [],
                    'hint' => '',
                    'searches' => [],
                ]);
                return;
            }
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        $enabled = function_exists('aiTripEnabledModuleTypes')
            ? aiTripEnabledModuleTypes($db)
            : array_values(array_unique(array_column($GLOBALS['modules'] ?? [], 'type')));

        // Optional departure context — never merged into the user prompt string.
        $departureContext = [];
        $depCity = trim((string) ($_GET['departure_city'] ?? ''));
        $depCountry = trim((string) ($_GET['departure_country'] ?? ''));
        $depAirport = strtoupper(trim((string) ($_GET['departure_airport'] ?? '')));
        $depSource = strtolower(trim((string) ($_GET['departure_source'] ?? '')));
        if ($depCity !== '' && mb_strlen($depCity) <= 80) {
            $departureContext['city'] = $depCity;
        }
        if ($depCountry !== '' && mb_strlen($depCountry) <= 80) {
            $departureContext['country'] = $depCountry;
        }
        if ($depAirport !== '' && preg_match('/^[A-Z]{3}$/', $depAirport)) {
            $departureContext['airport'] = $depAirport;
        }
        if (in_array($depSource, ['geolocation', 'manual', 'last_used', 'session', 'explicit'], true)) {
            $departureContext['source'] = $depSource;
        }

        $natIso = strtoupper(trim((string) ($_GET['nationality'] ?? '')));
        $natSource = strtolower(trim((string) ($_GET['nationality_source'] ?? '')));
        if (function_exists('countryIsoFromLabel')) {
            $natIso = countryIsoFromLabel($db, $natIso);
        } elseif (!preg_match('/^[A-Z]{2}$/', $natIso)) {
            $natIso = '';
        }
        if ($natIso !== '') {
            $departureContext['nationality'] = $natIso;
        }
        if (in_array($natSource, ['manual', 'geolocation', 'last_used', 'session', 'prompt'], true)) {
            $departureContext['nationality_source'] = $natSource;
        }

        $service = new \App\lib\ai\aiSearchService($db);
        $payload = $service->search($q, $enabled, $departureContext);

        if (empty($payload['status'])) {
            $code = (string) ($payload['error_code'] ?? '');
            $isAdmin = strtolower((string) ($_SESSION['user_role'] ?? '')) === 'admin'
                || !empty($_SESSION['admin_logged_in']);

            // Config / billing / model details are for admins only.
            // Guests get a simple temporary-availability message instead.
            $adminOnlyCodes = [
                'AI_QUOTA_EXCEEDED',
                'AI_INVALID_MODEL',
                'AI_NOT_CONFIGURED',
                'AI_INVALID_API_KEY',
                'AI_PROVIDER_UNSUPPORTED',
                'AI_PROVIDER_ERROR',
                'AI_PROVIDER_UNAVAILABLE',
                'AI_SEARCH_FAILED',
            ];
            if ($code === 'AI_MODULE_DISABLED') {
                $disabled = is_array($payload['disabled_modules'] ?? null)
                    ? array_values($payload['disabled_modules'])
                    : [];
                if (!$isAdmin) {
                    error_log('[ai][search] module_disabled guest modules=' . implode(',', $disabled));
                    $guestLabels = [];
                    foreach ($disabled as $mod) {
                        $guestLabels[] = match (strtolower((string) $mod)) {
                            'flights' => 'Flights',
                            'stays' => 'Hotels',
                            'cars' => 'Cars',
                            'tours' => 'Tours',
                            'visa' => 'Visa',
                            'umrah' => 'Umrah',
                            'esim' => 'eSIM',
                            'bus' => 'Bus',
                            'rail' => 'Trains',
                            'ferries' => 'Ferries',
                            'cruises' => 'Cruises',
                            default => ucfirst((string) $mod),
                        };
                    }
                    $availableTypes = function_exists('aiTripEnabledModuleTypes')
                        ? aiTripEnabledModuleTypes($db ?? ($GLOBALS['db'] ?? null))
                        : [];
                    $availLabels = [];
                    foreach ($availableTypes as $mod) {
                        $availLabels[] = match (strtolower((string) $mod)) {
                            'flights' => 'Flights',
                            'stays' => 'Hotels',
                            'cars' => 'Cars',
                            'tours' => 'Tours',
                            'visa' => 'Visa',
                            'umrah' => 'Umrah',
                            'esim' => 'eSIM',
                            'bus' => 'Bus',
                            'rail' => 'Trains',
                            'ferries' => 'Ferries',
                            'cruises' => 'Cruises',
                            default => ucfirst((string) $mod),
                        };
                    }
                    $availLabels = array_values(array_unique(array_filter($availLabels)));
                    if (count($guestLabels) === 1) {
                        $payload['message'] = $guestLabels[0] . ' is not available for AI Trip right now.';
                    } else {
                        $payload['message'] = 'That travel option is not available for AI Trip right now.';
                    }
                    if ($availLabels !== []) {
                        $examples = array_slice($availLabels, 0, 3);
                        $payload['message'] .= ' Try ' . implode(', ', $examples)
                            . (count($availLabels) > 3 ? ', or another shown on top' : '')
                            . '.';
                    } else {
                        $payload['message'] .= ' Please try another travel option.';
                    }
                }
                // Admin keeps the service message (enable under Modules).
            } elseif (!$isAdmin && in_array($code, $adminOnlyCodes, true)) {
                // Keep the real code in logs; guests only see a soft message.
                error_log('[ai][search] guest_masked code=' . $code . ' detail=' . (string) ($payload['message'] ?? ''));
                $payload['message'] = 'The AI Trip Planner is temporarily unavailable. Please try again later.';
                $payload['error_code'] = 'AI_TEMPORARILY_UNAVAILABLE';
                $payload['retryable'] = true;
            } elseif ($isAdmin && in_array($code, $adminOnlyCodes, true)) {
                // Admin sees actionable detail (invalid/expired key, quota, etc.).
                $payload['retryable'] = in_array($code, [
                    'AI_QUOTA_EXCEEDED',
                    'AI_PROVIDER_ERROR',
                    'AI_PROVIDER_UNAVAILABLE',
                    'AI_SEARCH_FAILED',
                ], true);
            }

            // User-facing guidance errors (not provider secrets).
            if ($code === 'AI_NOT_TRAVEL_QUERY' || $code === 'AI_MODULE_DISABLED' || $code === 'AI_FLIGHT_ROUTE_REQUIRED' || $code === 'AI_STAY_DESTINATION_REQUIRED' || $code === 'AI_DEPARTURE_REQUIRED') {
                http_response_code(400);
            } elseif ($code === 'AI_EMPTY_QUERY' || $code === 'AI_NO_MODULES') {
                http_response_code(400);
            } elseif ($code === 'AI_DISABLED') {
                http_response_code(403);
            } elseif (in_array($code, [
                'AI_QUOTA_EXCEEDED',
                'AI_INVALID_MODEL',
                'AI_NOT_CONFIGURED',
                'AI_INVALID_API_KEY',
                'AI_PROVIDER_UNSUPPORTED',
                'AI_PROVIDER_ERROR',
                'AI_PROVIDER_UNAVAILABLE',
                'AI_TEMPORARILY_UNAVAILABLE',
            ], true)) {
                http_response_code(503);
            } elseif ($code !== '') {
                http_response_code(502);
            }
        } else {
            // Degraded success (soft AI failure → keyword parse): admins see why.
            $isAdmin = strtolower((string) ($_SESSION['user_role'] ?? '')) === 'admin'
                || !empty($_SESSION['admin_logged_in']);
            if (!empty($payload['ai_degraded'])) {
                error_log(
                    '[ai][search] degraded code='
                    . (string) ($payload['ai_error_code'] ?? '')
                    . ' detail='
                    . (string) ($payload['ai_error'] ?? '')
                );
                if (!$isAdmin) {
                    unset($payload['ai_error'], $payload['ai_error_code']);
                }
            }
        }

        echo json_encode($payload);
    } catch (Throwable $e) {
        error_log('[ai][search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'AI search failed.',
            'error_code' => 'AI_INTERNAL_ERROR',
            'result' => '',
            'module' => '',
            'modules' => [],
            'hint' => '',
            'searches' => [],
        ]);
    }
});
