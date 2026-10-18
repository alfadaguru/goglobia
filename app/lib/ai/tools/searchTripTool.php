<?php

namespace App\lib\ai;

/**
 * GIA read-only tool: search live travel offers across the enabled modules.
 *
 * Phase A wires GIA to the EXISTING trip-search engine (aiSearchService) rather
 * than to any new supplier integration — so the agentic loop is proven against
 * what already works, over whatever suppliers the admin has enabled. It is
 * strictly READ-ONLY (BRD §10.5 R0/R1): it builds search intents, it never holds,
 * books, pays or changes any state.
 *
 * Verified contract (app/lib/ai/aiSearchService.php:43):
 *   search(string $query, array $enabledModules, array $departureContext = []): array
 *   returns {status,message,error_code,result,module,modules[],hint,searches[],parser_tier}
 * Enabled modules come from aiTripEnabledModuleTypes($db) (functions.php:11628),
 * the exact source the existing /api/ai/search route uses.
 */
class searchTripTool implements giaToolInterface
{
    public function name(): string
    {
        return 'search_trip';
    }

    public function description(): string
    {
        return 'Search live, bookable travel offers (flights, stays, tours, cars, visa, '
            . 'esim, ferries, rail, bus, umrah — whichever are enabled) from a natural-language '
            . 'trip request. Returns structured search intents and the modules matched. '
            . 'Read-only: this finds options; it never books or charges anything.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The traveler\'s request in natural language, e.g. '
                        . '"2 adults from Abuja to Dubai, Nov 10-17, 4-star hotel near downtown".',
                ],
                'departure_city' => [
                    'type' => 'string',
                    'description' => 'Optional known departure city (do not invent it).',
                ],
                'departure_airport' => [
                    'type' => 'string',
                    'description' => 'Optional 3-letter IATA departure airport code if known.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function riskClass(): string
    {
        return 'R0'; // informational / read-only search
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Search is unavailable.'];
        }

        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '' || mb_strlen($query) < 2) {
            return ['ok' => false, 'error_code' => 'AI_EMPTY_QUERY', 'message' => 'Tell me what trip you want to search.'];
        }

        // Gate exactly like the existing search route.
        if (!function_exists('aiTripIsEnabled') || !\aiTripIsEnabled($db)) {
            return ['ok' => false, 'error_code' => 'AI_DISABLED', 'message' => 'Live trip search is currently disabled.'];
        }

        $enabled = function_exists('aiTripEnabledModuleTypes')
            ? \aiTripEnabledModuleTypes($db)
            : [];

        $departureContext = [];
        $depCity = trim((string) ($input['departure_city'] ?? ''));
        if ($depCity !== '' && mb_strlen($depCity) <= 80) {
            $departureContext['city'] = $depCity;
            $departureContext['source'] = 'explicit';
        }
        $depAirport = strtoupper(trim((string) ($input['departure_airport'] ?? '')));
        if ($depAirport !== '' && preg_match('/^[A-Z]{3}$/', $depAirport)) {
            $departureContext['airport'] = $depAirport;
        }

        try {
            $service = new aiSearchService($db);
            $result = $service->search($query, $enabled, $departureContext);
        } catch (\Throwable $e) {
            error_log('[gia] search_trip error: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_SEARCH_ERROR', 'message' => 'The search engine hit an error. Please try again.'];
        }

        // Normalize aiSearchService's return into the tool_result the model reads.
        // We hand the model a COMPACT summary (not the full raw payload) so it can
        // decide next steps; the full `searches` go to the caller for UI rendering.
        $modules = is_array($result['modules'] ?? null) ? $result['modules'] : [];
        $searches = is_array($result['searches'] ?? null) ? $result['searches'] : [];

        return [
            'ok' => (bool) ($result['status'] ?? false),
            'data' => [
                'status'       => (bool) ($result['status'] ?? false),
                'message'      => (string) ($result['message'] ?? ''),
                'error_code'   => (string) ($result['error_code'] ?? ''),
                'module'       => (string) ($result['module'] ?? ''),
                'modules'      => $modules,
                'hint'         => (string) ($result['hint'] ?? ''),
                'search_count' => count($searches),
                // the agent sees that searches exist + their module/labels; the raw
                // search intents are returned to the HTTP caller for rendering.
                'searches'     => $searches,
            ],
            'message' => (string) ($result['message'] ?? ''),
        ];
    }
}
