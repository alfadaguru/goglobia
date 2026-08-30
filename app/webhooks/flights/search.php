<?php
/**
 * =============================================================================
 * FLIGHTS SEARCH WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the flight search process. Use them to track 
 * user search behavior, popular routes, and search patterns.
 * 
 * AVAILABLE EVENTS:
 * 1. flights.search.initiated - When user submits a search form
 * 2. flights.search.results_loaded - When search results are displayed
 * 3. flights.search.no_results - When no flights are found for search criteria
 * 
 * =============================================================================
 */

// Handle the webhook event
// Variables available: $event (event name), $data (event data)

// Your custom webhook logic here:
// - Track search patterns
// - Update analytics
// - Monitor popular routes
// - etc.

// Example: Log search events

// Always return a response array
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];