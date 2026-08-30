# Hotelbeds Content Integration Module

## Overview

The Hotelbeds Content Integration Module is a comprehensive system designed to import and manage hotel inventory data from the Hotelbeds API into a local database. This module handles the synchronization of over 234,000 hotels along with their complete metadata including facilities, rooms, images, and reference data.

**Version:** 1.0  
**Database:** hotelbeds (separate database)  
**API Provider:** Hotelbeds (test.hotelbeds.com / api.hotelbeds.com)  
**Module Type:** Stays  
**Author:** Senior Development Team  
**Last Updated:** August 20, 2026

---

## Table of Contents

1. [Technical Architecture](#technical-architecture)
2. [Database Schema](#database-schema)
3. [File Structure](#file-structure)
4. [Core Functionality](#core-functionality)
5. [API Integration](#api-integration)
6. [Import Process](#import-process)
7. [Data Flow](#data-flow)
8. [Known Limitations](#known-limitations)
9. [Development Guidelines](#development-guidelines)
10. [Troubleshooting](#troubleshooting)

---

## Technical Architecture

### Technology Stack

- **Backend Framework:** PHP 8.2+
- **Database:** MySQL 8.0+ (InnoDB engine)
- **ORM:** Medoo (lightweight PHP database framework)
- **Frontend:** Alpine.js for reactive UI components
- **API Communication:** cURL with SHA256 signature authentication
- **Session Management:** Database-persisted import state (JSON in LONGTEXT)

### Separate Database Architecture

This module uses a **dedicated database** named `hotelbeds` to ensure:
- **Data isolation** from the main application database
- **Performance optimization** for large-scale imports
- **Easy backup and restore** capabilities
- **Independent scaling** without affecting main application

**Connection Details:**
- Host: localhost
- Database: hotelbeds
- Connection managed via `getHotelbedsDb()` function
- Credentials stored in main database `modules` table

---

## Database Schema

**API JSON → table mapping:** see [`content/API_JSON_SCHEMA.md`](content/API_JSON_SCHEMA.md) (Hotelbeds returns JSON only; SQL is created by `createHotelbedsSchema()`). Postman requests: [`content/ContentAPI.postman_collection.json`](content/ContentAPI.postman_collection.json).

### Tables Overview

Reference import: `content/reference_import.php` (paginated Content API masters). Rate comments resolve via `resolveHotelbedsRateComments()`.

**Runtime (Hotelbeds only):** `hotelbedsEnrichHotelContentMeta()` resolves zone/chain/category/segments/issues/terminals/grouped amenities for details + search; promotions on rooms rates; draft/invoice carry zone/chain/notices. Other suppliers unchanged.

#### 1. Reference Data Tables (Content API masters)
- `hotelbeds_countries`, `hotelbeds_destinations`, `hotelbeds_zones`
- `hotelbeds_categories`, `hotelbeds_accommodations`, `hotelbeds_chains`, `hotelbeds_segments`
- `hotelbeds_image_types`, `hotelbeds_facility_groups`, `hotelbeds_facilities`
- `hotelbeds_boards`, `hotelbeds_rooms`, `hotelbeds_issues`, `hotelbeds_terminals`
- `hotelbeds_currencies`, `hotelbeds_promotions`, `hotelbeds_rate_comments`

#### 2. Hotel Data Tables (from `/hotels`)
- `hotelbeds_hotels` (+ `segment_codes`)
- `hotelbeds_amenities`, `hotelbeds_hotel_images`, `hotelbeds_hotel_rooms`
- `hotelbeds_hotel_issues`, `hotelbeds_hotel_terminals`

#### 3. System Tables
- `hotelbeds_import_log` - Import process tracking and state management

### Detailed Table Structures

#### hotelbeds_hotels
```sql
CREATE TABLE hotelbeds_hotels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_code VARCHAR(10) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    category_code VARCHAR(10),
    category_group VARCHAR(50),
    accommodation_type VARCHAR(100),
    chain_code VARCHAR(10),
    address TEXT,
    postal_code VARCHAR(20),
    city VARCHAR(100),
    country_code VARCHAR(5),
    destination_code VARCHAR(10),
    zone_code INT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    email VARCHAR(255),
    phone VARCHAR(50),
    web VARCHAR(255),
    star_rating DECIMAL(3,1),
    license_number VARCHAR(100),
    ranking INT,
    s2c VARCHAR(10),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hotel_code (hotel_code),
    INDEX idx_country (country_code),
    INDEX idx_destination (destination_code),
    INDEX idx_city (city),
    INDEX idx_category (category_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Key Columns:**
- `hotel_code` - Unique identifier from Hotelbeds API
- `category_code` - Links to `hotelbeds_categories`
- `chain_code` - Links to `hotelbeds_chains`
- `country_code` - Links to `hotelbeds_countries`
- `destination_code` - Links to `hotelbeds_destinations`
- `latitude/longitude` - Geo-coordinates for mapping
- `ranking` - Hotel ranking score from API

#### hotelbeds_amenities
```sql
CREATE TABLE hotelbeds_amenities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_code VARCHAR(10) NOT NULL,
    facility_code INT NOT NULL,
    facility_description TEXT,
    facility_group_code INT,
    distance INT,
    order_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_hotel_facility (hotel_code, facility_code),
    FOREIGN KEY (hotel_code) REFERENCES hotelbeds_hotels(hotel_code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Note:** `facility_description` remains blank as Hotelbeds API doesn't return text descriptions for facility codes. Use `facility_code` to link with `hotelbeds_facilities.code` for facility names.

#### hotelbeds_hotel_rooms
```sql
CREATE TABLE hotelbeds_hotel_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_code VARCHAR(10) NOT NULL,
    room_code VARCHAR(50) NOT NULL,
    room_type VARCHAR(50),
    characteristic VARCHAR(50),
    description TEXT,
    min_pax INT DEFAULT 1,
    max_pax INT DEFAULT 2,
    max_adults INT DEFAULT 2,
    max_children INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_hotel_room (hotel_code, room_code, characteristic),
    FOREIGN KEY (hotel_code) REFERENCES hotelbeds_hotels(hotel_code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Note:** `description` remains blank as API returns only codes. Use `room_code` to link with `hotelbeds_rooms.code` for room type definitions.

#### hotelbeds_import_log
```sql
CREATE TABLE hotelbeds_import_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mode ENUM('fresh', 'update') NOT NULL,
    status ENUM('in_progress', 'completed', 'failed', 'cancelled') DEFAULT 'in_progress',
    hotels_imported INT DEFAULT 0,
    destinations_imported INT DEFAULT 0,
    countries_imported INT DEFAULT 0,
    import_state LONGTEXT,
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Critical:** `import_state` is **LONGTEXT** (not TEXT) to handle large JSON payloads containing logs for 2,348 chunks. TEXT field caused truncation at 65KB leading to corrupted state.

---

## File Structure

### Core Files

```
v10/modules/stays/hotelbeds/
├── content.php                    # Backend API handler (1,679 lines)
├── hotelbeds-import.php          # Frontend UI interface (614 lines)
├── search.php                     # Hotel search endpoint (600+ lines)
├── details.php                    # Hotel details endpoint (NEW)
├── rooms.php                      # Room availability & pricing endpoint (NEW)
├── index.php                      # Route registration (includes all endpoints)
└── README.md                     # This documentation
```

### File Responsibilities

#### `content.php` - Backend Controller & Business Logic

**Primary Functions:**

1. **Database Connection Management**
   - `getHotelbedsDb()` - Lines 107-139
     - Establishes PDO connection to separate hotelbeds database
     - Uses Medoo ORM for query abstraction
     - Reads credentials from main `modules` table
     - Returns configured Medoo instance

2. **Route Handling** (Lines 140-617)
   - `/stats` - GET - Fetch import statistics
   - `/content` - POST - Initialize import process
   - `/progress` - GET - Get current import progress
   - `/process` - POST - Process next chunk of hotels
   - `/content_cancel` - POST - Cancel ongoing import
   - `/populate-countries-destinations` - POST - Manual population endpoint

3. **Reference Data Import**
   - `importHotelbedsReferenceData()` - Lines 633-850
     - Imports 8 types of reference data:
       * Countries (HTTP 404 in test API)
       * Destinations (HTTP 404 in test API)
       * Categories (65 records)
       * Accommodations (24 records)
       * Chains (100 records)
       * Facilities (49 records)
       * Boards (32 records)
       * Rooms (100 records)
     - Uses cURL with SHA256 signature authentication
     - Handles duplicates gracefully
     - Logs import counts

4. **Countries/Destinations Workaround**
   - `populateCountriesFromHotels()` - Lines 854-880
     - Extracts unique country codes from imported hotels
     - Populates `hotelbeds_countries` when API endpoint unavailable
     - Returns count of inserted countries
   
   - `populateDestinationsFromHotels()` - Lines 882-915
     - Extracts unique destination codes from imported hotels
     - Populates `hotelbeds_destinations` from hotel data
     - Includes country_code and zone_code relationships
     - Returns count of inserted destinations

5. **Hotel Content Import**
   - `importHotelbedsChunk()` - Lines 1052-1325
     - Processes 25 hotels per chunk
     - Imports hotel data, facilities, images, and rooms
     - Handles both 'fresh' and 'update' modes
     - Stamps each hotel with `sync_run_id` (= current `hotelbeds_import_log.id`)
     - On full import complete, `purgeStaleHotelbedsHotels()` deletes hotels not in this run (keeps Total Hotels aligned with API catalogue after update mode)
     - Returns success/error status with logs
     - **Performance:** Logs only chunk summaries, not individual hotels
     - **Error Handling:** Logs only errors, not successful operations

6. **Hotelbeds API Communication**
   - `callHotelbedsApi()` - Lines 917-1050
     - Constructs API requests with proper authentication
     - SHA256 signature: `hash('sha256', $apiKey . $apiSecret . $timestamp)`
     - Handles test and production environments
     - Comprehensive debug logging for troubleshooting
     - Returns decoded JSON response

7. **Database Schema Management**
   - `createHotelbedsSchema()` - Lines 1327-1583
     - Creates all 13 tables with proper indexes
     - Sets up foreign key constraints
     - Handles existing table scenarios
     - Uses InnoDB engine for transaction support

8. **Data Cleanup**
   - `truncateHotelbedsTables()` - Lines 1585-1612
     - Truncates in correct dependency order
     - Child tables first to avoid constraint violations
     - Used in 'fresh' import mode

#### `details.php` - Hotel Details API Endpoint (NEW - December 10, 2025)

**Purpose:** Fetch comprehensive hotel information from imported database

**Endpoint:** `POST /stays/hotelbeds/details`

**Key Features:**

1. **Hotel Metadata Retrieval**
   - Fetches from `hotelbeds_hotels` table by hotel_code
   - Returns name, description, address, location, coordinates
   - Converts category_code to numeric star rating (1-5)
   - Converts ranking score (0-100) to guest rating (0-5)

2. **Image Collection**
   - Queries `hotelbeds_hotel_images` table
   - Returns array of image URLs ordered by display order
   - Full resolution images from Hotelbeds CDN

3. **Amenities/Facilities**
   - Joins `hotelbeds_amenities` with `hotelbeds_facilities`
   - Returns facility names (e.g., "Wi-Fi", "Swimming Pool")
   - Removes duplicates

4. **Location Building**
   - Combines city name with country name
   - Looks up country name from `hotelbeds_countries` by code
   - Format: "Barcelona, Spain"

5. **Policy Information**
   - Returns generic cancellation and privacy policies
   - Note: Specific policies come from Booking API per rate

**Request Parameters:**
```php
{
    "hotel_id": "123456",           // Hotel code (required)
    "supplier": "hotelbeds",        // Always "hotelbeds"
    "checkin": "15-12-2025",        // DD-MM-YYYY format
    "checkout": "18-12-2025",       // DD-MM-YYYY format
    "nationality": "US",            // ISO country code
    "rooms": []                     // Optional room config
}
```

**Response Format:**
```json
{
    "success": true,
    "data": {
        "id": "123456",
        "name": "Hilton Barcelona",
        "description": "Luxury hotel in city center...",
        "address": "Av. Diagonal 589-591",
        "location": "Barcelona, Spain",
        "cancellation_policy": "...",
        "privacy_policy": "...",
        "stars": 5,
        "rating": 4.5,
        "latitude": 41.3851,
        "longitude": 2.1734,
        "images": ["url1.jpg", "url2.jpg"],
        "amenities": ["Wi-Fi", "Pool", "Restaurant"],
        "rooms": [],
        "supplier": "hotelbeds",
        "checkin": "15-12-2025",
        "checkout": "18-12-2025"
    }
}
```

**Differences from Manual Hotels Details:**
- Uses hotel_code instead of numeric ID
- Separate database connection (Hotelbeds DB)
- Star rating extracted from category_code (e.g., "5EST" → 5)
- Rating calculated from ranking score (0-100 scale → 0-5)
- Images from separate hotelbeds_hotel_images table
- Amenities via JOIN with hotelbeds_facilities table

---

#### `rooms.php` - Room Availability & Pricing API Endpoint (NEW - December 10, 2025)

**Purpose:** Fetch real-time room availability and pricing from Hotelbeds Booking API

**Endpoint:** `POST /stays/hotelbeds/rooms`

**Key Features:**

1. **Real-Time API Integration**
   - Calls Hotelbeds Booking API (`/hotel-api/1.0/hotels`)
   - Requires valid dates and occupancy data
   - Returns actual room availability and allotment
   - Includes rate keys for booking confirmation

2. **Pricing with Markup & Currency Conversion**
   - Extracts base price (net) from API in hotel's currency
   - Applies B2B/B2C markup using `MARKUP()` function
   - Converts to user's display currency
   - Formula: `MARKUP(stayNet)` for total; `price_per_night = total ÷ nights` (API `net` is stay total, not per night)

3. **Rate Options**
   - Multiple rate types per room (Refundable, Non-Refundable)
   - Board types: RO (Room Only), BB (Bed & Breakfast), HB (Half Board), FB (Full Board), AI (All Inclusive)
   - Each rate has unique rate_key for booking
   - Cancellation policies with amounts and deadlines

4. **Local Database Enrichment**
   - Queries `hotelbeds_hotel_rooms` for room metadata
   - Adds room images from database
   - Includes room amenities (if available)
   - Enriches API response with local data

5. **Occupancy Handling**
   - Supports multiple rooms with different occupancies
   - Handles child ages for accurate pricing
   - Maps to API payload format with paxes array

**Request Parameters:**
```php
{
    "hotel_id": "123456",                    // Hotel code (required)
    "supplier": "hotelbeds",
    "checkin": "15-12-2025",                 // Required for API call
    "checkout": "18-12-2025",                // Required for API call
    "nationality": "US",
    "rooms": [                                // Room configuration
        {
            "adults": 2,
            "children": 1,
            "childAges": [5]
        }
    ]
}
```

**Response Format:**
```json
{
    "success": true,
    "data": {
        "hotel_id": "123456",
        "nights": 3,
        "currency": "USD",
        "rooms": [
            {
                "room_id": "DBL.ST",
                "room_type_id": "DBL.ST",
                "room_name": "Double Standard",
                "room_images": ["url1.jpg"],
                "room_main_image": "url1.jpg",
                "amenities": [{"id": 1, "name": "Wi-Fi"}],
                "max_adults": 2,
                "max_children": 1,
                "options": [
                    {
                        "option_index": 0,
                        "max_adults": 2,
                        "max_children": 1,
                        "price_per_night": 150.00,
                        "total_price": 450.00,
                        "base_price": 120.00,
                        "currency": "USD",
                        "discount_percentage": 0,
                        "breakfast_included": 1,
                        "cancellation_free": 0,
                        "refundable": 1,
                        "available_quantity": 5,
                        "board_id": "BB",
                        "board_name": "Bed & Breakfast",
                        "rate_key": "20231215|20231218|W|1|123456|DBL.ST|NRF|BB|1~2~0||N@",
                        "rate_type": "REFUNDABLE",
                        "cancellation_policies": [
                            {
                                "amount": 50.00,
                                "from": "2023-12-10T00:00:00"
                            }
                        ]
                    }
                ]
            }
        ]
    }
}
```

**Differences from Manual Hotels Rooms:**
- Pricing from live API (not pre-configured in database)
- Requires valid checkin/checkout dates for API call
- Returns rate_key field (required for booking)
- Includes detailed cancellation policies per rate
- Board types from API (BB, HB, FB, AI)
- Actual availability and allotment counts
- Rate type indicators (REFUNDABLE, NON-REFUNDABLE)

---

#### `search.php` - Hotel Search API Endpoint

**Purpose:** Real-time hotel search combining local database with live API pricing

**Key Features:**

1. **Dynamic Database Connection**
   - Queries `modules` table to get database credentials
   - Uses Medoo `$db->get()` to fetch module configuration
   - Extracts: `host`, `database`, `username`, `password`, `c1` (API key), `c2` (API secret), `env`
   - Creates separate PDO connection to Hotelbeds database dynamically

2. **Pagination Support (NEW)**
   - Default: 25 hotels per page
   - Supports `page` and `per_page` parameters
   - Maximum: 100 hotels per page
   - Returns pagination headers: `X-Total-Results`, `X-Total-Pages`, `X-Current-Page`, `X-Per-Page`, `X-Has-More`
   - Enables infinite scroll on frontend

3. **Smart Destination Mapping**
   - Searches `hotelbeds_destinations` by name or code
   - Falls back to `hotelbeds_hotels.city` search
   - Handles multiple destination codes per city
   - Example: "Barcelona" → finds BCN, LLM, STS destination codes

4. **Local + API Hybrid Approach**
   - Fetches hotel details from local database (fast)
   - Calls Hotelbeds Booking API for real-time rates
   - Combines: Local images + amenities + Live pricing

5. **Pricing with Markup & Currency Conversion**
   - Applies B2B/B2C markup using `MARKUP()` function
   - Converts from API currency to user's display currency
   - Order: Base price → Markup → Currency conversion
   - Same logic as manual hotel system

6. **Comprehensive Hotel Response**
   - Hotel info: name, address, stars, coordinates
   - Images from `hotelbeds_hotel_images` table
   - Amenities from `hotelbeds_amenities` + `hotelbeds_facilities` JOIN
   - Room options with pricing breakdown
   - Availability status flag

**Request Parameters:**
```php
POST /stays/hotelbeds/search
{
    "destination": "Barcelona",        // City name or destination code
    "checkin": "25-12-2025",          // DD-MM-YYYY format
    "checkout": "28-12-2025",         // DD-MM-YYYY format
    "rooms": 1,
    "adults": 2,
    "children": 0,
    "rooms_data": '[{"adults":2,"children":0,"childAges":[]}]',
    "currency": "USD",                 // Display currency
    "star_rating": "4",               // Optional: Filter by stars
    "nationality": "US",
    "page": 1,                        // Optional: Page number (default: 1)
    "per_page": 25                    // Optional: Results per page (default: 25, max: 100)
}
```

**Response Format:**
```json
{
    "status": true,
    "message": "Hotelbeds Hotels Retrieved Successfully",
    "response": [
        {
            "hotel_id": "21",
            "name": "Hotel Viladomat",
            "img": "00/000021/000021a_hb_ro_005.jpg",
            "images": ["...", "...", "..."],
            "location": "BARCELONA",
            "address": "...",
            "stars": 4,
            "rating": 4.0,
            "latitude": 41.38247400,
            "longitude": 2.15334600,
            "display_price": 450.50,
            "display_price_per_night": 150.17,
            "currency": "USD",
            "original_currency": "EUR",
            "amenities": [...],
            "room_options": [...],
            "has_available_rooms": true,
            "supplier_name": "hotelbeds"
        }
    ],
    "total": 520,
    "destination_codes_searched": ["BCN", "LLM"],
    "nights": 3
}
```

**Response Headers:**
```
X-Total-Results: 520
X-Total-Pages: 21
X-Current-Page: 1
X-Per-Page: 25
X-Has-More: true
Content-Type: application/json
```

**Database Query Flow:**
```
1. Query modules table → Get database credentials
2. Connect to Hotelbeds database dynamically
3. Search destinations by name/code
4. Count total hotels matching criteria
5. Calculate pagination (LIMIT/OFFSET)
6. Fetch hotels by destination_code(s) with pagination
7. Get images from hotelbeds_hotel_images
8. Get amenities via JOIN with hotelbeds_facilities
9. Call Hotelbeds Booking API for rates
10. Apply MARKUP() → Currency conversion
11. Set pagination headers
12. Format and return response
```

**Pagination Implementation:**
```php
// Extract pagination parameters
$page = max(1, (int)($_POST['page'] ?? 1));
$per_page = min(100, max(1, (int)($_POST['per_page'] ?? 25)));

// Count total hotels
$totalHotels = count($hotelCodes); // or database count

// Calculate pagination
$totalPages = ceil($totalHotels / $per_page);
$offset = ($page - 1) * $per_page;

// Apply LIMIT/OFFSET
$paginatedHotels = array_slice($hotels, $offset, $per_page);

// Set response headers
header('X-Total-Results: ' . $totalHotels);
header('X-Total-Pages: ' . $totalPages);
header('X-Current-Page: ' . $page);
header('X-Per-Page: ' . $per_page);
header('X-Has-More: ' . ($page < $totalPages ? 'true' : 'false'));
```

#### `hotelbeds-import.php` - Frontend UI

**Structure:**

1. **Statistics Dashboard** (Lines 30-82)
   - Total Hotels counter (`COUNT(DISTINCT hotel_code)` in our DB — unique hotels stored)
   - Last Sync timestamp
   - Destinations count
   - Import status indicator

2. **Import Actions Section** (Lines 84-137)
   - Test Credentials button (REMOVED per requirement)
   - Import Content button
   - Dynamic content based on data availability

3. **Progress Display** (Lines 139-201)
   - Overall progress bar
   - Current operation indicator
   - Chunk progress (X of 2348)
   - Records processed counter
   - ETA calculation
   - Real-time terminal log

4. **Import Options Modal** (Lines 206-279)
   - Fresh Install mode (deletes all existing data)
   - Update Existing mode (preserves data)
   - Warning messages and confirmations

5. **JavaScript Functions** (Lines 283-614)
   - `loadImportStatistics()` - Fetches current stats via AJAX
   - `showImportOptions()` / `closeImportOptions()` - Modal control
   - `startImport()` - Initiates import process
   - `executeImport()` - Sends import request to backend
   - `processImportChunk()` - Continuous chunk processing loop
   - `updateProgressUI()` - Updates progress indicators
   - `addLogEntry()` - Adds colored log messages to terminal
   - `onImportComplete()` - Handles successful completion
   - `onImportError()` - Handles errors gracefully
   - `pauseImport()` / `resumeImport()` - Pause/resume controls
   - `cancelImport()` - Aborts import process

**UI Features:**
- Real-time progress updates
- Color-coded terminal logs (green=success, red=error, yellow=warning)
- Auto-scrolling log container
- Pause/Resume/Cancel controls
- ETA estimation
- Responsive design with Tailwind CSS

---

## API Endpoints Summary

### Customer-Facing APIs (Live Search & Booking)

| Endpoint | Method | Purpose | Data Source |
|----------|--------|---------|-------------|
| `/stays/hotelbeds/search` | POST | Search hotels by destination | Local DB + API pricing |
| `/stays/hotelbeds/details` | POST | Get hotel details | Local DB only |
| `/stays/hotelbeds/rooms` | POST | Get room rates & availability | API + Local enrichment |

### Admin APIs (Content Management)

| Endpoint | Method | Purpose | Access |
|----------|--------|---------|--------|
| `/stays/hotelbeds/stats` | GET | Import statistics dashboard | Admin only |
| `/stays/hotelbeds/content` | POST | Initialize import process | Admin only |
| `/stays/hotelbeds/progress` | GET | Get current import progress | Admin only |
| `/stays/hotelbeds/process` | POST | Process next import chunk | Admin only |
| `/stays/hotelbeds/content_cancel` | POST | Cancel ongoing import | Admin only |
| `/stays/hotelbeds/populate-countries-destinations` | POST | Manual data population | Admin only |

### API Flow Comparison

**Manual Hotels System:**
```
Search Request → stays table → stays_rooms table → Return results
```

**Hotelbeds System:**
```
Search Request → hotelbeds_hotels table → Hotelbeds Booking API → 
Apply MARKUP → Currency conversion → Return results
```

### Authentication & Security

**Customer APIs:**
- Session-based authentication
- Rate limiting: 60 requests/minute per IP
- Origin validation (blocks external tools)

**Admin APIs:**
- Requires admin role in session
- CSRF token validation
- Additional rate limiting

**Hotelbeds API Authentication:**
```php
$timestamp = time();
$signature = hash('sha256', $apiKey . $apiSecret . $timestamp);

Headers:
- Api-key: {apiKey}
- X-Signature: {signature}
```

---

## Core Functionality

### Import Process Flow

```
1. User clicks "Import Content"
   ↓
2. Select mode: Fresh Install or Update Existing
   ↓
3. Backend initializes import (content.php POST /content)
   ├─ Create database schema if not exists
   ├─ Truncate tables (if fresh mode)
   ├─ Import reference data (370 records)
   └─ Create import log entry with state
   ↓
4. Frontend starts chunk processing loop
   ↓
5. For each chunk (1 to 2348):
   ├─ Backend fetches 100 hotels from API
   ├─ Process hotel data, facilities, images, rooms
   ├─ Update import state in database
   ├─ Return progress to frontend
   └─ Frontend updates UI
   ↓
6. After chunk 1 completes:
   ├─ Auto-populate countries from hotels (workaround for 404 API)
   └─ Auto-populate destinations from hotels
   ↓
7. Continue until all chunks processed
   ↓
8. Mark import as complete
   ↓
9. Update statistics and show success message
```

### Import Modes

#### Fresh Install Mode
- **Purpose:** Complete database rebuild
- **Process:**
  1. Truncates all 13 tables
  2. Imports all reference data
  3. Imports all 234,760 hotels
  4. Populates countries/destinations from hotel data
- **Use Case:** Initial setup or data corruption recovery
- **Warning:** All existing data is deleted

#### Update Existing Mode
- **Purpose:** Incremental updates
- **Process:**
  1. Preserves existing data
  2. Updates reference data (new records only)
  3. Updates existing hotels or inserts new ones
  4. Removes and re-inserts child records (amenities, images, rooms)
- **Use Case:** Daily/weekly synchronization
- **Safety:** Existing data is preserved

### Chunking Strategy

**Why 100 hotels per chunk?**
- API response size: ~6-7 MB per chunk
- Processing time: ~20-30 seconds per chunk
- Memory management: Prevents PHP memory exhaustion
- Database transactions: Manageable commit sizes
- Progress tracking: Granular user feedback

**Total Import Time:**
- 2,348 chunks × 25 seconds avg = ~16.3 hours
- Can be optimized by running in background
- Supports pause/resume functionality

---

## API Integration

### Authentication

Hotelbeds API uses **SHA256 signature-based authentication**:

```php
$timestamp = time();
$signature = hash('sha256', $apiKey . $apiSecret . $timestamp);

// Headers
'Api-key: ' . $apiKey
'X-Signature: ' . $signature
```

### Endpoints Used

#### Reference Data Endpoints
```
GET /hotel-content-api/1.0/types/countries       # Returns 404 in test env
GET /hotel-content-api/1.0/types/destinations    # Returns 404 in test env
GET /hotel-content-api/1.0/types/categories      # 65 categories
GET /hotel-content-api/1.0/types/accommodations  # 24 types
GET /hotel-content-api/1.0/types/chains          # 100 chains
GET /hotel-content-api/1.0/types/facilities      # 49 facilities
GET /hotel-content-api/1.0/types/boards          # 32 board types
GET /hotel-content-api/1.0/types/rooms           # 100 room types
```

#### Hotel Content Endpoint
```
GET /hotel-content-api/1.0/hotels?from={start}&to={end}&fields=all&language=ENG&useSecondaryLanguage=false
```

**Parameters:**
- `from` - Starting hotel index (1-based)
- `to` - Ending hotel index (100 per chunk)
- `fields=all` - Returns complete hotel data
- `language=ENG` - Primary language
- `useSecondaryLanguage=false` - No fallback language

### Environment Switching

**Test Environment:**
```
Base URL: https://api.test.hotelbeds.com/hotel-content-api/1.0
Total Hotels: 234,760 (as of Dec 2025)
Limitations: Countries and Destinations endpoints return 404
```

**Production Environment:**
```
Base URL: https://api.hotelbeds.com/hotel-content-api/1.0
Total Hotels: Variable (updated regularly)
Full API Access: All endpoints available
```

Switch environment in module settings: `$module['env']` = 'test' or 'live'

---

## Data Flow

### Import State Management

Import state is stored as JSON in `hotelbeds_import_log.import_state`:

```json
{
  "mode": "fresh",
  "api_key": "531c46fc...",
  "api_secret": "1e83c...",
  "environment": "test",
  "current_chunk": 13,
  "total_chunks": 2348,
  "processed": 1300,
  "total": 234760,
  "start_time": 1765274008,
  "logs": ["[10:53:29] ✓ Chunk 1 complete: Imported 100 hotels", ...],
  "reference_data_imported": true,
  "countries_populated": true
}
```

**State Updates:**
- After each chunk completion
- On error occurrence
- On pause/resume/cancel actions
- Persisted to database (not session)

### Error Handling Strategy

**Principle:** Fail gracefully, continue processing

1. **Individual Hotel Errors**
   - Log error to `_error.log`
   - Add error message to terminal logs
   - Increment skipped counter
   - Continue with next hotel

2. **Chunk Processing Errors**
   - Return error in JSON response
   - Frontend displays error message
   - Import can be resumed from last successful chunk

3. **API Communication Errors**
   - Retry mechanism (not implemented yet)
   - Log detailed API response
   - Mark chunk as failed
   - Allow manual retry

**Logging Levels:**
- **SUCCESS:** Only chunk summaries (e.g., "✓ Chunk 5 complete: Imported 100 hotels")
- **ERROR:** Individual failures with hotel codes
- **DEBUG:** API request/response details in `_error.log`

---

## Known Limitations

### 1. Missing API Data

**Problem:** The following columns remain blank because Hotelbeds API doesn't return text descriptions:

- `hotelbeds_amenities.facility_description` - Only `facility_code` returned
- `hotelbeds_categories.accommodation_type` - Only codes returned (use `description` column)
- `hotelbeds_hotel_rooms.description` - Only room codes returned
- `hotelbeds_rooms.description` - Only codes and types returned

**Solution:** 
- Link facility codes to `hotelbeds_facilities.description` for facility names
- Link room codes to `hotelbeds_rooms.code` for room type info
- Consider fetching descriptions from alternative Hotelbeds endpoints if available

### 2. Test API Limitations

**Problem:** Test environment returns HTTP 404 for:
- `/types/countries` endpoint
- `/types/destinations` endpoint

**Workaround Implemented:**
- `populateCountriesFromHotels()` extracts countries from imported hotels
- `populateDestinationsFromHotels()` extracts destinations from hotels
- Auto-triggered after chunk 1 completion
- Manual trigger available: POST `/populate-countries-destinations`

**Production Note:** Live API may have these endpoints available.

### 3. Import State Corruption (FIXED)

**Original Problem:** `import_state` column was TEXT (65KB max), causing JSON truncation after ~1,300 hotels.

**Fix Applied:** Changed to LONGTEXT (16MB max) to accommodate full import logs.

**Prevention:** Always use LONGTEXT for JSON fields that may grow large.

### 4. Performance Considerations

**Large Dataset Impact:**
- 234,760 hotels = 2,348 chunks
- ~16.3 hours for complete import
- Database grows to several GB
- Consider indexes on frequently queried columns

**Optimization Opportunities:**
- Implement background processing (cron job)
- Add database connection pooling
- Implement Redis caching for frequent queries
- Add chunk parallel processing (with proper locking)

---

## Development Guidelines

### Infinite Scroll Implementation (Frontend)

**Feature:** Hotels auto-load when user scrolls near bottom of page

**Location:** `v10/app/views/modules/stays/listing/stays.php`

**Key Components:**

1. **Pagination State Management**
```javascript
// Alpine.js data structure
{
    currentPage: {},         // {hotels: 1, hotelbeds: 1}
    hasMorePages: {},        // {hotels: true, hotelbeds: true}
    loadingMore: false,      // Prevents duplicate loads
    allSuppliersExhausted: false,
    showNoMoreMessage: false,
    scrollListenerAttached: false,
    targetPage: null         // For URL hash deep linking
}
```

2. **Scroll Detection**
```javascript
attachScrollListener() {
    if (this.scrollListenerAttached) return;
    
    const scrollHandler = () => {
        const scrollPosition = window.innerHeight + window.scrollY;
        const pageHeight = document.documentElement.scrollHeight;
        const threshold = 500; // Trigger 500px before bottom
        
        if (scrollPosition >= pageHeight - threshold) {
            this.loadMoreHotels();
        }
    };
    
    window.addEventListener('scroll', scrollHandler);
    this.scrollListenerAttached = true;
}
```

3. **Load More Logic**
```javascript
async loadMoreHotels() {
    if (this.loadingMore) return; // Prevent duplicate calls
    
    const suppliersWithMore = this.suppliers.filter(s => this.hasMorePages[s]);
    if (suppliersWithMore.length === 0) {
        // All exhausted - show message only if page > 1
        if (!this.allSuppliersExhausted) {
            this.allSuppliersExhausted = true;
            const hasLoadedMultiplePages = Object.values(this.currentPage).some(page => page > 1);
            if (hasLoadedMultiplePages) {
                this.showNoMoreMessage = true;
                setTimeout(() => { this.showNoMoreMessage = false; }, 5000);
            }
        }
        return;
    }
    
    this.loadingMore = true;
    
    // Update URL hash (e.g., #2, #3)
    const maxPage = Math.max(...Object.values(this.currentPage));
    if (maxPage >= 1) {
        const newHash = maxPage + 1;
        if (newHash > 1) {
            window.history.replaceState(null, '', '#' + newHash);
        }
    }
    
    // Load next page from each supplier
    const loadPromises = suppliersWithMore.map(async (supplier) => {
        const nextPage = this.currentPage[supplier] + 1;
        this.currentPage[supplier] = nextPage;
        const hotels = await this.fetchSupplier(supplier, nextPage);
        if (hotels?.length) { this.mergeResults(hotels); }
    });
    
    await Promise.allSettled(loadPromises);
    this.loadingMore = false;
}
```

4. **URL Hash Deep Linking**
```javascript
// On page load, check for #2, #3 in URL
checkUrlHash() {
    const hash = window.location.hash.replace('#', '');
    const pageNum = parseInt(hash);
    if (pageNum && pageNum > 1) {
        this.targetPage = pageNum;
    }
}

// Auto-load pages up to target
async autoLoadToPage(targetPage) {
    let currentMaxPage = Math.max(...Object.values(this.currentPage));
    
    while (currentMaxPage < targetPage) {
        const suppliersWithMore = this.suppliers.filter(s => this.hasMorePages[s]);
        if (suppliersWithMore.length === 0) break;
        
        await this.loadMoreHotels();
        currentMaxPage = Math.max(...Object.values(this.currentPage));
        await new Promise(resolve => setTimeout(resolve, 500));
    }
}
```

5. **Header Parsing**
```javascript
async fetchSupplier(supplier, page = 1) {
    const form = new FormData();
    form.append('page', page);
    form.append('per_page', 25);
    // ... other params
    
    const res = await fetch(`/modules/stays/${supplier}/search`, {
        method: 'POST',
        body: form
    });
    
    // Capture pagination headers
    const hasMore = res.headers.get('X-Has-More') === 'true';
    this.hasMorePages[supplier] = hasMore;
    
    const hotels = await res.json();
    return hotels;
}
```

6. **Card Animation Effects**
```html
<!-- Staggered fade-in animation -->
<template x-for="(hotel, index) in filteredHotels" :key="hotel.id">
    <div x-html="renderHotelCard(hotel, index)"
         x-init="$el.style.opacity = '0'; 
                 $el.style.transform = 'translateY(10px)'; 
                 setTimeout(() => { 
                     $el.style.transition = 'all 0.2s ease-out'; 
                     $el.style.opacity = '1'; 
                     $el.style.transform = 'translateY(0)'; 
                 }, Math.min(index * 10, 300))"
         class="hotel-card-animate"></div>
</template>
```

**UX Features:**
- Loads at 500px before bottom (early loading for smooth experience)
- Loading spinner shows during fetch
- Cards animate in with stagger effect (10ms delay between cards, max 300ms)
- "No more stays" message only after page 2+
- URL updates with hash (#2, #3) for sharing
- Deep linking: Opening URL with #3 auto-loads pages 1-3

### Code Standards

**1. Function Documentation**
```php
/**
 * Import a chunk of hotels from Hotelbeds API
 * 
 * This function processes a single chunk (100 hotels) from the Hotelbeds content API,
 * including all related data such as facilities, images, and room types. It handles
 * both fresh installation and update modes with appropriate data persistence strategies.
 *
 * @param object $hotelbedsDb - Medoo database instance connected to hotelbeds database
 * @param string $apiKey - Hotelbeds API key for authentication
 * @param string $apiSecret - Hotelbeds API secret for signature generation
 * @param int $from - Starting hotel index (1-based, inclusive)
 * @param int $to - Ending hotel index (1-based, inclusive)
 * @param string $mode - Import mode: 'fresh' (replace all) or 'update' (merge existing)
 * @param string $environment - API environment: 'test' or 'live'
 * 
 * @return array {
 *     @type bool $success - Whether chunk processing succeeded
 *     @type int $records_processed - Number of hotels successfully imported
 *     @type int $skipped - Number of hotels skipped due to errors
 *     @type bool $is_last_chunk - Whether this was the final chunk
 *     @type array $terminal_logs - Log messages for frontend display
 *     @type string $error - Error message if success is false
 * }
 * 
 * @throws Exception - On catastrophic failures (API unreachable, database connection lost)
 * 
 * @example
 * $result = importHotelbedsChunk($db, $apiKey, $apiSecret, 1, 100, 'fresh', 'test');
 * if ($result['success']) {
 *     echo "Imported {$result['records_processed']} hotels";
 * }
 */
function importHotelbedsChunk($hotelbedsDb, $apiKey, $apiSecret, $from, $to, $mode = 'fresh', $environment = 'test') {
    // Implementation
}
```

**2. Error Handling Pattern**
```php
try {
    // Operation that may fail
    $result = $db->insert('table', $data);
    $recordsProcessed++;
} catch (PDOException $e) {
    // Log error
    $errorMsg = "[" . date('H:i:s') . "] [ERROR] Failed to insert: " . $e->getMessage();
    error_log("[MODULE] " . $errorMsg);
    $terminalLogs[] = $errorMsg;
    $skippedRecords++;
}
```

**3. Database Query Patterns**
```php
// INSERT with error handling
try {
    $hotelbedsDb->insert('hotelbeds_hotels', [
        'hotel_code' => $code,
        'name' => $name,
        'created_at' => $hotelbedsDb->raw('NOW()')
    ]);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate entry') === false) {
        error_log("Insert failed: " . $e->getMessage());
    }
}

// UPDATE with conditional
$existing = $hotelbedsDb->get('hotelbeds_hotels', 'id', [
    'hotel_code' => $code
]);

if ($existing) {
    $hotelbedsDb->update('hotelbeds_hotels', $data, [
        'hotel_code' => $code
    ]);
} else {
    $hotelbedsDb->insert('hotelbeds_hotels', $data);
}

// SELECT with JOIN
$hotels = $hotelbedsDb->select('hotelbeds_hotels', [
    '[>]hotelbeds_categories' => ['category_code' => 'code'],
    '[>]hotelbeds_chains' => ['chain_code' => 'code']
], [
    'hotelbeds_hotels.hotel_code',
    'hotelbeds_hotels.name',
    'hotelbeds_categories.description(category_name)',
    'hotelbeds_chains.description(chain_name)'
], [
    'hotelbeds_hotels.country_code' => 'ES',
    'LIMIT' => 50
]);
```

**4. Frontend AJAX Pattern**
```javascript
fetch('<?= root ?>modules/stays/hotelbeds/endpoint', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        // Handle success
        updateUI(data);
    } else {
        // Handle error
        showError(data.error);
    }
})
.catch(error => {
    // Handle network/parsing errors
    console.error('Request failed:', error);
});
```

### Currency & Pricing Configuration

**CRITICAL:** Each supplier module has its own base currency configured in `modules.currency` field.

**Example:**
- Manual Hotels module: `currency = 'USD'`
- Hotelbeds module: `currency = 'EUR'`

**Pricing Flow:**
1. **Base Price:** Retrieved from database or API in module's currency
2. **Markup Application:** MARKUP() applies B2B/B2C markup in module's currency
3. **Currency Conversion:** Converts marked-up price to user's display currency (`$_SESSION['app_currency']`)

**Implementation:**
```php
// Get module currency (NOT hardcoded!)
$moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'EUR';

// Apply markup + conversion
$priceWithMarkup = MARKUP($basePrice, 'stays', $db, $moduleCurrency, $sessionCurrency);

// Extract converted price
$displayPrice = $priceWithMarkup['price'];
```

**Common Mistakes:**
- ❌ Hardcoding currency: `$apiCurrency = 'EUR'`
- ❌ Not reading from `modules.currency` field
- ❌ Calling MARKUP() twice on same price
- ✅ Always use `$module['currency']` dynamically

**Why This Matters:**
- Module currency can be changed in admin panel
- B2C markup changes should reflect immediately
- Different suppliers use different base currencies
- User sees prices in their preferred currency

### Image Fallback Handling

**Problem:** Some hotel images fail to load (broken URLs, missing files)

**Solution:** Implement `onerror` handler to fallback to placeholder

**Location:** `v10/app/views/modules/stays/listing/stays-items.php`

```html
<!-- Hotel card image with fallback -->
<img x-show="currentImage === index"
     :src="img"
     alt="${h.name.replace(/"/g, '&quot;')}"
     class="w-full h-48 md:h-full object-cover"
     onerror="this.onerror=null;this.src='<?=root?>uploads/no_img.jpg'">
```

**Key Points:**
- `this.onerror=null` - Prevents infinite loop if fallback also fails
- `<?=root?>uploads/no_img.jpg` - Path to placeholder image
- Applied to all hotel card images in carousel

**Creating Placeholder Image:**
```bash
# Create a simple placeholder (Linux/Mac)
convert -size 800x600 -background "#f3f4f6" \
        -fill "#9ca3af" -gravity center \
        -pointsize 48 label:"No Image Available" \
        uploads/no_img.jpg

# Or use data URI for inline SVG placeholder:
data:image/svg+xml,%3Csvg width='400' height='300' xmlns='http://www.w3.org/2000/svg'%3E
  %3Crect width='400' height='300' fill='%23f3f4f6'/%3E
  %3Ctext x='50%25' y='50%25' font-family='Arial' font-size='18' 
        text-anchor='middle' dy='.3em' fill='%23999'%3EHotel%3C/text%3E
%3C/svg%3E
```

### Sorting & Filtering

**Default Behavior:** Hotels display in received order (unsorted)

**User Options:**
- Sort By (dropdown)
- Price: Low to High
- Price: High to Low
- Guest Rating
- Star Rating

**Implementation:**
```javascript
sortBy: 'none',  // Default: no sorting

sortAndFilterResults() {
    let result = this.hotels.filter(h => {
        // Apply filters...
        return true;
    });
    
    // Only sort if user explicitly selected option
    if (this.sortBy !== 'none') {
        result.sort((a, b) => {
            switch(this.sortBy) {
                case 'price_low': return (a.price || 0) - (b.price || 0);
                case 'price_high': return b.price - a.price;
                case 'rating': return (b.rating || 0) - (a.rating || 0);
                case 'stars': return (b.stars || 0) - (a.stars || 0);
                default: return 0;
            }
        });
    }
    
    this.filteredHotels = result;
}
```

**Why Default Unsorted:**
- Allows users to see new hotels as they load at bottom
- Prevents confusion during infinite scroll
- Respects API/database order
- User can sort anytime via dropdown

### Loading Spinner Design

**Modern Dual-Ring Spinner:**

```html
<div x-show="loadingMore" class="flex justify-center items-center py-8 mt-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border px-8 py-6">
        <div class="flex items-center gap-4">
            <!-- Dual ring spinner -->
            <div class="relative w-10 h-10">
                <div class="absolute inset-0 rounded-full border-4 border-gray-200 dark:border-gray-700"></div>
                <div class="absolute inset-0 rounded-full border-4 border-transparent border-t-blue-600 border-r-purple-600 animate-spin"></div>
            </div>
            <div class="flex flex-col">
                <p class="text-sm font-semibold">Loading more stays...</p>
                <p class="text-xs text-gray-500">Please wait</p>
            </div>
        </div>
    </div>
</div>
```

**Features:**
- Card-style design with shadow
- Gradient spinner (blue to purple)
- Dual-ring animation effect
- Text hierarchy (bold main + subtle sub)
- Dark mode support
- Smooth transitions

### Translation Keys

**All lowercase for consistency:**

```html
<!-- Sorting dropdown -->
<option value="none"><?=T::sort?> <?=T::by?></option>
<option value="price_low"><?=T::price?>: <?=T::low?> <?=T::to?> <?=T::high?></option>
<option value="price_high"><?=T::price?>: <?=T::high?> <?=T::to?> <?=T::low?></option>
<option value="rating"><?=T::guest?> <?=T::rating?></option>
<option value="stars"><?=T::star?> <?=T::rating?></option>
```

**Translation files:** `v10/app/lang/{locale}.json`

Example entries:
```json
{
    "sort": "sort",
    "by": "by",
    "price": "price",
    "low": "low",
    "high": "high",
    "guest": "guest",
    "rating": "rating",
    "star": "star",
    "loading": "loading",
    "more": "more",
    "stays": "stays",
    "please": "please",
    "wait": "wait"
}
```

### Adding New Features

**Example: Add Hotel Search Endpoint**

1. **Add Route in content.php**
```php
if ($action === 'search') {
    @ob_end_clean();
    header('Content-Type: application/json');
    
    $searchTerm = $_GET['q'] ?? '';
    $countryCode = $_GET['country'] ?? null;
    
    $hotelbedsDb = getHotelbedsDb();
    
    $conditions = [
        'OR' => [
            'name[~]' => $searchTerm,
            'city[~]' => $searchTerm
        ],
        'LIMIT' => 20
    ];
    
    if ($countryCode) {
        $conditions['country_code'] => $countryCode;
    }
    
    $results = $hotelbedsDb->select('hotelbeds_hotels', [
        'hotel_code',
        'name',
        'city',
        'country_code',
        'star_rating'
    ], $conditions);
    
    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results)
    ]);
    exit;
}
```

2. **Add Frontend Function**
```javascript
function searchHotels(query) {
    fetch(`<?= root ?>modules/stays/hotelbeds/search?q=${encodeURIComponent(query)}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displaySearchResults(data.results);
        }
    });
}
```

3. **Add UI Component**
```html
<input type="text" id="hotelSearch" placeholder="Search hotels..." 
       onkeyup="searchHotels(this.value)">
<div id="searchResults"></div>
```

### Testing Guidelines

**1. Unit Testing Example**
```php
// Test database connection
function testHotelbedsConnection() {
    try {
        $db = getHotelbedsDb();
        $result = $db->query('SELECT 1')->fetch();
        return $result !== false;
    } catch (Exception $e) {
        error_log("Connection test failed: " . $e->getMessage());
        return false;
    }
}

// Test API authentication
function testHotelbedsAuth($apiKey, $apiSecret) {
    $timestamp = time();
    $signature = hash('sha256', $apiKey . $apiSecret . $timestamp);
    
    // Verify signature format
    if (strlen($signature) !== 64) {
        return false;
    }
    
    // Test API call
    $response = callHotelbedsApi(
        'https://api.test.hotelbeds.com/hotel-content-api/1.0/types/boards',
        $apiKey,
        $apiSecret
    );
    
    return isset($response['boards']);
}
```

**2. Import Testing Checklist**
```
□ Fresh install completes without errors
□ Update mode preserves existing data
□ Countries populated after chunk 1
□ Destinations populated after chunk 1
□ Import state persists correctly
□ Pause/resume functionality works
□ Cancel stops processing immediately
□ Progress updates in real-time
□ Error messages display correctly
□ Statistics update after completion
□ Database indexes created properly
□ Foreign key constraints enforced
□ Duplicate handling works correctly
□ Memory doesn't exceed PHP limits
```

---

## Troubleshooting

**Booking funnel QA:** For manual test steps aligned with the gap-analysis todo list, see [`MANUAL-TESTING.md`](./MANUAL-TESTING.md) (companion to [`todos.md`](./todos.md)).

### Common Issues

#### 1. "Page Unresponsive" During Import

**Symptom:** Browser shows "Page Unresponsive" dialog

**Cause:** Long-running chunk processing (20-30 seconds per chunk)

**Solution:** 
- Click "Wait" button
- This is normal behavior for large data processing
- Consider implementing WebSocket for real-time updates

#### 2. "Chunk undefined of undefined" Error

**Symptom:** Frontend displays "undefined" values in progress

**Cause:** 
- Import state JSON corrupted
- `import_state` column was TEXT instead of LONGTEXT
- JSON exceeded 65KB and was truncated

**Solution:**
```sql
-- Fix column type
ALTER TABLE hotelbeds_import_log MODIFY import_state LONGTEXT;

-- Fix corrupted state
UPDATE hotelbeds_import_log 
SET import_state = '{"current_chunk":0,"total_chunks":2348,"processed":0,"total":234760}'
WHERE status = 'in_progress' 
ORDER BY id DESC LIMIT 1;
```

#### 3. Missing Countries/Destinations

**Symptom:** Tables `hotelbeds_countries` and `hotelbeds_destinations` are empty

**Cause:** Test API returns HTTP 404 for these endpoints

**Solution:**
- Automatic: Wait for chunk 1 to complete (auto-populates)
- Manual: POST to `/populate-countries-destinations` endpoint
- Use production API if available

#### 4. Memory Exhaustion

**Symptom:** "Fatal error: Allowed memory size exhausted"

**Cause:** Processing too many hotels at once

**Solution:**
```php
// Increase PHP memory limit temporarily
ini_set('memory_limit', '512M'); // in content.php

// Or reduce chunk size
// Change from 100 to 50 hotels per chunk
$chunkSize = 50;
$totalChunks = ceil(234760 / $chunkSize);
```

#### 5. Import Stuck/Frozen

**Symptom:** Progress stops updating, no new chunks processed

**Cause:** 
- Network timeout
- API rate limiting
- PHP execution timeout

**Solution:**
```php
// Increase timeout in content.php
set_time_limit(300); // 5 minutes

// Check import log for last successful chunk
SELECT * FROM hotelbeds_import_log 
WHERE status = 'in_progress' 
ORDER BY id DESC LIMIT 1;

// Resume from last chunk
// Frontend will continue from current_chunk + 1
```

### Debug Mode

Enable detailed logging:

```php
// In content.php, add at top of importHotelbedsChunk()
$debug = true;

if ($debug) {
    error_log("[HOTELBEDS] Processing chunk $from to $to");
    error_log("[HOTELBEDS] Mode: $mode");
    error_log("[HOTELBEDS] Memory usage: " . memory_get_usage(true));
}
```

### Database Maintenance

```sql
-- Check import status
SELECT 
    status,
    hotels_imported,
    started_at,
    TIMESTAMPDIFF(MINUTE, started_at, NOW()) as minutes_elapsed
FROM hotelbeds_import_log 
ORDER BY id DESC LIMIT 1;

-- Check table sizes
SELECT 
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb,
    table_rows
FROM information_schema.tables
WHERE table_schema = 'hotelbeds'
ORDER BY (data_length + index_length) DESC;

-- Optimize tables after large import
OPTIMIZE TABLE hotelbeds_hotels;
OPTIMIZE TABLE hotelbeds_amenities;
OPTIMIZE TABLE hotelbeds_hotel_rooms;
OPTIMIZE TABLE hotelbeds_hotel_images;

-- Rebuild indexes
ALTER TABLE hotelbeds_hotels ENGINE=InnoDB;
```

---

## Performance Optimization

### Recommended Indexes

```sql
-- For search queries
CREATE INDEX idx_hotel_name ON hotelbeds_hotels(name);
CREATE INDEX idx_hotel_city ON hotelbeds_hotels(city);

-- For filtering
CREATE INDEX idx_hotel_rating ON hotelbeds_hotels(star_rating);
CREATE INDEX idx_hotel_ranking ON hotelbeds_hotels(ranking);

-- For geographical searches
CREATE INDEX idx_hotel_coords ON hotelbeds_hotels(latitude, longitude);

-- Composite indexes for common queries
CREATE INDEX idx_country_city ON hotelbeds_hotels(country_code, city);
CREATE INDEX idx_destination_rating ON hotelbeds_hotels(destination_code, star_rating);
```

### Caching Strategy

```php
// Example: Cache hotel details for 1 hour
$cacheKey = "hotel_" . $hotelCode;
$cached = apcu_fetch($cacheKey);

if ($cached === false) {
    $hotel = $hotelbedsDb->get('hotelbeds_hotels', '*', [
        'hotel_code' => $hotelCode
    ]);
    apcu_store($cacheKey, $hotel, 3600); // 1 hour
} else {
    $hotel = $cached;
}
```

### Background Processing

```bash
# Run import in background (Linux/Unix)
nohup php -r "
    require 'content.php';
    // Run import logic
" > import.log 2>&1 &

# Check progress
tail -f import.log
```

---

## Appendix

### API Response Examples

#### Hotel Object Structure
```json
{
  "code": "1",
  "name": "Ohtels Villa Dorada",
  "categoryCode": "3EST",
  "categoryGroupCode": "HOTEL",
  "chainCode": "OHTEL",
  "accommodationTypeCode": "H",
  "address": {
    "content": "Calle Punta Umbria, 4",
    "number": "4",
    "street": "Calle Punta Umbria"
  },
  "postalCode": "21100",
  "city": { "content": "Punta Umbria" },
  "email": "reservas@ohtelsviladorada.com",
  "phones": [
    { "phoneNumber": "+34959311650", "phoneType": "PHONEHOTEL" }
  ],
  "web": "www.ohtels.com",
  "coordinates": {
    "longitude": -6.966724,
    "latitude": 37.181183
  },
  "categoryCode": "3EST",
  "destinationCode": "PMI",
  "countryCode": "ES",
  "zoneCode": 10,
  "stateCode": "AN",
  "segmentCodes": [1, 3],
  "facilities": [
    {
      "facilityCode": 70,
      "facilityGroupCode": 10,
      "order": 1,
      "number": 110
    }
  ],
  "images": [
    {
      "imageTypeCode": "GEN",
      "path": "09/091448/091448a_hb_a_001.jpg",
      "order": 1,
      "visualOrder": 1
    }
  ],
  "rooms": [
    {
      "roomCode": "DBL.ST",
      "roomType": "DBL",
      "characteristicCode": "ST",
      "roomStays": [],
      "roomFacilities": [
        { "facilityCode": 10, "facilityGroupCode": 60 }
      ]
    }
  ]
}
```

### SQL Queries for Common Operations

```sql
-- Get hotels by country with category info
SELECT 
    h.hotel_code,
    h.name,
    h.city,
    h.star_rating,
    c.description as category,
    ch.description as chain
FROM hotelbeds_hotels h
LEFT JOIN hotelbeds_categories c ON h.category_code = c.code
LEFT JOIN hotelbeds_chains ch ON h.chain_code = ch.code
WHERE h.country_code = 'ES'
ORDER BY h.ranking DESC
LIMIT 50;

-- Get hotel facilities
SELECT 
    h.name as hotel_name,
    f.description as facility_name,
    a.facility_group_code,
    a.distance
FROM hotelbeds_amenities a
INNER JOIN hotelbeds_hotels h ON a.hotel_code = h.hotel_code
INNER JOIN hotelbeds_facilities f ON a.facility_code = f.code
WHERE h.hotel_code = '1'
ORDER BY a.order_by;

-- Get available room types per hotel
SELECT 
    h.name as hotel_name,
    hr.room_code,
    hr.room_type,
    hr.description,
    hr.min_pax,
    hr.max_pax,
    hr.max_adults,
    hr.max_children
FROM hotelbeds_hotel_rooms hr
INNER JOIN hotelbeds_hotels h ON hr.hotel_code = h.hotel_code
WHERE h.city = 'Barcelona'
ORDER BY h.name, hr.room_code;

-- Find hotels near coordinates (within ~10km)
SELECT 
    hotel_code,
    name,
    city,
    latitude,
    longitude,
    (6371 * acos(
        cos(radians(41.3851)) * 
        cos(radians(latitude)) * 
        cos(radians(longitude) - radians(2.1734)) + 
        sin(radians(41.3851)) * 
        sin(radians(latitude))
    )) AS distance_km
FROM hotelbeds_hotels
HAVING distance_km < 10
ORDER BY distance_km
LIMIT 20;
```

---

## Change Log

### Version 1.0 (December 9, 2025)

**Initial Release:**
- Complete import system for 234,760 hotels
- 13 database tables with full schema
- Reference data import (8 types)
- Chunk-based processing (100 hotels per chunk)
- Real-time progress tracking
- Pause/resume/cancel functionality
- Countries/destinations workaround for test API 404s
- Professional error handling and logging

**Bug Fixes:**
- Fixed TEXT to LONGTEXT for import_state column
- Fixed corrupted JSON state during large imports
- Removed verbose hotel-by-hotel logging
- Optimized performance with chunk summaries only

**Known Issues:**
- Description fields remain blank (API limitation)
- Test API lacks countries/destinations endpoints
- Long import duration (~16 hours for full dataset)

---

## Credits & License

**Developed By:** Senior PHP Development Team  
**Framework:** PHP 8.2 + Medoo ORM + Alpine.js  
**Database:** MySQL 8.0 with InnoDB engine  
**API Provider:** Hotelbeds (www.hotelbeds.com)

**License:** Proprietary - Internal Use Only

---

## Contact & Support

For technical support or feature requests, please contact:
- Development Team: dev@phptravels.com
- API Documentation: https://developer.hotelbeds.com
- Module Issues: Submit to internal ticketing system

**Last Updated:** December 9, 2025  
**Documentation Version:** 1.0  
**Module Version:** 1.0
