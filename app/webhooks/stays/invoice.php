<?php
/**
 * =============================================================================
 * STAYS INVOICE WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during invoice viewing and PDF generation. Use them
 * to track invoice interactions and document generation.
 * 
 * AVAILABLE EVENTS:
 * 1. stays.invoice.viewed - When invoice page is accessed
 * 2. stays.invoice.pdf_generated - When PDF invoice is created
 * 3. stays.invoice.pdf_downloaded - When PDF invoice is downloaded
 * 4. stays.invoice.expired - When booking session expires
 * 
 * =============================================================================
 */

/**
 * WEBHOOK: stays.invoice.viewed
 * Triggers when user accesses invoice page
 * 
 * @param array $data Invoice view information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: 8-character invoice ID
 * - booking_id: Database booking ID
 * - user_id: Customer user ID
 * - payment_status: Payment status (paid/unpaid)
 * - total_amount: Invoice total
 * - currency: Currency code
 * - hotel_name: Hotel name
 * - customer_email: Customer email
 * - timestamp: View timestamp
 * - view_count: Number of times viewed (if tracked)
 * - user_agent: Browser user agent
 * - ip_address: Viewer IP address
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/invoice', 'stays.invoice.viewed', $data);

// EXAMPLE 1: Track Invoice Views
// -------------------------------------------------------------------
// trackEvent('invoice_viewed', [
//     'invoice_id' => $data['invoice_id'],
//     'payment_status' => $data['payment_status'],
//     'value' => $data['total_amount'],
//     'days_since_booking' => daysSinceBooking($data['booking_id'])
// ]);

// EXAMPLE 2: Trigger Payment Reminder if Unpaid
// -------------------------------------------------------------------
// if ($data['payment_status'] === 'unpaid') {
//     // Customer viewing unpaid invoice - send gentle reminder
//     scheduleEmail('payment_reminder', $data['customer_email'], [
//         'invoice_id' => $data['invoice_id'],
//         'hotel_name' => $data['hotel_name'],
//         'total_amount' => $data['total_amount'],
//         'currency' => $data['currency'],
//         'payment_link' => "https://yoursite.com/invoice/stays/{$data['invoice_id']}"
//     ], '+2 hours'); // Send reminder in 2 hours if still unpaid
// }

// EXAMPLE 3: Log in CRM
// -------------------------------------------------------------------
// $crm->logActivity([
//     'type' => 'invoice_viewed',
//     'invoice_id' => $data['invoice_id'],
//     'customer_email' => $data['customer_email'],
//     'payment_status' => $data['payment_status'],
//     'timestamp' => $data['timestamp']
// ]);

// EXAMPLE 4: Detect Multiple Views (Potential Issues)
// -------------------------------------------------------------------
// if ($data['view_count'] > 5 && $data['payment_status'] === 'unpaid') {
//     // Customer viewing invoice multiple times but not paying
//     sendSlackNotification('#sales-support', [
//         'text' => '🤔 Customer Viewing Invoice Multiple Times',
//         'fields' => [
//             'Invoice' => $data['invoice_id'],
//             'Customer' => $data['customer_email'],
//             'Views' => $data['view_count'],
//             'Total' => "{$data['currency']} {$data['total_amount']}",
//             'Status' => $data['payment_status']
//         ],
//         'actions' => [
//             ['text' => 'Contact Customer', 'url' => "mailto:{$data['customer_email']}"]
//         ]
//     ]);
// }

/**
 * WEBHOOK: stays.invoice.pdf_generated
 * Triggers when PDF invoice is created
 * 
 * @param array $data PDF generation information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - booking_id: Booking ID
 * - pdf_filename: Generated PDF filename
 * - pdf_path: File system path to PDF
 * - pdf_size_bytes: PDF file size
 * - generation_time_ms: Time taken to generate PDF
 * - hotel_name: Hotel name
 * - customer_email: Customer email
 * - total_amount: Invoice total
 * - timestamp: Generation timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/invoice', 'stays.invoice.pdf_generated', $data);

// EXAMPLE 1: Track PDF Generation Performance
// -------------------------------------------------------------------
// logPerformance('pdf_generation', [
//     'invoice_id' => $data['invoice_id'],
//     'generation_time' => $data['generation_time_ms'],
//     'file_size' => $data['pdf_size_bytes'],
//     'timestamp' => $data['timestamp']
// ]);

// EXAMPLE 2: Backup PDF to Cloud Storage
// -------------------------------------------------------------------
// $s3->uploadFile($data['pdf_path'], "invoices/stays/{$data['invoice_id']}.pdf", [
//     'invoice_id' => $data['invoice_id'],
//     'booking_id' => $data['booking_id'],
//     'customer_email' => $data['customer_email'],
//     'generated_at' => $data['timestamp']
// ]);

// EXAMPLE 3: Log for Compliance/Audit
// -------------------------------------------------------------------
// $auditLog->record([
//     'action' => 'invoice_pdf_generated',
//     'invoice_id' => $data['invoice_id'],
//     'filename' => $data['pdf_filename'],
//     'file_size' => $data['pdf_size_bytes'],
//     'timestamp' => $data['timestamp']
// ]);

/**
 * WEBHOOK: stays.invoice.pdf_downloaded
 * Triggers when user downloads PDF invoice
 * 
 * @param array $data Download information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - booking_id: Booking ID
 * - user_id: User ID who downloaded
 * - pdf_filename: Downloaded filename
 * - download_method: 'direct'/'email_attachment'
 * - timestamp: Download timestamp
 * - user_agent: Browser user agent
 * - ip_address: Downloader IP address
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/invoice', 'stays.invoice.pdf_downloaded', $data);

// EXAMPLE 1: Track Downloads
// -------------------------------------------------------------------
// trackEvent('invoice_pdf_downloaded', [
//     'invoice_id' => $data['invoice_id'],
//     'download_method' => $data['download_method'],
//     'user_id' => $data['user_id']
// ]);

// EXAMPLE 2: Update Download Counter
// -------------------------------------------------------------------
// $db->update('bookings', [
//     'pdf_download_count[+]' => 1,
//     'last_pdf_download' => $data['timestamp']
// ], ['invoice_id' => $data['invoice_id']]);

// EXAMPLE 3: Log in CRM
// -------------------------------------------------------------------
// $crm->logActivity([
//     'type' => 'invoice_downloaded',
//     'invoice_id' => $data['invoice_id'],
//     'user_id' => $data['user_id'],
//     'timestamp' => $data['timestamp']
// ]);

/**
 * WEBHOOK: stays.invoice.expired
 * Triggers when booking session expires before payment
 * 
 * @param array $data Expiration information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - booking_id: Booking ID
 * - user_id: Customer user ID
 * - hotel_name: Hotel name
 * - customer_email: Customer email
 * - total_amount: Unpaid amount
 * - currency: Currency code
 * - booking_created_at: When booking was created
 * - expiry_time_minutes: Expiry duration setting
 * - timestamp: Expiration timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/invoice', 'stays.invoice.expired', $data);

// EXAMPLE 1: Send Expiry Notification
// -------------------------------------------------------------------
// sendEmail($data['customer_email'], 'booking_expired', [
//     'hotel_name' => $data['hotel_name'],
//     'total_amount' => $data['total_amount'],
//     'currency' => $data['currency'],
//     'search_link' => 'https://yoursite.com/stays'
// ]);

// EXAMPLE 2: Track Lost Revenue
// -------------------------------------------------------------------
// trackEvent('booking_expired', [
//     'invoice_id' => $data['invoice_id'],
//     'lost_value' => $data['total_amount'],
//     'currency' => $data['currency'],
//     'hotel_name' => $data['hotel_name'],
//     'minutes_to_expiry' => $data['expiry_time_minutes']
// ]);

// EXAMPLE 3: Alert Team About High-Value Expirations
// -------------------------------------------------------------------
// if ($data['total_amount'] > 1000) {
//     sendSlackNotification('#revenue-alerts', [
//         'text' => '💰 High-Value Booking Expired',
//         'fields' => [
//             'Invoice' => $data['invoice_id'],
//             'Hotel' => $data['hotel_name'],
//             'Customer' => $data['customer_email'],
//             'Lost Revenue' => "{$data['currency']} {$data['total_amount']}",
//             'Expired After' => "{$data['expiry_time_minutes']} minutes"
//         ]
//     ]);
// }

// EXAMPLE 4: Update CRM Opportunity
// -------------------------------------------------------------------
// $crm->updateOpportunity([
//     'stage' => 'lost',
//     'lost_reason' => 'Booking expired - no payment',
//     'invoice_id' => $data['invoice_id'],
//     'value' => $data['total_amount'],
//     'customer_email' => $data['customer_email'],
//     'closed_at' => $data['timestamp']
// ]);

// EXAMPLE 5: Flag for Win-Back Campaign
// -------------------------------------------------------------------
// $marketingAutomation->addToSegment($data['customer_email'], 'expired_bookings', [
//     'last_hotel' => $data['hotel_name'],
//     'last_amount' => $data['total_amount'],
//     'expired_date' => $data['timestamp']
// ]);

// =============================================================================
// WEBHOOK RESPONSE - Always return status array
// =============================================================================
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];
