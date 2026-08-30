<?php

namespace App\lib\ai;

/**
 * Natural-language home AI search — builds live search intents for all matching enabled modules.
 */
class aiSearchService
{
    private $db;

    /** @var string|null */
    private $lastAiError = null;

    /** @var string|null */
    private $lastAiErrorCode = null;

    /** @var array<int,array<string,mixed>>|null */
    private $ferryPorts = null;

    /** @var array<int,array<string,mixed>>|null */
    private $ferryRoutes = null;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @param string[] $enabledModules
     * @param array{city?:string,country?:string,airport?:string,source?:string} $departureContext
     * @return array{
     *   status:bool,
     *   message?:string,
     *   result:string,
     *   module:string,
     *   modules:array,
     *   hint:string,
     *   searches:array,
     *   departure?:array
     * }
     */
    public function search(string $query, array $enabledModules, array $departureContext = []): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        $enabledModules = array_values(array_unique(array_filter(array_map('strval', $enabledModules))));

        $empty = [
            'status' => false,
            'message' => '',
            'error_code' => '',
            'result' => '',
            'module' => '',
            'modules' => [],
            'hint' => '',
            'searches' => [],
            'parser_tier' => '',
        ];

        if ($query === '' || mb_strlen($query) < 2) {
            $empty['message'] = 'Please type what you want to search.';
            $empty['error_code'] = 'AI_EMPTY_QUERY';
            $empty['parser_tier'] = 'skipped';
            return $this->finishSearch($query, $empty);
        }

        if ($enabledModules === []) {
            $empty['message'] = 'No travel modules are enabled.';
            $empty['error_code'] = 'AI_NO_MODULES';
            $empty['parser_tier'] = 'skipped';
            return $this->finishSearch($query, $empty);
        }

        if (!aiTripIsEnabled($this->db)) {
            $empty['message'] = 'AI search is disabled.';
            $empty['error_code'] = 'AI_DISABLED';
            $empty['parser_tier'] = 'skipped';
            return $this->finishSearch($query, $empty);
        }

        // Query asks only for module(s) that are off / inactive.
        $requestedModules = $this->detectModuleKeywords($query, $this->allKnownModuleTypes());
        $requestedEnabled = array_values(array_intersect($requestedModules, $enabledModules));
        if ($requestedModules !== [] && $requestedEnabled === []) {
            $labels = array_map(fn ($m) => $this->moduleLabel($m), $requestedModules);
            $labelList = implode(', ', $labels);
            $empty['message'] = $labelList
                . (count($labels) === 1 ? ' is' : ' are')
                . ' not enabled for AI Trip. Enable '
                . (count($labels) === 1 ? 'it' : 'them')
                . ' under Admin → Settings → Modules (set status and active).';
            $empty['error_code'] = 'AI_MODULE_DISABLED';
            $empty['disabled_modules'] = $requestedModules;
            $empty['parser_tier'] = 'rejected';
            return $this->finishSearch($query, $empty);
        }

        $flightRouteMsg = $this->missingFlightRouteMessage($query);
        if ($flightRouteMsg !== '') {
            $empty['message'] = $flightRouteMsg;
            $empty['error_code'] = 'AI_FLIGHT_ROUTE_REQUIRED';
            $empty['parser_tier'] = 'rejected';
            return $this->finishSearch($query, $empty);
        }

        $stayDestMsg = $this->missingStayDestinationMessage($query);
        if ($stayDestMsg !== '') {
            $empty['message'] = $stayDestMsg;
            $empty['error_code'] = 'AI_STAY_DESTINATION_REQUIRED';
            $empty['parser_tier'] = 'rejected';
            return $this->finishSearch($query, $empty);
        }

        if ($this->queryNeedsDetectedFlightOrigin($query)) {
            $ctxCity = $this->sanitizePlaceName((string) ($departureContext['city'] ?? ''));
            $ctxAirport = strtoupper(trim((string) ($departureContext['airport'] ?? '')));
            $ctxSource = strtolower(trim((string) ($departureContext['source'] ?? '')));
            $hasLiveGeo = $ctxSource === 'geolocation'
                && ($ctxCity !== '' || preg_match('/^[A-Z]{3}$/', $ctxAirport));
            if (!$hasLiveGeo) {
                $empty['message'] = 'Turn on location in your browser settings. We use it as your departure city.';
                $empty['error_code'] = 'AI_DEPARTURE_REQUIRED';
                $empty['parser_tier'] = 'rejected';
                return $this->finishSearch($query, $empty);
            }
        }

        // Reject trivia / non-travel prompts — never invent flights/hotels for them.
        if (!$this->queryHasTravelIntent($query, $enabledModules) || $this->isGenericNonTravelQuery($query, $enabledModules)) {
            $empty['message'] = 'Please type a relevant travel prompt (for example, \'trip to Dubai\' or \'flights from London to Paris\').';
            $empty['error_code'] = 'AI_NOT_TRAVEL_QUERY';
            $empty['parser_tier'] = 'rejected';
            return $this->finishSearch($query, $empty);
        }

        $this->lastAiError = null;
        $this->lastAiErrorCode = null;

        $complexity = $this->classifyQueryComplexity($query, $enabledModules);
        $parserTier = $complexity;
        $parsed = null;
        $aiDegradedCode = null;
        $aiDegradedMessage = null;

        if ($complexity === 'simple') {
            $parsed = $this->parseWithKeywords($query, $enabledModules);
            if (!$this->keywordParseIsConfident($parsed, $query)) {
                $complexity = 'complex';
                $parserTier = 'complex';
                $parsed = null;
            }
        }

        if ($complexity === 'complex') {
            $config = passportAiActiveProviderConfig($this->db);
            if ($config === null) {
                $empty['message'] = 'AI API key is missing or incomplete. Add a valid key under Admin → Settings → AI.';
                $empty['error_code'] = 'AI_NOT_CONFIGURED';
                $empty['parser_tier'] = 'complex';
                return $this->finishSearch($query, $empty);
            }

            $parsed = $this->parseWithAi($query, $enabledModules);
            if ($parsed === null) {
                $errCode = (string) ($this->lastAiErrorCode ?? '');
                // Hard config / billing / auth failures must surface (admin sees detail; guests get a soft message in the route).
                $hardFail = [
                    'AI_NOT_CONFIGURED',
                    'AI_INVALID_API_KEY',
                    'AI_QUOTA_EXCEEDED',
                    'AI_INVALID_MODEL',
                    'AI_PROVIDER_UNSUPPORTED',
                ];
                if (in_array($errCode, $hardFail, true)) {
                    $empty['message'] = $this->lastAiError
                        ?: 'AI search failed. Check that your API key is valid under Admin → Settings → AI.';
                    $empty['error_code'] = $errCode;
                    $empty['parser_tier'] = 'complex';
                    return $this->finishSearch($query, $empty);
                }

                // Soft provider/parse failures → keyword fallback when possible.
                $parsed = $this->parseWithKeywords($query, $enabledModules);
                $parserTier = 'simple_fallback';
                if ($this->lastAiErrorCode) {
                    // Preserve for admin diagnostics on degraded success responses.
                    $aiDegradedCode = $this->lastAiErrorCode;
                    $aiDegradedMessage = $this->lastAiError;
                }
            } else {
                $parserTier = 'complex';
            }
        }

        if ($parsed === null) {
            $empty['message'] = $this->lastAiError
                ?: 'AI search failed. Check that your API key is valid under Admin → Settings → AI.';
            $empty['error_code'] = $this->lastAiErrorCode ?: 'AI_SEARCH_FAILED';
            $empty['parser_tier'] = $parserTier;
            return $this->finishSearch($query, $empty);
        }

        $modules = $this->reorderModulesByMention(
            is_array($parsed['modules'] ?? null) ? $parsed['modules'] : [],
            $query,
            $enabledModules
        );
        // Keyword-detected modules must not be dropped when AI omits one (e.g. tours+flight).
        $keywordModules = $this->detectModuleKeywords($query, $enabledModules);
        if ($keywordModules !== []) {
            $modules = $this->reorderModulesByMention(
                array_values(array_unique(array_merge($keywordModules, $modules))),
                $query,
                $enabledModules
            );
        }
        // LLM often returns only "esim" for "trip to Dubai with esim" — expand core inventory.
        $modules = $this->expandBroadTripModules($modules, $query, $enabledModules);
        // Flight-only fare prompts must not keep empty hotel/eSIM tabs.
        if ($this->isFlightOnlyDestinationAsk($query) && in_array('flights', $enabledModules, true)) {
            $modules = ['flights'];
        }
        if ($modules === []) {
            // If AI failed hard we already returned; empty modules after soft fallback = not a travel parse.
            if (!empty($aiDegradedCode) && in_array($aiDegradedCode, [
                'AI_PROVIDER_ERROR', 'AI_PROVIDER_UNAVAILABLE', 'AI_SEARCH_FAILED', 'AI_INVALID_RESPONSE',
            ], true)) {
                $empty['message'] = $aiDegradedMessage
                    ?: 'AI search failed. Check that your API key is valid under Admin → Settings → AI.';
                $empty['error_code'] = $aiDegradedCode;
                $empty['parser_tier'] = 'complex';
                return $this->finishSearch($query, $empty);
            }
            $empty['message'] = 'Please describe a travel search (for example flights, hotels, visa, or bus).';
            $empty['error_code'] = 'AI_NOT_TRAVEL_QUERY';
            $empty['parser_tier'] = $parserTier === 'simple_fallback' ? 'simple' : $parserTier;
            return $this->finishSearch($query, $empty);
        }
        $module = strtolower(trim((string) ($parsed['module'] ?? '')));
        if (!in_array($module, $modules, true)) {
            $module = $modules[0] ?? ($enabledModules[0] ?? '');
        }
        $hint = $this->sanitizePlaceName((string) ($parsed['hint'] ?? ''));
        $fields = $this->sanitizeFields($parsed['fields'] ?? [], $query, $hint, $departureContext);
        $fields = $this->applyFieldDefaults($fields, $query);
        $fields = $this->resolveCodesAndCatalogs($fields, $modules, $query);

        $hasDestination = false;
        $destKeys = [
            'destination_city', 'bus_destination_city', 'visa_to_country',
            'esim_country', 'umrah_destination', 'rail_destination', 'ferry_destination'
        ];
        foreach ($destKeys as $dk) {
            if (isset($fields[$dk]) && trim((string) $fields[$dk]) !== '') {
                $hasDestination = true;
                break;
            }
        }
        if (trim($hint) !== '') {
            $hasDestination = true;
        }

        if (!$hasDestination) {
            $empty['message'] = 'Please specify a destination or route in your travel prompt (for example, \'flights to Paris\' or \'hotels in Dubai\').';
            $empty['error_code'] = 'AI_NOT_TRAVEL_QUERY';
            $empty['parser_tier'] = $parserTier === 'simple_fallback' ? 'simple' : $parserTier;
            return $this->finishSearch($query, $empty);
        }

        $searches = $this->buildSearches($modules, $fields, $hint, $query);
        $result = $this->buildSummary($modules, $searches);

        $departureMeta = [
            'city' => (string) ($fields['origin_city'] ?? ''),
            'airport' => (string) ($fields['origin_code'] ?? ''),
            'source' => (string) ($fields['departure_source'] ?? ''),
            'needs_departure' => empty($fields['origin_city']) && empty($fields['origin_code']),
        ];

        $out = [
            'status' => true,
            'result' => $result,
            'module' => $module,
            'modules' => $modules,
            'hint' => $hint,
            'searches' => $searches,
            'departure' => $departureMeta,
            'parser_tier' => $parserTier === 'simple_fallback' ? 'simple' : $parserTier,
        ];
        if (!empty($aiDegradedCode)) {
            $out['ai_degraded'] = true;
            $out['ai_error_code'] = $aiDegradedCode;
            $out['ai_error'] = (string) ($aiDegradedMessage ?? '');
        }
        return $this->finishSearch($query, $out);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function finishSearch(string $query, array $payload): array
    {
        return $payload;
    }

    /**
     * @param string[] $enabledModules
     * @return array{result:string,module:string,modules:array,hint:string,fields:array}|null
     */
    private function parseWithAi(string $query, array $enabledModules): ?array
    {
        $config = passportAiActiveProviderConfig($this->db);
        if ($config === null) {
            $this->lastAiError = 'AI API key is missing or incomplete. Add a valid key under Admin → Settings → AI.';
            $this->lastAiErrorCode = 'AI_NOT_CONFIGURED';
            return null;
        }

        $provider = strtolower(trim((string) ($config['key'] ?? '')));
        if (!in_array($provider, ['openai', 'claude', 'gemini'], true)) {
            $this->lastAiError = 'The selected AI provider is not supported for trip search.';
            $this->lastAiErrorCode = 'AI_PROVIDER_UNSUPPORTED';
            return null;
        }

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            $this->lastAiError = 'AI API key is missing. Add a valid key under Admin → Settings → AI.';
            $this->lastAiErrorCode = 'AI_NOT_CONFIGURED';
            return null;
        }

        $prompt = $this->buildSearchPrompt($query, $enabledModules);
        $content = match ($provider) {
            'openai' => $this->chatOpenAi($prompt, $config),
            'claude' => $this->chatClaude($prompt, $config),
            'gemini' => $this->chatGemini($prompt, $config),
            default => null,
        };

        if ($content === null || trim($content) === '') {
            if ($this->lastAiError === null) {
                $this->lastAiError = 'AI search failed. Check that your API key is valid under Admin → Settings → AI.';
                $this->lastAiErrorCode = 'AI_SEARCH_FAILED';
            }
            return null;
        }

        $parsed = $this->decodeJsonObject($content);
        if ($parsed === null) {
            $this->lastAiError = 'AI returned an invalid response. Please try again.';
            $this->lastAiErrorCode = 'AI_INVALID_RESPONSE';
            return null;
        }

        $modules = [];
        if (isset($parsed['modules']) && is_array($parsed['modules'])) {
            foreach ($parsed['modules'] as $m) {
                $m = strtolower(trim((string) $m));
                if (in_array($m, $enabledModules, true)) {
                    $modules[] = $m;
                }
            }
        }
        $modules = array_values(array_unique($modules));

        $module = strtolower(trim((string) ($parsed['module'] ?? '')));
        if (!in_array($module, $enabledModules, true)) {
            $module = $modules[0] ?? ($enabledModules[0] ?? '');
        }
        if ($modules === [] && $module !== '') {
            $modules = [$module];
        }

        $fields = is_array($parsed['fields'] ?? null) ? $parsed['fields'] : [];

        return [
            'result' => '',
            'module' => $module,
            'modules' => $modules,
            'hint' => trim((string) ($parsed['hint'] ?? '')),
            'fields' => $fields,
        ];
    }

    /**
     * @param string[] $enabledModules
     */
    private function buildSearchPrompt(string $query, array $enabledModules): string
    {
        $modulesJson = json_encode(array_values($enabledModules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $today = date('Y-m-d');
        $safeQuery = str_replace(['\\', '"'], ['\\\\', '\\"'], $query);

        $fieldKeys = [
            'origin_city', 'destination_city', 'departure_date', 'return_date', 'trip_type',
            'flight_routes', 'adults', 'children', 'infants', 'rooms', 'class', 'duration_days',
            'bus_origin_city', 'bus_destination_city',
            'visa_from_country', 'visa_to_country',
            'esim_country', 'umrah_destination',
            'rail_origin', 'rail_destination',
            'ferry_origin', 'ferry_destination',
        ];
        $fieldsShape = [];
        foreach ($fieldKeys as $key) {
            $fieldsShape[$key] = $key === 'flight_routes' ? [] : ($key === 'adults' || $key === 'children' || $key === 'infants' || $key === 'rooms' || $key === 'duration_days' ? 0 : '');
        }
        $fieldsShape['trip_type'] = 'oneway';
        $jsonShape = json_encode([
            'modules' => ['flights'],
            'module' => 'flights',
            'hint' => '',
            'fields' => $fieldsShape,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You extract travel-search entities for a booking website. Return ONLY valid JSON matching this shape (use "" or 0 when not stated; leave airport/catalog codes empty — backend resolves them):
{$jsonShape}

Available modules (ONLY use these): {$modulesJson}
User text: "{$safeQuery}"
Today's date (context only): {$today}

Rules:
- "modules" = every related enabled module that could return useful live results; list them in the SAME ORDER the user mentions them.
- If the user asks to arrange/plan a trip/vacation/holiday to a place, include flights, stays, and esim when those modules are available. Add tours/cars/visa/etc. only when the user asks for them.
- If no mention order, use: flights → stays → tours → cars → bus → rail → ferries → esim → visa → umrah → others.
- "module" = best primary module (usually first in "modules").
- Put real city/station/port/country names in the matching fields; never invent placeholders.
- If the user asks for flights/hotels/etc. without naming any place or route, leave origin/destination empty — do not invent cities.
- NEVER invent origin_city or origin_code. Destination-only prompts ("flights to Dubai", "fly to London tomorrow") MUST leave origin_city and origin_code as "". The app fills departure separately.
- For eSIM, put the destination country in esim_country as ISO-2 when known (ES, AE, US). Leave "" if the user did not name a country — the app shows a country picker.
- Bus cities → bus_*; rail → rail_*; ferry → ferry_*; main origin_city/destination_city are for air/hotel destination.
- For tours, prefer the city after "tours in …" even when a later flight goes elsewhere.
- trip_type: oneway | return | multicity. For multicity fill flight_routes (2–6 legs with origin_city, destination_city, date).
- Never invent modules outside the available list. Do not write prose.
PROMPT;
    }

    /**
     * Conservative simple vs complex routing for token savings.
     *
     * @param string[] $enabledModules
     * @return 'simple'|'complex'
     */
    private function classifyQueryComplexity(string $query, array $enabledModules): string
    {
        $q = strtolower($query);

        if (preg_match('/\b(multi[\s-]?city|multicity|open[\s-]?jaw|multi[\s-]?leg)\b/i', $q)) {
            return 'complex';
        }

        $matched = $this->detectModuleKeywords($query, $enabledModules);
        if (count($matched) >= 3) {
            return 'complex';
        }

        if (in_array('bus', $matched, true) && in_array('flights', $matched, true)) {
            return 'complex';
        }

        if (in_array('bus', $matched, true) && count($matched) >= 2 && preg_match_all('/\bto\b/i', $q) >= 2) {
            return 'complex';
        }

        if ((in_array('visa', $matched, true) || in_array('umrah', $matched, true)) && count($matched) >= 2) {
            return 'complex';
        }

        if (in_array('rail', $matched, true) || in_array('ferries', $matched, true)) {
            return 'complex';
        }

        if (in_array('esim', $matched, true) && count($matched) === 1) {
            // Bare eSIM with no place → complex (do not invent a country).
            if (!preg_match('/\b(?:for|in|to|at)\s+[A-Za-z]{2,}/i', $q)) {
                return 'complex';
            }
        }

        if (preg_match_all('/\b(?:flight|flights|fly)\b/i', $q) >= 2) {
            return 'complex';
        }

        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})';
        if (preg_match_all('/\b' . $cityTok . '\s+to\s+' . $cityTok . '/i', $query) >= 2) {
            return 'complex';
        }

        if (preg_match('/\b(then|also|plus)\b/i', $q) && count($matched) >= 2) {
            return 'complex';
        }

        return 'simple';
    }

    /**
     * All module types the keyword map understands (enabled or not).
     *
     * @return string[]
     */
    private function allKnownModuleTypes(): array
    {
        return [
            'flights', 'stays', 'cars', 'tours', 'visa', 'umrah', 'esim',
            'cruises', 'ferries', 'bus', 'rail',
        ];
    }

    /**
     * Flight prompts need an arrival city. Departure can come from geolocation
     * (or last-used / manual context) when the traveler does not name origin.
     */
    private function missingFlightRouteMessage(string $query): string
    {
        $mentionsFlights = $this->detectModuleKeywords($query, ['flights']) !== []
            || $this->isFlightOnlyDestinationAsk($query);
        if (!$mentionsFlights) {
            return '';
        }

        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})';
        $hasPair = $this->queryHasRealFlightCityPair($query)
            || (bool) preg_match('/\b[A-Z]{3}\s*(?:-|–|to|→)\s*[A-Z]{3}\b/', $query);

        $hasTo = $hasPair || $this->extractFlightDestinationOnlyHint($query) !== '';
        if (!$hasTo && preg_match('/\bto\s+' . $cityTok . '/i', $query, $m)) {
            $place = $this->sanitizePlaceName($m[1] ?? '');
            $hasTo = $place !== '' && !preg_match(
                '/^(today|tomorrow|now|me|you|us|cheap|cheapest|best|flights?|fly|hotels?|tours?|cars?)$/i',
                $place
            );
        }

        if ($hasTo) {
            return '';
        }

        return 'Please mention Arrival To in your prompt (for example, flights to Paris or flights in Dubai).';
    }

    /**
     * Hotel/stay prompts need a destination city. Do not invent one (e.g. Dubai).
     */
    private function missingStayDestinationMessage(string $query): string
    {
        $mentionsStays = $this->detectModuleKeywords($query, ['stays']) !== [];
        if (!$mentionsStays) {
            return '';
        }
        if ($this->queryHasStayDestination($query)) {
            return '';
        }
        return 'Please mention a hotel destination in your prompt (for example, hotels in Dubai).';
    }

    private function queryHasStayDestination(string $query): bool
    {
        if ($this->extractFlightDestinationOnlyHint($query) !== '') {
            return true;
        }
        if ($this->queryHasRealFlightCityPair($query)) {
            return true;
        }

        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})';
        $filler = '/^(today|tomorrow|tonight|now|me|you|us|look|looking|find|get|show|give|need|want|book|search|please|'
            . 'cheap|cheapest|best|good|a|an|the|flights?|fly|hotels?|stays?|stay|resort|hostel|apartment|'
            . 'tours?|cars?|tickets?|room|rooms)$/i';

        if (preg_match('/\b(?:hotels?|stays?|stay|resort|hostel|apartment)\s+(?:in|at|near|around|for|to)\s+' . $cityTok . '\b/i', $query, $m)) {
            $place = $this->sanitizePlaceName($m[1] ?? '');
            if ($place !== '' && !preg_match($filler, $place)) {
                return true;
            }
        }
        if (preg_match('/\b' . $cityTok . '\s+(?:hotels?|stays?|stay|resort|hostel|apartment)\b/i', $query, $m)) {
            $place = $this->sanitizePlaceName($m[1] ?? '');
            if ($place !== '' && !preg_match($filler, $place)) {
                return true;
            }
        }
        if (preg_match('/\b(?:in|at|near|to)\s+' . $cityTok . '\b/i', $query, $m)) {
            $place = $this->sanitizePlaceName($m[1] ?? '');
            if ($place !== '' && !preg_match($filler, $place)) {
                return true;
            }
        }

        return false;
    }

    /** True when "City to City" uses real place names, not "flights to Paris". */
    private function queryHasRealFlightCityPair(string $query): bool
    {
        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})';
        if (!preg_match('/\b' . $cityTok . '\s+to\s+' . $cityTok . '\b/i', $query, $m)) {
            return false;
        }
        $left = $this->sanitizePlaceName($m[1] ?? '');
        $right = $this->sanitizePlaceName($m[2] ?? '');
        return $left !== '' && $right !== ''
            && !$this->isFillerFlightPlace($left)
            && !$this->isFillerFlightPlace($right);
    }

    private function isFillerFlightPlace(string $place): bool
    {
        return (bool) preg_match(
            '/^(today|tomorrow|now|me|you|us|look|looking|find|get|show|give|need|want|book|search|please|'
            . 'cheap|cheapest|best|good|a|an|the|flights?|fly|hotels?|stays?|tours?|cars?|tickets?)$/i',
            trim($place)
        );
    }

    /** Destination-only flight prompt: origin must come from geolocation context. */
    private function queryNeedsDetectedFlightOrigin(string $query): bool
    {
        $mentionsFlights = $this->detectModuleKeywords($query, ['flights']) !== []
            || $this->isFlightOnlyDestinationAsk($query);
        if (!$mentionsFlights) {
            return false;
        }

        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})';
        $hasPair = $this->queryHasRealFlightCityPair($query)
            || (bool) preg_match('/\b[A-Z]{3}\s*(?:-|–|to|→)\s*[A-Z]{3}\b/', $query);

        $hasFrom = $hasPair;
        if (!$hasFrom && preg_match('/\bfrom\s+(?!today|tomorrow|now|here|there|date\b)' . $cityTok . '/i', $query, $m)) {
            $place = $this->sanitizePlaceName($m[1] ?? '');
            $hasFrom = $place !== '' && !preg_match('/^(flights?|fly|hotels?|tours?|cars?)$/i', $place);
        }

        $hasTo = $hasPair || $this->extractFlightDestinationOnlyHint($query) !== '';
        if (!$hasTo && preg_match('/\bto\s+' . $cityTok . '/i', $query, $m)) {
            $place = $this->sanitizePlaceName($m[1] ?? '');
            $hasTo = $place !== '' && !preg_match(
                '/^(today|tomorrow|now|me|you|us|cheap|cheapest|best|flights?|fly|hotels?|tours?|cars?)$/i',
                $place
            );
        }

        return $hasTo && !$hasFrom;
    }

    /**
     * @param string[] $enabledModules empty array = treat as allow-all (caller should pass known types)
     * @return string[] modules in mention order
     */
    private function detectModuleKeywords(string $query, array $enabledModules): array
    {
        $q = strtolower($query);
        $map = [
            'flights' => ['flight', 'flights', 'fly', 'airfare', 'airline', 'airport'],
            'stays' => ['hotel', 'hotels', 'stay', 'stays', 'resort', 'apartment', 'hostel'],
            'cars' => ['car', 'cars', 'rent a car', 'rental', 'vehicle'],
            'tours' => ['tour', 'tours', 'activity', 'activities', 'excursion'],
            'visa' => ['visa', 'visas'],
            'umrah' => ['umrah', 'hajj'],
            'esim' => ['esim', 'e-sim', 'e sim', 'sim card', 'data sim', 'travel sim', 'tourist sim', 'data plan', 'data package', 'mobile data', 'roaming'],
            'cruises' => ['cruise', 'cruises'],
            'ferries' => ['ferry', 'ferries'],
            'bus' => ['bus', 'buses', 'coach'],
            'rail' => ['rail', 'train', 'trains', 'railway', 'whoosh'],
        ];

        $allowAll = $enabledModules === [];
        $positions = [];
        foreach ($map as $module => $words) {
            if (!$allowAll && !in_array($module, $enabledModules, true)) {
                continue;
            }
            $earliest = null;
            foreach ($words as $word) {
                $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
                if (!preg_match($pattern, $q, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $pos = (int) $m[0][1];
                if ($earliest === null || $pos < $earliest) {
                    $earliest = $pos;
                }
            }
            if ($earliest !== null) {
                $positions[$module] = $earliest;
            }
        }
        asort($positions, SORT_NUMERIC);
        return array_keys($positions);
    }

    /**
     * True when the text looks like a travel inventory search (not trivia/chat).
     *
     * @param string[] $enabledModules
     */
    private function queryHasTravelIntent(string $query, array $enabledModules): bool
    {
        $q = strtolower(trim($query));
        if ($q === '') {
            return false;
        }

        // Knowledge / trivia questions with no travel product words → reject.
        if (preg_match(
            '/^\s*(who|what|why|when|how|which|whose|whom)\b.+\?*\s*$/i',
            $query
        ) && $this->detectModuleKeywords($query, $enabledModules) === []) {
            return false;
        }
        if (preg_match(
            '/\b(who\s+is|who\s+was|what\s+is|what\s+are|tell\s+me\s+about|define|explain|founder|capital\s+of|population\s+of)\b/i',
            $q
        ) && $this->detectModuleKeywords($query, $enabledModules) === []) {
            return false;
        }

        if ($this->detectModuleKeywords($query, $enabledModules) !== []) {
            return true;
        }

        // Travel verbs / nouns beyond module keywords.
        if (preg_match(
            '/\b(book|booking|travel|travelling|traveling|trip|vacation|holiday|itinerary|'
            . 'depart|departure|arrive|arrival|ticket|tickets|round[\s-]?trip|one[\s-]?way|'
            . 'check[\s-]?in|nights?)\b/i',
            $q
        )) {
            return true;
        }

        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})';
        // "Lahore to Dubai" / "from Karachi to Istanbul"
        if (preg_match('/\b(?:from\s+)?' . $cityTok . '\s+to\s+' . $cityTok . '\b/i', $query)) {
            return true;
        }
        // Destination-only with a date window: "Barcelona next week"
        if (preg_match('/\b(?:in|to|near)\s+[A-Za-z]{3,}/i', $q)
            && $this->queryMentionsDate($query)) {
            return true;
        }

        return false;
    }

    /**
     * Reject generic prompts without destination or specific products (e.g. "find me a best trip").
     */
    private function isGenericNonTravelQuery(string $query, array $enabledModules): bool
    {
        $q = strtolower(trim($query));
        if ($q === '') {
            return true;
        }

        // Generic patterns
        $genericTripPatterns = [
            '/^(find\s+)?(me\s+)?(a\s+)?(best\s+)?(trip|vacation|holiday|getaway|itinerary|travel)$/i',
            '/^(plan\s+)?(a\s+)?(trip|vacation|holiday|getaway|itinerary|travel)$/i',
            '/^(book\s+)?(a\s+)?(trip|vacation|holiday|getaway|itinerary|travel)$/i',
            '/^best\s+(trip|vacation|holiday|getaway|itinerary|travel)$/i',
            '/^(what\s+is\s+)?(the\s+)?best\s+(trip|vacation|holiday|getaway|itinerary|travel)$/i',
        ];

        foreach ($genericTripPatterns as $pattern) {
            if (preg_match($pattern, $q)) {
                return true;
            }
        }

        // Check word count and lack of specific details or prepositions indicating travel destinations
        $words = explode(' ', preg_replace('/\s+/', ' ', $q));
        $wordCount = count($words);

        // If they ask something like "find me a trip" or "best trip"
        if ($wordCount <= 5) {
            // Check if there is NO specific module keyword
            $hasSpecificModule = $this->detectModuleKeywords($query, $enabledModules) !== [];
            // Check if there is a preposition indicating destination:
            $hasDestinationPreposition = (bool) preg_match('/\b(to|in|for|at|around|near|from)\b/i', $q);
            // Check if there is any date
            $hasDate = $this->queryMentionsDate($query);

            if (!$hasSpecificModule && !$hasDestinationPreposition && !$hasDate) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{modules?:array,fields?:array} $parsed
     */
    private function keywordParseIsConfident(array $parsed, string $query): bool
    {
        $fields = is_array($parsed['fields'] ?? null) ? $parsed['fields'] : [];
        $modules = is_array($parsed['modules'] ?? null) ? $parsed['modules'] : [];

        $routeKeys = [
            'origin_city', 'destination_city', 'bus_origin_city', 'bus_destination_city',
            'visa_from_country', 'visa_to_country', 'esim_country', 'umrah_destination',
            'rail_origin', 'rail_destination', 'ferry_origin', 'ferry_destination',
        ];
        foreach ($routeKeys as $key) {
            if (trim((string) ($fields[$key] ?? '')) !== '') {
                return true;
            }
        }

        if (count($modules) === 1 && preg_match(
            '/\b(hotel|hotels|flight|flights|fly|visa|umrah|esim|e-?sim|car|cars|tour|tours|bus|buses)\b/i',
            $query
        )) {
            return true;
        }

        return false;
    }

    /**
     * @param string[] $modules
     * @param string[] $enabledModules
     * @return string[]
     */
    private function reorderModulesByMention(array $modules, string $query, array $enabledModules): array
    {
        $modules = array_values(array_unique(array_filter(array_map(
            static fn ($m) => strtolower(trim((string) $m)),
            $modules
        ))));
        $modules = array_values(array_filter($modules, static fn ($m) => in_array($m, $enabledModules, true)));
        if ($modules === []) {
            return $modules;
        }

        $order = $this->detectModuleKeywords($query, $enabledModules);
        if ($order === []) {
            return $modules;
        }

        $ordered = [];
        foreach ($order as $m) {
            if (in_array($m, $modules, true)) {
                $ordered[] = $m;
            }
        }
        foreach ($modules as $m) {
            if (!in_array($m, $ordered, true)) {
                $ordered[] = $m;
            }
        }
        return $ordered;
    }

    /**
     * Final date/pax defaults after sanitizeFields — fills gaps only.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function applyFieldDefaults(array $fields, string $query): array
    {
        $default = $this->defaultTravelDateYmd();
        $dateMentioned = $this->queryMentionsDate($query);

        $departure = $this->normalizeYmdDate((string) ($fields['departure_date'] ?? ''));
        if ($departure === '' || !$dateMentioned) {
            $departure = $default;
        }
        $fields['departure_date'] = $departure;

        foreach (['bus_date', 'rail_date', 'ferry_date', 'visa_entry_date', 'umrah_start_date'] as $key) {
            $v = $this->normalizeYmdDate((string) ($fields[$key] ?? ''));
            if ($v === '' || !$dateMentioned) {
                $fields[$key] = $departure;
            } else {
                $fields[$key] = $v;
            }
        }

        $checkin = $this->normalizeYmdDate((string) ($fields['checkin'] ?? ''));
        if ($checkin === '' || !$dateMentioned) {
            $checkin = $departure;
        }
        $fields['checkin'] = $checkin;

        $checkout = $this->normalizeYmdDate((string) ($fields['checkout'] ?? ''));
        $nights = max(1, (int) ($fields['duration_days'] ?? 1));
        // Hotel nights = checkout − checkin (same as normal stays). Never use inclusive day counts.
        if ($checkout !== '' && $checkin !== '' && $checkout > $checkin) {
            $nights = max(1, min(30, (int) ((strtotime($checkout) - strtotime($checkin)) / 86400)));
        } elseif ($checkout === '') {
            $ts = strtotime($checkin . ' +' . $nights . ' days');
            $checkout = $ts ? date('Y-m-d', $ts) : $checkin;
        }
        $fields['checkout'] = $checkout;
        $fields['duration_days'] = $nights;

        if ((int) ($fields['adults'] ?? 0) < 1) {
            $fields['adults'] = 1;
        }
        $fields['children'] = max(0, (int) ($fields['children'] ?? 0));
        $fields['infants'] = max(0, (int) ($fields['infants'] ?? 0));
        $fields['rooms'] = max(1, min(5, (int) ($fields['rooms'] ?? 1)));
        if ($fields['rooms'] > (int) $fields['adults']) {
            $fields['rooms'] = (int) $fields['adults'];
        }
        $ages = $fields['child_ages'] ?? [];
        if (is_string($ages) && $ages !== '') {
            $decoded = json_decode($ages, true);
            $ages = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($ages)) {
            $ages = [];
        }
        $fields['child_ages'] = array_values(array_filter(
            array_map('intval', $ages),
            static fn ($a) => $a >= 1 && $a <= 17
        ));

        if (trim((string) ($fields['esim_package_type'] ?? '')) === '') {
            $fields['esim_package_type'] = 'all';
        } else {
            $fields['esim_package_type'] = $this->normalizeEsimPackageType((string) $fields['esim_package_type']);
        }

        if (empty($fields['date_assumed'])) {
            $fields['date_assumed'] = $dateMentioned ? '0' : '1';
        }

        return $fields;
    }

    /**
     * Resolve IATA / catalog values against DB helpers.
     *
     * @param array<string,mixed> $fields
     * @param string[] $modules
     * @return array<string,mixed>
     */
    private function resolveCodesAndCatalogs(array $fields, array $modules, string $query = ''): array
    {
        $originCity = (string) ($fields['origin_city'] ?? '');
        $destCity = (string) ($fields['destination_city'] ?? '');
        $originCode = strtoupper(trim((string) ($fields['origin_code'] ?? '')));
        $destCode = strtoupper(trim((string) ($fields['destination_code'] ?? '')));

        if ($originCode === '' || !preg_match('/^[A-Z]{3}$/', $originCode)) {
            $resolved = $this->resolveAirportCode($originCode, $originCity);
            if ($resolved !== null) {
                $fields['origin_code'] = $resolved;
            }
        } else {
            $fields['origin_code'] = $originCode;
        }

        if ($destCode === '' || !preg_match('/^[A-Z]{3}$/', $destCode)) {
            $resolved = $this->resolveAirportCode($destCode, $destCity);
            if ($resolved !== null) {
                $fields['destination_code'] = $resolved;
            }
        } else {
            $fields['destination_code'] = $destCode;
        }

        if (in_array('visa', $modules, true)) {
            $fields['visa_type'] = $this->normalizeVisaType((string) ($fields['visa_type'] ?? 'tourist'));
            $fields['visa_processing_speed'] = $this->normalizeVisaProcessingSpeed(
                (string) ($fields['visa_processing_speed'] ?? 'standard')
            );
        }

        if (in_array('umrah', $modules, true)) {
            $umrahDest = trim((string) ($fields['umrah_destination'] ?? ''));
            if ($umrahDest !== '') {
                $matched = $this->matchUmrahLocation($umrahDest, $this->umrahLocationsFromDb());
                $fields['umrah_destination'] = $matched;
            }
        }

        if (in_array('esim', $modules, true)) {
            $resolved = $this->resolveEsimCountry(
                (string) ($fields['esim_country'] ?? ''),
                $destCity !== '' ? $destCity : (string) ($fields['hint'] ?? ''),
                $query
            );
            // Only persist an active Airalo ISO — never a bare country name.
            $fields['esim_country'] = (string) ($resolved['iso'] ?? '');
            if (($resolved['name'] ?? '') !== '') {
                $fields['esim_country_name'] = (string) $resolved['name'];
            }
        }

        if (in_array('rail', $modules, true)) {
            $journeyType = (int) ($fields['rail_journey_type'] ?? 0);
            foreach (['rail_origin', 'rail_destination'] as $key) {
                $raw = trim((string) ($fields[$key] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $station = $this->matchRailStation($raw, $journeyType);
                if (is_array($station)) {
                    $fields[$key] = (string) ($station['name'] ?? ($station['station_name'] ?? ($station['code'] ?? $raw)));
                    if ($journeyType === 0 && !empty($station['journey_type'])) {
                        $fields['rail_journey_type'] = (string) $station['journey_type'];
                    }
                }
            }
        }

        if (in_array('ferries', $modules, true)) {
            $ports = $this->ferryPortsCatalog();
            foreach (['ferry_origin', 'ferry_destination'] as $key) {
                $raw = trim((string) ($fields[$key] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $port = $this->matchFerryPort($raw, $ports);
                if (is_array($port)) {
                    $fields[$key] = (string) ($port['name'] ?? ($port['port_name'] ?? ($port['code'] ?? $raw)));
                }
            }
        }

        return $fields;
    }

    /**
     * OpenAI / Gemini JSON Schema for structured trip-search output.
     *
     * @return array<string,mixed>
     */
    private function tripSearchJsonSchema(): array
    {
        $string = ['type' => 'string'];
        $int = ['type' => 'integer'];
        $routeItem = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'origin_city' => $string,
                'origin_code' => $string,
                'destination_city' => $string,
                'destination_code' => $string,
                'date' => $string,
            ],
            'required' => [
                'origin_city', 'origin_code', 'destination_city', 'destination_code', 'date',
            ],
        ];
        $fieldProps = [
            'origin_city' => $string,
            'destination_city' => $string,
            'departure_date' => $string,
            'return_date' => $string,
            'trip_type' => $string,
            'flight_routes' => [
                'type' => 'array',
                'items' => $routeItem,
            ],
            'adults' => $int,
            'children' => $int,
            'infants' => $int,
            'rooms' => $int,
            'class' => $string,
            'duration_days' => $int,
            'bus_origin_city' => $string,
            'bus_destination_city' => $string,
            'visa_from_country' => $string,
            'visa_to_country' => $string,
            'esim_country' => $string,
            'umrah_destination' => $string,
            'rail_origin' => $string,
            'rail_destination' => $string,
            'ferry_origin' => $string,
            'ferry_destination' => $string,
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'modules' => [
                    'type' => 'array',
                    'items' => $string,
                ],
                'module' => $string,
                'hint' => $string,
                'fields' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => $fieldProps,
                    'required' => array_keys($fieldProps),
                ],
            ],
            'required' => ['modules', 'module', 'hint', 'fields'],
        ];
    }

    /**
     * "value (Label — description) | …" for the prompt, straight from visa_settings.
     */
    private function visaSettingPromptList(string $settingType): string
    {
        $parts = [];
        foreach ($this->visaSettingsFromDb($settingType) as $row) {
            $label = $row['name'] !== '' ? $row['name'] : $row['value'];
            $parts[] = $row['desc'] !== ''
                ? ($row['value'] . ' (' . $label . ' — ' . $row['desc'] . ')')
                : ($row['value'] . ' (' . $label . ')');
        }
        return $parts === [] ? '(no active options configured)' : implode(' | ', $parts);
    }

    /**
     * @param array<string,mixed> $config
     */
    private function chatOpenAi(string $prompt, array $config): ?string
    {
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'https://api.openai.com/v1/chat/completions';
        }
        $model = trim((string) ($config['model'] ?? 'gpt-4o')) ?: 'gpt-4o';
        $timeout = max(5, (int) ($config['timeout'] ?? 30));
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];

        $basePayload = [
            'model' => $model,
            'temperature' => 0,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $schemaPayload = $basePayload + [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'ai_trip_search',
                    'strict' => true,
                    'schema' => $this->tripSearchJsonSchema(),
                ],
            ],
        ];

        $raw = $this->httpJsonPost($endpoint, $schemaPayload, $headers, min($timeout, 20));

        // Models that reject json_schema → fall back to json_object.
        if ($raw === null && $this->lastAiErrorCode === 'AI_PROVIDER_ERROR') {
            $this->lastAiError = null;
            $this->lastAiErrorCode = null;
            $objectPayload = $basePayload + [
                'response_format' => ['type' => 'json_object'],
            ];
            $raw = $this->httpJsonPost($endpoint, $objectPayload, $headers, min($timeout, 20));
        }

        if ($raw === null) {
            return null;
        }
        $response = json_decode($raw, true);
        $content = $response['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $content = json_encode($content);
        }
        return is_string($content) ? $content : null;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function chatClaude(string $prompt, array $config): ?string
    {
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'https://api.anthropic.com/v1/messages';
        }
        $model = trim((string) ($config['model'] ?? 'claude-sonnet-4-6')) ?: 'claude-sonnet-4-6';
        // Map deprecated Claude model ids used in older settings.
        $aliases = [
            'claude-sonnet-4-20250514' => 'claude-sonnet-4-6',
            'claude-sonnet-4' => 'claude-sonnet-4-6',
        ];
        $model = $aliases[$model] ?? $model;
        $timeout = max(5, (int) ($config['timeout'] ?? 30));
        $apiKey = trim((string) ($config['api_key'] ?? ''));

        $payload = [
            'model' => $model,
            'max_tokens' => 1024,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $raw = $this->httpJsonPost($endpoint, $payload, [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ], min($timeout, 20));

        if ($raw === null) {
            return null;
        }
        $response = json_decode($raw, true);
        $content = '';
        $blocks = $response['content'] ?? [];
        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $content .= (string) ($block['text'] ?? '');
                }
            }
        }
        return $content !== '' ? $content : null;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function chatGemini(string $prompt, array $config): ?string
    {
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        $model = trim((string) ($config['model'] ?? 'gemini-2.0-flash')) ?: 'gemini-2.0-flash';
        $timeout = max(5, (int) ($config['timeout'] ?? 30));
        $apiKey = trim((string) ($config['api_key'] ?? ''));

        if ($endpoint === '') {
            $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent';
        }
        $endpoint = str_replace('{model}', rawurlencode($model), $endpoint);
        if (!str_contains($endpoint, 'key=')) {
            $endpoint .= (str_contains($endpoint, '?') ? '&' : '?') . 'key=' . rawurlencode($apiKey);
        }

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->tripSearchJsonSchema(),
            ],
        ];

        $raw = $this->httpJsonPost($endpoint, $payload, [
            'Content-Type: application/json',
        ], min($timeout, 20));

        // Older Gemini models may reject responseSchema — retry without it.
        if ($raw === null && $this->lastAiErrorCode === 'AI_PROVIDER_ERROR') {
            $this->lastAiError = null;
            $this->lastAiErrorCode = null;
            unset($payload['generationConfig']['responseSchema']);
            $raw = $this->httpJsonPost($endpoint, $payload, [
                'Content-Type: application/json',
            ], min($timeout, 20));
        }

        if ($raw === null) {
            return null;
        }
        $response = json_decode($raw, true);
        $content = (string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? '');
        return $content !== '' ? $content : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @param string[] $headers
     */
    private function httpJsonPost(
        string $endpoint,
        array $payload,
        array $headers,
        int $timeout
    ): ?string {
        $http = function_exists('aiHttpPost')
            ? aiHttpPost($endpoint, $payload, $headers, $timeout)
            : (function_exists('aiUsageHttpPost')
                ? aiUsageHttpPost($endpoint, $payload, $headers, $timeout)
                : $this->httpJsonPostLegacy($endpoint, $payload, $headers, $timeout));

        $raw = (string) ($http['body'] ?? '');
        $errno = (int) ($http['errno'] ?? 0);
        $httpCode = (int) ($http['http'] ?? 0);

        if (empty($http['ok']) || ($errno && ($http['body'] ?? '') === '')) {
            error_log('[ai][search] provider_failed http=' . $httpCode . ' errno=' . $errno);
            $this->lastAiError = 'Could not reach the AI provider. Please try again.';
            $this->lastAiErrorCode = 'AI_PROVIDER_UNAVAILABLE';
            return null;
        }

        if ($httpCode === 401 || $httpCode === 403) {
            error_log('[ai][search] invalid_api_key http=' . $httpCode);
            $this->lastAiError = 'Invalid AI API key. Update the key under Admin → Settings → AI.';
            $this->lastAiErrorCode = 'AI_INVALID_API_KEY';
            return null;
        }

        if ($httpCode >= 400) {
            $decoded = is_array($http['json'] ?? null) ? $http['json'] : json_decode($raw, true);
            $apiError = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $apiCode = (string) ($apiError['code'] ?? '');
            $apiType = (string) ($apiError['type'] ?? '');
            $apiStatus = (string) ($apiError['status'] ?? '');
            $apiMsg = strtolower((string) ($apiError['message'] ?? ''));
            error_log('[ai][search] provider_failed http=' . $httpCode . ' code=' . $apiCode . ' type=' . $apiType . ' status=' . $apiStatus);

            if (
                $apiStatus === 'INVALID_ARGUMENT'
                || $apiStatus === 'UNAUTHENTICATED'
                || str_contains($apiMsg, 'api key')
                || str_contains($apiMsg, 'api_key')
                || str_contains($apiMsg, 'invalid key')
                || str_contains($apiMsg, 'authentication')
            ) {
                $this->lastAiError = 'Invalid AI API key. Update the key under Admin → Settings → AI.';
                $this->lastAiErrorCode = 'AI_INVALID_API_KEY';
                return null;
            }

            if ($httpCode === 429 || $apiCode === 'insufficient_quota' || $apiType === 'insufficient_quota' || str_contains($apiMsg, 'quota')) {
                $this->lastAiError = 'AI provider quota exceeded. Add billing/credits or use another API key in AI settings.';
                $this->lastAiErrorCode = 'AI_QUOTA_EXCEEDED';
                return null;
            }
            if ($apiCode === 'model_not_found' || str_contains($apiMsg, 'model')) {
                $this->lastAiError = 'The configured AI model is invalid. Update the model under Admin → Settings → AI.';
                $this->lastAiErrorCode = 'AI_INVALID_MODEL';
                return null;
            }

            $this->lastAiError = 'AI provider error. Check your API key and settings under Admin → Settings → AI.';
            $this->lastAiErrorCode = 'AI_PROVIDER_ERROR';
            return null;
        }

        return (string) $raw;
    }

    /**
     * @param array<string,mixed> $payload
     * @param string[] $headers
     * @return array{ok:bool,body:string,json:?array,http:int,errno:int,error:string,latency_ms:int,headers:array}
     */
    private function httpJsonPostLegacy(string $endpoint, array $payload, array $headers, int $timeout): array
    {
        $started = microtime(true);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = (string) curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = is_string($raw) ? $raw : '';
        $json = $body !== '' ? json_decode($body, true) : null;

        return [
            'ok' => $errno === 0 && $raw !== false,
            'body' => $body,
            'json' => is_array($json) ? $json : null,
            'http' => $http,
            'errno' => $errno,
            'error' => $error,
            'latency_ms' => (int) max(0, round((microtime(true) - $started) * 1000)),
            'headers' => [],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJsonObject(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $m)) {
            $content = trim($m[1]);
        } elseif (!str_starts_with($content, '{')) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $content = substr($content, $start, $end - $start + 1);
            }
        }
        $parsed = json_decode($content, true);
        return is_array($parsed) ? $parsed : null;
    }

    /**
     * @param string[] $enabledModules
     * @return array{result:string,module:string,modules:array,hint:string,fields:array}
     */
    private function parseWithKeywords(string $query, array $enabledModules): array
    {
        $matched = $this->detectModuleKeywords($query, $enabledModules);

        // Broad "arrange/plan a trip to X" → flights+stays+eSIM (when enabled),
        // then merge any products the user named (car, tours, visa, …).
        // Flight-only wording ("dubai round trip", departure/return dates) stays flights.
        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})';
        $looksLikeRoute = (bool) preg_match(
            '/\b(?:from\s+)?' . $cityTok . '\s+to\s+' . $cityTok . '\b/i',
            $query
        );
        $broadTrip = $this->isBroadTripRequest($query);
        $flightOnly = $this->isFlightOnlyDestinationAsk($query);

        if ($flightOnly && in_array('flights', $enabledModules, true)) {
            $matched = ['flights'];
        } elseif ($matched === [] && ($looksLikeRoute || $broadTrip)) {
            $matched = $this->defaultInventoryModules($enabledModules);
        } elseif ($broadTrip) {
            $matched = array_values(array_unique(array_merge(
                $this->defaultInventoryModules($enabledModules),
                $matched
            )));
        } elseif ($matched === [] && $looksLikeRoute && in_array('flights', $enabledModules, true)) {
            $matched = ['flights'];
        }

        $matched = array_values(array_unique($matched));

        if ($matched === []) {
            return [
                'result' => '',
                'module' => '',
                'modules' => [],
                'hint' => $this->extractHint($query),
                'fields' => $this->extractRouteFields($query, $this->extractHint($query)),
            ];
        }

        $hint = $this->extractHint($query);
        $fields = $this->extractRouteFields($query, $hint);

        // Broad "trip for Dubai" — ensure destination is the place, not filler words.
        $tripDest = $this->extractTripDestinationHint($query);
        if ($tripDest !== '') {
            $fields['destination_city'] = $tripDest;
            $fields['tour_destination'] = $tripDest;
            $hint = $tripDest;
        }
        $flightDest = $this->extractFlightDestinationOnlyHint($query);
        if ($flightDest !== '' && (
            $flightOnly
            || trim((string) ($fields['destination_city'] ?? '')) === ''
            || preg_match('/\b(departure|return|date|aug|jan|feb|mar|apr|may|jun|jul|sep|oct|nov|dec)\b/i', (string) ($fields['destination_city'] ?? ''))
        )) {
            $fields['destination_city'] = $flightDest;
            $hint = $flightDest;
        }

        return [
            'result' => '',
            'module' => $matched[0],
            'modules' => $matched,
            'hint' => $hint,
            'fields' => $fields,
        ];
    }

    /**
     * True package-trip asks ("arrange a trip", "vacation in X") — NOT flight fare
     * wording like "round trip" / "one-way trip".
     */
    private function isBroadTripRequest(string $query): bool
    {
        $q = trim($query);
        if ($q === '') {
            return false;
        }

        // Flight ticket language must not unlock hotels/eSIM defaults.
        $flightFareWording = (bool) preg_match(
            '/\b(round[\s-]?trip|one[\s-]?way(?:\s+trip)?|return\s+(?:on|flight|ticket|date)|'
            . 'departure\s+date|return\s+date)\b/i',
            $q
        );
        $packageIntent = (bool) preg_match(
            '/\b(arrange|plan|organise|organize|vacation|holiday|getaway|itinerary|'
            . 'hotel|hotels|stay|stays|esim|e-?sim|tour|tours|car|cars)\b/i',
            $q
        );
        if ($flightFareWording && !$packageIntent) {
            return false;
        }

        return (bool) preg_match(
            '/\b(?:(?:arrange|plan|organise|organize|book)\s+(?:me\s+|us\s+|a\s+|an\s+|the\s+)?)'
            . '(?:trip|vacation|holiday|getaway|itinerary)\b'
            . '|\b(?:a|an|the)\s+(?:trip|vacation|holiday|getaway|itinerary)\s+(?:for|to|in|at|around|near)\b'
            . '|\b(?:vacation|holiday|getaway|itinerary)\b'
            . '|\b(?:travel\s+to|going\s+to|visit(?:ing)?)\b/i',
            $q
        );
    }

    /**
     * Destination-only / city + round-trip flight asks (no package verbs).
     * Example: "i need dubai round trip departure date 19 aug and return on 21 aug"
     */
    private function isFlightOnlyDestinationAsk(string $query): bool
    {
        if ($this->isBroadTripRequest($query)) {
            return false;
        }
        if (preg_match(
            '/\b(hotel|hotels|stay|stays|esim|e-?sim|tour|tours|car|cars|visa|bus|train|rail|ferry)\b/i',
            $query
        )) {
            return false;
        }
        return (bool) preg_match(
            '/\b(round[\s-]?trip|one[\s-]?way|return\s+(?:on|flight|ticket|date)|'
            . 'departure\s+date|return\s+date|airfare|flight|flights|fly)\b/i',
            $query
        ) || (
            $this->queryMentionsDate($query)
            && $this->extractFlightDestinationOnlyHint($query) !== ''
        );
    }

    /** City before "round trip" / after "need|to" for destination-only flight prompts. */
    private function extractFlightDestinationOnlyHint(string $query): string
    {
        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,1})';
        if (preg_match(
            '/\b(?:need|want|book|find|search|look(?:ing)?\s+for)\s+(?:a\s+|an\s+|the\s+)?'
            . $cityTok . '\s+(?:round[\s-]?trip|one[\s-]?way|flights?|fly|airfare)\b/i',
            $query,
            $m
        )) {
            $place = $this->sanitizePlaceName($this->stripDateWordsFromPlace($m[1]));
            if ($place !== '' && !preg_match(
                '/^(cheap|cheapest|best|good|a|an|the|me|us)$/i',
                $place
            )) {
                return $place;
            }
        }
        if (preg_match(
            '/\b' . $cityTok . '\s+(?:round[\s-]?trip|one[\s-]?way)\b/i',
            $query,
            $m
        )) {
            $place = $this->sanitizePlaceName($this->stripDateWordsFromPlace($m[1]));
            if ($place !== '' && !preg_match('/^(need|want|book|find|i|we|a|an|the)$/i', $place)) {
                return $place;
            }
        }
        if (preg_match(
            '/\b' . $cityTok . '\s+(?:flights?|fly|airfare)\b/i',
            $query,
            $m
        )) {
            $place = $this->sanitizePlaceName($this->stripDateWordsFromPlace($m[1]));
            if ($place !== '' && !preg_match(
                '/^(need|want|book|find|search|look|looking|cheap|cheapest|best|good|my|our|the|a|an|me|for)$/i',
                $place
            )) {
                return $place;
            }
        }
        if (preg_match(
            '/\b(?:flights?|fly|airfare)\s+(?:to|for|in)\s+' . $cityTok . '/i',
            $query,
            $m
        )) {
            $place = $this->sanitizePlaceName($this->stripDateWordsFromPlace($m[1]));
            if ($place !== '' && !preg_match(
                '/^(today|tomorrow|tonight|now|cheap|cheapest|best|tickets?|flights?|fly|me|you|us)$/i',
                $place
            )) {
                return $place;
            }
        }
        return '';
    }

    /** True when the user named a concrete inventory product (not just "trip"). */
    private function hasSpecificProductKeyword(string $query): bool
    {
        return (bool) preg_match(
            '/\b(flight|flights|fly|hotel|hotels|stay|stays|resort|apartment|hostel|'
            . 'tour|tours|activity|activities|excursion|car|cars|rental|visa|visas|'
            . 'umrah|hajj|esim|e-?sim|bus|buses|coach|train|trains|rail|ferry|ferries|'
            . 'cruise|cruises)\b/i',
            $query
        );
    }

    /**
     * Modules that add to a trip but do not replace flights/hotels on their own.
     *
     * @param string[] $modules
     */
    private function modulesAreOnlyTripAddons(array $modules): bool
    {
        if ($modules === []) {
            return false;
        }
        foreach ($modules as $module) {
            if (!$this->isTripAddonModule((string) $module)) {
                return false;
            }
        }
        return true;
    }

    private function isTripAddonModule(string $module): bool
    {
        return in_array(strtolower(trim($module)), ['esim', 'visa'], true);
    }

    /**
     * Broad trip prompts always include the core inventory (flights, stays, eSIM)
     * plus anything else the user named (cars, tours, visa, …).
     *
     * @param string[] $modules
     * @param string[] $enabledModules
     * @return string[]
     */
    private function expandBroadTripModules(array $modules, string $query, array $enabledModules): array
    {
        $modules = array_values(array_unique(array_filter(array_map(
            static fn ($m) => strtolower(trim((string) $m)),
            $modules
        ), static fn ($m) => $m !== '')));
        $modules = array_values(array_filter(
            $modules,
            static fn ($m) => in_array($m, $enabledModules, true)
        ));

        if (!$this->isBroadTripRequest($query)) {
            return $modules;
        }

        $modules = array_values(array_unique(array_merge(
            $this->defaultInventoryModules($enabledModules),
            $modules
        )));

        // Trip inventory first, then anything else the user named.
        $ordered = [];
        foreach (array_merge($this->defaultInventoryModules($enabledModules), $modules) as $module) {
            if (in_array($module, $modules, true) && !in_array($module, $ordered, true)) {
                $ordered[] = $module;
            }
        }
        return $ordered;
    }

    /**
     * Core modules for a broad "arrange a trip to X" prompt (when enabled).
     * Tours/cars/visa/etc. stay opt-in via explicit mention.
     *
     * @param string[] $enabledModules
     * @return string[]
     */
    private function defaultInventoryModules(array $enabledModules): array
    {
        $out = [];
        foreach (['flights', 'stays', 'esim'] as $module) {
            if (in_array($module, $enabledModules, true)) {
                $out[] = $module;
            }
        }
        return $out;
    }

    private function extractTripDestinationHint(string $query): string
    {
        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})';
        if (preg_match(
            '/\b(?:trip|vacation|holiday|getaway|itinerary|travel|visit(?:ing)?)\s+'
            . '(?:for|to|in|at|around|near)\s+' . $cityTok . '/i',
            $query,
            $m
        )) {
            // "trip for Dubai with esim" → cityTok can greedily take "Dubai with esim".
            $raw = preg_replace(
                '/\s+(?:with|and|plus|including|also|,)\b.*$/i',
                '',
                (string) $m[1]
            ) ?? (string) $m[1];
            $place = $this->sanitizePlaceName($this->stripDateWordsFromPlace($raw));
            if ($place !== '' && !preg_match('/^(for|to|in|at|a|an|the|days?)$/i', $place)) {
                return $place;
            }
        }
        if (preg_match(
            '/\b(?:for|to|in|at)\s+' . $cityTok . '\s+(?:next|this|coming|for\s+\d|tomorrow|today)/i',
            $query,
            $m
        )) {
            $place = $this->sanitizePlaceName($this->stripDateWordsFromPlace($m[1]));
            if ($place !== '') {
                return $place;
            }
        }
        return '';
    }

    /**
     * Occupancy only when the prompt states it. "childrens" / "kids" count as children.
     *
     * @return array{adults:?int,children:?int,infants:?int,rooms:?int,child_ages:list<int>}
     */
    private function occupancyFromQuery(string $query): array
    {
        $out = [
            'adults' => null,
            'children' => null,
            'infants' => null,
            'rooms' => null,
            'child_ages' => [],
        ];
        if (preg_match('/\b(\d+)\s*adults?\b/i', $query, $m)) {
            $out['adults'] = max(1, (int) $m[1]);
        } elseif (preg_match('/\b(?:for\s+|we\s+are\s+)?(\d+)\s*(?:people|persons?|pax|travellers?|travelers?)\b/i', $query, $m)) {
            $out['adults'] = max(1, (int) $m[1]);
        }
        if (preg_match('/\b(\d+)\s*(?:children?s?|kids?)\b/i', $query, $m)) {
            $out['children'] = max(0, (int) $m[1]);
        }
        if (preg_match('/\b(\d+)\s*infants?\b/i', $query, $m)) {
            $out['infants'] = max(0, (int) $m[1]);
        }
        if (preg_match('/\b(\d+)\s*rooms?\b/i', $query, $m)) {
            $out['rooms'] = max(1, min(5, (int) $m[1]));
        }
        $out['child_ages'] = $this->childAgesFromQuery($query, (int) ($out['children'] ?? 0));
        return $out;
    }

    /**
     * Ages only when the traveler typed them (e.g. "children aged 5 and 8"). Never invent.
     *
     * @return list<int>
     */
    private function childAgesFromQuery(string $query, int $children): array
    {
        if ($children < 1) {
            return [];
        }
        $ages = [];
        if (preg_match('/\b(?:aged?|ages)\s+((?:\d{1,2}\s*(?:,|and|&)\s*)*\d{1,2})\b/i', $query, $m)) {
            preg_match_all('/\d{1,2}/', $m[1], $n);
            $ages = array_map('intval', $n[0] ?? []);
        }
        if ($ages === [] && preg_match_all('/\b(\d{1,2})\s*[- ]?\s*(?:year|yr)s?\s*[- ]?\s*old\b/i', $query, $m)) {
            $ages = array_map('intval', $m[1]);
        }
        if ($ages === [] && preg_match(
            '/\b(?:children?s?|kids?)\b[^\d]{0,24}((?:\d{1,2}\s*(?:,|and|&)\s*)+\d{1,2})/i',
            $query,
            $m
        )) {
            preg_match_all('/\d{1,2}/', $m[1], $n);
            $ages = array_map('intval', $n[0] ?? []);
        }
        $ages = array_values(array_filter($ages, static fn ($a) => $a >= 1 && $a <= 17));
        return array_slice($ages, 0, $children);
    }

    /**
     * Split total guests across N rooms (same idea as the normal stays form).
     * Child ages are attached only when the prompt (or caller) supplied them.
     *
     * @param list<int> $ages
     * @return list<array{adults:int,children:int,childAges:list<int>,children_ages:list<int>}>
     */
    private function buildStayRoomsData(int $adults, int $children, int $rooms, array $ages = []): array
    {
        $adults = max(1, $adults);
        $children = max(0, $children);
        $rooms = max(1, min(5, $rooms));
        if ($rooms > $adults) {
            $rooms = $adults;
        }

        $data = [];
        for ($i = 0; $i < $rooms; $i++) {
            $data[] = [
                'adults' => 0,
                'children' => 0,
                'childAges' => [],
                'children_ages' => [],
            ];
        }
        for ($i = 0; $i < $adults; $i++) {
            $data[$i % $rooms]['adults']++;
        }
        for ($i = 0; $i < $children; $i++) {
            $idx = $i % $rooms;
            $data[$idx]['children']++;
            if (isset($ages[$i]) && $ages[$i] !== '' && $ages[$i] !== null) {
                $age = max(1, min(17, (int) $ages[$i]));
                $data[$idx]['childAges'][] = $age;
                $data[$idx]['children_ages'][] = $age;
            }
        }
        foreach ($data as &$room) {
            if ($room['adults'] < 1) {
                $room['adults'] = 1;
            }
        }
        unset($room);

        return $data;
    }

    /**
     * Compact room config for listing URLs: 2-0 or 2-1-5, rooms joined with /.
     *
     * @param array<int,array<string,mixed>> $roomsData
     */
    private function stayRoomConfigString(array $roomsData): string
    {
        $parts = [];
        foreach ($roomsData as $room) {
            $adults = max(1, (int) ($room['adults'] ?? 1));
            $children = max(0, (int) ($room['children'] ?? 0));
            $cfg = $adults . '-' . $children;
            $ages = is_array($room['childAges'] ?? null) ? $room['childAges'] : [];
            if ($children > 0 && $ages !== []) {
                $valid = [];
                foreach ($ages as $age) {
                    $n = (int) $age;
                    if ($n >= 0 && $n <= 17) {
                        $valid[] = $n;
                    }
                }
                if ($valid !== []) {
                    $cfg .= '-' . implode('-', array_slice($valid, 0, $children));
                }
            }
            $parts[] = $cfg;
        }
        return $parts !== [] ? implode('/', $parts) : '1-0';
    }

    /**
     * Guest nationality for hotel availability. Never invents US.
     *
     * @param array<string,mixed> $fields
     * @return array{iso:string,source:string}
     */
    private function resolveStayNationality(array $fields): array
    {
        $prompt = $this->normalizeStayNationalityIso((string) ($fields['stay_nationality'] ?? ''));
        $ctx = $this->normalizeStayNationalityIso((string) ($fields['stay_nationality_context'] ?? ''));
        $ctxSrc = strtolower(trim((string) ($fields['stay_nationality_context_source'] ?? '')));
        if (!in_array($ctxSrc, ['manual', 'geolocation', 'last_used', 'session', 'prompt'], true)) {
            $ctxSrc = $ctx !== '' ? 'geolocation' : '';
        }
        $session = $this->sessionStayNationality();

        if ($ctx !== '' && $ctxSrc === 'manual') {
            return ['iso' => $ctx, 'source' => 'manual'];
        }
        if ($prompt !== '') {
            return ['iso' => $prompt, 'source' => 'prompt'];
        }
        if ($ctx !== '') {
            return ['iso' => $ctx, 'source' => $ctxSrc !== '' ? $ctxSrc : 'geolocation'];
        }
        if ($session !== '') {
            return ['iso' => $session, 'source' => 'session'];
        }
        return ['iso' => '', 'source' => ''];
    }

    private function sessionStayNationality(): string
    {
        $nat = strtoupper(trim((string) ($_SESSION['hotel_nationality'] ?? '')));
        if ($nat === '' || $nat === 'NULL') {
            $detail = $_SESSION['stay_detail'] ?? null;
            if (is_array($detail)) {
                $nat = strtoupper(trim((string) ($detail['nationality'] ?? '')));
            }
        }
        if ($nat === '' || $nat === 'NULL') {
            return '';
        }
        return $this->normalizeStayNationalityIso($nat);
    }

    private function normalizeStayNationalityIso(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strcasecmp($value, 'NULL') === 0) {
            return '';
        }
        if (function_exists('countryIsoFromLabel')) {
            return countryIsoFromLabel($this->db, $value);
        }
        $iso = strtoupper($value);
        return preg_match('/^[A-Z]{2}$/', $iso) ? $iso : '';
    }

    /**
     * Tight phrases only — never infer from destination or "flight from X".
     */
    private function extractStayNationalityHint(string $query): string
    {
        $q = trim($query);
        if ($q === '') {
            return '';
        }
        $patterns = [
            '/\bi(?:\'m| am)\s+from\s+([A-Za-z][A-Za-z\s\-]{1,40})/i',
            '/\bwe(?:\'re| are)\s+from\s+([A-Za-z][A-Za-z\s\-]{1,40})/i',
            '/\bmy\s+nationality\s+is\s+([A-Za-z][A-Za-z\s\-]{1,40})/i',
            '/\b(?:guest\s+)?nationality\s*(?:is|:)?\s*([A-Za-z]{2,40})\b/i',
            '/\bi(?:\'m| am)(?:\s+a)?\s+([A-Za-z]{4,30})\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $q, $m)) {
                continue;
            }
            $tok = trim((string) preg_replace(
                '/\b(and|with|looking|searching|flying|next|this|tomorrow|today|,).*$/i',
                '',
                $m[1]
            ));
            $tok = trim($tok, " \t.,;:!?");
            if ($tok === '' || preg_match('/^(looking|searching|booking|flying|going|planning|need|want)$/i', $tok)) {
                continue;
            }
            $iso = $this->normalizeStayNationalityIso($tok);
            if ($iso !== '') {
                return $iso;
            }
            $stripped = preg_replace('/(ian|ese|ish|i)$/i', '', $tok) ?? $tok;
            if ($stripped !== '' && strcasecmp($stripped, $tok) !== 0) {
                $iso = $this->normalizeStayNationalityIso($stripped);
                if ($iso !== '') {
                    return $iso;
                }
            }
        }
        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function extractRouteFields(string $query, string $hint): array
    {
        $originCity = '';
        $destCity = $hint;
        $busOrigin = '';
        $busDest = '';
        $q = trim($query);

        // Collect city→city legs with tight city tokens (1–3 words, no filler).
        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})';
        $legs = [];
        $pushLeg = function (string $from, string $to, string $kind) use (&$legs) {
            $from = $this->sanitizePlaceName($from);
            $to = $this->sanitizePlaceName($to);
            if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) {
                return;
            }
            // Skip filler captures
            foreach ([$from, $to] as $p) {
                if (preg_match('/^(look|find|search|book|also|and|for|a|an|the|next|week|then|plus|multi|city|flight|flights|fly|on)$/i', $p)) {
                    return;
                }
            }
            $legs[] = ['from' => $from, 'to' => $to, 'kind' => $kind];
        };

        if (preg_match_all('/\bbus(?:es)?\s+(?:from\s+)?' . $cityTok . '\s+to\s+' . $cityTok . '/i', $q, $bm, PREG_SET_ORDER)) {
            foreach ($bm as $m) {
                $pushLeg($m[1], preg_replace('/\b(next|this|also|and|,|flight|on).*$/i', '', $m[2]) ?? $m[2], 'bus');
            }
        }
        if (preg_match_all('/\b(?:flight|flights|fly)\s+(?:from\s+)?' . $cityTok . '\s+to\s+' . $cityTok . '/i', $q, $fm, PREG_SET_ORDER)) {
            foreach ($fm as $m) {
                $pushLeg(
                    $m[1],
                    preg_replace('/\b(next|this|also|and|,|hotel|tour|car|on|for|\d).*$/i', '', $m[2]) ?? $m[2],
                    'flight'
                );
            }
        }
        // "Lahore to Barcelona flight" / "also Lahore to Barcelona flight"
        if (preg_match_all(
            '/\b(?:also|then|and|plus)?\s*' . $cityTok . '\s+to\s+' . $cityTok . '\s+(?:flight|flights|fly)\b/i',
            $q,
            $fm2,
            PREG_SET_ORDER
        )) {
            foreach ($fm2 as $m) {
                $pushLeg($m[1], $m[2], 'flight');
            }
        }
        // "A to B on DATE then C to D" — explicit chain legs with optional "on DATE"
        if (preg_match_all(
            '/\b' . $cityTok . '\s+to\s+' . $cityTok . '(?:\s+on\s+[^\s,]+(?:\s+[^\s,]+){0,3})?/i',
            $q,
            $cm,
            PREG_SET_ORDER
        )) {
            foreach ($cm as $m) {
                $from = $this->sanitizePlaceName(preg_replace(
                    '/\b(multi[\s-]?city|multicity|flight|flights|fly|bus|then|also|and|plus)\b/i',
                    '',
                    $m[1]
                ) ?? $m[1]);
                $to = $this->sanitizePlaceName(preg_replace(
                    '/\b(next|this|also|and|,|flight|hotel|tour|car|on|for|\d).*$/i',
                    '',
                    $m[2]
                ) ?? $m[2]);
                if ($from === '' || $to === '') {
                    continue;
                }
                $dup = false;
                foreach ($legs as $leg) {
                    if (strcasecmp($leg['from'], $from) === 0 && strcasecmp($leg['to'], $to) === 0) {
                        $dup = true;
                        break;
                    }
                }
                if (!$dup) {
                    $pushLeg($from, $to, 'generic');
                }
            }
        }
        // Generic remaining "A to B" (skip if already covered)
        if (preg_match_all('/\b' . $cityTok . '\s+to\s+' . $cityTok . '\b/i', $q, $gm, PREG_SET_ORDER)) {
            foreach ($gm as $m) {
                $from = $this->sanitizePlaceName(preg_replace(
                    '/\b(multi[\s-]?city|multicity|flight|flights|fly|bus|then|also|and|plus)\b/i',
                    '',
                    $m[1]
                ) ?? $m[1]);
                $to = $this->sanitizePlaceName(preg_replace('/\b(next|this|also|and|,|flight|hotel|tour|car|on|for|\d).*$/i', '', $m[2]) ?? $m[2]);
                $dup = false;
                foreach ($legs as $leg) {
                    if (strcasecmp($leg['from'], $from) === 0 && strcasecmp($leg['to'], $to) === 0) {
                        $dup = true;
                        break;
                    }
                }
                if (!$dup) {
                    $pushLeg($from, $to, 'generic');
                }
            }
        }

        foreach ($legs as $leg) {
            if ($leg['kind'] === 'bus' && $busOrigin === '') {
                $busOrigin = $leg['from'];
                $busDest = $leg['to'];
            }
        }

        // Multi-city air: ≥2 flight (or chained generic) legs, excluding bus-only legs.
        $airLegs = [];
        foreach ($legs as $leg) {
            if ($leg['kind'] === 'bus') {
                continue;
            }
            // Prefer explicit flight legs; keep generic when no flight-tagged legs exist yet
            // or when the chain continues from the previous air destination.
            if ($leg['kind'] === 'flight') {
                $airLegs[] = $leg;
            }
        }
        if (count($airLegs) < 2) {
            // Fallback: consecutive non-bus legs that form a chain A→B, B→C
            $nonBus = array_values(array_filter($legs, static fn ($l) => $l['kind'] !== 'bus'));
            $chained = [];
            foreach ($nonBus as $idx => $leg) {
                if ($idx === 0) {
                    $chained[] = $leg;
                    continue;
                }
                $prev = $chained[count($chained) - 1];
                if (strcasecmp($prev['to'], $leg['from']) === 0) {
                    $chained[] = $leg;
                } elseif ($leg['kind'] === 'flight') {
                    $chained[] = $leg;
                }
            }
            if (count($chained) >= 2) {
                $airLegs = $chained;
            }
        }

        $explicitMulticity = (bool) preg_match(
            '/\b(multi[\s-]?city|multicity|open[\s-]?jaw|multi[\s-]?leg)\b/i',
            $q
        );
        // "A to B then B to C" / "A→B→C" style without round-trip wording
        $thenChain = (bool) preg_match(
            '/\b' . $cityTok . '\s+to\s+' . $cityTok . '\b.{0,80}\b(?:then|also|and\s+then|plus)\b.{0,40}\b' . $cityTok . '\s+to\s+' . $cityTok . '\b/i',
            $q
        );

        $dates = $this->extractFlightDatesFromQuery($q);
        $defaultDate = $dates['departure_date'];
        $returnDate = $dates['return_date'];
        $tripType = $dates['trip_type'];

        $flightRoutes = [];
        if ((count($airLegs) >= 2 && ($explicitMulticity || $thenChain || count($airLegs) >= 2))
            && $tripType !== 'return'
        ) {
            // Prefer multicity over treating a second leg as return when round-trip words are absent
            $isReturnWording = (bool) preg_match(
                '/\b(round[\s-]?trip|return(?:ing)?\s+(?:on\s+|flight\s+|trip\s+)?|back\s+on\b|\brt\b)/i',
                $q
            );
            if (!$isReturnWording || $explicitMulticity || $thenChain) {
                $legDates = $this->extractMulticityLegDates($q, count($airLegs), $defaultDate);
                foreach ($airLegs as $i => $leg) {
                    if (count($flightRoutes) >= 6) {
                        break;
                    }
                    $flightRoutes[] = [
                        'origin_city' => $leg['from'],
                        'origin_code' => '',
                        'destination_city' => $leg['to'],
                        'destination_code' => '',
                        'date' => $legDates[$i] ?? $defaultDate,
                    ];
                }
                if (count($flightRoutes) >= 2) {
                    $tripType = 'multicity';
                    $returnDate = '';
                    $originCity = $flightRoutes[0]['origin_city'];
                    $destCity = $flightRoutes[count($flightRoutes) - 1]['destination_city'];
                    $defaultDate = $flightRoutes[0]['date'];
                }
            }
        }

        // Flight / main trip: prefer an explicit flight leg; else last non-bus leg
        $flightLeg = null;
        if ($tripType !== 'multicity') {
            foreach ($legs as $leg) {
                if ($leg['kind'] === 'flight') {
                    $flightLeg = $leg;
                    break;
                }
            }
            if ($flightLeg === null) {
                for ($i = count($legs) - 1; $i >= 0; $i--) {
                    if ($legs[$i]['kind'] !== 'bus') {
                        $flightLeg = $legs[$i];
                        break;
                    }
                }
            }
            if ($flightLeg === null && count($legs) === 1) {
                $flightLeg = $legs[0];
                if ($legs[0]['kind'] === 'bus' && $busOrigin === '') {
                    $busOrigin = $legs[0]['from'];
                    $busDest = $legs[0]['to'];
                }
            }
            if ($flightLeg !== null) {
                $originCity = $flightLeg['from'];
                $destCity = $flightLeg['to'];
            } elseif (preg_match('/\bfrom\s+' . $cityTok . '\s+to\s+' . $cityTok . '/i', $q, $m)) {
                $originCity = $this->sanitizePlaceName($m[1]);
                $destCity = $this->sanitizePlaceName($m[2]);
            }
        }

        // Hotel/tour/car destination phrase wins as main destination when present
        if (preg_match('/\b(?:hotel|hotels|stay|stays|tour|tours).{0,40}\bin\s+' . $cityTok . '/i', $q, $m)
            || preg_match('/\bat\s+' . $cityTok . '\s+airport\b/i', $q, $m)) {
            $place = $this->sanitizePlaceName(preg_replace('/\b(next|this|for|and|,|as|well).*$/i', '', $m[1]) ?? $m[1]);
            if ($place !== '' && $tripType !== 'multicity') {
                $destCity = $place;
            } elseif ($place !== '' && $tripType === 'multicity') {
                // Keep multicity final destination unless hotel city matches last leg
                $destCity = $place;
            }
        }

        $originCity = $this->sanitizePlaceName($originCity);
        $destCity = $this->sanitizePlaceName($destCity !== '' ? $destCity : $hint);
        $busOrigin = $this->sanitizePlaceName($busOrigin);
        $busDest = $this->sanitizePlaceName($busDest);

        // Isolated bus when multi-match failed
        if ($busOrigin === '' && preg_match('/\bbus(?:es)?\s+(?:from\s+)?' . $cityTok . '\s+to\s+' . $cityTok . '/i', $q, $bm)) {
            $busOrigin = $this->sanitizePlaceName($bm[1]);
            $busDest = $this->sanitizePlaceName(preg_replace('/\b(next|this|also|and|,|flight).*$/i', '', $bm[2]) ?? $bm[2]);
        }

        $occ = $this->occupancyFromQuery($q);
        $adults = $occ['adults'] ?? 1;
        $children = $occ['children'] ?? 0;
        $infants = $occ['infants'] ?? 0;
        $rooms = $occ['rooms'] ?? 1;
        if ($rooms > $adults) {
            $rooms = $adults;
        }

        $class = 'economy';
        if (preg_match('/\b(premium[\s-]?economy|business|first|economy)\b/i', $q, $m)) {
            $class = strtolower(str_replace(' ', '-', trim($m[1])));
            if ($class === 'premiumeconomy') {
                $class = 'premium-economy';
            }
        }

        // Default 1 night when hotels are implied but length is not stated
        // Match "4 nights", "4-night", "2 days hotel", "2 day trip", "for 4 days"
        $nights = 1;
        if (preg_match('/\b(\d+)\s*-?\s*nights?\b/i', $q, $m)) {
            $nights = max(1, min(30, (int) $m[1]));
        } elseif (preg_match('/\b(?:for|stay(?:ing)?)\s+(\d+)\s*-?\s*days?\b/i', $q, $m)) {
            $nights = max(1, min(30, (int) $m[1]));
        } elseif (preg_match('/\b(\d+)\s*-?\s*days?\s+(?:hotel|hotels|stay|stays|car|cars|rental|trip|trips|holiday|holidays|vacation|getaway)\b/i', $q, $m)) {
            $nights = max(1, min(30, (int) $m[1]));
        } elseif (preg_match('/\b(\d+)\s*-?\s*days?\s+(?:in|to|at)\b/i', $q, $m)) {
            $nights = max(1, min(30, (int) $m[1]));
        } elseif (preg_match('/\b(?:hotel|hotels|stay|car|cars|rental).{0,40}?\b(\d+)\s*-?\s*days?\b/i', $q, $m)) {
            $nights = max(1, min(30, (int) $m[1]));
        }

        // Ferry extras — "with a car", "2 motorbikes", "taking my dog", "bicycle on board"
        $ferryVehicles = 0;
        $ferryVehicleType = '';
        if (preg_match('/\b(?:with|taking|bringing|plus|and)\s+(?:my|a|an|(\d+))?\s*(car|cars|van|vans|camper|motorbike|motorbikes|motorcycle|motorcycles|moped|mopeds|scooter|scooters|bicycle|bicycles|bike|bikes)\b/i', $q, $m)) {
            $ferryVehicles = max(1, min(4, (int)($m[1] ?? 1)));
            $ferryVehicleType = $this->normalizeFerryVehicleType($m[2]);
        } elseif (preg_match('/\b(\d+)\s*(car|cars|van|vans|motorbike|motorbikes|motorcycle|motorcycles|moped|mopeds|bicycle|bicycles|bike|bikes)\b/i', $q, $m)) {
            $ferryVehicles = max(1, min(4, (int)$m[1]));
            $ferryVehicleType = $this->normalizeFerryVehicleType($m[2]);
        }

        $ferryPets = 0;
        $ferryPetType = '';
        if (preg_match('/\b(?:with|taking|bringing|plus|and)?\s*(?:my|a|an|(\d+))?\s*(pet|pets|dog|dogs|cat|cats)\b/i', $q, $m)) {
            $ferryPets = max(1, min(4, (int)($m[1] ?? 1)));
            $ferryPetType = $this->normalizeFerryPetType($q);
        }

        return [
            'origin_city' => $originCity,
            'origin_code' => '',
            'destination_city' => $destCity,
            'destination_code' => '',
            'departure_date' => $defaultDate,
            'return_date' => $returnDate,
            'trip_type' => $tripType,
            'flight_routes' => $flightRoutes,
            'class' => $class,
            'adults' => $adults,
            'children' => $children,
            'infants' => $infants,
            'rooms' => $rooms,
            'child_ages' => $occ['child_ages'] ?? [],
            'checkin' => $defaultDate,
            'checkout' => $returnDate !== ''
                ? $returnDate
                : date('Y-m-d', strtotime($defaultDate . ' +' . $nights . ' days')),
            'duration_days' => $nights,
            'bus_origin_city' => $busOrigin,
            'bus_destination_city' => $busDest,
            'bus_date' => $busOrigin !== '' ? $defaultDate : '',
            'tour_destination' => $this->extractTourDestinationHint($q, $destCity, $hint),
            'esim_country' => $this->extractEsimCountryHint($q, $destCity, $hint),
            'esim_package_type' => $this->extractEsimPackageType($q),
            'stay_nationality' => $this->extractStayNationalityHint($q),
            'visa_from_country' => $this->extractVisaFromHint($q, $originCity),
            'visa_to_country' => $this->extractVisaToHint($q, $destCity, $hint),
            'visa_entry_date' => $defaultDate,
            'visa_type' => $this->extractVisaType($q),
            'visa_processing_speed' => $this->extractVisaProcessingSpeed($q),
            'visa_travelers' => $adults,
            'umrah_destination' => $this->extractUmrahDestinationHint($q, $destCity, $hint),
            'umrah_start_date' => $defaultDate,
            'umrah_duration' => '',
            'umrah_type' => '',
            'umrah_services' => '',
            'rail_origin' => $this->extractRailOriginHint($q, $originCity, $busOrigin),
            'rail_destination' => $this->extractRailDestinationHint($q, $destCity, $hint, $busDest),
            'rail_date' => $defaultDate,
            'rail_journey_type' => $this->extractRailJourneyTypeHint($q),
            'ferry_origin' => $this->extractFerryOriginHint($q, $originCity, $busOrigin),
            'ferry_destination' => $this->extractFerryDestinationHint($q, $destCity, $hint, $busDest),
            'ferry_date' => $defaultDate,
            'ferry_return_date' => $returnDate,
            'ferry_vehicles' => $ferryVehicles,
            'ferry_vehicle_type' => $ferryVehicleType,
            'ferry_pets' => $ferryPets,
            'ferry_pet_type' => $ferryPetType,
        ];
    }

    /**
     * Assign chronological ISO dates to multi-city air legs from the query.
     *
     * @return list<string> YYYY-MM-DD dates, length === $legCount
     */
    private function extractMulticityLegDates(string $query, int $legCount, string $defaultDate): array
    {
        $legCount = max(2, min(6, $legCount));
        $dates = [];
        if (preg_match_all('/\b(\d{4}-\d{2}-\d{2})\b/', $query, $m)) {
            foreach ($m[1] as $iso) {
                $ts = strtotime($iso);
                if ($ts && $ts >= strtotime('today')) {
                    $dates[] = date('Y-m-d', $ts);
                }
            }
        }
        // "on 6 Aug" / "6 August 2026" / "August 31" style near legs
        if (count($dates) < $legCount) {
            foreach ($this->parseNamedDayMonthDates($query) as $named) {
                $dates[] = $named;
                if (count($dates) >= $legCount) {
                    break;
                }
            }
        }

        $out = [];
        $prev = $defaultDate !== '' ? $defaultDate : $this->defaultTravelDateYmd();
        for ($i = 0; $i < $legCount; $i++) {
            if (isset($dates[$i]) && $dates[$i] !== '') {
                $iso = $dates[$i];
                if ($iso < $prev) {
                    $iso = date('Y-m-d', strtotime($prev . ' +3 days'));
                }
                $out[] = $iso;
                $prev = $iso;
            } else {
                $iso = $i === 0
                    ? $prev
                    : date('Y-m-d', strtotime($prev . ' +3 days'));
                $out[] = $iso;
                $prev = $iso;
            }
        }
        return $out;
    }

    /**
     * Normalize AI / fallback flight_routes into 2–6 validated leg arrays (city names + ISO dates).
     *
     * @param mixed $raw
     * @return list<array{origin_city:string,origin_code:string,destination_city:string,destination_code:string,date:string}>
     */
    private function normalizeFlightRoutes($raw, string $fallbackDate = ''): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        // Associative single route mistaken for list
        if (isset($raw['origin_city']) || isset($raw['from']) || isset($raw['from_city'])) {
            $raw = [$raw];
        }
        $out = [];
        $prevDate = $fallbackDate !== '' ? $this->normalizeYmdDate($fallbackDate) : $this->defaultTravelDateYmd();
        foreach ($raw as $row) {
            if (!is_array($row) || count($out) >= 6) {
                break;
            }
            $fromCity = $this->sanitizePlaceName((string) (
                $row['origin_city'] ?? ($row['from_city'] ?? ($row['from'] ?? ''))
            ));
            $toCity = $this->sanitizePlaceName((string) (
                $row['destination_city'] ?? ($row['to_city'] ?? ($row['to'] ?? ''))
            ));
            $fromCode = strtoupper(trim((string) ($row['origin_code'] ?? ($row['from_code'] ?? ''))));
            $toCode = strtoupper(trim((string) ($row['destination_code'] ?? ($row['to_code'] ?? ''))));
            if ($fromCode !== '' && !preg_match('/^[A-Z]{3}$/', $fromCode)) {
                $fromCode = '';
            }
            if ($toCode !== '' && !preg_match('/^[A-Z]{3}$/', $toCode)) {
                $toCode = '';
            }
            // Allow IATA-only legs
            if ($fromCity === '' && preg_match('/^[A-Z]{3}$/', $fromCode)) {
                $fromCity = $fromCode;
            }
            if ($toCity === '' && preg_match('/^[A-Z]{3}$/', $toCode)) {
                $toCity = $toCode;
            }
            if ($fromCity === '' || $toCity === '' || strcasecmp($fromCity, $toCity) === 0) {
                continue;
            }
            $date = $this->normalizeYmdDate((string) ($row['date'] ?? ($row['departure_date'] ?? '')));
            if ($date === '') {
                $date = count($out) === 0
                    ? $prevDate
                    : date('Y-m-d', strtotime($prevDate . ' +3 days'));
            }
            if ($date < $prevDate) {
                $date = date('Y-m-d', strtotime($prevDate . ' +3 days'));
            }
            $out[] = [
                'origin_city' => $fromCity,
                'origin_code' => $fromCode,
                'destination_city' => $toCity,
                'destination_code' => $toCode,
                'date' => $date,
            ];
            $prevDate = $date;
        }
        return count($out) >= 2 ? $out : [];
    }

    /**
     * Distinct active package cities from umrah.location (canonical labels).
     * @return string[]
     */
    private function umrahLocationsFromDb(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $cache = [];
        try {
            $rows = $this->db->select('umrah', ['location'], [
                'status' => 1,
            ]) ?: [];
            $seen = [];
            foreach ($rows as $row) {
                $loc = trim((string)($row['location'] ?? ''));
                if ($loc === '' || strcasecmp($loc, 'any') === 0) {
                    continue;
                }
                $key = strtolower($loc);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $cache[] = $loc;
            }
            usort($cache, static function ($a, $b) {
                return strlen($b) <=> strlen($a) ?: strcasecmp($a, $b);
            });
        } catch (\Throwable $e) {
            $cache = [];
        }
        return $cache;
    }

    /**
     * Match free text to a catalog umrah.location (DB labels only — no hardcoded cities).
     */
    private function matchUmrahLocation(string $text, array $locations): string
    {
        $text = trim($text);
        if ($text === '' || count($locations) < 1) {
            return '';
        }
        $hay = ' ' . strtolower(preg_replace('/\s+/', ' ', $text) ?? '') . ' ';

        // Exact word match against catalog labels (longest first)
        foreach ($locations as $loc) {
            $needle = strtolower(trim($loc));
            if ($needle === '') {
                continue;
            }
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $hay)) {
                return $loc;
            }
        }

        // Contained / prefix match (e.g. "Makkah, KSA")
        $plain = strtolower(trim($text));
        foreach ($locations as $loc) {
            $needle = strtolower(trim($loc));
            if ($needle === '') {
                continue;
            }
            if ($plain === $needle || str_contains($plain, $needle)) {
                return $loc;
            }
            if (strlen($plain) >= 4 && str_contains($needle, $plain)) {
                return $loc;
            }
        }

        return '';
    }

    /**
     * Resolve umrah destination from query / AI fields against umrah.location (not hardcoded cities).
     */
    private function extractUmrahDestinationHint(string $query, string $destCity, string $hint): string
    {
        $locations = $this->umrahLocationsFromDb();
        if (count($locations) < 1) {
            return '';
        }
        $candidates = array($query, $destCity, $hint);
        foreach ($candidates as $text) {
            $matched = $this->matchUmrahLocation((string) $text, $locations);
            if ($matched !== '') {
                return $matched;
            }
        }
        return '';
    }

    private function extractVisaType(string $query): string
    {
        if (preg_match('/\b(tourist|business|student|work|transit|medical|family)\s+visa\b/i', $query, $m)) {
            $matched = $this->matchVisaSetting($m[1], 'visa_type');
            if ($matched !== '') {
                return $matched;
            }
        }
        if (preg_match('/\bvisa\s+(?:type\s+)?([A-Za-z]{3,20})\b/i', $query, $m)) {
            $matched = $this->matchVisaSetting($m[1], 'visa_type');
            if ($matched !== '') {
                return $matched;
            }
        }
        $matched = $this->matchVisaSetting($query, 'visa_type');
        return $matched !== '' ? $matched : $this->defaultVisaSetting('visa_type', 'tourist');
    }

    private function extractVisaProcessingSpeed(string $query): string
    {
        $matched = $this->matchVisaSetting($query, 'processing_speed');
        return $matched !== '' ? $matched : $this->defaultVisaSetting('processing_speed', 'standard');
    }

    /**
     * Active visa_settings rows for a setting type, in admin display order.
     *
     * @return list<array{value:string,name:string,desc:string}>
     */
    private function visaSettingsFromDb(string $settingType): array
    {
        static $cache = [];
        if (isset($cache[$settingType])) {
            return $cache[$settingType];
        }
        $rows = [];
        try {
            $result = $this->db->select('visa_settings', ['value', 'name', 'description'], [
                'setting_type' => $settingType,
                'status' => 1,
                'ORDER' => ['display_order' => 'ASC'],
            ]) ?: [];
            foreach ($result as $row) {
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $rows[] = [
                    'value' => $value,
                    'name' => trim((string) ($row['name'] ?? $value)),
                    'desc' => trim((string) ($row['description'] ?? '')),
                ];
            }
        } catch (\Throwable $e) {
            $rows = [];
        }
        $cache[$settingType] = $rows;
        return $rows;
    }

    /**
     * First active catalog value (what the website form pre-selects).
     */
    private function defaultVisaSetting(string $settingType, string $fallback): string
    {
        $rows = $this->visaSettingsFromDb($settingType);
        return $rows === [] ? $fallback : $rows[0]['value'];
    }

    /**
     * Resolve free text ("express", "urgent", "Super Rush", "same day") to an active
     * visa_settings value. Returns '' when the catalog has no sensible match.
     */
    private function matchVisaSetting(string $text, string $settingType): string
    {
        $rows = $this->visaSettingsFromDb($settingType);
        if ($rows === [] || trim($text) === '') {
            return '';
        }
        $hay = $this->visaSettingKey($text);
        if ($hay === '') {
            return '';
        }

        foreach ($rows as $row) {
            if ($this->visaSettingKey($row['value']) === $hay) {
                return $row['value'];
            }
        }
        foreach ($rows as $row) {
            if ($this->visaSettingKey($row['name']) === $hay) {
                return $row['value'];
            }
        }

        // Longest label first so "super rush" never collapses into "rush".
        $byLength = $rows;
        usort($byLength, static function (array $a, array $b): int {
            return strlen($b['value'] . $b['name']) <=> strlen($a['value'] . $a['name']);
        });
        foreach ($byLength as $row) {
            foreach ([$row['value'], $row['name']] as $label) {
                $needle = $this->visaSettingKey($label);
                if ($needle !== '' && $this->visaSettingKeyContains($hay, $needle)) {
                    return $row['value'];
                }
            }
        }

        // Traveler wording that is not a catalog label — map onto the same speed tier.
        $synonymGroups = [
            ['same_day', 'sameday', 'super_rush', 'superrush', 'asap', 'immediate', 'emergency', 'overnight'],
            ['rush'],
            ['express', 'urgent', 'priority', 'expedited', 'fast', 'faster', 'fastest', 'quick', 'quickest', 'speedy'],
            ['standard', 'normal', 'regular', 'economy', 'basic', 'cheapest'],
        ];
        foreach ($synonymGroups as $group) {
            $mentioned = false;
            foreach ($group as $word) {
                if ($this->visaSettingKeyContains($hay, $word)) {
                    $mentioned = true;
                    break;
                }
            }
            if (!$mentioned) {
                continue;
            }
            foreach ($byLength as $row) {
                $valueKey = $this->visaSettingKey($row['value']);
                $nameKey = $this->visaSettingKey($row['name']);
                foreach ($group as $word) {
                    if ($this->visaSettingKeyContains($valueKey, $word) || $this->visaSettingKeyContains($nameKey, $word)) {
                        return $row['value'];
                    }
                }
            }
        }

        return '';
    }

    private function visaSettingKey(string $value): string
    {
        $key = strtolower(trim($value));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
        return trim($key, '_');
    }

    private function visaSettingKeyContains(string $haystackKey, string $needle): bool
    {
        $needle = $this->visaSettingKey($needle);
        if ($needle === '' || $haystackKey === '') {
            return false;
        }
        return (bool) preg_match('/(^|_)' . preg_quote($needle, '/') . '(_|$)/', $haystackKey);
    }

    private function extractVisaFromHint(string $query, string $originCity): string
    {
        if (preg_match(
            '/\b(?:visa\s+)?(?:from|for)\s+([A-Za-z][A-Za-z\s\-]{1,40}?)\s+(?:to|→|->)\s+/i',
            $query,
            $m
        )) {
            $tok = $this->sanitizePlaceName($m[1]);
            if ($tok !== '') {
                return $tok;
            }
        }
        if (preg_match('/\b([A-Za-z][A-Za-z\s\-]{1,30})\s+to\s+[A-Za-z].{0,40}\bvisa\b/i', $query, $m)) {
            $tok = $this->sanitizePlaceName($m[1]);
            if ($tok !== '') {
                return $tok;
            }
        }
        return $originCity;
    }

    private function extractVisaToHint(string $query, string $destCity, string $hint): string
    {
        if (preg_match(
            '/\b(?:to|for|in)\s+([A-Za-z][A-Za-z\s\-]{1,40}?)\s+(?:tourist|business|student)?\s*visa\b/i',
            $query,
            $m
        )) {
            $tok = $this->sanitizePlaceName($m[1]);
            if ($tok !== '' && !preg_match('/^(from|to|for|in|a|an|the)$/i', $tok)) {
                return $tok;
            }
        }
        if (preg_match(
            '/\bvisa\s+(?:for|to|in)\s+([A-Za-z][A-Za-z\s\-]{1,40})/i',
            $query,
            $m
        )) {
            $tok = $this->sanitizePlaceName(preg_replace(
                '/\b(next|this|also|and|,|flight|hotel|tourist|business|application|entry).*$/i',
                '',
                $m[1]
            ) ?? $m[1]);
            if ($tok !== '') {
                return $tok;
            }
        }
        return $destCity !== '' ? $destCity : $hint;
    }

    private function extractEsimPackageType(string $query): string
    {
        if (preg_match('/\b(global)\s+(?:esim|e-?sim|packages?|data)\b|\b(?:esim|e-?sim).{0,20}\bglobal\b/i', $query)) {
            return 'global';
        }
        if (preg_match('/\b(local)\s+(?:esim|e-?sim|packages?|data)\b|\b(?:esim|e-?sim).{0,20}\blocal\b/i', $query)) {
            return 'local';
        }
        return 'all';
    }

    /**
     * Best-effort country token for eSIM (ISO or name) from query / trip destination.
     */
    private function extractEsimCountryHint(string $query, string $destCity, string $hint): string
    {
        $trigger = '(?:esim|e-?\s?sim|data\s*sim|sim\s*card|travel\s*sim|tourist\s*sim|data\s*(?:plan|package)|mobile\s*data|roaming)';

        if (preg_match(
            '/\b' . $trigger . '\s+(?:for|in|to|at)?\s*([A-Za-z][A-Za-z\s\-]{1,40})/i',
            $query,
            $m
        )) {
            $tok = $this->esimPlaceToken(
                preg_replace('/\b(next|this|also|and|,|flight|hotel|tour|car|package).*$/i', '', $m[1]) ?? $m[1]
            );
            if ($tok !== '') {
                return $tok;
            }
        }
        if (preg_match('/\b([A-Za-z][A-Za-z\s\-]{1,30})\s+' . $trigger . '\b/i', $query, $m)) {
            $tok = $this->esimPlaceToken($m[1]);
            if ($tok !== '') {
                return $tok;
            }
        }
        // Destination city from a wider trip is a valid country hint; never fall back to
        // leftover filler from the query (e.g. "i need an" from "i need an esim").
        $fromDest = $this->esimPlaceToken($destCity);
        if ($fromDest !== '') {
            return $fromDest;
        }
        return $this->esimPlaceToken($hint);
    }

    /**
     * City for tours only — "tours in Dubai" must not become the flight destination
     * when the same prompt also has "flight from Dubai to Lahore".
     */
    private function extractTourDestinationHint(string $query, string $destCity, string $hint): string
    {
        $tripDest = $this->extractTripDestinationHint($query);
        if ($tripDest !== '') {
            return $tripDest;
        }

        $cityTok = '([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})';
        if (preg_match(
            '/\b(?:tours?|activities|excursions?)\s+(?:in|at|around|near|for)\s+' . $cityTok . '/i',
            $query,
            $m
        )) {
            $place = $this->stripDateWordsFromPlace($m[1]);
            $place = $this->sanitizePlaceName($place);
            if ($place !== '') {
                return $place;
            }
        }
        if (preg_match(
            '/\b' . $cityTok . '\s+(?:tours?|activities|excursions?)\b/i',
            $query,
            $m
        )) {
            $place = $this->stripDateWordsFromPlace($m[1]);
            $place = $this->sanitizePlaceName($place);
            if ($place !== '' && !preg_match('/^(look|find|search|book|also|and)$/i', $place)) {
                return $place;
            }
        }

        // Tours-only prompts: use destination/hint after stripping date filler.
        $fallback = $this->stripDateWordsFromPlace($destCity !== '' ? $destCity : $hint);
        $fallback = $this->sanitizePlaceName($fallback);
        if ($fallback !== '' && preg_match('/\b(arrange|plan|look|find|search|book|trip|days?)\b/i', $fallback)) {
            return '';
        }
        return $fallback;
    }

    private function stripDateWordsFromPlace(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $clean = preg_replace(
            '/\b(?:coming|next|this|on|for|in|at|around|near|'
            . 'today|tomorrow|tonight|week|weekend|month|year|'
            . 'monday|tuesday|wednesday|thursday|friday|saturday|sunday|'
            . 'mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun|'
            . 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|'
            . 'jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\b/i',
            ' ',
            $raw
        ) ?? $raw;
        return trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    }

    /**
     * Clean a captured eSIM place token — drops filler wording so "i need esim" never
     * becomes a country candidate.
     */
    private function esimPlaceToken(string $raw): string
    {
        $filler = '(?:i|we|you|me|my|our|a|an|the|some|please|need|needs|want|wants|require|looking|look|find|search|book|buy|get|order|good|best|cheap|new)';
        $clean = preg_replace('/^(?:\s*' . $filler . '\b)+/i', '', trim($raw)) ?? $raw;
        $clean = preg_replace('/(?:\b' . $filler . '\b\s*)+$/i', '', $clean) ?? $clean;

        return $this->sanitizePlaceName(trim($clean));
    }

    /**
     * Parse calendar dates written as day + month name (or month + day).
     * Examples: "31 august", "31st Aug 2026", "August 31", "on 6 Sep".
     * Year omitted → current year, or next year if that date already passed.
     *
     * @return list<string> YYYY-MM-DD dates in query order
     */
    private function parseNamedDayMonthDates(string $query): array
    {
        $monthMap = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];
        $monthTok = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|'
            . 'aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
        $hits = [];
        // "31 august" / "31st Aug 2026"
        if (preg_match_all(
            '/\b(?:on\s+)?(\d{1,2})(?:st|nd|rd|th)?\s+(' . $monthTok . ')(?:\s+(\d{4}))?\b/i',
            $query,
            $dm,
            PREG_SET_ORDER
        )) {
            $hits = array_merge($hits, $dm);
        }
        // "august 31" / "Aug 31st 2026"
        if (preg_match_all(
            '/\b(?:on\s+)?(' . $monthTok . ')\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s+(\d{4}))?\b/i',
            $query,
            $md,
            PREG_SET_ORDER
        )) {
            foreach ($md as $hit) {
                // Normalize to [full, day, month, year?] like the day-first matches
                $hits[] = [$hit[0], $hit[2], $hit[1], $hit[3] ?? ''];
            }
        }

        $dates = [];
        $yearNow = (int) date('Y');
        $todayTs = strtotime('today');
        foreach ($hits as $hit) {
            $day = (int) ($hit[1] ?? 0);
            $monKey = strtolower(substr((string) ($hit[2] ?? ''), 0, 3));
            $mon = $monthMap[$monKey] ?? 0;
            if ($day < 1 || $day > 31 || $mon < 1) {
                continue;
            }
            $y = isset($hit[3]) && $hit[3] !== '' ? (int) $hit[3] : $yearNow;
            if (!checkdate($mon, $day, $y)) {
                continue;
            }
            $ts = strtotime(sprintf('%04d-%02d-%02d', $y, $mon, $day));
            if ($ts === false) {
                continue;
            }
            // No explicit year and date already passed → roll to next year
            if ($ts < $todayTs && (!isset($hit[3]) || $hit[3] === '')) {
                $nextY = $y + 1;
                if (!checkdate($mon, $day, $nextY)) {
                    continue;
                }
                $ts = strtotime(sprintf('%04d-%02d-%02d', $nextY, $mon, $day));
                if ($ts === false) {
                    continue;
                }
            }
            if ($ts >= $todayTs) {
                $dates[] = date('Y-m-d', $ts);
            }
        }
        return array_values(array_unique($dates));
    }

    /**
     * Resolve departure/return dates + trip_type from natural language (aligned with website search).
     *
     * @return array{departure_date:string,return_date:string,trip_type:string}
     */
    private function extractFlightDatesFromQuery(string $query): array
    {
        $q = trim($query);
        $isReturn = (bool) preg_match(
            '/\b(round[\s-]?trip|return(?:ing)?\s+(?:on\s+|flight\s+|trip\s+)?|back\s+on\b|\brt\b)/i',
            $q
        );

        $departure = $this->defaultTravelDateYmd();
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $q, $m)) {
            $ts = strtotime($m[1]);
            if ($ts && $ts >= strtotime('today')) {
                $departure = date('Y-m-d', $ts);
                // "on DATE … flight next day" → air travel is day after the stated date
                if (preg_match('/\b(?:flight|flights|fly).{0,40}\bnext\s+day\b|\bnext\s+day\b.{0,40}\b(?:flight|flights|fly)\b/i', $q)) {
                    $departure = date('Y-m-d', strtotime($departure . ' +1 day'));
                }
            }
        } elseif (($namedDates = $this->parseNamedDayMonthDates($q)) !== []) {
            $departure = $namedDates[0];
            if (preg_match('/\b(?:flight|flights|fly).{0,40}\bnext\s+day\b|\bnext\s+day\b.{0,40}\b(?:flight|flights|fly)\b/i', $q)) {
                $departure = date('Y-m-d', strtotime($departure . ' +1 day'));
            }
        } elseif (preg_match('/\btomorrow\b/i', $q)) {
            $departure = date('Y-m-d', strtotime('+1 day'));
        } elseif (preg_match('/\btoday\b/i', $q)) {
            $departure = date('Y-m-d');
        } elseif (preg_match('/\bnext\s+week\b/i', $q)) {
            $departure = date('Y-m-d', strtotime('+7 days'));
        } elseif (preg_match('/\bnext\s+month\b/i', $q)) {
            $departure = date('Y-m-d', strtotime('+1 month'));
        } elseif (preg_match('/\bnext\s+year\b/i', $q)) {
            $departure = date('Y-m-d', strtotime('+1 year'));
        } elseif (preg_match('/\b(?:next|this|coming)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $q, $m)) {
            $preferThis = stripos($m[0], 'this') === 0;
            $departure = $this->resolveWeekdayDate($m[1], $preferThis);
        } elseif (preg_match('/\bon\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $q, $m)) {
            $departure = $this->resolveWeekdayDate($m[1], false);
        }

        $returnDate = '';
        if (preg_match('/\breturn(?:ing)?\s+(?:on\s+)?(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $q, $m)) {
            $isReturn = true;
            $returnDate = $this->resolveWeekdayDateAfter($m[1], $departure);
        } elseif (preg_match('/\breturn(?:ing)?\s+(?:on\s+)?(\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/i', $q, $m)) {
            $isReturn = true;
            $ts = strtotime(str_replace('/', '-', $m[1]));
            if ($ts) {
                $returnDate = date('Y-m-d', $ts);
            }
        } elseif (preg_match(
            '/\breturn(?:ing)?\s+(?:on\s+)?(.+)$/i',
            $q,
            $rm
        )) {
            $namedReturn = $this->parseNamedDayMonthDates($rm[1]);
            if ($namedReturn !== []) {
                $isReturn = true;
                $returnDate = $namedReturn[0];
            }
        }
        if ($returnDate === '' && $isReturn) {
            // Round-trip mentioned but no explicit return day → +3 days after departure
            $returnDate = date('Y-m-d', strtotime($departure . ' +3 days'));
        }

        if ($returnDate !== '' && $returnDate < $departure) {
            $returnDate = $this->resolveWeekdayDateAfter(
                date('l', strtotime($returnDate)),
                $departure
            );
        }

        return [
            'departure_date' => $departure,
            'return_date' => $isReturn ? $returnDate : '',
            'trip_type' => ($isReturn && $returnDate !== '') ? 'return' : 'oneway',
        ];
    }

    /** Next occurrence of weekday (PHP "next monday" style — not today if today matches). */
    private function resolveWeekdayDate(string $weekday, bool $preferThisWeek): string
    {
        $weekday = strtolower(trim($weekday));
        $today = new \DateTimeImmutable('today');
        if ($preferThisWeek && strtolower($today->format('l')) === $weekday) {
            return $today->format('Y-m-d');
        }
        $ts = strtotime('next ' . $weekday);
        if ($ts === false) {
            $ts = strtotime('+7 days');
        }
        // If "this Friday" and Friday is still ahead this week, prefer that
        if ($preferThisWeek) {
            for ($i = 0; $i < 7; $i++) {
                $d = $today->modify('+' . $i . ' days');
                if (strtolower($d->format('l')) === $weekday) {
                    return $d->format('Y-m-d');
                }
            }
        }
        return date('Y-m-d', $ts);
    }

    /** First weekday on/after $afterIso (YYYY-MM-DD). */
    private function resolveWeekdayDateAfter(string $weekday, string $afterIso): string
    {
        $weekday = strtolower(trim($weekday));
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $afterIso) ?: new \DateTimeImmutable('today');
        for ($i = 0; $i < 14; $i++) {
            $d = $start->modify('+' . $i . ' days');
            if (strtolower($d->format('l')) === $weekday) {
                // Returning same day as departure is rare; prefer later same weekday next week
                if ($i === 0) {
                    continue;
                }
                return $d->format('Y-m-d');
            }
        }
        return $start->modify('+3 days')->format('Y-m-d');
    }

    /**
     * @param array<string,mixed> $fields
     * @param array{city?:string,country?:string,airport?:string,source?:string} $departureContext
     * @return array<string,mixed>
     */
    private function sanitizeFields(array $fields, string $query, string $hint, array $departureContext = []): array
    {
        $fallback = $this->extractRouteFields($query, $hint);

        $originCity = $this->sanitizePlaceName((string) ($fields['origin_city'] ?? ''));
        $destCity = $this->sanitizePlaceName((string) ($fields['destination_city'] ?? ''));
        $originCode = strtoupper(trim((string) ($fields['origin_code'] ?? '')));
        $destCode = strtoupper(trim((string) ($fields['destination_code'] ?? '')));

        // Strict: discard LLM-invented origins that are not present in the traveler's text.
        // Otherwise "flights to Dubai" can invent ADV/LHR/etc. and skip real departure context (e.g. LHE).
        if ($originCity !== '' && !$this->queryMentionsPlace($query, $originCity)) {
            $originCity = '';
        }
        if ($originCode !== '' && preg_match('/^[A-Z]{3}$/', $originCode)
            && !$this->queryMentionsIata($query, $originCode)
            && ($originCity === '' || !$this->queryMentionsPlace($query, $originCity))
        ) {
            $originCode = '';
        }

        // Traveler-named origin (grounded AI fields or heuristic from their text) always wins.
        $explicitOriginFromText = $originCity !== ''
            || ($originCode !== '' && preg_match('/^[A-Z]{3}$/', $originCode));
        $departureSource = $explicitOriginFromText ? 'explicit' : '';

        if ($originCity === '') {
            $originCity = $this->sanitizePlaceName((string) ($fallback['origin_city'] ?? ''));
            if ($originCity !== '') {
                $explicitOriginFromText = true;
                $departureSource = 'explicit';
            }
        }
        if ($destCity === '') {
            $destCity = $fallback['destination_city'] !== '' ? $fallback['destination_city'] : $hint;
        }

        // Multi-leg guard: if AI reused the bus A→B cities as the main trip, but the
        // query also has a later flight/hotel destination, prefer keyword fallback.
        $fbBusFrom = $this->sanitizePlaceName((string) ($fallback['bus_origin_city'] ?? ''));
        $fbBusTo = $this->sanitizePlaceName((string) ($fallback['bus_destination_city'] ?? ''));
        $fbOrigin = $this->sanitizePlaceName((string) ($fallback['origin_city'] ?? ''));
        $fbDest = $this->sanitizePlaceName((string) ($fallback['destination_city'] ?? ''));
        $aiLooksLikeBusLeg = $fbBusFrom !== '' && $fbBusTo !== ''
            && strcasecmp($originCity, $fbBusFrom) === 0
            && strcasecmp($destCity, $fbBusTo) === 0;
        $fallbackHasFurtherTrip = $fbDest !== '' && strcasecmp($fbDest, $fbBusTo) !== 0;
        if ($aiLooksLikeBusLeg && $fallbackHasFurtherTrip) {
            if ($fbOrigin !== '') {
                $originCity = $fbOrigin;
            }
            $destCity = $fbDest;
            if ($originCode === '' || !preg_match('/^[A-Z]{3}$/', $originCode)) {
                $originCode = '';
            }
            if ($destCode === '' || !preg_match('/^[A-Z]{3}$/', $destCode)) {
                $destCode = '';
            }
        }

        if ($originCode === '' || !preg_match('/^[A-Z]{3}$/', $originCode)) {
            $originCode = (string) ($fallback['origin_code'] ?? '');
        }
        if ($destCode === '' || !preg_match('/^[A-Z]{3}$/', $destCode)) {
            $destCode = (string) ($fallback['destination_code'] ?? '');
        }

        $departure = (string) ($fields['departure_date'] ?? '');
        $relativeTravelPhrase = (bool) preg_match(
            '/\b(tomorrow|today|next\s+week|next\s+month|next\s+year|(?:next|this|coming)\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b/i',
            $query
        );
        $namedCalendarDates = $this->parseNamedDayMonthDates($query);
        $fixedDateInQuery = (bool) preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $query)
            || $namedCalendarDates !== [];
        $dateMentioned = $this->queryMentionsDate($query);
        // Undated prompt: ignore AI date guesses and use the next calendar day.
        if (!$dateMentioned) {
            $departure = (string) ($fallback['departure_date'] ?? $this->defaultTravelDateYmd());
        } elseif ($departure === '' || $relativeTravelPhrase || $fixedDateInQuery) {
            if ($relativeTravelPhrase || $fixedDateInQuery) {
                $departure = (string) $fallback['departure_date'];
            } elseif ($departure === '') {
                $departure = (string) $fallback['departure_date'];
            }
        }
        if ($departure === '') {
            $departure = $this->defaultTravelDateYmd();
        }

        $tripType = strtolower(trim((string) ($fields['trip_type'] ?? '')));
        $returnDate = trim((string) ($fields['return_date'] ?? ''));

        // Multi-city air routes from AI fields or keyword fallback (2–6 legs).
        $flightRoutes = $this->normalizeFlightRoutes(
            $fields['flight_routes'] ?? ($fields['routes'] ?? []),
            (string) ($fields['departure_date'] ?? ($fallback['departure_date'] ?? ''))
        );
        if ($flightRoutes === [] && !empty($fallback['flight_routes'])) {
            $flightRoutes = $this->normalizeFlightRoutes(
                $fallback['flight_routes'],
                (string) ($fallback['departure_date'] ?? '')
            );
        }
        $explicitMulticityQuery = (bool) preg_match(
            '/\b(multi[\s-]?city|multicity|open[\s-]?jaw|multi[\s-]?leg)\b/i',
            $query
        );
        if (($fallback['trip_type'] ?? '') === 'multicity' || $explicitMulticityQuery) {
            $tripType = 'multicity';
        }
        if ($tripType === 'multicity' || ($flightRoutes !== [] && !in_array($tripType, ['return', 'roundtrip'], true))) {
            if ($flightRoutes !== []) {
                $tripType = 'multicity';
                $returnDate = '';
                $first = $flightRoutes[0];
                $last = $flightRoutes[count($flightRoutes) - 1];
                if ($originCity === '' || $explicitMulticityQuery || ($fallback['trip_type'] ?? '') === 'multicity') {
                    $originCity = $first['origin_city'] !== '' ? $first['origin_city'] : $originCity;
                }
                if ($destCity === '' || $explicitMulticityQuery || ($fallback['trip_type'] ?? '') === 'multicity') {
                    $destCity = $last['destination_city'] !== '' ? $last['destination_city'] : $destCity;
                }
                if ($first['origin_code'] !== '' && ($originCode === '' || $explicitMulticityQuery)) {
                    $originCode = $first['origin_code'];
                }
                if ($last['destination_code'] !== '' && ($destCode === '' || $explicitMulticityQuery)) {
                    $destCode = $last['destination_code'];
                }
                $departure = $first['date'] !== '' ? $first['date'] : $departure;
            }
        }

        // Detected / manual / last-used departure fills ONLY when the traveler did not name one.
        // Never overrides an explicit origin from AI fields or prompt heuristics.
        if (!$explicitOriginFromText && $originCity === '' && ($originCode === '' || !preg_match('/^[A-Z]{3}$/', $originCode))) {
            $ctxCity = $this->sanitizePlaceName((string) ($departureContext['city'] ?? ''));
            $ctxAirport = strtoupper(trim((string) ($departureContext['airport'] ?? '')));
            $ctxSource = strtolower(trim((string) ($departureContext['source'] ?? 'geolocation')));
            if (!in_array($ctxSource, ['geolocation', 'manual', 'last_used', 'session'], true)) {
                $ctxSource = 'geolocation';
            }
            // Geolocation must include a real IATA — bare city from a weak match is not enough
            // to invent a departure (was causing random codes like ADV).
            $geoOk = $ctxSource !== 'geolocation'
                || ($ctxAirport !== '' && preg_match('/^[A-Z]{3}$/', $ctxAirport));
            if ($geoOk) {
                if ($ctxCity !== '') {
                    $originCity = $ctxCity;
                    $departureSource = $ctxSource;
                }
                if ($ctxAirport !== '' && preg_match('/^[A-Z]{3}$/', $ctxAirport)) {
                    $originCode = $ctxAirport;
                    if ($departureSource === '') {
                        $departureSource = $ctxSource;
                    }
                    // Prefer airport city label when context only sent the IATA as "city"
                    if ($originCity === '' || strcasecmp($originCity, $ctxAirport) === 0) {
                        $resolvedCity = $this->airportCityLabel($ctxAirport);
                        if ($resolvedCity !== '') {
                            $originCity = $resolvedCity;
                        }
                    }
                }
            }
        }
        if ($departureSource === '' && $originCity !== '') {
            $departureSource = 'explicit';
        }

        // Query wins for round-trip intent (AI often defaults to oneway) — but not over multicity
        if ($tripType !== 'multicity' && ($fallback['trip_type'] ?? '') === 'return') {
            $tripType = 'return';
            if ($returnDate === '' && !empty($fallback['return_date'])) {
                $returnDate = (string) $fallback['return_date'];
            }
        }
        if ($tripType !== 'multicity' && $returnDate !== '' && !in_array($tripType, ['return', 'roundtrip'], true)) {
            $tripType = 'return';
        }
        if ($tripType === 'roundtrip') {
            $tripType = 'return';
        }
        if ($tripType === 'return' && $returnDate === '' && !empty($fallback['return_date'])) {
            $returnDate = (string) $fallback['return_date'];
        }
        if ($tripType === 'multicity') {
            $returnDate = '';
            if ($flightRoutes === [] && !empty($fallback['flight_routes'])) {
                $flightRoutes = $this->normalizeFlightRoutes($fallback['flight_routes'], $departure);
            }
        } elseif ($tripType !== 'return') {
            $tripType = 'oneway';
            $returnDate = '';
            $flightRoutes = [];
        } else {
            $flightRoutes = [];
        }

        // Passenger / room counts are deterministic: only accept values explicitly
        // stated in the prompt. Otherwise ignore model guesses and use 1/0/0 / 1 room.
        $occ = $this->occupancyFromQuery($query);
        $adults = $occ['adults'] !== null ? $occ['adults'] : max(1, (int) ($fallback['adults'] ?? 1));
        $children = $occ['children'] !== null ? $occ['children'] : max(0, (int) ($fallback['children'] ?? 0));
        $infants = $occ['infants'] !== null ? $occ['infants'] : max(0, (int) ($fallback['infants'] ?? 0));
        $rooms = $occ['rooms'] !== null ? $occ['rooms'] : 1;
        if ($rooms > $adults) {
            $rooms = max(1, $adults);
        }
        $childAges = $occ['child_ages'] ?? [];
        if ($childAges === [] && !empty($fallback['child_ages']) && is_array($fallback['child_ages'])) {
            $childAges = $fallback['child_ages'];
        }
        $class = strtolower(trim((string) ($fields['class'] ?? $fallback['class'] ?? 'economy'))) ?: 'economy';
        if (preg_match('/\b(premium[\s-]?economy|business|first|economy)\b/i', $query) && !empty($fallback['class'])) {
            $class = (string) $fallback['class'];
        }

        // Hotel dates: start from flight departure for initial search; UI realigns
        // check-in to the selected flight's ARRIVAL day (overnight flights).
        $checkin = (string) ($fields['checkin'] ?? '');
        // Do NOT treat bare "next" (as in "next day") as a travel-window override.
        if (!$dateMentioned) {
            $checkin = $departure;
        } elseif ($checkin === '' || $relativeTravelPhrase || $fixedDateInQuery) {
            $checkin = (string) ($fallback['checkin'] ?? $departure);
        }
        if ($checkin === '') {
            $checkin = $departure;
        }
        // If prompt says hotel on the flight / "next day" after a fixed bus/flight date,
        // keep checkin aligned to departure (AI often drifts hotel to unrelated days).
        if ($dateMentioned && ($fixedDateInQuery || $relativeTravelPhrase)) {
            $checkin = $departure;
        }
        $nights = max(1, min(30, (int) ($fallback['duration_days'] ?? 1)));
        // Prefer AI/heuristic checkout when present — do not drop explicit check-out dates.
        $checkout = (string) ($fields['checkout'] ?? '');
        if ($checkout === '') {
            $checkout = (string) ($fallback['checkout'] ?? '');
        }
        $checkinIso = $this->toIsoDate($checkin);
        $checkoutIso = $this->toIsoDate($checkout);

        // Explicit check-in + check-out always win (hotel nights = date diff, e.g. 16→18 Aug = 2).
        // "3 day trip" wording must not overwrite a concrete check-out or inflate nights.
        if ($checkinIso !== '' && $checkoutIso !== '' && $checkoutIso > $checkinIso) {
            $nights = max(1, min(30, (int) ((strtotime($checkoutIso) - strtotime($checkinIso)) / 86400)));
            $checkout = $checkoutIso;
        } else {
            // Explicit "4-night hotel" / "2 days hotel" / "2 day trip" / "for 4 days" → N hotel nights
            $explicitNights = (bool) preg_match('/\b(\d+)\s*-?\s*nights?\b/i', $query, $nightMatch)
                || (bool) preg_match('/\b(?:for|stay(?:ing)?)\s+(\d+)\s*-?\s*days?\b/i', $query, $dayMatch)
                || (bool) preg_match('/\b(\d+)\s*-?\s*days?\s+(?:hotel|hotels|stay|stays|car|cars|rental|trip|trips|holiday|holidays|vacation|getaway)\b/i', $query, $dayMatch2)
                || (bool) preg_match('/\b(\d+)\s*-?\s*days?\s+(?:in|to|at)\b/i', $query, $dayMatchTrip)
                || (bool) preg_match('/\b(?:hotel|hotels|stay|car|cars|rental).{0,40}?\b(\d+)\s*-?\s*days?\b/i', $query, $dayMatch3);
            if ($explicitNights) {
                if (!empty($nightMatch[1])) {
                    $nights = max(1, min(30, (int) $nightMatch[1]));
                } elseif (!empty($dayMatch[1])) {
                    $nights = max(1, min(30, (int) $dayMatch[1]));
                } elseif (!empty($dayMatch2[1])) {
                    $nights = max(1, min(30, (int) $dayMatch2[1]));
                } elseif (!empty($dayMatchTrip[1])) {
                    $nights = max(1, min(30, (int) $dayMatchTrip[1]));
                } elseif (!empty($dayMatch3[1])) {
                    $nights = max(1, min(30, (int) $dayMatch3[1]));
                } else {
                    $nights = max(1, min(30, (int) ($fallback['duration_days'] ?? $nights)));
                }
                if ($checkinIso !== '') {
                    $checkout = date('Y-m-d', strtotime($checkinIso . ' +' . $nights . ' days'));
                } else {
                    $checkout = date('Y-m-d', strtotime($checkin . ' +' . $nights . ' days'));
                }
            } elseif ($returnDate !== '') {
                $checkout = $returnDate;
                $checkoutIso = $this->toIsoDate($checkout);
                if ($checkinIso !== '' && $checkoutIso !== '' && $checkoutIso > $checkinIso) {
                    $nights = max(1, min(30, (int) ((strtotime($checkoutIso) - strtotime($checkinIso)) / 86400)));
                }
            } else {
                // No stay length in prompt — 1 night (not AI's common 3-night default)
                $nights = 1;
                $checkout = date('Y-m-d', strtotime(($checkinIso !== '' ? $checkinIso : $checkin) . ' +' . $nights . ' days'));
            }
        }

        // Relative weekday phrases in query should win over AI date guesses
        if (preg_match('/\b(coming|next|this)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $query)) {
            $departure = (string) $fallback['departure_date'];
            $checkin = (string) ($fallback['checkin'] ?? $departure);
            $checkinIso = $this->toIsoDate($checkin);
            // Keep an explicit check-out span when we already resolved one above
            $checkoutIso = $this->toIsoDate($checkout);
            if ($returnDate === '' && !($checkinIso !== '' && $checkoutIso !== '' && $checkoutIso > $checkinIso)) {
                $checkout = date('Y-m-d', strtotime(($checkinIso !== '' ? $checkinIso : $checkin) . ' +' . $nights . ' days'));
            } elseif ($checkinIso !== '' && $checkoutIso !== '' && $checkoutIso > $checkinIso) {
                $nights = max(1, min(30, (int) ((strtotime($checkoutIso) - strtotime($checkinIso)) / 86400)));
            }
        }

        // Bus date: same relative-date override as flight (AI often copies the prompt
        // example "tomorrow" into bus_date while departure_date correctly becomes next week).
        $busDate = trim((string) ($fields['bus_date'] ?? ''));
        if (!$dateMentioned) {
            $busDate = $departure;
        } elseif ($busDate === '' || $relativeTravelPhrase || $fixedDateInQuery) {
            $busDate = (string) ($fallback['bus_date'] ?? '');
        }
        if ($busDate === '') {
            $busDate = $departure;
        }
        // "bus on DATE … flight next day" → keep bus on the fixed date, flight already
        // advanced separately by AI when it understands "next day".
        if ($dateMentioned && $fixedDateInQuery && preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $query, $fd)) {
            $busDate = $fd[1];
        }
        $busReturnDate = trim((string) ($fields['bus_return_date'] ?? ($fallback['bus_return_date'] ?? '')));

        return array_merge($fallback, $fields, [
            'origin_city' => $originCity,
            'origin_code' => $originCode,
            'destination_city' => $destCity,
            'destination_code' => $destCode,
            'departure_source' => $departureSource,
            'departure_date' => $departure,
            'return_date' => $returnDate,
            'trip_type' => $tripType,
            'flight_routes' => $flightRoutes,
            'adults' => $adults,
            'children' => $children,
            'infants' => $infants,
            'rooms' => $rooms,
            'child_ages' => $childAges,
            'class' => $class,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'duration_days' => $nights,
            'date_assumed' => $dateMentioned ? '0' : '1',
            'bus_origin_city' => $this->sanitizePlaceName((string) (
                $fields['bus_origin_city'] ?? ($fallback['bus_origin_city'] ?? '')
            )),
            'bus_destination_city' => $this->sanitizePlaceName((string) (
                $fields['bus_destination_city'] ?? ($fallback['bus_destination_city'] ?? '')
            )),
            'bus_date' => $busDate,
            'bus_return_date' => $busReturnDate,
            'tour_destination' => $this->sanitizePlaceName((string) (
                $fields['tour_destination']
                    ?? ($fallback['tour_destination'] ?? '')
            )) ?: $this->extractTourDestinationHint(
                $query,
                $destCity,
                $hint
            ),
            'esim_country' => trim((string) (
                $fields['esim_country'] ?? ($fallback['esim_country'] ?? '')
            )) ?: $this->extractEsimCountryHint($query, $destCity, $hint),
            'stay_nationality' => $this->normalizeStayNationalityIso((string) (
                $fallback['stay_nationality'] ?? $this->extractStayNationalityHint($query)
            )),
            'stay_nationality_context' => $this->normalizeStayNationalityIso((string) (
                $departureContext['nationality'] ?? ''
            )),
            'stay_nationality_context_source' => in_array(
                strtolower(trim((string) ($departureContext['nationality_source'] ?? ''))),
                ['manual', 'geolocation', 'last_used', 'session', 'prompt'],
                true
            ) ? strtolower(trim((string) ($departureContext['nationality_source'] ?? ''))) : '',
            'esim_package_type' => $this->normalizeEsimPackageType((string) (
                $fields['esim_package_type'] ?? ($fallback['esim_package_type'] ?? 'all')
            )),
            'visa_from_country' => trim((string) (
                $fields['visa_from_country'] ?? ($fallback['visa_from_country'] ?? '')
            )),
            'visa_to_country' => trim((string) (
                $fields['visa_to_country'] ?? ($fallback['visa_to_country'] ?? '')
            )),
            'visa_entry_date' => $this->normalizeYmdDate((string) (
                $fields['visa_entry_date']
                    ?? ($fallback['visa_entry_date'] ?? '')
                    ?: ($fields['departure_date'] ?? ($fallback['departure_date'] ?? ''))
            )),
            'visa_type' => $this->normalizeVisaType((string) (
                $fields['visa_type'] ?? ($fallback['visa_type'] ?? 'tourist')
            )),
            'visa_processing_speed' => $this->normalizeVisaProcessingSpeed((string) (
                $fields['visa_processing_speed'] ?? ($fallback['visa_processing_speed'] ?? 'standard')
            )),
            'visa_travelers' => max(1, min(10, (int) (
                $fields['visa_travelers'] ?? ($fallback['visa_travelers'] ?? ($fields['adults'] ?? ($fallback['adults'] ?? 1)))
            ))),
            'umrah_destination' => $this->extractUmrahDestinationHint(
                $query . ' ' . trim((string) (
                    $fields['umrah_destination'] ?? ($fallback['umrah_destination'] ?? '')
                )),
                trim((string) ($fields['destination_city'] ?? ($fallback['destination_city'] ?? ''))),
                $hint
            ),
            'umrah_start_date' => $this->normalizeYmdDate((string) (
                $fields['umrah_start_date']
                    ?? ($fallback['umrah_start_date'] ?? '')
                    ?: ($fields['departure_date'] ?? ($fallback['departure_date'] ?? ''))
            )),
            'umrah_duration' => trim((string) (
                $fields['umrah_duration'] ?? ($fallback['umrah_duration'] ?? '')
            )),
            'umrah_type' => trim((string) (
                $fields['umrah_type'] ?? ($fallback['umrah_type'] ?? '')
            )),
            'umrah_services' => trim((string) (
                $fields['umrah_services'] ?? ($fallback['umrah_services'] ?? '')
            )),
            'rail_origin' => $this->sanitizePlaceName((string) (
                $fields['rail_origin'] ?? ($fallback['rail_origin'] ?? '')
            )),
            'rail_destination' => $this->sanitizePlaceName((string) (
                $fields['rail_destination'] ?? ($fallback['rail_destination'] ?? '')
            )),
            'rail_date' => $this->normalizeYmdDate((string) (
                $fields['rail_date']
                    ?? ($fallback['rail_date'] ?? '')
                    ?: ($fields['departure_date'] ?? ($fallback['departure_date'] ?? ''))
            )),
            'rail_journey_type' => $this->normalizeRailJourneyType((string) (
                $fields['rail_journey_type'] ?? ($fallback['rail_journey_type'] ?? '')
            )),
            'ferry_origin' => $this->sanitizePlaceName((string) (
                $fields['ferry_origin'] ?? ($fallback['ferry_origin'] ?? '')
            )),
            'ferry_destination' => $this->sanitizePlaceName((string) (
                $fields['ferry_destination'] ?? ($fallback['ferry_destination'] ?? '')
            )),
            'ferry_date' => $this->normalizeYmdDate((string) (
                $fields['ferry_date']
                    ?? ($fallback['ferry_date'] ?? '')
                    ?: ($fields['departure_date'] ?? ($fallback['departure_date'] ?? ''))
            )),
            'ferry_return_date' => $this->normalizeYmdDate((string) (
                $fields['ferry_return_date'] ?? ($fallback['ferry_return_date'] ?? '')
            )),
            'ferry_vehicles' => max(0, min(4, (int) (
                $fields['ferry_vehicles'] ?? ($fallback['ferry_vehicles'] ?? 0)
            ))),
            'ferry_vehicle_type' => $this->normalizeFerryVehicleType((string) (
                $fields['ferry_vehicle_type'] ?? ($fallback['ferry_vehicle_type'] ?? '')
            )),
            'ferry_pets' => max(0, min(4, (int) (
                $fields['ferry_pets'] ?? ($fallback['ferry_pets'] ?? 0)
            ))),
            'ferry_pet_type' => $this->normalizeFerryPetType((string) (
                $fields['ferry_pet_type'] ?? ($fallback['ferry_pet_type'] ?? '')
            )),
        ]);
    }

    private function normalizeVisaType(string $type): string
    {
        $matched = $this->matchVisaSetting($type, 'visa_type');
        if ($matched !== '') {
            return $matched;
        }
        $t = $this->visaSettingKey($type);
        return $t !== '' ? $t : $this->defaultVisaSetting('visa_type', 'tourist');
    }

    /**
     * Values must exist in visa_settings — the booking catalog matches price variants on
     * them, so an invented speed (e.g. "urgent") silently degrades every visa to inquiry.
     */
    private function normalizeVisaProcessingSpeed(string $speed): string
    {
        $matched = $this->matchVisaSetting($speed, 'processing_speed');
        if ($matched !== '') {
            return $matched;
        }
        return $this->defaultVisaSetting('processing_speed', 'standard');
    }

    /**
     * True when the traveler actually named a date/time window in their own words.
     */
    private function queryMentionsDate(string $query): bool
    {
        $q = strtolower(trim($query));
        if ($q === '') {
            return false;
        }
        $patterns = [
            '/\b\d{4}-\d{1,2}-\d{1,2}\b/',
            '/\b\d{1,2}[\/\-.]\d{1,2}(?:[\/\-.]\d{2,4})?\b/',
            '/\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\b/',
            '/\b(?:today|tonight|tomorrow|overmorrow)\b/',
            '/\b(?:mon|tues?|wed(?:nes)?|thur?s?|fri|sat(?:ur)?|sun)(?:day)?\b/',
            '/\b(?:next|this|coming|following|upcoming)\s+(?:week|month|year|weekend)\b/',
            '/\b(?:in|after|within)\s+\d+\s+(?:day|week|month)s?\b/',
            '/\b(?:early|mid|late|end)\s+(?:of\s+)?(?:next\s+|this\s+)?(?:week|month|year)\b/',
            '/\b(?:summer|winter|spring|autumn|fall|eid|ramadan|christmas|new\s+year|holidays)\b/',
            '/\b\d{1,2}(?:st|nd|rd|th)\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $q)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeYmdDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $date)
            ?: \DateTime::createFromFormat('d-m-Y', $date);
        return $dt ? $dt->format('Y-m-d') : '';
    }

    private function normalizeEsimPackageType(string $type): string
    {
        $t = strtolower(trim($type));
        return in_array($t, ['all', 'global', 'local'], true) ? $t : 'all';
    }

    private function sanitizePlaceName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Drop placeholder / noise tokens often returned by models or copied from UI copy.
        $noise = [
            'origin', 'destination', 'from', 'to', 'flight', 'flights', 'fly', 'airfare',
            'airline', 'airlines', 'tomorrow', 'today', 'next', 'week', 'month', 'oneway',
            'one-way', 'round', 'trip', 'return', 'economy', 'business', 'first', 'class',
            'book', 'search', 'looking', 'look', 'find', 'need', 'want', 'please', 'for',
            'a', 'an', 'the', 'me', 'my', 'and', 'also', 'then', 'as', 'well', 'or', 'in',
            'on', 'at', 'of', 'with', 'plus', 'including', 'too', 'best', 'better', 'good', 'great',
            'cheap', 'cheapest', 'affordable', 'budget', 'lowest', 'top', 'available',
            'options', 'option', 'recommendation', 'recommendations', 'deal', 'deals',
            'ticket', 'tickets', 'any', 'some', 'help',
            // Product / module words must never stick to a city ("Dubai esim", "Paris hotel")
            'hotel', 'hotels', 'stay', 'stays', 'resort', 'apartment', 'hostel',
            'tour', 'tours', 'activity', 'activities', 'excursion',
            'car', 'cars', 'rental', 'vehicle',
            'visa', 'visas', 'umrah', 'hajj',
            'esim', 'e-sim', 'sim', 'roaming',
            'bus', 'buses', 'coach', 'train', 'trains', 'rail', 'ferry', 'ferries',
            'cruise', 'cruises', 'package', 'packages', 'data',
        ];

        $parts = preg_split('/[\s,]+/', $value) ?: [];
        $kept = [];
        foreach ($parts as $part) {
            $p = strtolower(trim($part, " \t\n\r\0\x0B.,;:!?"));
            if ($p === '' || in_array($p, $noise, true)) {
                continue;
            }
            $kept[] = $part;
        }

        $clean = trim(implode(' ', $kept));
        $clean = preg_replace('/\s+/', ' ', $clean) ?? '';
        $lower = strtolower($clean);
        if (in_array($lower, ['origin', 'destination', 'n/a', 'na', 'none', 'unknown'], true)) {
            return '';
        }

        // Title-case simple place names (keeps IATA-style already-uppercase codes intact).
        if ($clean !== '' && !preg_match('/^[A-Z]{3}$/', $clean)) {
            $clean = implode(' ', array_map(static function (string $w): string {
                return preg_match('/^[A-Z]{2,}$/', $w) ? $w : ucfirst(strtolower($w));
            }, explode(' ', $clean)));
        }

        return $clean;
    }

    /**
     * @param string[] $modules
     * @param array<string,mixed> $fields
     * @return list<array<string,mixed>>
     */
    private function buildSearches(array $modules, array $fields, string $hint, string $query): array
    {
        $currency = (string) ($_SESSION['app_currency'] ?? 'USD');
        $language = (string) ($_SESSION['app_language'] ?? 'en');
        $searches = [];

        foreach ($modules as $module) {
            $suppliers = $this->activeSuppliers($module);
            if ($suppliers === [] && !in_array($module, ['visa', 'umrah', 'esim', 'rail', 'ferries'], true)) {
                // Still surface the module so UI can show a browse link.
            }

            $item = match ($module) {
                'flights' => $this->buildFlightSearch($fields, $hint, $suppliers, $currency, $query),
                'tours' => $this->buildTourSearch($fields, $hint, $suppliers, $currency, $language, $query),
                'stays' => $this->buildStaySearch($fields, $hint, $suppliers, $currency, $language),
                'cars' => $this->buildCarSearch($fields, $hint, $suppliers, $currency, $query),
                'bus' => $this->buildBusSearch($fields, $hint, $suppliers, $currency),
                'esim' => $this->buildEsimSearch($fields, $hint, $suppliers, $currency, $query),
                'visa' => $this->buildVisaSearch($fields, $hint, $suppliers, $currency, $query),
                'umrah' => $this->buildUmrahSearch($fields, $hint, $suppliers, $currency, $query),
                'rail' => $this->buildRailSearch($fields, $hint, $suppliers, $currency, $query),
                'ferries' => $this->buildFerrySearch($fields, $hint, $suppliers, $currency, $query),
                default => $this->buildGenericSearch($module, $hint, $suppliers),
            };

            if ($item !== null) {
                $searches[] = $item;
            }
        }

        return $searches;
    }

    /**
     * @param array<string,mixed> $fields
     * @param string[] $suppliers
     * @return array<string,mixed>|null
     */
    private function buildFlightSearch(array $fields, string $hint, array $suppliers, string $currency, string $query = ''): ?array
    {
        $class = strtolower((string) ($fields['class'] ?? 'economy')) ?: 'economy';
        $adults = max(1, (int) ($fields['adults'] ?? 1));
        $children = max(0, (int) ($fields['children'] ?? 0));
        $infants = max(0, (int) ($fields['infants'] ?? 0));

        $tripType = strtolower((string) ($fields['trip_type'] ?? 'oneway'));
        if ($tripType === 'roundtrip' || $tripType === 'round') {
            $tripType = 'return';
        }
        if ($tripType === 'multi-city' || $tripType === 'multi_city' || $tripType === 'openjaw') {
            $tripType = 'multicity';
        }

        $flightRoutesRaw = $this->normalizeFlightRoutes(
            $fields['flight_routes'] ?? ($fields['routes'] ?? []),
            (string) ($fields['departure_date'] ?? '')
        );
        if ($flightRoutesRaw === [] && $query !== '') {
            $reparsed = $this->extractRouteFields($query, $hint);
            if (($reparsed['trip_type'] ?? '') === 'multicity' && !empty($reparsed['flight_routes'])) {
                $flightRoutesRaw = $this->normalizeFlightRoutes($reparsed['flight_routes'], (string) ($reparsed['departure_date'] ?? ''));
                $tripType = 'multicity';
            }
        }
        if ($tripType === 'multicity' || count($flightRoutesRaw) >= 2) {
            $built = $this->buildMulticityFlightSearch(
                $flightRoutesRaw,
                $fields,
                $hint,
                $suppliers,
                $currency,
                $query,
                $class,
                $adults,
                $children,
                $infants
            );
            if ($built !== null) {
                return $built;
            }
            // Fall through to oneway if routes could not be resolved
            $tripType = 'oneway';
        }

        $originCity = $this->sanitizePlaceName((string) ($fields['origin_city'] ?? ''));
        $destCity = $this->sanitizePlaceName((string) ($fields['destination_city'] ?? $hint));
        $origin = $this->resolveAirportCode((string) ($fields['origin_code'] ?? ''), $originCity);
        $destination = $this->resolveAirportCode((string) ($fields['destination_code'] ?? ''), $destCity);

        // Last-chance extraction from the raw query (handles messy AI output).
        if ($origin === null || $destination === null) {
            $reparsed = $this->extractRouteFields($query, $hint);
            if ($origin === null) {
                $originCity = $reparsed['origin_city'] !== '' ? $reparsed['origin_city'] : $originCity;
                $origin = $this->resolveAirportCode('', $originCity);
            }
            if ($destination === null) {
                $destCity = $reparsed['destination_city'] !== '' ? $reparsed['destination_city'] : $destCity;
                $destination = $this->resolveAirportCode('', $destCity);
            }
        }

        // Context already applied in sanitizeFields; re-resolve if city is present but code failed.
        if ($origin === null && $originCity !== '') {
            $origin = $this->resolveAirportCode((string) ($fields['origin_code'] ?? ''), $originCity);
        }

        if ($origin === null && $destination !== null) {
            return [
                'module' => 'flights',
                'badge' => 'AI FLIGHT GUIDE',
                'title' => 'Flights to ' . ($destCity !== '' ? $destCity : $destination),
                'subtitle' => 'Please include a departure city (e.g. Lahore to Karachi tomorrow).',
                'searchable' => false,
                'suppliers' => $suppliers,
                'params' => [
                    'origin' => '',
                    'origin_city' => $originCity,
                    'destination' => $destination,
                    'destination_city' => $destCity,
                    'needs_departure' => '1',
                ],
                'listing_url' => '',
                // 0 = no client-side cap (same as normal /flights listing)
                'items_limit' => 0,
            ];
        }

        if ($origin !== null && $destination === null) {
            return [
                'module' => 'flights',
                'badge' => 'AI FLIGHT GUIDE',
                'title' => 'Where do you want to fly?',
                'subtitle' => 'Tell us the destination (e.g. flights from '
                    . ($originCity !== '' ? $originCity : $origin)
                    . ' to Dubai tomorrow).',
                'searchable' => false,
                'empty_message' => 'Add a destination to search flights',
                'empty_detail' => 'Include where you want to go, for example “flights from Lahore to Dubai”.',
                'suppliers' => $suppliers,
                'params' => [
                    'origin' => $origin,
                    'origin_city' => $originCity,
                    'destination' => '',
                    'destination_city' => '',
                    'needs_destination' => '1',
                ],
                'listing_url' => '',
                'items_limit' => 0,
            ];
        }

        if ($origin === null || $destination === null) {
            return [
                'module' => 'flights',
                'badge' => 'AI FLIGHT GUIDE',
                'title' => 'Tell us your flight route',
                'subtitle' => 'Add where you are flying from and to (e.g. find flights from Lahore to Karachi).',
                'searchable' => false,
                'empty_message' => 'More details needed',
                'empty_detail' => 'Include origin and destination cities so we can search live flights.',
                'suppliers' => $suppliers,
                'params' => new \stdClass(),
                'listing_url' => '',
                'items_limit' => 0,
            ];
        }

        $departure = $this->toDisplayDate((string) ($fields['departure_date'] ?? ''));
        $returnDateRaw = trim((string) ($fields['return_date'] ?? ''));
        $returnDate = $returnDateRaw !== '' ? $this->toDisplayDate($returnDateRaw) : '';
        // Final safety: re-read query so round-trip is never dropped if the model said oneway
        $fromQuery = $this->extractFlightDatesFromQuery($query);
        if (($fromQuery['trip_type'] ?? '') === 'return') {
            $tripType = 'return';
            if ($returnDate === '' && !empty($fromQuery['return_date'])) {
                $returnDate = $this->toDisplayDate((string) $fromQuery['return_date']);
            }
            // Prefer query-resolved departure for relative days and named calendar dates
            // ("31 august", "next Monday") so LLM/defaults cannot drift to tomorrow.
            if (!empty($fromQuery['departure_date']) && (
                preg_match('/\b(next|this|return(?:ing)?|today|tomorrow)\b/i', $query)
                || $this->parseNamedDayMonthDates($query) !== []
            )) {
                $departure = $this->toDisplayDate((string) $fromQuery['departure_date']);
            }
        }
        // One-way with a named calendar date in the prompt — always trust query parse.
        if (($fromQuery['trip_type'] ?? '') !== 'return'
            && !empty($fromQuery['departure_date'])
            && $this->parseNamedDayMonthDates($query) !== []
        ) {
            $departure = $this->toDisplayDate((string) $fromQuery['departure_date']);
        }
        if (!in_array($tripType, ['oneway', 'return'], true)) {
            $tripType = $returnDate !== '' ? 'return' : 'oneway';
        }
        if ($tripType === 'return' && $returnDate === '') {
            // Supplier needs a return date — default +3 days from departure
            $returnDate = $this->toDisplayDate(date('Y-m-d', strtotime($this->toIsoDate($departure) . ' +3 days')));
        }
        if ($tripType !== 'return') {
            $tripType = 'oneway';
            $returnDate = '';
        }

        $originLabel = $originCity !== '' ? $originCity : $origin;
        $destLabel = $destCity !== '' ? $destCity : $destination;

        $listingUrl = 'flights/' . strtolower($origin) . '/' . strtolower($destination) . '/'
            . ($tripType === 'return' ? 'roundtrip' : 'oneway') . '/' . $class . '/' . $departure;
        if ($tripType === 'return' && $returnDate !== '') {
            $listingUrl .= '/' . $returnDate;
        }
        $listingUrl .= '/' . $adults . '/' . $children . '/' . $infants;

        $title = $this->flightTitle($originLabel, $destLabel);
        if ($tripType === 'return') {
            $title = 'Round-trip: ' . $originLabel . ' ⇄ ' . $destLabel;
        }
        $subtitle = $tripType === 'return'
            ? 'Round-trip live results (outbound + return) from active flight suppliers.'
            : 'Live results from active flight supplier modules.';
        $dateAssumed = (string) ($fields['date_assumed'] ?? '') === '1'
            || !$this->queryMentionsDate($query);
        if ($dateAssumed) {
            $subtitle = 'Date assumed (' . $departure . ') — no date in your prompt. '
                . $subtitle;
        }

        return [
            'module' => 'flights',
            'badge' => 'AI FLIGHT GUIDE',
            'title' => $title,
            'subtitle' => $subtitle,
            'searchable' => $suppliers !== [],
            'suppliers' => $suppliers,
            'params' => [
                'origin' => $origin,
                'origin_city' => $originCity,
                'destination' => $destination,
                'destination_city' => $destCity,
                'departure_date' => $departure,
                'return_date' => $tripType === 'return' ? $returnDate : '',
                'adults' => (string) $adults,
                'childrens' => (string) $children,
                'infants' => (string) $infants,
                // Supplier POST type: oneway | return (same as listing page mapping)
                'type' => $tripType,
                'class' => $class,
                'currency' => $currency,
                'date_assumed' => $dateAssumed ? '1' : '0',
            ],
            'listing_url' => $listingUrl,
            // 0 = unlimited — AI Trip must not truncate flights vs normal listing
            'items_limit' => 0,
        ];
    }

    /**
     * Build multi-city flight search matching normal listing contract.
     *
     * @param list<array{origin_city:string,origin_code:string,destination_city:string,destination_code:string,date:string}> $flightRoutesRaw
     * @param array<string,mixed> $fields
     * @param string[] $suppliers
     * @return array<string,mixed>|null
     */
    private function buildMulticityFlightSearch(
        array $flightRoutesRaw,
        array $fields,
        string $hint,
        array $suppliers,
        string $currency,
        string $query,
        string $class,
        int $adults,
        int $children,
        int $infants
    ): ?array {
        if (count($flightRoutesRaw) < 2) {
            return null;
        }

        $routes = [];
        $labels = [];
        foreach ($flightRoutesRaw as $leg) {
            if (count($routes) >= 6) {
                break;
            }
            $fromCity = $this->sanitizePlaceName((string) ($leg['origin_city'] ?? ''));
            $toCity = $this->sanitizePlaceName((string) ($leg['destination_city'] ?? ''));
            $from = $this->resolveAirportCode((string) ($leg['origin_code'] ?? ''), $fromCity);
            $to = $this->resolveAirportCode((string) ($leg['destination_code'] ?? ''), $toCity);
            if ($from === null || $to === null) {
                return null;
            }
            $date = $this->toDisplayDate((string) ($leg['date'] ?? ($fields['departure_date'] ?? '')));
            $routes[] = [
                'from' => $from,
                'to' => $to,
                'date' => $date,
            ];
            $labels[] = ($fromCity !== '' ? $fromCity : $from) . ' → ' . ($toCity !== '' ? $toCity : $to);
        }

        if (count($routes) < 2) {
            return null;
        }

        $first = $routes[0];
        $last = $routes[count($routes) - 1];
        $originCity = $this->sanitizePlaceName((string) ($fields['origin_city'] ?? $flightRoutesRaw[0]['origin_city'] ?? ''));
        $destCity = $this->sanitizePlaceName((string) (
            $fields['destination_city']
                ?? ($flightRoutesRaw[count($flightRoutesRaw) - 1]['destination_city'] ?? $hint)
        ));
        $originLabel = $originCity !== '' ? $originCity : $first['from'];
        $destLabel = $destCity !== '' ? $destCity : $last['to'];

        // Prefer suppliers with native multi-leg offers. Exclude kiwi (cartesian
        // fake combos) and googleflights (per-leg single cards) — see normal listing risks.
        // mystifly / tbo / local flights return [] for multicity and are omitted here.
        $multicityPreferred = [
            'pkfare', 'sabre', 'kayak', 'travelpayouts',
            'duffel', 'amadeus', 'amadeus_enterprise', 'seeru', 'travelport',
        ];
        $preferred = array_values(array_intersect($suppliers, $multicityPreferred));
        $searchSuppliers = $preferred !== [] ? $preferred : $suppliers;

        // Sabre/Seeru cap at 4 legs; UI allows 6 — truncate for reliable live search.
        if (count($routes) > 4) {
            $routes = array_slice($routes, 0, 4);
            $labels = array_slice($labels, 0, 4);
            $first = $routes[0];
            $last = $routes[count($routes) - 1];
            $originLabel = $originCity !== '' ? $originCity : $first['from'];
            $destLabel = $last['to'];
        }

        $routeParts = [];
        foreach ($routes as $r) {
            $routeParts[] = strtolower($r['from']) . '-' . strtolower($r['to']) . '-' . $r['date'];
        }
        $listingUrl = 'flights/multicity/' . $class . '/' . implode('/', $routeParts)
            . '/' . $adults . '/' . $children . '/' . $infants;

        $title = 'Multi-city: ' . implode(' · ', $labels);
        if (strlen($title) > 90) {
            $title = 'Multi-city: ' . $originLabel . ' → … → ' . $destLabel
                . ' (' . count($routes) . ' legs)';
        }

        return [
            'module' => 'flights',
            'badge' => 'AI FLIGHT GUIDE',
            'title' => $title,
            'subtitle' => 'Multi-city live results (' . count($routes) . ' legs) from active flight suppliers.',
            'searchable' => $searchSuppliers !== [],
            'suppliers' => $searchSuppliers,
            'params' => [
                'origin' => $first['from'],
                'origin_city' => $originLabel,
                'destination' => $last['to'],
                'destination_city' => $destLabel,
                'departure_date' => $first['date'],
                'return_date' => '',
                'adults' => (string) $adults,
                'childrens' => (string) $children,
                'infants' => (string) $infants,
                'type' => 'multicity',
                'class' => $class,
                'currency' => $currency,
                'routes' => $routes,
            ],
            'listing_url' => $listingUrl,
            // 0 = unlimited — same as one-way / return flight searches
            'items_limit' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $fields
     * @param string[] $suppliers
     * @return array<string,mixed>
     */
    private function buildTourSearch(
        array $fields,
        string $hint,
        array $suppliers,
        string $currency,
        string $language,
        string $query = ''
    ): array
    {
        // Tours stay in the city named for tours ("tours in Dubai"), NOT the flight
        // arrival city when the prompt also has "flight from Dubai to Lahore".
        $destCity = $this->sanitizePlaceName((string) ($fields['tour_destination'] ?? ''));
        if ($destCity === '' && $query !== '') {
            $destCity = $this->extractTourDestinationHint(
                $query,
                (string) ($fields['destination_city'] ?? ''),
                $hint
            );
        }
        if ($destCity === '') {
            $destCity = $this->sanitizePlaceName((string) ($fields['destination_city'] ?? $hint));
        }
        if ($destCity === '') {
            $destCity = 'Dubai';
        }
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $destCity) ?? 'dubai');
        $slug = trim($slug, '-') ?: 'dubai';
        $hasRequestedDate = (bool) preg_match('/\d{1,4}[\/\-]\d{1,2}/', $query);
        $dateWords = ' ' . trim((string) preg_replace('/[^a-z]+/i', ' ', strtolower($query))) . ' ';
        $dateTokens = [
            'today', 'tomorrow', 'next week', 'next month', 'next year',
            'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
            'jan', 'january', 'feb', 'february', 'mar', 'march', 'apr', 'april',
            'may', 'jun', 'june', 'jul', 'july', 'aug', 'august', 'sep', 'september',
            'oct', 'october', 'nov', 'november', 'dec', 'december'
        ];
        if (!$hasRequestedDate) {
            foreach ($dateTokens as $dateToken) {
                if (strpos($dateWords, ' ' . $dateToken . ' ') !== false) {
                    $hasRequestedDate = true;
                    break;
                }
            }
        }
        // Tours require a search date. When the user did not request one, use
        // the shared undated default (next calendar day).
        $start = $hasRequestedDate
            ? $this->toDisplayDate((string) ($fields['departure_date'] ?? $fields['checkin'] ?? ''))
            : $this->toDisplayDate($this->defaultTravelDateYmd());
        // The normal Tours listing uses "any" when no tour duration is selected.
        // Do not reuse hotel/trip duration_days here: it silently filters the
        // local tours table to one-day products when the prompt gives no duration.
        $duration = 'any';
        $tourDays = 0;
        if (preg_match('/\b(\d+)\s*-?\s*days?\s+(?:long\s+)?tours?\b/i', $query, $m)
            || preg_match('/\btours?\b.{0,20}\b(?:for\s+)?(\d+)\s*-?\s*days?\b/i', $query, $m)
        ) {
            $tourDays = max(1, (int) ($m[1] ?? 0));
        }
        if ($tourDays === 1) {
            $duration = '1';
        } elseif ($tourDays <= 3 && $tourDays > 1) {
            $duration = '2-3';
        } elseif ($tourDays <= 7 && $tourDays > 3) {
            $duration = '4-7';
        } elseif ($tourDays <= 14 && $tourDays > 7) {
            $duration = '8-14';
        } elseif ($tourDays >= 15) {
            $duration = '15+';
        }
        $adults = max(1, (int) ($fields['adults'] ?? 1));
        $children = max(0, (int) ($fields['children'] ?? 0));

        return [
            'module' => 'tours',
            'badge' => 'AI TOUR GUIDE',
            'title' => 'Tours in ' . $destCity,
            'subtitle' => 'Live results from active tour supplier modules.',
            'searchable' => $suppliers !== [],
            'suppliers' => $suppliers,
            'params' => [
                'destination' => $destCity,
                'destination_code' => $slug,
                'start_date' => $start,
                'duration' => (string) $duration,
                'adults' => (string) $adults,
                'children' => (string) $children,
                'travelers' => $adults . '-' . $children,
                'travelers_data' => json_encode([['adults' => $adults, 'children' => $children]]),
                'currency' => $currency,
                'language' => $language,
            ],
            'listing_url' => 'tours/' . rawurlencode($slug) . '/' . $start . '/' . $duration . '/' . $adults . '-' . $children . '/all',
            // 0 = no artificial AI cap
            'items_limit' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $fields
     * @param string[] $suppliers
     * @return array<string,mixed>
     */
    private function buildStaySearch(array $fields, string $hint, array $suppliers, string $currency, string $language): array
    {
        $destCity = trim((string) ($fields['destination_city'] ?? $hint));
        $needsDestination = $destCity === '';
        $slug = $needsDestination
            ? 'hotel'
            : (strtolower(preg_replace('/[^a-z0-9]+/i', '-', $destCity) ?? 'hotel'));
        $slug = trim($slug, '-') ?: 'hotel';
        $checkin = $this->toDisplayDate((string) ($fields['checkin'] ?? $fields['departure_date'] ?? ''));
        $checkoutRaw = (string) ($fields['checkout'] ?? '');
        $checkout = $checkoutRaw !== ''
            ? $this->toDisplayDate($checkoutRaw)
            : '';
        // Same as normal stays details: nights = checkout − checkin (16→18 Aug = 2 nights)
        $checkinIso = $this->toIsoDate($checkin);
        $checkoutIso = $checkout !== '' ? $this->toIsoDate($checkout) : '';
        if ($checkinIso !== '' && $checkoutIso !== '' && $checkoutIso > $checkinIso) {
            $nights = max(1, min(30, (int) ((strtotime($checkoutIso) - strtotime($checkinIso)) / 86400)));
        } else {
            $nights = max(1, min(30, (int) ($fields['duration_days'] ?? 1)));
            $checkout = $this->toDisplayDate(date('Y-m-d', strtotime($checkinIso . ' +' . $nights . ' days')));
        }
        $adults = max(1, (int) ($fields['adults'] ?? 1));
        $children = max(0, (int) ($fields['children'] ?? 0));
        $rooms = max(1, min(5, (int) ($fields['rooms'] ?? 1)));
        if ($rooms > $adults) {
            $rooms = $adults;
        }
        $ages = is_array($fields['child_ages'] ?? null) ? $fields['child_ages'] : [];
        $roomsData = $this->buildStayRoomsData($adults, $children, $rooms, $ages);
        $resolvedNat = $this->resolveStayNationality($fields);
        $nationality = (string) ($resolvedNat['iso'] ?? '');
        $nationalitySource = (string) ($resolvedNat['source'] ?? '');
        $needsNationality = $nationality === '';
        if ($nationality !== '') {
            $_SESSION['hotel_nationality'] = $nationality;
        }
        $dateAssumed = (string) ($fields['date_assumed'] ?? '') === '1';
        $searchable = $suppliers !== [] && !$needsNationality && !$needsDestination;
        $subtitle = 'Live results from active hotel supplier modules.';
        if ($needsDestination) {
            $subtitle = 'Add a destination city to see hotels.';
        } elseif ($needsNationality) {
            $subtitle = 'Select your nationality to see hotels available to you.';
        } elseif ($dateAssumed) {
            $subtitle = 'Dates assumed (' . $checkin . ' → ' . $checkout . ') — no date in your prompt. '
                . $subtitle;
        }

        $emptyMessage = '';
        $emptyDetail = '';
        if ($needsDestination) {
            $emptyMessage = 'Please mention a hotel destination in your prompt (for example, hotels in Dubai).';
            $emptyDetail = 'Hotel search needs a city or place name.';
        } elseif ($needsNationality) {
            $emptyMessage = 'Select your nationality to see hotels available to you.';
            $emptyDetail = 'Hotel availability depends on guest nationality. Choose a country above, or add it to your prompt (for example "I\'m from Pakistan").';
        }

        $roomConfigs = $this->stayRoomConfigString($roomsData);
        $listingUrl = (!$needsDestination && $nationality !== '')
            ? ('stays/' . rawurlencode($slug) . '/' . $checkin . '/' . $checkout . '/'
                . $nationality . '/' . $rooms . '/' . $roomConfigs)
            : 'stays';

        return [
            'module' => 'stays',
            'badge' => 'AI STAYS GUIDE',
            'title' => $needsDestination ? 'Stays' : ('Stays in ' . $destCity),
            'subtitle' => $subtitle,
            'searchable' => $searchable,
            'suppliers' => $suppliers,
            'empty_message' => $emptyMessage,
            'empty_detail' => $emptyDetail,
            'params' => [
                'destination' => $destCity,
                // Slug is for listing URLs only — Hotelbeds expects dest codes or city name.
                'destination_code' => '',
                'checkin' => $checkin,
                'checkout' => $checkout,
                'nights' => (string) $nights,
                'duration_days' => (string) $nights,
                'nationality' => $nationality,
                'nationality_source' => $nationalitySource,
                'nationality_required' => $needsNationality ? '1' : '0',
                'rooms' => (string) $rooms,
                'adults' => (string) $adults,
                'children' => (string) $children,
                'child_ages' => $ages,
                'rooms_data' => json_encode($roomsData),
                'currency' => $currency,
                'language' => $language,
                'date_assumed' => $dateAssumed ? '1' : '0',
            ],
            'listing_url' => $listingUrl,
            // 0 = no artificial AI cap
            'items_limit' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $fields
     * @param string[] $suppliers
     * @return array<string,mixed>
     */
    private function buildCarSearch(array $fields, string $hint, array $suppliers, string $currency, string $query = ''): array
    {
        $destCity = $this->sanitizePlaceName((string) ($fields['destination_city'] ?? $hint));
        $originCity = $this->sanitizePlaceName((string) ($fields['origin_city'] ?? ''));
        if ($destCity === '') {
            $destCity = $originCity !== '' ? $originCity : 'Dubai';
        }

        // Trip rentals are almost always at the DESTINATION (arrival city / airport).
        // Using origin (e.g. Lahore) when the user flew LHE→BCN wrongly searches Lahore cars.
        $pickupLoc = $destCity;
        $dropoffLoc = $destCity;
        $pickupCode = $this->resolveAirportCode(
            (string) ($fields['destination_code'] ?? ''),
            $destCity
        );
        $dropoffCode = $pickupCode;

        // Explicit one-way / origin pickup phrases only
        $queryHint = strtolower(trim($query . ' ' . $hint . ' ' . (string) ($fields['car_pickup_hint'] ?? '')));
        $wantsOriginPickup = false;
        if ($originCity !== '') {
            $wantsOriginPickup = (bool) preg_match(
                '/\b(pick\s*up|collect|rent).{0,40}\b(from|at)\s+' . preg_quote(strtolower($originCity), '/') . '\b/i',
                $queryHint
            ) || (bool) preg_match('/\bone[-\s]?way\s+rental\b|\bpickup\s+at\s+origin\b/i', $queryHint);
        }
        if ($wantsOriginPickup && $originCity !== '') {
            $pickupLoc = $originCity;
            $pickupCode = $this->resolveAirportCode(
                (string) ($fields['origin_code'] ?? ''),
                $originCity
            );
            $dropoffCode = $pickupCode;
            $dropoffLoc = $pickupLoc;
        }

        // Prefer airport-labelled location when user asked for airport rental (matches normal cars UX)
        $wantsAirport = (bool) preg_match('/\bairport\b/i', $queryHint)
            || (bool) preg_match('/\bairport\b/i', (string) ($fields['car_pickup_location'] ?? ''));
        if ($pickupCode && ($wantsAirport || stripos($pickupLoc, 'airport') === false)) {
            $baseCity = $wantsOriginPickup && $originCity !== '' ? $originCity : $destCity;
            $pickupLoc = $wantsAirport ? ($baseCity . ' Airport') : $baseCity;
            // Same-location return by default ("give back on same location")
            $dropoffLoc = $pickupLoc;
            $dropoffCode = $pickupCode;
        }
        if ($dropoffCode === null || $dropoffCode === '') {
            $dropoffCode = $pickupCode;
        }
        if ($dropoffLoc === '' || strcasecmp($dropoffLoc, $destCity) === 0) {
            $dropoffLoc = $pickupLoc;
        }

        // Align with hotel stay when present: pickup on check-in / arrival day, return on check-out
        $pickupDate = $this->toDisplayDate((string) (
            $fields['checkin']
            ?? $fields['departure_date']
            ?? $fields['car_pickup_date']
            ?? ''
        ));
        $returnRaw = trim((string) (
            $fields['checkout']
            ?? $fields['return_date']
            ?? $fields['car_return_date']
            ?? ''
        ));
        $nights = max(1, min(30, (int) ($fields['duration_days'] ?? 7)));
        // Prefer 7 days for "a week" rentals when length unknown
        if ($returnRaw === '' && empty($fields['checkout']) && empty($fields['duration_days'])) {
            $nights = 7;
        }
        $returnDate = $returnRaw !== ''
            ? $this->toDisplayDate($returnRaw)
            : $this->toDisplayDate(date('Y-m-d', strtotime($this->toIsoDate($pickupDate) . ' +' . $nights . ' days')));

        $pickupTime = trim((string) ($fields['car_pickup_time'] ?? '10:00')) ?: '10:00';
        $returnTime = trim((string) ($fields['car_return_time'] ?? '10:00')) ?: '10:00';
        $driverAge = max(18, min(99, (int) ($fields['driver_age'] ?? 30)));

        // Include ALL AI-enabled rental suppliers (local inventory + GDS).
        // Previously only modules with issue.php (CarTrawler) were kept, which
        // silently dropped local `cars` and `discover_cars` whenever CarTrawler
        // was active — matching the website rental listing which searches all three.
        $rentalSuppliers = [];
        $searchOnly = [];
        foreach ($suppliers as $s) {
            $name = strtolower(trim((string) $s));
            if ($name === '' || $name === 'kiwitaxi') {
                continue;
            }
            $issuePath = __DIR__ . '/../../../modules/cars/' . $name . '/issue.php';
            if (is_file($issuePath)) {
                $rentalSuppliers[] = $name;
            } else {
                $searchOnly[] = $name;
            }
        }
        // Local inventory first (same preference as /cars rental listing), then GDS.
        $rentalSuppliers = array_values(array_unique(array_merge($searchOnly, $rentalSuppliers)));
        if ($rentalSuppliers === []) {
            $rentalSuppliers = ['cars'];
        }
        $slugify = static function (string $name): string {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'city');
            return trim($slug, '-') ?: 'city';
        };
        $pickupSlug = $slugify($pickupLoc);
        $dropoffSlug = $slugify($dropoffLoc);

        $listingUrl = 'cars/rental/'
            . rawurlencode($pickupSlug) . '/'
            . rawurlencode($dropoffSlug) . '/'
            . $pickupDate . '/'
            . $returnDate . '/'
            . rawurlencode($pickupTime) . '/'
            . rawurlencode($returnTime) . '/'
            . $driverAge;

        $params = [
            'service_type' => 'rental',
            'pickup_location' => $pickupLoc,
            'dropoff_location' => $dropoffLoc,
            'pickup_date' => $pickupDate,
            'return_date' => $returnDate,
            'dropoff_date' => $returnDate,
            'pickup_time' => $pickupTime,
            'dropoff_time' => $returnTime,
            'return_time' => $returnTime,
            'driver_age' => (string) $driverAge,
            'driver_country' => 'US',
            'currency' => $currency,
        ];
        if ($pickupCode) {
            $params['pickup_code'] = $pickupCode;
        }
        if ($dropoffCode) {
            $params['dropoff_code'] = $dropoffCode;
        }

        return [
            'module' => 'cars',
            'badge' => 'AI CAR GUIDE',
            'title' => 'Cars in ' . $destCity,
            'subtitle' => 'Live rental results from active car supplier modules.',
            'searchable' => $rentalSuppliers !== [],
            'suppliers' => $rentalSuppliers,
            'params' => $params,
            'listing_url' => $listingUrl,
            // 0 = no artificial AI cap
            'items_limit' => 0,
        ];
    }

    /**
     * Local bus inventory — searched via POST /api/bus/listing (no modules/bus/search.php).
     * @param array<string,mixed> $fields
     * @param string[] $suppliers
     * @return array<string,mixed>
     */
    private function buildBusSearch(array $fields, string $hint, array $suppliers, string $currency): array
    {
        // Prefer dedicated bus_* cities so multi-leg trips (bus then flight) do not
        // reuse flight origin/destination for the coach search.
        $originCity = $this->sanitizePlaceName((string) (
            $fields['bus_origin_city'] ?? ''
        ));
        $destCity = $this->sanitizePlaceName((string) (
            $fields['bus_destination_city'] ?? ''
        ));
        if ($originCity === '' || $destCity === '') {
            // Bus-only prompts may still put cities in origin/destination
            if ($originCity === '') {
                $originCity = $this->sanitizePlaceName((string) ($fields['origin_city'] ?? ''));
            }
            if ($destCity === '') {
                $destCity = $this->sanitizePlaceName((string) ($fields['destination_city'] ?? $hint));
            }
        }
        if ($destCity === '') {
            $destCity = $originCity !== '' ? $originCity : 'Lahore';
        }
        if ($originCity === '') {
            $originCity = 'Islamabad';
            if (strcasecmp($originCity, $destCity) === 0) {
                $originCity = 'Lahore';
            }
        }

        $tripType = strtolower(trim((string) ($fields['trip_type'] ?? 'oneway')));
        // Do not inherit flight return trip_type for bus unless a bus return is stated
        $busReturnRaw = trim((string) ($fields['bus_return_date'] ?? ''));
        if ($busReturnRaw === '') {
            $tripType = 'oneway';
        } elseif (!in_array($tripType, ['oneway', 'return'], true)) {
            $tripType = 'return';
        }
        if ($tripType === 'oneway' && $busReturnRaw !== '') {
            $tripType = 'return';
        }

        $date = $this->toDisplayDate((string) (
            $fields['bus_date']
            ?? $fields['departure_date']
            ?? $fields['checkin']
            ?? ''
        ));
        $returnDate = '';
        if ($tripType === 'return') {
            $returnDate = $this->toDisplayDate($busReturnRaw !== ''
                ? $busReturnRaw
                : (string) ($fields['return_date'] ?? $fields['checkout'] ?? ''));
            if ($returnDate === '' || $returnDate === $date) {
                $returnDate = $this->toDisplayDate(date(
                    'Y-m-d',
                    strtotime($this->toIsoDate($date) . ' +2 days')
                ));
            }
        }

        $adults = max(1, min(10, (int) ($fields['adults'] ?? 1)));
        $children = max(0, min(10, (int) ($fields['children'] ?? 0)));
        $passengers = $adults + $children;

        // Prefer local inventory supplier named "bus"
        $busSuppliers = [];
        foreach ($suppliers as $s) {
            $name = strtolower(trim((string) $s));
            if ($name === '') {
                continue;
            }
            $busSuppliers[] = $name;
        }
        if ($busSuppliers === []) {
            $busSuppliers = ['bus'];
        } elseif (!in_array('bus', $busSuppliers, true)) {
            array_unshift($busSuppliers, 'bus');
            $busSuppliers = array_values(array_unique($busSuppliers));
        }

        $slugify = static function (string $name): string {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'city');
            return trim($slug, '-') ?: 'city';
        };
        $originSlug = $slugify($originCity);
        $destSlug = $slugify($destCity);

        if ($tripType === 'return' && $returnDate !== '') {
            $listingUrl = 'bus/'
                . rawurlencode($originSlug) . '/'
                . rawurlencode($destSlug) . '/return/'
                . $date . '/'
                . $returnDate . '/'
                . $adults . '-' . $children;
        } else {
            $listingUrl = 'bus/'
                . rawurlencode($originSlug) . '/'
                . rawurlencode($destSlug) . '/oneway/'
                . $date . '/'
                . $adults . '-' . $children;
        }

        $params = [
            'origin' => $originCity,
            'destination' => $destCity,
            'date' => $date,
            'return_date' => $returnDate,
            'trip_type' => $tripType,
            'adults' => (string) $adults,
            'children' => (string) $children,
            'passengers' => (string) max(1, $passengers),
            'currency' => $currency,
        ];

        return [
            'module' => 'bus',
            'badge' => 'AI BUS GUIDE',
            'title' => 'Bus ' . $originCity . ' → ' . $destCity,
            'subtitle' => 'Live results from local bus inventory.',
            'searchable' => true,
            'suppliers' => $busSuppliers,
            'params' => $params,
            'listing_url' => $listingUrl,
            // 0 = no artificial AI cap
            'items_limit' => 0,
        ];
    }

    /**
     * Airalo eSIM — packages via GET /esim/{moduleId}/{cc}/{type}/packages (same as website).
     * @param array<string,mixed> $fields
     * @param string[] $suppliers
     * @return array<string,mixed>
     */
    private function buildEsimSearch(array $fields, string $hint, array $suppliers, string $currency, string $query = ''): array
    {
        $packageType = $this->normalizeEsimPackageType((string) ($fields['esim_package_type'] ?? 'all'));
        $resolved = $this->resolveEsimCountry(
            (string) ($fields['esim_country'] ?? ''),
            (string) ($fields['destination_city'] ?? $hint),
            $query
        );
        $countryIso = strtolower((string) ($resolved['iso'] ?? ''));
        $countryName = (string) ($resolved['name'] ?? '');

        $moduleId = 0;
        $esimSuppliers = [];
        foreach ($suppliers as $s) {
            $name = strtolower(trim((string) $s));
            if ($name === '') {
                continue;
            }
            $esimSuppliers[] = $name;
        }
        if ($esimSuppliers === []) {
            $esimSuppliers = ['airalo'];
        } elseif (!in_array('airalo', $esimSuppliers, true)) {
            array_unshift($esimSuppliers, 'airalo');
            $esimSuppliers = array_values(array_unique($esimSuppliers));
        }

        try {
            $mod = $this->db->get('modules', ['id', 'name'], [
                'type' => 'esim',
                'name' => 'airalo',
                'status' => 1,
                'ORDER' => ['id' => 'ASC'],
            ]);
            if (is_array($mod) && !empty($mod['id'])) {
                $moduleId = (int) $mod['id'];
            }
        } catch (\Throwable $e) {
            $moduleId = 0;
        }

        $searchable = $moduleId > 0 && $countryIso !== '' && strlen($countryIso) === 2;
        $listingUrl = $searchable
            ? ('esim/' . $moduleId . '/' . rawurlencode($countryIso) . '/' . rawurlencode($packageType) . '/')
            : 'esim';

        $titleCountry = $countryName !== '' ? $countryName : strtoupper($countryIso);
        if ($titleCountry === '') {
            $titleCountry = $hint;
        }

        // Airalo packages are always country-scoped, so a prompt like "I need an eSIM"
        // cannot be searched — tell the traveler what is missing instead of "no results".
        $needsCountry = $moduleId > 0 && $countryIso === '';

        $emptyMessage = '';
        $emptyDetail = '';
        if ($needsCountry) {
            $emptyMessage = 'Which country do you need the eSIM for?';
            $emptyDetail = 'Select a country below — same as the normal eSIM search — or mention it in your prompt (e.g. "eSIM for Spain").';
        } elseif (!$searchable) {
            $emptyMessage = 'eSIM packages are not available right now';
            $emptyDetail = 'The eSIM supplier is not configured. You can continue your trip without this step.';
        }

        $subtitle = 'Select a country to browse Airalo packages.';
        if ($searchable) {
            $subtitle = 'Live Airalo packages for ' . $titleCountry . ' (' . strtoupper($packageType) . ').';
        } elseif ($needsCountry) {
            $subtitle = 'Pick a country below to load eSIM packages.';
        }

        return [
            'module' => 'esim',
            'badge' => 'AI ESIM GUIDE',
            'title' => $titleCountry !== '' ? ('eSIM — ' . $titleCountry) : 'eSIM',
            'subtitle' => $subtitle,
            'searchable' => $searchable,
            'suppliers' => $esimSuppliers,
            'empty_message' => $emptyMessage,
            'empty_detail' => $emptyDetail,
            'params' => [
                'module_id' => (string) $moduleId,
                'country' => strtoupper($countryIso),
                'country_name' => $countryName !== '' ? $countryName : strtoupper($countryIso),
                'package_type' => $packageType,
                'currency' => $currency,
            ],
            'listing_url' => $listingUrl,
            // 0 = no artificial AI cap
            'items_limit' => 0,
        ];
    }

    /**
     * Local visa inventory — one confirm card via POST /api/ai/visa/listing (web AI, CSRF).
     * Priced when catalog matches; otherwise inquiry / price-on-request (same as /visa booking).
     *
     * @param array<string,mixed> $fields
     * @param string[] $suppliers
     * @return array<string,mixed>
     */
    private function buildVisaSearch(array $fields, string $hint, array $suppliers, string $currency, string $query = ''): array
    {
        $fromRaw = trim((string)($fields['visa_from_country'] ?? ''));
        $toRaw = trim((string)($fields['visa_to_country'] ?? ''));
        if ($fromRaw === '') {
            $fromRaw = trim((string)($fields['origin_city'] ?? ''));
        }
        if ($toRaw === '') {
            $toRaw = trim((string)($fields['destination_city'] ?? $hint));
        }

        $from = $this->resolveWorldCountryIso($fromRaw);
        $to = $this->resolveWorldCountryIso($toRaw);

        $fromIso = strtoupper((string)($from['iso'] ?? ''));
        $toIso = strtoupper((string)($to['iso'] ?? ''));
        $fromName = (string)($from['name'] ?? $fromIso);
        $toName = (string)($to['name'] ?? $toIso);

        $entryYmd = $this->normalizeYmdDate((string)($fields['visa_entry_date'] ?? ''));
        if ($entryYmd === '') {
            $entryYmd = $this->normalizeYmdDate((string)($fields['departure_date'] ?? ($fields['checkin'] ?? '')));
        }
        if ($entryYmd === '') {
            $entryYmd = $this->defaultTravelDateYmd();
        }
        $entryDmY = date('d-m-Y', strtotime($entryYmd));
        // The website form requires an entry date, so a default is always filled in —
        // flag it when the traveler never named one so the card does not look like their input.
        $entryAssumed = !$this->queryMentionsDate($query);

        $visaType = $this->normalizeVisaType((string)($fields['visa_type'] ?? 'tourist'));
        $speed = $this->normalizeVisaProcessingSpeed((string)($fields['visa_processing_speed'] ?? 'standard'));
        $travelers = max(1, min(10, (int)($fields['visa_travelers'] ?? ($fields['adults'] ?? 1))));

        $visaSuppliers = [];
        foreach ($suppliers as $s) {
            $name = strtolower(trim((string)$s));
            if ($name !== '') {
                $visaSuppliers[] = $name;
            }
        }
        if ($visaSuppliers === []) {
            $visaSuppliers = ['visa'];
        }

        $searchable = $fromIso !== '' && $toIso !== '' && strlen($fromIso) === 2 && strlen($toIso) === 2 && $fromIso !== $toIso;
        $listingUrl = $searchable
            ? ('visa/' . rawurlencode($fromIso) . '/' . rawurlencode($toIso) . '/'
                . rawurlencode($entryDmY) . '/' . rawurlencode($visaType) . '/'
                . rawurlencode($speed) . '/' . $travelers)
            : 'visa';

        $title = 'Visa';
        if ($fromName !== '' && $toName !== '') {
            $title = 'Visa — ' . $fromName . ' → ' . $toName;
        } elseif ($toName !== '') {
            $title = 'Visa — ' . $toName;
        }

        return [
            'module' => 'visa',
            'badge' => 'AI VISA GUIDE',
            'title' => $title,
            'subtitle' => $searchable
                ? ('Confirm ' . $fromName . ' → ' . $toName . ' · ' . ucfirst(str_replace('_', ' ', $visaType))
                    . ' · ' . ucfirst(str_replace('_', ' ', $speed))
                    . ($entryAssumed ? ' · entry date assumed' : '') . ' (priced or inquiry).')
                : 'Select nationality and destination on /visa to apply.',
            'searchable' => $searchable,
            'suppliers' => $visaSuppliers,
            'params' => [
                'from_country' => $fromIso,
                'from_country_name' => $fromName,
                'to_country' => $toIso,
                'to_country_name' => $toName,
                'entry_date' => $entryDmY,
                'entry_date_ymd' => $entryYmd,
                'entry_date_assumed' => $entryAssumed ? '1' : '0',
                'travel_date' => $entryDmY,
                'visa_type' => $visaType,
                'processing_speed' => $speed,
                'travelers' => (string)$travelers,
                'currency' => $currency,
            ],
            'listing_url' => $listingUrl,
            // Visa is a single confirm card; 1 is enough
            'items_limit' => 1,
        ];
    }

    /**
     * Local umrah packages — cards via POST /api/umrah/listing (web, CSRF).
     *
     * @param array<string,mixed> $fields
     * @param string[] $suppliers
     * @return array<string,mixed>
     */
    private function buildUmrahSearch(array $fields, string $hint, array $suppliers, string $currency, string $query = ''): array
    {
        $dest = trim((string)($fields['umrah_destination'] ?? ''));
        if ($dest === '') {
            $dest = trim((string)($fields['destination_city'] ?? $hint));
        }
        $dest = $this->extractUmrahDestinationHint($dest !== '' ? $dest : $query, $dest, $hint);

        $startYmd = $this->normalizeYmdDate((string)($fields['umrah_start_date'] ?? ''));
        if ($startYmd === '') {
            $startYmd = $this->normalizeYmdDate((string)($fields['departure_date'] ?? ($fields['checkin'] ?? '')));
        }
        if ($startYmd === '') {
            $startYmd = $this->defaultTravelDateYmd();
        }
        $startDmY = date('d-m-Y', strtotime($startYmd));

        $notes = [];
        $durationId = $this->resolveUmrahSettingId((string)($fields['umrah_duration'] ?? ''), 'duration', $notes);
        $typeId = $this->resolveUmrahSettingId((string)($fields['umrah_type'] ?? ''), 'umrah_type', $notes);
        $serviceRaw = trim((string)($fields['umrah_services'] ?? ''));
        $serviceIds = [];
        if ($serviceRaw !== '') {
            foreach (preg_split('/[,|]+/', $serviceRaw) ?: [] as $tok) {
                $sid = $this->resolveUmrahSettingId(trim($tok), ['service', 'hotel', 'flight', 'car'], $notes);
                if ($sid !== '') {
                    $serviceIds[] = $sid;
                }
            }
        }

        // Requested travelers from AI query — package max_* applied per card in listing
        $adults = (int)($fields['adults'] ?? 0);
        $children = (int)($fields['children'] ?? 0);
        $infants = (int)($fields['infants'] ?? 0);
        $durationParam = $durationId !== '' ? $durationId : 'any';
        $typeParam = $typeId !== '' ? $typeId : 'any';
        $servicesParam = $serviceIds !== [] ? implode(',', $serviceIds) : 'any';

        $umrahSuppliers = [];
        foreach ($suppliers as $s) {
            $n = strtolower(trim((string)$s));
            if ($n !== '') {
                $umrahSuppliers[] = $n;
            }
        }
        if ($umrahSuppliers === []) {
            $umrahSuppliers = ['umrah'];
        }

        $locChoices = $this->umrahLocationsFromDb();
        // Always searchable when the module is enabled — empty city → list all (destination=any)
        $hasDest = $dest !== '' && strcasecmp($dest, 'any') !== 0;
        $destParam = $hasDest ? $dest : 'any';
        $searchable = true;
        $listingUrl = $hasDest
            ? ('umrah/' . rawurlencode(strtolower(str_replace(' ', '-', $dest))) . '/'
                . rawurlencode($startDmY) . '/' . rawurlencode($durationParam) . '/'
                . rawurlencode($servicesParam) . '/' . rawurlencode($typeParam))
            : ('umrah/any/' . rawurlencode($startDmY) . '/' . rawurlencode($durationParam) . '/'
                . rawurlencode($servicesParam) . '/' . rawurlencode($typeParam));

        $subtitle = $hasDest
            ? ('Packages for ' . $dest . ' · ' . $startDmY)
            : ('All package locations'
                . ($locChoices !== [] ? (' (' . implode(' / ', $locChoices) . ')') : '')
                . ' · ' . $startDmY);
        if ($notes !== []) {
            $subtitle .= ' · ' . implode('; ', $notes);
        }

        return [
            'module' => 'umrah',
            'badge' => 'AI UMRAH',
            'title' => $hasDest ? ('Umrah — ' . $dest) : 'Umrah',
            'subtitle' => $subtitle,
            'searchable' => $searchable,
            'suppliers' => $umrahSuppliers,
            'params' => [
                'destination' => $destParam,
                'start_date' => $startDmY,
                'start_date_ymd' => $startYmd,
                'duration' => $durationParam,
                'umrah_type' => $typeParam,
                'services' => $servicesParam,
                'adults' => (string)$adults,
                'children' => (string)$children,
                'infants' => (string)$infants,
                'currency' => $currency,
            ],
            'listing_url' => $listingUrl,
            // 0 = no artificial AI cap
            'items_limit' => 0,
        ];
    }

    /**
     * Live rail schedules via the normal web POST /ticket/trainQuery flow.
     *
     * @param string[] $suppliers
     * @return array<string,mixed>
     */
    private function buildRailSearch(array $fields, string $hint, array $suppliers, string $currency, string $query = ''): array
    {
        $originRaw = trim((string)($fields['rail_origin'] ?? ''));
        $destRaw = trim((string)($fields['rail_destination'] ?? ''));
        if ($originRaw === '') {
            $originRaw = trim((string)($fields['origin_city'] ?? ($fields['bus_origin_city'] ?? '')));
        }
        if ($destRaw === '') {
            $destRaw = trim((string)($fields['destination_city'] ?? ($fields['bus_destination_city'] ?? $hint)));
        }

        $journeyType = $this->normalizeRailJourneyType((string)($fields['rail_journey_type'] ?? ''));
        $fromStation = $this->matchRailStation($originRaw, $journeyType);
        $toStation = $this->matchRailStation($destRaw, $journeyType);

        if ($journeyType === 0 && $fromStation && $toStation) {
            $fj = (int)($fromStation['journey_type'] ?? 0);
            $tj = (int)($toStation['journey_type'] ?? 0);
            if ($fj > 0 && $fj === $tj) {
                $journeyType = $fj;
            } elseif ($fj > 0) {
                $toRetry = $this->matchRailStation($destRaw, $fj);
                if ($toRetry) {
                    $toStation = $toRetry;
                    $journeyType = $fj;
                }
            }
        }

        $dateYmd = $this->normalizeYmdDate((string)($fields['rail_date'] ?? ''));
        if ($dateYmd === '') {
            $dateYmd = $this->normalizeYmdDate((string)($fields['departure_date'] ?? ($fields['bus_date'] ?? '')));
        }
        if ($dateYmd === '') {
            $dateYmd = date('Y-m-d', strtotime('+7 days'));
        }
        $dateDmY = date('d-m-Y', strtotime($dateYmd));

        $adults = max(1, min(10, (int)($fields['adults'] ?? 1)));
        $children = max(0, min(10, (int)($fields['children'] ?? 0)));
        $infants = max(0, min(10, (int)($fields['infants'] ?? 0)));

        $railSuppliers = [];
        foreach ($suppliers as $s) {
            $n = strtolower(trim((string)$s));
            if ($n !== '') {
                $railSuppliers[] = $n;
            }
        }
        if ($railSuppliers === []) {
            $railSuppliers = ['train'];
        }

        $searchable = $fromStation !== null && $toStation !== null
            && strcasecmp((string)$fromStation['code'], (string)$toStation['code']) !== 0;

        $fromCode = $fromStation ? (string)$fromStation['code'] : '';
        $toCode = $toStation ? (string)$toStation['code'] : '';
        $fromName = $fromStation
            ? (string)($fromStation['name'] ?: $fromStation['code'])
            : $originRaw;
        $toName = $toStation
            ? (string)($toStation['name'] ?: $toStation['code'])
            : $destRaw;

        $jtForUrl = $journeyType > 0 ? $journeyType : 1;
        $listingUrl = $searchable
            ? ('rail/search/' . rawurlencode($fromCode) . '/' . rawurlencode($toCode) . '/'
                . rawurlencode($dateDmY) . '/' . $jtForUrl . '/'
                . $adults . '/' . $children . '/' . $infants)
            : 'rail';

        $subtitle = $searchable
            ? ($fromName . ' → ' . $toName . ' · ' . $dateDmY)
            : 'Name known rail stations (from catalog) to search trains';
        if ($journeyType > 0) {
            try {
                if (!function_exists('_train_region_policy')) {
                    require_once dirname(__DIR__, 3) . '/modules/rail/train/search.php';
                }
                $policy = _train_region_policy($journeyType);
                $label = (string)($policy['label'] ?? '');
                if ($label !== '') {
                    $subtitle .= ' · ' . $label;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return [
            'module' => 'rail',
            'badge' => 'AI RAIL',
            'title' => $searchable ? ('Train — ' . $fromName . ' → ' . $toName) : 'Rail / Train',
            'subtitle' => $subtitle,
            'searchable' => $searchable,
            'suppliers' => $railSuppliers,
            'params' => [
                'journey_type' => $journeyType > 0 ? (string)$journeyType : '',
                'from_station' => $fromCode !== '' ? $fromCode : $originRaw,
                'to_station' => $toCode !== '' ? $toCode : $destRaw,
                'from_station_code' => $fromCode,
                'to_station_code' => $toCode,
                'from_station_name' => $fromName,
                'to_station_name' => $toName,
                'travel_date' => $dateDmY,
                'travel_date_ymd' => $dateYmd,
                'adults' => (string)$adults,
                'children' => (string)$children,
                'infants' => (string)$infants,
                'currency' => $currency,
            ],
            'listing_url' => $listingUrl,
            // 0 = no artificial AI cap
            'items_limit' => 0,
        ];
    }

    private function normalizeRailJourneyType(string $raw): int
    {
        $t = strtolower(trim($raw));
        if ($t === '1' || (str_contains($t, 'china') && !str_contains($t, 'laos'))) {
            return 1;
        }
        if ($t === '2' || str_contains($t, 'laos')) {
            return 2;
        }
        if ($t === '3' || str_contains($t, 'whoosh') || str_contains($t, 'jakarta') || str_contains($t, 'bandung')) {
            return 3;
        }
        if (ctype_digit($t) && in_array((int)$t, [1, 2, 3], true)) {
            return (int)$t;
        }
        return 0;
    }

    private function extractRailJourneyTypeHint(string $q): string
    {
        $q = strtolower($q);
        if (str_contains($q, 'whoosh') || str_contains($q, 'jakarta') || str_contains($q, 'bandung')) {
            return '3';
        }
        if (str_contains($q, 'laos')) {
            return '2';
        }
        if (preg_match('/\bchina\b.*\b(?:rail|train)|(?:rail|train).*\bchina\b/i', $q)) {
            return '1';
        }
        return '';
    }

    private function extractRailOriginHint(string $q, string $originCity, string $busOrigin): string
    {
        if (preg_match('/\b(?:train|rail|railway|whoosh)\b.{0,40}?\b(?:from|depart(?:ing)?)\s+([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})/i', $q, $m)) {
            return $this->sanitizePlaceName($m[1]);
        }
        if (preg_match('/\b([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})\s+(?:to|→|-)\s+([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2}).{0,30}\b(?:train|rail|whoosh)\b/i', $q, $m)) {
            return $this->sanitizePlaceName($m[1]);
        }
        foreach ([$busOrigin, $originCity] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $this->matchRailStation($candidate, 0)) {
                return $candidate;
            }
        }
        return '';
    }

    private function extractRailDestinationHint(string $q, string $destCity, string $hint, string $busDest): string
    {
        if (preg_match('/\b(?:train|rail|railway|whoosh)\b.{0,40}?\b(?:to|towards)\s+([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})/i', $q, $m)) {
            return $this->sanitizePlaceName($m[1]);
        }
        if (preg_match('/\b([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2})\s+(?:to|→|-)\s+([A-Za-z][A-Za-z\-]{1,24}(?:\s+[A-Za-z][A-Za-z\-]{1,24}){0,2}).{0,30}\b(?:train|rail|whoosh)\b/i', $q, $m)) {
            return $this->sanitizePlaceName($m[2]);
        }
        foreach ([$busDest, $destCity, $hint] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $this->matchRailStation($candidate, 0)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Match station code/name/city against rail_stations (dynamic — never invent).
     * @return array{code:string,name:string,city:string,journey_type:int}|null
     */
    private function matchRailStation(string $raw, int $journeyType = 0): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            if (function_exists('aiTripRailResolveStation')) {
                return aiTripRailResolveStation($this->db, $raw, $journeyType);
            }
            if (!function_exists('_train_search_stations')) {
                $SECURE = true;
                require_once dirname(__DIR__, 3) . '/modules/rail/train/stations.php';
            }
            _train_ensure_stations_table($this->db);
            $upper = strtoupper($raw);
            $where = ['code' => $upper];
            if (in_array($journeyType, [1, 2, 3], true)) {
                $where['journey_type'] = $journeyType;
            }
            $exact = $this->db->get('rail_stations', ['code', 'name', 'city', 'journey_type'], $where);
            if (is_array($exact) && !empty($exact['code'])) {
                return [
                    'code' => (string)$exact['code'],
                    'name' => function_exists('_train_station_english_only')
                        ? _train_station_english_only((string)($exact['name'] ?? ''))
                        : (string)($exact['name'] ?? ''),
                    'city' => function_exists('_train_station_english_only')
                        ? _train_station_english_only((string)($exact['city'] ?? ''))
                        : (string)($exact['city'] ?? ''),
                    'journey_type' => (int)($exact['journey_type'] ?? 0),
                ];
            }
            $types = in_array($journeyType, [1, 2, 3], true) ? [$journeyType] : [1, 2, 3];
            $best = null;
            $bestScore = 0;
            $q = strtolower($raw);
            foreach ($types as $jt) {
                $result = _train_search_stations($this->db, $raw, (int)$jt, 15);
                foreach (($result['stations'] ?? []) as $s) {
                    if (!is_array($s) || empty($s['code'])) {
                        continue;
                    }
                    $name = strtolower((string)($s['name'] ?? ''));
                    $city = strtolower((string)($s['city'] ?? ''));
                    $code = strtolower((string)$s['code']);
                    $score = 0;
                    if ($code === $q || $name === $q || $city === $q) {
                        $score = 100;
                    } elseif ($name !== '' && (str_starts_with($name, $q) || str_contains($name, $q))) {
                        $score = 80;
                    } elseif ($city !== '' && (str_starts_with($city, $q) || str_contains($city, $q))) {
                        $score = 70;
                    }
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = [
                            'code' => (string)$s['code'],
                            'name' => (string)($s['name'] ?? $s['code']),
                            'city' => (string)($s['city'] ?? ''),
                            'journey_type' => (int)($s['journey_type'] ?? $jt),
                        ];
                    }
                }
            }
            return $bestScore >= 60 ? $best : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ferries — both Kikoto ports plus a live route between them are required before search.
     *
     * @param array<string,mixed> $fields
     * @param string[] $suppliers
     * @return array<string,mixed>
     */
    private function buildFerrySearch(array $fields, string $hint, array $suppliers, string $currency, string $query = ''): array
    {
        $originRaw = trim((string)($fields['ferry_origin'] ?? ''));
        $destRaw = trim((string)($fields['ferry_destination'] ?? ''));
        if ($originRaw === '' && $destRaw === '') {
            $originRaw = trim((string)($fields['origin_city'] ?? ''));
            $destRaw = trim((string)($fields['destination_city'] ?? $hint));
        }

        $ports = $this->ferryPortsCatalog();
        $routes = $this->ferryRoutesCatalog();
        $fromPort = $this->matchFerryPort($originRaw, $ports);
        $toPort = $this->matchFerryPort($destRaw, $ports);

        $depId = $fromPort ? (int)$fromPort['id'] : 0;
        $destId = $toPort ? (int)$toPort['id'] : 0;
        $fromName = $fromPort ? (string)$fromPort['name'] : $originRaw;
        $toName = $toPort ? (string)$toPort['name'] : $destRaw;

        $route = null;
        if ($depId > 0 && $destId > 0 && $depId !== $destId) {
            foreach ($routes as $r) {
                if ((int)($r['departure_port_id'] ?? 0) === $depId
                    && (int)($r['destination_port_id'] ?? 0) === $destId) {
                    $route = $r;
                    break;
                }
            }
        }

        $dateYmd = $this->normalizeYmdDate((string)($fields['ferry_date'] ?? ''));
        if ($dateYmd === '') {
            $dateYmd = $this->normalizeYmdDate((string)($fields['departure_date'] ?? ($fields['rail_date'] ?? '')));
        }
        if ($dateYmd === '') {
            $dateYmd = $this->defaultTravelDateYmd();
        }
        $returnYmd = $this->normalizeYmdDate((string)($fields['ferry_return_date'] ?? ''));
        if ($returnYmd !== '' && $returnYmd <= $dateYmd) {
            $returnYmd = '';
        }
        $tripType = $returnYmd !== '' ? 'return' : 'oneway';

        $adults = max(1, min(9, (int)($fields['adults'] ?? 1)));
        $children = max(0, min(8, (int)($fields['children'] ?? 0)));
        $infants = max(0, min(9, (int)($fields['infants'] ?? 0)));

        $vehicles = max(0, min(4, (int)($fields['ferry_vehicles'] ?? 0)));
        $vehicleType = $this->normalizeFerryVehicleType((string)($fields['ferry_vehicle_type'] ?? ''));
        if ($vehicles > 0 && $vehicleType === '') {
            $vehicleType = 'car';
        }
        if ($vehicles === 0) {
            $vehicleType = '';
        }
        $pets = max(0, min(4, (int)($fields['ferry_pets'] ?? 0)));
        $petType = $this->normalizeFerryPetType((string)($fields['ferry_pet_type'] ?? ''));
        if ($pets > 0 && $petType === '') {
            $petType = 'carrier';
        }
        if ($pets === 0) {
            $petType = '';
        }

        $routeServices = is_array($route['services'] ?? null) ? $route['services'] : [];

        $ferrySuppliers = [];
        foreach ($suppliers as $s) {
            $n = strtolower(trim((string)$s));
            if ($n !== '') {
                $ferrySuppliers[] = $n;
            }
        }
        if ($ferrySuppliers === []) {
            $ferrySuppliers = ['kikoto'];
        }

        $searchable = $depId > 0 && $destId > 0 && $depId !== $destId && $route !== null;

        $emptyMessage = '';
        $emptyDetail = '';
        if (!$searchable) {
            $exampleArrival = $this->ferryExampleArrivalName($depId, $routes, $ports) ?: 'Tangier Med';
            $exampleDeparture = $depId > 0 ? $fromName : 'Algeciras';
            if ($depId > 0 && $destId === 0) {
                $emptyMessage = 'Which arrival port do you need?';
                $suggestions = $this->ferryArrivalSuggestions($depId, $routes, $ports, 5);
                $suggestText = $suggestions !== []
                    ? (' From ' . $fromName . ' you can sail to: ' . implode(', ', $suggestions) . '.')
                    : '';
                $emptyDetail = $destRaw !== ''
                    ? ('I could not find a ferry port called "' . $destRaw . '".'
                        . $suggestText
                        . ' Try for example "ferry from ' . $fromName . ' to ' . $exampleArrival . '".')
                    : ('Add the arrival port, for example "ferry from ' . $fromName . ' to ' . $exampleArrival . '".'
                        . $suggestText);
            } elseif ($destId > 0 && $depId === 0) {
                $emptyMessage = 'Which departure port do you need?';
                $emptyDetail = $originRaw !== ''
                    ? ('I could not find a ferry port called "' . $originRaw . '". Name the departure port, for example "ferry from ' . $exampleDeparture . ' to ' . $toName . '".')
                    : ('Add the departure port, for example "ferry from ' . $exampleDeparture . ' to ' . $toName . '".');
            } elseif ($depId > 0 && $destId > 0 && $depId === $destId) {
                $emptyMessage = 'Departure and arrival ports must be different';
                $emptyDetail = 'Name two different ferry ports, for example "ferry from ' . $exampleDeparture . ' to ' . $exampleArrival . '".';
            } elseif ($depId > 0 && $destId > 0) {
                $emptyMessage = 'No ferry route between ' . $fromName . ' and ' . $toName;
                $suggestions = $this->ferryArrivalSuggestions($depId, $routes, $ports, 5);
                $emptyDetail = $suggestions !== []
                    ? ('That crossing is not sold by our ferry operators. From ' . $fromName . ' try: ' . implode(', ', $suggestions) . '.')
                    : 'That crossing is not sold by our ferry operators. Try another pair of ports.';
            } else {
                $emptyMessage = 'Which ferry crossing do you need?';
                $emptyDetail = 'Name both ports, for example "ferry from Algeciras to Tangier Med next week".';
            }
        }

        $extras = [];
        if ($vehicles > 0) {
            $extras['vehicles'] = (string)$vehicles;
            if ($vehicleType !== '') {
                $extras['vehicle_type'] = $vehicleType;
            }
        }
        if ($pets > 0) {
            $extras['pets'] = (string)$pets;
            if ($petType !== '') {
                $extras['pet_type'] = $petType;
            }
        }
        $extrasQuery = $extras !== [] ? ('?' . http_build_query($extras)) : '';

        $listingUrl = 'ferries';
        if ($searchable) {
            $listingUrl = 'ferries/' . $depId . '/' . $destId . '/' . rawurlencode($dateYmd);
            if ($returnYmd !== '') {
                $listingUrl .= '/' . rawurlencode($returnYmd);
            }
            $listingUrl .= '/' . $adults . '/' . $children . '/' . $infants . $extrasQuery;
        }

        $subtitle = $searchable
            ? ($fromName . ' → ' . $toName . ' · ' . date('d-m-Y', strtotime($dateYmd))
                . ($returnYmd !== '' ? (' · return ' . date('d-m-Y', strtotime($returnYmd))) : ''))
            : 'Name both ferry ports (from the operator catalog) to search sailings';

        return [
            'module' => 'ferries',
            'badge' => 'AI FERRY',
            'title' => $searchable ? ('Ferry — ' . $fromName . ' → ' . $toName) : 'Ferries',
            'subtitle' => $subtitle,
            'searchable' => $searchable,
            'suppliers' => $ferrySuppliers,
            'empty_message' => $emptyMessage,
            'empty_detail' => $emptyDetail,
            'params' => [
                'departure_port_id' => (string)$depId,
                'destination_port_id' => (string)$destId,
                'departure_port_name' => $fromName,
                'destination_port_name' => $toName,
                'date' => $dateYmd,
                'return_date' => $returnYmd,
                'trip_type' => $tripType,
                'adults' => (string)$adults,
                'children' => (string)$children,
                'infant' => (string)$infants,
                'vehicles' => (string)$vehicles,
                'vehicle_type' => $vehicleType,
                'pets' => (string)$pets,
                'pet_type' => $petType,
                'bonuses' => [],
                'currency' => $currency,
                'route_services' => [
                    'vehicles' => !empty($routeServices['vehicles']),
                    'pets' => !empty($routeServices['pets']),
                ],
            ],
            'listing_url' => $listingUrl,
            // 0 = no artificial AI cap
            'items_limit' => 0,
        ];
    }

    private function normalizeFerryVehicleType(string $raw): string
    {
        $t = strtolower(trim($raw));
        if ($t === '') {
            return '';
        }
        if (str_contains($t, 'van') || str_contains($t, 'camper')) {
            return 'van';
        }
        if (str_contains($t, 'moped') || str_contains($t, 'scooter')) {
            return 'moped';
        }
        if (str_contains($t, 'motorcycle') || str_contains($t, 'motorbike') || str_contains($t, 'motor')) {
            return 'motorcycle';
        }
        if (str_contains($t, 'bicycle') || str_contains($t, 'bike') || str_contains($t, 'cycle')) {
            return 'bicycle';
        }
        if (str_contains($t, 'car') || str_contains($t, 'tourism') || str_contains($t, 'vehicle')) {
            return 'car';
        }
        return '';
    }

    private function normalizeFerryPetType(string $raw): string
    {
        $t = strtolower(trim($raw));
        if ($t === '') {
            return '';
        }
        if (str_contains($t, 'large')) {
            return 'large_cage';
        }
        if (str_contains($t, 'medium')) {
            return 'medium_cage';
        }
        if (str_contains($t, 'carrier') || str_contains($t, 'pet') || str_contains($t, 'dog') || str_contains($t, 'cat')) {
            return 'carrier';
        }
        return '';
    }

    private function extractFerryOriginHint(string $q, string $originCity, string $busOrigin): string
    {
        // Prefer "ferry from A to B" so we do not swallow the destination into origin.
        if (preg_match('/\b(?:ferry|ferries|sailing|crossing)\b.{0,40}?\b(?:from|depart(?:ing)?)\s+([A-Za-z][A-Za-z\s\-]{1,40}?)\s+(?:to|→|->|towards)\b/i', $q, $m)) {
            return $this->sanitizePlaceName($m[1]);
        }
        if (preg_match('/\b([A-Za-z][A-Za-z\s\-]{1,40}?)\s+(?:to|→|->)\s+([A-Za-z][A-Za-z\s\-]{1,40}?).{0,30}\b(?:ferry|ferries|sailing|crossing)\b/i', $q, $m)) {
            return $this->sanitizePlaceName($m[1]);
        }
        $ports = $this->ferryPortsCatalog();
        foreach ([$busOrigin, $originCity] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $this->matchFerryPort($candidate, $ports)) {
                return $candidate;
            }
        }
        return '';
    }

    private function extractFerryDestinationHint(string $q, string $destCity, string $hint, string $busDest): string
    {
        if (preg_match('/\b(?:ferry|ferries|sailing|crossing)\b.{0,40}?\b(?:from|depart(?:ing)?)\s+[A-Za-z][A-Za-z\s\-]{1,40}?\s+(?:to|→|->|towards)\s+([A-Za-z][A-Za-z\s\-]{1,40}?)(?:\s+(?:next|on|for|with|and|,|\.|$)|$)/i', $q, $m)) {
            return $this->sanitizePlaceName($m[1]);
        }
        if (preg_match('/\b(?:ferry|ferries|sailing|crossing)\b.{0,40}?\b(?:to|towards)\s+([A-Za-z][A-Za-z\s\-]{1,40}?)(?:\s+(?:next|on|for|with|and|from|,|\.|$)|$)/i', $q, $m)) {
            return $this->sanitizePlaceName($m[1]);
        }
        if (preg_match('/\b([A-Za-z][A-Za-z\s\-]{1,40}?)\s+(?:to|→|->)\s+([A-Za-z][A-Za-z\s\-]{1,40}?).{0,30}\b(?:ferry|ferries|sailing|crossing)\b/i', $q, $m)) {
            return $this->sanitizePlaceName($m[2]);
        }
        $ports = $this->ferryPortsCatalog();
        foreach ([$busDest, $destCity, $hint] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $this->matchFerryPort($candidate, $ports)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Match a Kikoto port by id, code, name, aliases (Tanger↔Tangier), then loose contains.
     * Prefer the longest / most specific name so "Tangier Med" wins over "Tangier".
     *
     * @param array<int,array<string,mixed>> $ports
     * @return array{id:int,name:string,code:string,country:string}|null
     */
    private function matchFerryPort(string $raw, array $ports): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || $ports === []) {
            return null;
        }
        $normalize = static function (array $p): array {
            return [
                'id' => (int)($p['id'] ?? 0),
                'name' => (string)($p['name'] ?? ''),
                'code' => (string)($p['code'] ?? ''),
                'country' => (string)($p['country'] ?? ''),
            ];
        };

        if (ctype_digit($raw)) {
            foreach ($ports as $p) {
                if ((int)($p['id'] ?? 0) === (int)$raw) {
                    return $normalize($p);
                }
            }
            return null;
        }

        $needles = $this->ferryPortMatchNeedles($raw);
        foreach ($ports as $p) {
            $code = mb_strtolower((string)($p['code'] ?? ''));
            if ($code !== '' && in_array($code, $needles, true)) {
                return $normalize($p);
            }
        }
        foreach ($ports as $p) {
            $name = mb_strtolower(trim((string)($p['name'] ?? '')));
            if ($name !== '' && in_array($name, $needles, true)) {
                return $normalize($p);
            }
        }

        // Loose contains — prefer the longest catalog name (Tangier Med > Tangier).
        $best = null;
        $bestLen = -1;
        foreach ($ports as $p) {
            $name = mb_strtolower(trim((string)($p['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $nameVariants = $this->ferryPortMatchNeedles($name);
            $hit = false;
            foreach ($needles as $needle) {
                if (mb_strlen($needle) < 3) {
                    continue;
                }
                foreach ($nameVariants as $nv) {
                    if (str_contains($nv, $needle) || str_contains($needle, $nv)) {
                        $hit = true;
                        break 2;
                    }
                }
            }
            if ($hit) {
                $len = mb_strlen($name);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $normalize($p);
                }
            }
        }
        return $best;
    }

    /**
     * Expand common ferry place-name spellings (LLM/user ↔ Kikoto catalog).
     *
     * @return list<string> lowercased unique needles
     */
    private function ferryPortMatchNeedles(string $raw): array
    {
        $base = mb_strtolower(trim(preg_replace('/\s+/', ' ', $raw) ?? $raw));
        if ($base === '') {
            return [];
        }
        $out = [$base];

        // Tanger (ES/FR) ↔ Tangier (EN); Alcudia ↔ Alcúdia accents stripped in compare via variants
        $aliasPairs = [
            'tanger' => 'tangier',
            'tangier' => 'tanger',
            'alcudia' => 'alcúdia',
            'alcúdia' => 'alcudia',
            'seté' => 'sete',
            'sete' => 'sète',
            'sète' => 'sete',
        ];
        foreach ($aliasPairs as $from => $to) {
            if (str_contains($base, $from)) {
                $out[] = str_replace($from, $to, $base);
            }
        }

        // Accent-stripped form
        $stripped = $base;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
            if (is_string($converted) && $converted !== '') {
                $stripped = mb_strtolower($converted);
                $out[] = $stripped;
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * Real Kikoto arrival port names reachable from a departure (for empty-state hints).
     *
     * @param array<int,array<string,mixed>> $routes
     * @param array<int,array<string,mixed>> $ports
     * @return list<string>
     */
    private function ferryArrivalSuggestions(int $depId, array $routes, array $ports, int $limit = 5): array
    {
        if ($depId <= 0 || $routes === [] || $ports === []) {
            return [];
        }
        $byId = [];
        foreach ($ports as $p) {
            $id = (int)($p['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = (string)($p['name'] ?? '');
            }
        }
        $names = [];
        foreach ($routes as $r) {
            if ((int)($r['departure_port_id'] ?? 0) !== $depId) {
                continue;
            }
            $destId = (int)($r['destination_port_id'] ?? 0);
            $name = trim((string)($byId[$destId] ?? ''));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
            if (count($names) >= $limit) {
                break;
            }
        }
        return $names;
    }

    /**
     * @param array<int,array<string,mixed>> $routes
     * @param array<int,array<string,mixed>> $ports
     */
    private function ferryExampleArrivalName(int $depId, array $routes, array $ports): string
    {
        $suggestions = $this->ferryArrivalSuggestions($depId, $routes, $ports, 1);
        if ($suggestions !== []) {
            return $suggestions[0];
        }
        foreach ($ports as $p) {
            $name = trim((string)($p['name'] ?? ''));
            if ($name !== '' && stripos($name, 'Tangier Med') !== false) {
                return $name;
            }
        }
        return '';
    }

    /**
     * Kikoto ports from the shared file cache, refreshed from the API when stale.
     *
     * @return array<int,array<string,mixed>>
     */
    private function ferryPortsCatalog(): array
    {
        if ($this->ferryPorts !== null) {
            return $this->ferryPorts;
        }
        $this->ferryPorts = $this->ferryCatalog('kikoto_ports_en.json', '/ports', static function (array $row): ?array {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                return null;
            }
            return [
                'id' => $id,
                'name' => (string)($row['name'] ?? ''),
                'code' => (string)($row['code'] ?? ''),
                'country' => (string)($row['country'] ?? ''),
            ];
        });
        return $this->ferryPorts;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function ferryRoutesCatalog(): array
    {
        if ($this->ferryRoutes !== null) {
            return $this->ferryRoutes;
        }
        $this->ferryRoutes = $this->ferryCatalog('kikoto_routes.json', '/routes', static function (array $row): ?array {
            $dep = (int)($row['departure_port_id'] ?? 0);
            $dest = (int)($row['destination_port_id'] ?? 0);
            if ($dep <= 0 || $dest <= 0) {
                return null;
            }
            return [
                'id' => (int)($row['id'] ?? 0),
                'departure_port_id' => $dep,
                'destination_port_id' => $dest,
                'name' => (string)($row['name'] ?? ''),
                'services' => is_array($row['services'] ?? null) ? $row['services'] : [],
            ];
        });
        return $this->ferryRoutes;
    }

    /**
     * Shared loader for the Kikoto port / route file caches (same files the web API writes).
     *
     * @return array<int,array<string,mixed>>
     */
    private function ferryCatalog(string $cacheName, string $path, callable $mapper): array
    {
        $cacheFile = dirname(__DIR__, 3) . '/app/cache/' . $cacheName;
        $rows = [];
        if (is_file($cacheFile)) {
            $decoded = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($decoded)) {
                $rows = $decoded;
            }
        }

        $stale = $rows === [] || !is_file($cacheFile) || (time() - (int)@filemtime($cacheFile)) >= 3600;
        if ($stale) {
            try {
                if (!function_exists('_kikoto_request')) {
                    require_once dirname(__DIR__, 3) . '/modules/ferries/kikoto/api.php';
                }
                $cfg = _kikoto_cfg($this->db);
                if (!empty($cfg) && ($cfg['status'] ?? '0') !== '0') {
                    $res = _kikoto_request('GET', $path, ['cfg' => $cfg, 'lang' => 'en', 'timeout' => 15]);
                    if (!empty($res['ok']) && is_array($res['data']['data'] ?? null)) {
                        $rows = $res['data']['data'];
                        @file_put_contents($cacheFile, json_encode($rows), LOCK_EX);
                    }
                }
            } catch (\Throwable $e) {
                // Keep whatever the cache had — ferries stay non-searchable when empty.
            }
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = $mapper($row);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }
        return $out;
    }

    /**
     * Active umrah_settings id only; inactive/unknown → '' + note.
     * @param string|string[] $types
     * @param string[] $notes
     */
    private function resolveUmrahSettingId(string $raw, $types, array &$notes): string
    {
        $raw = trim($raw);
        if ($raw === '' || strcasecmp($raw, 'any') === 0) {
            return '';
        }
        try {
            if (ctype_digit($raw)) {
                $row = $this->db->get('umrah_settings', ['id', 'status'], [
                    'id' => (int)$raw,
                    'setting_type' => $types,
                ]);
                if ($row && (int)($row['status'] ?? 0) === 1) {
                    return (string)$row['id'];
                }
                $notes[] = 'A selected filter is unavailable';
                return '';
            }
            $rows = $this->db->select('umrah_settings', ['id', 'setting_label', 'metadata'], [
                'setting_type' => $types,
                'status' => 1,
            ]) ?: [];
            $needle = strtolower($raw);
            foreach ($rows as $r) {
                if (strtolower((string)($r['setting_label'] ?? '')) === $needle) {
                    return (string)$r['id'];
                }
                $m = json_decode((string)($r['metadata'] ?? ''), true);
                if (is_array($m) && strtolower((string)($m['code'] ?? '')) === $needle) {
                    return (string)$r['id'];
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $notes[] = 'Filter "' . $raw . '" not found — showing all';
        return '';
    }

    /**
     * Resolve country ISO + name from ISO, country name, city, or alias (countries table).
     * @return array{iso:string,name:string}
     */
    private function resolveWorldCountryIso(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['iso' => '', 'name' => ''];
        }

        $alias = $this->normalizeCountryAlias($raw);
        if ($alias !== null) {
            $row = $this->lookupWorldCountry($alias['iso'], $alias['name']);
            return $row ?? $alias;
        }

        if (preg_match('/^[A-Za-z]{2}$/', $raw)) {
            $row = $this->lookupWorldCountry(strtoupper($raw), '');
            if ($row !== null) {
                return $row;
            }
        }

        $row = $this->lookupWorldCountry('', $raw);
        if ($row !== null) {
            return $row;
        }

        $fromCity = $this->lookupCountryFromCity($raw);
        if ($fromCity !== null) {
            return $fromCity;
        }

        return ['iso' => '', 'name' => $raw];
    }

    /**
     * @return array{iso:string,name:string}|null
     */
    private function lookupWorldCountry(string $iso, string $name): ?array
    {
        try {
            if ($iso !== '' && preg_match('/^[A-Z]{2}$/', strtoupper($iso))) {
                $c = $this->db->get('countries', ['iso', 'nicename'], [
                    'iso' => strtoupper($iso),
                    'status' => 'active',
                ]);
                if (is_array($c) && !empty($c['iso'])) {
                    return [
                        'iso' => strtoupper((string)$c['iso']),
                        'name' => (string)($c['nicename'] ?? strtoupper((string)$c['iso'])),
                    ];
                }
            }
            $name = trim($name);
            if ($name !== '') {
                $c = $this->db->get('countries', ['iso', 'nicename'], [
                    'nicename' => $name,
                    'status' => 'active',
                ]);
                if (!$c && mb_strlen($name) >= 4) {
                    $c = $this->db->get('countries', ['iso', 'nicename'], [
                        'nicename[~]' => $name,
                        'status' => 'active',
                    ]);
                }
                if (is_array($c) && !empty($c['iso'])) {
                    return [
                        'iso' => strtoupper((string)$c['iso']),
                        'name' => (string)($c['nicename'] ?? strtoupper((string)$c['iso'])),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    /**
     * Resolve eSIM country ISO + display name from AI field, city hint, or query.
     * @return array{iso:string,name:string}
     */
    private function resolveEsimCountry(string $esimCountry, string $placeHint, string $query = ''): array
    {
        $candidates = [];
        foreach ([$esimCountry, $placeHint] as $c) {
            $c = trim($c);
            if ($c !== '') {
                $candidates[] = $c;
            }
        }
        if (preg_match(
            '/\b(?:esim|e-?\s?sim|data\s*sim|sim\s*card|travel\s*sim|tourist\s*sim|data\s*(?:plan|package)|mobile\s*data|roaming)'
                . '\s+(?:for|in|to|at)?\s*([A-Za-z][A-Za-z\s\-]{1,40})/i',
            $query,
            $m
        )) {
            $tok = $this->esimPlaceToken(preg_replace('/\b(next|this|also|and|,|flight|hotel).*$/i', '', $m[1]) ?? $m[1]);
            if ($tok !== '') {
                array_unshift($candidates, $tok);
            }
        }

        foreach ($candidates as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            // Common aliases before fuzzy DB lookups (UAE ⊂ Tabuaeran → Kiribati via LIKE)
            $alias = $this->normalizeCountryAlias($raw);
            if ($alias !== null) {
                $row = $this->lookupAiraloCountry($alias['iso'], $alias['name']);
                if ($row !== null) {
                    return $row;
                }
                // Alias without an active Airalo country — keep searching other candidates.
                continue;
            }
            // Direct ISO
            if (preg_match('/^[A-Za-z]{2}$/', $raw)) {
                $iso = strtoupper($raw);
                $row = $this->lookupAiraloCountry($iso, '');
                if ($row !== null) {
                    return $row;
                }
            }
            // Country name / nicename (exact / prefix — avoid short LIKE false positives)
            $byName = $this->lookupAiraloCountry('', $raw);
            if ($byName !== null) {
                return $byName;
            }
            // City → country via locations table (min length to avoid LIKE '%uae%' → Tabuaeran)
            if (mb_strlen($raw) >= 3) {
                $fromCity = $this->lookupCountryFromCity($raw);
                if ($fromCity !== null) {
                    $row = $this->lookupAiraloCountry($fromCity['iso'], $fromCity['name']);
                    if ($row !== null) {
                        return $row;
                    }
                }
            }
        }

        return ['iso' => '', 'name' => ''];
    }

    /**
     * Map common country shortcuts to ISO + display name.
     * @return array{iso:string,name:string}|null
     */
    private function normalizeCountryAlias(string $raw): ?array
    {
        $key = strtoupper(preg_replace('/[^A-Za-z]/', '', $raw) ?? '');
        $map = [
            'UAE' => ['iso' => 'AE', 'name' => 'United Arab Emirates'],
            'UK' => ['iso' => 'GB', 'name' => 'United Kingdom'],
            'USA' => ['iso' => 'US', 'name' => 'United States'],
            'US' => ['iso' => 'US', 'name' => 'United States'],
            'KSA' => ['iso' => 'SA', 'name' => 'Saudi Arabia'],
            'SOUTHKOREA' => ['iso' => 'KR', 'name' => 'Korea, Republic of'],
            'NORTHKOREA' => ['iso' => 'KP', 'name' => "Korea, Democratic People's Republic of"],
            'HOLLAND' => ['iso' => 'NL', 'name' => 'Netherlands'],
            'ENGLAND' => ['iso' => 'GB', 'name' => 'United Kingdom'],
            'SCOTLAND' => ['iso' => 'GB', 'name' => 'United Kingdom'],
            'WALES' => ['iso' => 'GB', 'name' => 'United Kingdom'],
        ];
        return $map[$key] ?? null;
    }

    /**
     * @return array{iso:string,name:string}|null
     */
    private function lookupAiraloCountry(string $iso, string $name): ?array
    {
        try {
            if ($iso !== '' && preg_match('/^[A-Z]{2}$/', $iso)) {
                $row = $this->db->get('airalo_countries', ['iso', 'nicename', 'status'], [
                    'iso' => $iso,
                ]);
                if (is_array($row) && (int) ($row['status'] ?? 0) === 1) {
                    return [
                        'iso' => strtoupper((string) $row['iso']),
                        'name' => (string) ($row['nicename'] ?? $iso),
                    ];
                }
            }
            $name = trim($name);
            if ($name === '') {
                return null;
            }
            // Prefer exact / case-insensitive equality before fuzzy LIKE (short tokens like
            // "AE" match "Israel", "UAE" is handled via aliases).
            $exact = $this->db->get('airalo_countries', ['iso', 'nicename', 'status'], [
                'nicename' => $name,
                'status' => 1,
            ]);
            if (!is_array($exact) || empty($exact['iso'])) {
                $exact = $this->db->get('airalo_countries', ['iso', 'nicename', 'status'], [
                    'nicename[~]' => '^' . preg_quote($name, '/') . '$',
                    'status' => 1,
                ]);
            }
            // Medoo may not support regex anchors — try plain equality via SQL if needed
            if ((!is_array($exact) || empty($exact['iso'])) && mb_strlen($name) >= 4) {
                $exact = $this->db->get('airalo_countries', ['iso', 'nicename', 'status'], [
                    'nicename[~]' => $name,
                    'status' => 1,
                    'ORDER' => ['nicename' => 'ASC'],
                ]);
                // Reject weak substring hits shorter than the needle (e.g. AE ⊂ Israel)
                if (is_array($exact) && !empty($exact['nicename'])) {
                    $nn = (string) $exact['nicename'];
                    if (stripos($nn, $name) === false && strcasecmp($nn, $name) !== 0) {
                        $exact = null;
                    }
                }
            }
            $row = (is_array($exact) && !empty($exact['iso'])) ? $exact : null;
            if (!is_array($row) || empty($row['iso'])) {
                // Exact-ish match on countries table then map to airalo
                $c = null;
                if (mb_strlen($name) >= 4) {
                    $c = $this->db->get('countries', ['iso', 'nicename'], [
                        'OR' => [
                            'nicename[~]' => $name,
                            'name[~]' => $name,
                        ],
                    ]);
                }
                if ((!is_array($c) || empty($c['iso']))) {
                    $c = $this->db->get('countries', ['iso', 'nicename'], [
                        'OR' => [
                            'nicename' => $name,
                            'name' => $name,
                        ],
                    ]);
                }
                if (is_array($c) && !empty($c['iso'])) {
                    return $this->lookupAiraloCountry(strtoupper((string) $c['iso']), (string) ($c['nicename'] ?? ''));
                }
                return null;
            }
            return [
                'iso' => strtoupper((string) $row['iso']),
                'name' => (string) ($row['nicename'] ?? $name),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{iso:string,name:string}|null
     */
    private function lookupCountryFromCity(string $city): ?array
    {
        $city = trim($city);
        if ($city === '') {
            return null;
        }
        try {
            $loc = $this->db->get('locations', ['city', 'country', 'country_code'], [
                'status' => '1',
                'city' => $city,
                'ORDER' => ['city' => 'ASC'],
            ]);
            if (!is_array($loc)) {
                // Fuzzy only for reasonably long names; short tokens false-match (UAE→Tabuaeran)
                if (mb_strlen($city) < 4) {
                    return null;
                }
                $loc = $this->db->get('locations', ['city', 'country', 'country_code'], [
                    'status' => '1',
                    'city[~]' => $city,
                    'ORDER' => ['city' => 'ASC'],
                ]);
            }
            if (!is_array($loc)) {
                return null;
            }
            // Reject weak substring matches (needle much shorter than matched city)
            $matchedCity = trim((string) ($loc['city'] ?? ''));
            if ($matchedCity !== '' && strcasecmp($matchedCity, $city) !== 0
                && stripos($matchedCity, $city) !== false
                && mb_strlen($city) < 4) {
                return null;
            }
            $iso = strtoupper(trim((string) ($loc['country_code'] ?? '')));
            $name = trim((string) ($loc['country'] ?? ''));
            if ($iso === '' && $name === '') {
                return null;
            }
            if ($iso !== '' && !preg_match('/^[A-Z]{2}$/', $iso)) {
                $iso = '';
            }
            return ['iso' => $iso, 'name' => $name !== '' ? $name : $iso];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param string[] $suppliers
     * @return array<string,mixed>
     */
    private function buildGenericSearch(string $module, string $hint, array $suppliers): array
    {
        $label = $this->moduleLabel($module);
        return [
            'module' => $module,
            'badge' => 'AI ' . strtoupper($label) . ' GUIDE',
            'title' => $hint !== '' ? ($label . ' — ' . $hint) : $label,
            'subtitle' => 'Open the ' . strtolower($label) . ' module to continue.',
            'searchable' => false,
            'suppliers' => $suppliers,
            'params' => (object) ['hint' => $hint],
            'listing_url' => '',
            'items_limit' => 0,
        ];
    }

    /**
     * @param string[] $modules
     * @param list<array<string,mixed>> $searches
     */
    private function buildSummary(array $modules, array $searches): string
    {
        if ($searches === []) {
            return 'No live searches could be prepared for your request.';
        }
        $parts = [];
        foreach ($searches as $s) {
            $parts[] = (string) ($s['title'] ?? $this->moduleLabel((string) ($s['module'] ?? '')));
        }
        return 'Showing live results for ' . $this->joinLabels($parts) . '.';
    }

    /**
     * @return string[]
     */
    private function activeSuppliers(string $moduleType): array
    {
        try {
            $rows = $this->db->select('modules', ['name'], [
                'type' => $moduleType,
                'status' => 1,
                'active' => 1,
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }
        $names = [];
        foreach ($rows as $row) {
            $name = strtolower(trim((string) ($row['name'] ?? '')));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * True when the traveler's text names this place (city / airport label).
     * Used to reject LLM-invented origins (e.g. ADV) on destination-only prompts.
     */
    private function queryMentionsPlace(string $query, string $place): bool
    {
        $place = $this->sanitizePlaceName($place);
        if ($place === '' || strlen($place) < 2) {
            return false;
        }
        if (preg_match('/\b' . preg_quote($place, '/') . '\b/iu', $query)) {
            return true;
        }
        // Multi-word: require the full phrase, or all significant tokens
        $tokens = preg_split('/\s+/', strtolower($place)) ?: [];
        $tokens = array_values(array_filter($tokens, static function ($t) {
            return strlen((string) $t) >= 3 && !in_array($t, ['the', 'and', 'airport', 'intl', 'international'], true);
        }));
        if (count($tokens) >= 2) {
            $q = strtolower($query);
            foreach ($tokens as $t) {
                if (!preg_match('/\b' . preg_quote($t, '/') . '\b/u', $q)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    private function queryMentionsIata(string $query, string $code): bool
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            return false;
        }
        return (bool) preg_match('/\b' . preg_quote($code, '/') . '\b/i', $query);
    }

    /** City label for an IATA from flights_airports (empty when unknown). */
    private function airportCityLabel(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            return '';
        }
        try {
            $row = $this->db->get('flights_airports', ['city', 'airport'], [
                'code' => $code,
                'LIMIT' => 1,
            ]);
            if (!is_array($row)) {
                return '';
            }
            $city = $this->sanitizePlaceName((string) ($row['city'] ?? ''));
            if ($city !== '') {
                return $city;
            }
            return $this->sanitizePlaceName((string) ($row['airport'] ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function resolveAirportCode(string $code, string $city): ?string
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{3}$/', $code)) {
            return $code;
        }

        $city = $this->sanitizePlaceName($city);
        if ($city === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z]{3}$/', $city)) {
            return strtoupper($city);
        }

        $aliases = $this->cityAirportAliases();
        $key = strtolower($city);
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        // Match known city names inside longer messy strings.
        uksort($aliases, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
        foreach ($aliases as $name => $iata) {
            if (preg_match('/\b' . preg_quote((string) $name, '/') . '\b/i', $city)) {
                return $iata;
            }
        }

        try {
            // Exact / prefix city or airport name only — never guess IATA from the first
            // 3 letters of a longer word (e.g. "best" → BES).
            $row = $this->db->get('flights_airports', ['code', 'city'], [
                'OR' => [
                    'city[~]' => $city,
                    'airport[~]' => $city,
                ],
                'LIMIT' => 1,
            ]);
            if (is_array($row) && !empty($row['code'])) {
                $matchedCity = strtolower(trim((string) ($row['city'] ?? '')));
                $matchedAirport = strtolower(trim((string) ($row['airport'] ?? '')));
                $needle = strtolower($city);
                // Require a real place match — reject accidental substring hits on filler words.
                $isRealPlace = $matchedCity === $needle
                    || $matchedAirport === $needle
                    || str_starts_with($matchedCity, $needle)
                    || str_contains($matchedCity, $needle)
                    || str_contains($matchedAirport, $needle);
                if ($isRealPlace && strlen($needle) >= 3) {
                    return strtoupper((string) $row['code']);
                }
            }

            // Token fallback: try each significant word against city column.
            $tokens = preg_split('/\s+/', $city) ?: [];
            foreach ($tokens as $token) {
                $token = trim($token);
                if (strlen($token) < 4) {
                    continue;
                }
                if ($this->isPlaceNoiseToken($token)) {
                    continue;
                }
                $row = $this->db->get('flights_airports', ['code', 'city'], [
                    'city[~]' => $token,
                    'LIMIT' => 1,
                ]);
                if (is_array($row) && !empty($row['code'])) {
                    $matchedCity = strtolower(trim((string) ($row['city'] ?? '')));
                    if ($matchedCity === strtolower($token) || str_starts_with($matchedCity, strtolower($token))) {
                        return strtoupper((string) $row['code']);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore DB miss
        }

        return null;
    }

    private function isPlaceNoiseToken(string $token): bool
    {
        $t = strtolower(trim($token));
        return in_array($t, [
            'best', 'better', 'good', 'great', 'cheap', 'cheapest', 'affordable', 'budget',
            'lowest', 'top', 'available', 'option', 'options', 'deal', 'deals', 'ticket',
            'tickets', 'flight', 'flights', 'fly', 'find', 'search', 'book', 'any', 'some',
            'help', 'recommendation', 'recommendations',
        ], true);
    }

    /**
     * @return array<string,string>
     */
    private function cityAirportAliases(): array
    {
        return [
            'lahore' => 'LHE',
            'dubai' => 'DXB',
            'abu dhabi' => 'AUH',
            'karachi' => 'KHI',
            'islamabad' => 'ISB',
            'rawalpindi' => 'ISB',
            'peshawar' => 'PEW',
            'multan' => 'MUX',
            'sialkot' => 'SKT',
            'faisalabad' => 'LYP',
            'jeddah' => 'JED',
            'riyadh' => 'RUH',
            'dammam' => 'DMM',
            'doha' => 'DOH',
            'london' => 'LHR',
            'new york' => 'JFK',
            'istanbul' => 'IST',
            'cairo' => 'CAI',
            'bangkok' => 'BKK',
            'singapore' => 'SIN',
            'kuala lumpur' => 'KUL',
            'paris' => 'CDG',
            'madrid' => 'MAD',
            'barcelona' => 'BCN',
            'rome' => 'FCO',
            'toronto' => 'YYZ',
            'manchester' => 'MAN',
            'muscat' => 'MCT',
            'sharjah' => 'SHJ',
            'bahrain' => 'BAH',
            'kuwait' => 'KWI',
            'tehran' => 'IKA',
            'delhi' => 'DEL',
            'mumbai' => 'BOM',
            'beijing' => 'PEK',
            'tokyo' => 'NRT',
            'orlando' => 'MCO',
        ];
    }

    private function toDisplayDate(string $value): string
    {
        $fallbackTs = strtotime($this->defaultTravelDateYmd());
        $value = trim($value);
        if ($value === '') {
            return date('d-m-Y', $fallbackTs);
        }

        $ts = false;
        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $value)) {
            $parts = explode('-', $value);
            $ts = strtotime($parts[2] . '-' . $parts[1] . '-' . $parts[0]);
        } else {
            $ts = strtotime($value);
        }

        if ($ts === false) {
            return date('d-m-Y', $fallbackTs);
        }

        // Never search past dates — bump to today (same as the normal flights form).
        $todayStart = strtotime('today');
        if ($ts < $todayStart) {
            $ts = $todayStart;
        }

        return date('d-m-Y', $ts);
    }

    private function toIsoDate(string $displayDate): string
    {
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $displayDate, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $ts = strtotime($displayDate);
        return $ts ? date('Y-m-d', $ts) : $this->defaultTravelDateYmd();
    }

    /**
     * Default start date when the traveler did not name any date/window.
     * Match the normal flights search form: today is selectable, so default to today.
     */
    private function defaultTravelDateYmd(): string
    {
        return date('Y-m-d');
    }

    private function flightTitle(string $from, string $to): string
    {
        return trim($from) . ' to ' . trim($to) . ' Flights';
    }

    private function extractHint(string $query): string
    {
        $flightDest = $this->extractFlightDestinationOnlyHint($query);
        if ($flightDest !== '') {
            return $flightDest;
        }
        $tripDest = $this->extractTripDestinationHint($query);
        if ($tripDest !== '') {
            return $tripDest;
        }
        if (preg_match('/\b(?:to|in|for|from)\s+([A-Za-z][A-Za-z\s\-]{1,40})$/i', $query, $m)) {
            return trim($m[1]);
        }
        $hint = preg_replace(
            '/\b(flights?|fly|hotels?|stays?|cars?|tours?|visa|umrah|esim|e-?sim|cruises?|ferries?|bus(es)?|'
            . 'find|search|book|me|a|an|the|to|in|for|from|next|week|tomorrow|today|'
            . 'round|trip|one[\s-]?way|departure|return|date|dates|need|want|looking|'
            . 'best|better|good|great|cheap|cheapest|affordable|budget|lowest|top|deal|deals|'
            . 'ticket|tickets|options?|recommendations?|available|any|some|help|'
            . 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|'
            . 'aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|'
            . 'and|on|\d+)\b/i',
            ' ',
            $query
        );
        return trim(preg_replace('/\s+/', ' ', (string) $hint) ?? '');
    }

    private function moduleLabel(string $module): string
    {
        return match ($module) {
            'flights' => 'Flights',
            'stays' => 'Stays',
            'cars' => 'Cars',
            'tours' => 'Tours',
            'visa' => 'Visa',
            'umrah' => 'Umrah',
            'esim' => 'eSIM',
            'cruises' => 'Cruises',
            'ferries' => 'Ferries',
            'bus' => 'Bus',
            default => ucfirst($module),
        };
    }

    /**
     * @param string[] $labels
     */
    private function joinLabels(array $labels): string
    {
        $labels = array_values($labels);
        $n = count($labels);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $labels[0];
        }
        if ($n === 2) {
            return $labels[0] . ' and ' . $labels[1];
        }
        $last = array_pop($labels);
        return implode(', ', $labels) . ', and ' . $last;
    }
}
