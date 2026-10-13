-- ============================================================================
-- PHASE 1: DATABASE SCHEMA FOR WHITE-LABEL BOOKING INTEGRATION
-- ============================================================================
-- This script creates all necessary tables and schema changes for integrating
-- white-label bookings with the main payment/payout system.
--
-- CRITICAL: Execute in this order:
-- 1. ALTER bookings table (add white-label columns)
-- 2. CREATE stays_bookings (white-label booking records)
-- 3. CREATE booking_revenue (revenue split tracking)
-- 4. CREATE stays_booking_audit_log (audit trail)
--
-- All additions are backward-compatible (new columns nullable/defaulted).
-- ============================================================================

-- ============================================================================
-- 1. ALTER bookings TABLE - Add white-label integration columns
-- ============================================================================
-- These columns link main bookings to white-label suppliers and track
-- the supplier payout status.

ALTER TABLE `bookings` ADD COLUMN `white_label_booking_id` INT DEFAULT NULL
  COMMENT 'FK to stays_bookings.id (null for non-white-label bookings)';

ALTER TABLE `bookings` ADD COLUMN `supplier_org_id` INT DEFAULT NULL
  COMMENT 'FK to organizations.id (the supplier/hotel owner)';

ALTER TABLE `bookings` ADD COLUMN `supplier_booking_reference` VARCHAR(100) DEFAULT NULL
  COMMENT 'Guest-facing reference like BK-12345678 (stays_bookings.reference)';

-- Indexes for fast lookup by supplier/white-label
CREATE INDEX IF NOT EXISTS `idx_white_label_booking_id` ON `bookings`(`white_label_booking_id`);
CREATE INDEX IF NOT EXISTS `idx_supplier_org_id` ON `bookings`(`supplier_org_id`);

-- ============================================================================
-- 2. CREATE stays_bookings TABLE
-- ============================================================================
-- White-label booking records: one per guest, one per stay property.
-- This is the "single source of truth" for supplier booking management.
-- Linked to main bookings table via guest_booking_id.

CREATE TABLE IF NOT EXISTS `stays_bookings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `org_id` INT(11) NOT NULL COMMENT 'supplier/owner organization',
  `stay_id` INT(11) NOT NULL COMMENT 'hotel/property',
  `guest_booking_id` INT(11) DEFAULT NULL COMMENT 'FK to bookings.id (null until payment)',
  `room_id` INT(11) DEFAULT NULL COMMENT 'selected room',
  `option_id` INT(11) DEFAULT NULL COMMENT 'room option/rate',
  `reference` VARCHAR(100) NOT NULL UNIQUE COMMENT 'guest-facing: BK-{8-char-uniqid}',
  `check_in_date` DATE NOT NULL,
  `check_out_date` DATE NOT NULL,
  `number_of_nights` TINYINT(3) DEFAULT NULL,
  `guest_email` VARCHAR(191) NOT NULL,
  `guest_phone` VARCHAR(40) DEFAULT NULL,
  `guest_name_first` VARCHAR(100) NOT NULL,
  `guest_name_last` VARCHAR(100) DEFAULT NULL,
  `guest_special_requests` TEXT DEFAULT NULL,
  `guest_country` VARCHAR(100) DEFAULT NULL,
  `guest_address` VARCHAR(255) DEFAULT NULL,
  `guests_count` INT(3) DEFAULT 1,
  `total_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'USD',
  `status` ENUM('pending','confirmed','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'pending',
  `payment_status` ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid',
  `booking_confirmed_at` DATETIME DEFAULT NULL,
  `payment_received_at` DATETIME DEFAULT NULL,
  `checked_in_at` DATETIME DEFAULT NULL,
  `checked_out_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `cancellation_reason` VARCHAR(255) DEFAULT NULL,
  `refund_amount` DECIMAL(14,2) DEFAULT NULL,
  `refund_status` VARCHAR(50) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reference` (`reference`),
  KEY `idx_org_stay` (`org_id`, `stay_id`),
  KEY `idx_org_status` (`org_id`, `status`),
  KEY `idx_stay_id` (`stay_id`),
  KEY `idx_guest_email` (`guest_email`),
  KEY `idx_guest_booking_id` (`guest_booking_id`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='White-label booking records per supplier/property';

-- ============================================================================
-- 3. CREATE booking_revenue TABLE
-- ============================================================================
-- Revenue split tracking: how much gross was received, what the platform
-- takes (fees), what payment processor takes, what the supplier nets.
-- 
-- ONE-TO-ONE with bookings (one revenue record per booking).
-- Status tracks the revenue through its lifecycle:
--   'calculated' → 'recorded' → 'scheduled' (payout created) → 'paid'

CREATE TABLE IF NOT EXISTS `booking_revenue` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `booking_id` INT(11) NOT NULL UNIQUE COMMENT 'FK to bookings.id (unique: 1:1)',
  `org_id` INT(11) NOT NULL COMMENT 'supplier/owner',
  `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'total paid by guest',
  `currency` CHAR(3) NOT NULL DEFAULT 'USD',
  
  -- Platform fee (configurable, stored as both % and absolute)
  `platform_fee_pct` DECIMAL(5,2) NOT NULL DEFAULT 2.50 COMMENT 'percentage (e.g., 2.5%)',
  `platform_fee_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'calculated amount',
  
  -- Payment processor fee (Paystack ~2%, Stripe ~2.9%+0.30, etc)
  `processor_fee_pct` DECIMAL(5,2) DEFAULT 2.00 COMMENT 'payment processor percentage',
  `processor_fee_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'calculated amount',
  
  -- Net (what supplier actually receives)
  `net_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'gross - platform - processor',
  
  -- Lifecycle
  `status` ENUM('calculated','recorded','scheduled','paid','failed','refunded') NOT NULL DEFAULT 'calculated',
  `payment_method` VARCHAR(50) DEFAULT NULL COMMENT 'paystack|stripe|adyen',
  `payment_gateway_id` VARCHAR(255) DEFAULT NULL COMMENT 'gateway transaction ID for reconciliation',
  `scheduled_payout_id` INT(11) DEFAULT NULL COMMENT 'FK to supplier_payouts.id',
  
  -- Timestamps
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_at` DATETIME DEFAULT NULL,
  `scheduled_at` DATETIME DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_id` (`booking_id`),
  KEY `idx_org_status` (`org_id`, `status`),
  KEY `idx_status` (`status`),
  KEY `idx_scheduled_payout_id` (`scheduled_payout_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Revenue split tracking per booking';

-- ============================================================================
-- 4. CREATE stays_booking_audit_log TABLE
-- ============================================================================
-- Complete audit trail: every action on a white-label booking is logged.
-- Used for troubleshooting, compliance, and dispute resolution.

CREATE TABLE IF NOT EXISTS `stays_booking_audit_log` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `stays_booking_id` INT(11) NOT NULL COMMENT 'FK to stays_bookings.id',
  `action` VARCHAR(100) NOT NULL COMMENT 'created|email_sent|payment_received|payout_scheduled|cancelled|etc',
  `actor` VARCHAR(50) DEFAULT NULL COMMENT 'guest|supplier|admin|system',
  `actor_id` VARCHAR(155) DEFAULT NULL COMMENT 'user_id or email of actor',
  `details` LONGTEXT DEFAULT NULL COMMENT 'JSON details of the action',
  `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'request IP for guest actions',
  `user_agent` TEXT DEFAULT NULL COMMENT 'request user agent',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_stays_booking_id` (`stays_booking_id`),
  KEY `idx_action` (`action`),
  KEY `idx_actor_id` (`actor_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail for white-label bookings';

-- ============================================================================
-- 5. CREATE stays_booking_payment_events TABLE
-- ============================================================================
-- Raw webhook events from payment processors. Stored for:
-- - Idempotency (detect duplicate webhooks)
-- - Debugging (view exactly what Paystack/Stripe sent)
-- - Reconciliation (verify all events processed)

CREATE TABLE IF NOT EXISTS `stays_booking_payment_events` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `event_id` VARCHAR(255) NOT NULL UNIQUE COMMENT 'external event ID from gateway',
  `event_type` VARCHAR(100) NOT NULL COMMENT 'charge.success|charge.failed|etc',
  `gateway` VARCHAR(50) NOT NULL COMMENT 'paystack|stripe|adyen',
  `booking_id` INT(11) DEFAULT NULL COMMENT 'FK to bookings.id (null if unmatched)',
  `raw_payload` LONGTEXT NOT NULL COMMENT 'full JSON from gateway',
  `processed` TINYINT(1) NOT NULL DEFAULT 0,
  `processed_at` DATETIME DEFAULT NULL,
  `error` TEXT DEFAULT NULL COMMENT 'error if processing failed',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_id` (`event_id`, `gateway`),
  KEY `idx_booking_id` (`booking_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_processed` (`processed`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Payment webhook events for idempotency and debugging';

-- ============================================================================
-- VERIFICATION QUERIES (run after schema creation)
-- ============================================================================

-- Verify bookings table columns added:
-- SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
--   WHERE TABLE_NAME='bookings' AND COLUMN_NAME IN (
--     'white_label_booking_id','supplier_org_id','supplier_booking_reference'
--   );

-- Verify new tables created:
-- SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
--   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (
--     'stays_bookings','booking_revenue','stays_booking_audit_log','stays_booking_payment_events'
--   );

-- ============================================================================
-- END OF PHASE 1 SCHEMA
-- ============================================================================

