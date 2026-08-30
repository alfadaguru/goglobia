<?php
/**
 * =============================================================================
 * TOURS SEARCH WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the tour search process. Use them to track 
 * user search behavior, popular destinations, and search patterns.
 * 
 * AVAILABLE EVENTS:
 * 1. tours.search.initiated - When user submits a search form
 * 2. tours.search.results_loaded - When search results are displayed
 * 3. tours.search.no_results - When no tours are found for search criteria
 * 
 * =============================================================================
 */

// Handle the webhook event
// Variables available: $event (event name), $data (event data)

// Your custom webhook logic here:
// - Track search patterns
// - Update analytics
// - Monitor popular destinations
// - etc.

// Example: Log search events

// Always return a response array
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];