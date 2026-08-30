<?php
/**
 * =============================================================================
 * TOURS INVOICE WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during invoice viewing and PDF generation. Use them
 * to track invoice interactions and document generation.
 * 
 * AVAILABLE EVENTS:
 * 1. tours.invoice.viewed - When invoice page is accessed
 * 2. tours.invoice.pdf_generated - When PDF invoice is created
 * 3. tours.invoice.pdf_downloaded - When PDF invoice is downloaded
 * 4. tours.invoice.expired - When booking session expires
 * 
 * =============================================================================
 */

// Handle the webhook event
// Variables available: $event (event name), $data (event data)

// Your custom webhook logic here:
// - Sync with accounting software
// - Track PDF downloads
// - Monitor invoice views
// - etc.

// Example: Log PDF downloads
if ($event === 'tours.invoice.pdf_downloaded') {
}

// Always return a response array
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];