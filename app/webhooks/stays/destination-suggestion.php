<?php
/**
 * =============================================================================
 * DESTINATION SUGGESTION WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire when users search for hotel destinations/cities.
 * Use them to track search patterns, popular destinations, and user interests.
 * 
 * AVAILABLE EVENTS:
 * 1. stays.destination.searched - When user types in destination field
 * 2. stays.destination.results_found - When suggestions are returned
 * 3. stays.destination.selected - When user selects a destination
 * 
 * =============================================================================
 */

/**
 * WEBHOOK: stays.destination.searched
 * Triggers when user searches for a destination (autocomplete)
 * 
 * @param array $data Search query information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - query: Search query string
 * - results_count: Number of results found
 * - hotels_found: Number of hotel matches
 * - cities_found: Number of city matches
 * - timestamp: Search timestamp
 * - user_id: User ID if logged in (optional)
 * - session_id: Session identifier
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/destination-suggestion', 'stays.destination.searched', $data);

// EXAMPLE 1: Track Popular Destinations
// -------------------------------------------------------------------
// Identify trending destinations and search patterns
// 
// if (!empty($data['query'])) {
//     // Send to analytics
//     trackEvent('destination_searched', [
//         'query' => $data['query'],
//         'results_count' => $data['results_count'],
//         'hotels_found' => $data['hotels_found'],
//         'cities_found' => $data['cities_found']
//     ]);
//     
//     // Track in Mixpanel for pattern analysis
//     $mixpanel->track('Destination Search', [
//         'query' => $data['query'],
//         'has_results' => $data['results_count'] > 0,
//         'result_type' => $data['hotels_found'] > 0 ? 'hotels_and_cities' : 'cities_only'
//     ]);
// }

// EXAMPLE 2: Store Popular Searches
// -------------------------------------------------------------------
// Build database of trending destinations
// 
// $searchTracker->incrementSearchCount([
//     'query' => $data['query'],
//     'timestamp' => $data['timestamp'],
//     'results_found' => $data['results_count']
// ]);

// EXAMPLE 3: Alert Missing Destinations
// -------------------------------------------------------------------
// Notify team when users search for destinations not in database
// 
// if ($data['results_count'] === 0 && strlen($data['query']) >= 3) {
//     sendSlackNotification('#inventory-alerts', [
//         'text' => '🔍 Destination Not Found',
//         'fields' => [
//             'Query' => $data['query'],
//             'User' => $data['user_id'] ?? 'Guest',
//             'Session' => $data['session_id']
//         ]
//     ]);
// }

/**
 * WEBHOOK: stays.destination.results_found
 * Triggers when destination suggestions are successfully returned
 * 
 * @param array $data Results information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - query: Original search query
 * - results: Array of results returned
 * - hotels_count: Number of hotels in results
 * - cities_count: Number of cities in results
 * - total_count: Total results
 * - timestamp: Results timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/destination-suggestion', 'stays.destination.results_found', $data);

// EXAMPLE: Track Search Quality
// -------------------------------------------------------------------
// if ($data['total_count'] > 0) {
//     logSearchQuality([
//         'query' => $data['query'],
//         'results' => $data['total_count'],
//         'hotels' => $data['hotels_count'],
//         'cities' => $data['cities_count']
//     ]);
// }

/**
 * WEBHOOK: stays.destination.selected
 * Triggers when user selects a destination from suggestions (optional - frontend)
 * 
 * @param array $data Selection information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - selected_type: 'hotel' or 'city'
 * - selected_name: Name of destination
 * - selected_id: ID of destination
 * - timestamp: Selection timestamp
 * 
 * NOTE: This would need to be triggered from frontend JavaScript
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/destination-suggestion', 'stays.destination.selected', $data);

// EXAMPLE: Track Selection Preferences
// -------------------------------------------------------------------
// Understand if users prefer direct hotel selection vs city browsing
// 
// trackEvent('destination_selected', [
//     'type' => $data['selected_type'],
//     'name' => $data['selected_name']
// ]);

// =============================================================================
// WEBHOOK RESPONSE - Always return status array
// =============================================================================
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];
