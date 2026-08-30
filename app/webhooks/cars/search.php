<?php
/**
 * =============================================================================
 * CARS SEARCH WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the car rental search process. Use them to track 
 * user search behavior, popular locations, and search patterns.
 * 
 * AVAILABLE EVENTS:
 * 1. cars.search.initiated - When user submits a search form
 * 2. cars.search.results_loaded - When search results are displayed
 * 3. cars.search.no_results - When no cars are found for search criteria
 * 
 * =============================================================================
 */

// Handle the webhook event
// Variables available: $event (event name), $data (event data)

// Your custom webhook logic here:
// - Track search patterns
// - Update analytics
// - Monitor popular locations
// - etc.

// Example: Log search events

// Always return a response array
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];