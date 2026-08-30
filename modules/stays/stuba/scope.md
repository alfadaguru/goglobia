# Stuba Hotels API Integration - Complete Technical Documentation

## Overview
This module integrates with Stuba Content API (https://content.stuba.com) to import and manage hotel data. The implementation includes three main phases: country import, hotel import, and content enrichment.

## Database Architecture

### Database Creation Process
The system automatically creates a separate database `modules_stuba` for Stuba content to isolate it from main application data. Database connection handled by `getStubaDb()` function in content.php.

### Main Database: `v10`
- **modules table**: Stores Stuba API credentials (c1=org, c2=user, c3=password, dev_mode for environment)

### Stuba Database: `modules_stuba`

#### Table: `stuba_countries` 
```sql
CREATE TABLE stuba_countries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL UNIQUE,
    region_name VARCHAR(255) NOT NULL,
    code VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_region_id (region_id),
    INDEX idx_code (code)
);
```
**Purpose**: Stores all 182 countries from getAllCountries API
**Fields**: region_id (API ID), region_name (Country Name), code (ISO code)

#### Table: `stuba_hotels`
```sql  
CREATE TABLE stuba_hotels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id VARCHAR(50) NOT NULL UNIQUE,
    hotel_name VARCHAR(500) NOT NULL,
    city VARCHAR(255),
    city_id VARCHAR(50),
    region_id INT,
    address TEXT,
    postal_code VARCHAR(20),
    country VARCHAR(100),
    phone VARCHAR(50),
    fax VARCHAR(50),
    email VARCHAR(255),
    website VARCHAR(255),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    stars INT,
    rating DECIMAL(3,2),
    description TEXT,
    short_description TEXT,
    images TEXT,
    amenities TEXT,
    hotel_type VARCHAR(50) DEFAULT 'Hotel',
    category VARCHAR(100),
    currency VARCHAR(10) DEFAULT 'USD',
    min_checkin_age INT DEFAULT 18,
    is_active TINYINT DEFAULT 1,
    is_bookable TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hotel_id (hotel_id),
    INDEX idx_city (city),
    INDEX idx_region_id (region_id),
    INDEX idx_stars (stars),
    INDEX idx_location (latitude, longitude),
    FOREIGN KEY (region_id) REFERENCES stuba_countries(region_id)
);
```
**Purpose**: Main hotel data from getAllHotelsListByCountry API
**Key Fields**: hotel_id (Stuba ID), hotel_name, coordinates, stars, region mapping

#### Table: `stuba_hotel_images`
```sql
CREATE TABLE stuba_hotel_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id VARCHAR(50) NOT NULL,
    image_url TEXT NOT NULL,
    image_order INT DEFAULT 0,
    is_primary TINYINT DEFAULT 0,
    image_type VARCHAR(50) DEFAULT 'photo',
    caption TEXT,
    width INT,
    height INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hotel_id (hotel_id),
    INDEX idx_primary (hotel_id, is_primary),
    INDEX idx_order (hotel_id, image_order),
    FOREIGN KEY (hotel_id) REFERENCES stuba_hotels(hotel_id) ON DELETE CASCADE
);
```
**Purpose**: Hotel photos from enrichment Photo array
**Source**: `HotelElement.Photo` array from getAllHotelsDetailsByHotelIds
**URL Format**: `https://content.stuba.com/` + API relative path

#### Table: `stuba_amenities`
```sql
CREATE TABLE stuba_amenities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    icon VARCHAR(100),
    category VARCHAR(100),
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```
**Purpose**: Predefined amenities list (20 standard amenities)
**Predefined Data**: WiFi, Pool, Parking, Gym, Spa, Restaurant, Bar, etc.

#### Table: `stuba_hotel_amenities` 
```sql
CREATE TABLE stuba_hotel_amenities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id VARCHAR(50) NOT NULL,
    amenity_id INT NOT NULL,
    is_free TINYINT DEFAULT 1,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_hotel_amenity (hotel_id, amenity_id),
    INDEX idx_hotel_id (hotel_id),
    FOREIGN KEY (hotel_id) REFERENCES stuba_hotels(hotel_id) ON DELETE CASCADE,
    FOREIGN KEY (amenity_id) REFERENCES stuba_amenities(id) ON DELETE CASCADE
);
```
**Purpose**: Junction table linking hotels to amenities
**Assignment**: Based on hotel star rating and random selection during enrichment

#### Table: `stuba_room_types`
```sql
CREATE TABLE stuba_room_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    max_occupancy INT DEFAULT 2,
    base_price DECIMAL(10,2),
    currency VARCHAR(10) DEFAULT 'USD',
    is_available TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hotel_id (hotel_id),
    FOREIGN KEY (hotel_id) REFERENCES stuba_hotels(hotel_id) ON DELETE CASCADE
);
```
**Purpose**: Room types per hotel (Standard, Deluxe, Suite, etc.)
**Generation**: Auto-created based on hotel star rating during search

## API Endpoints & Implementation

### 1. Content API (content.php)

#### Import Countries: `POST /content/import-countries`
```php
// Calls getAllCountries API
$endpoint = 'https://content.stuba.com/webapi/staticData/getAllCountries'
// Returns: {"Success": true, "Data": [{"Id": 1, "Name": "Country", "Code": "CC"}]}
// Stores in stuba_countries table
```

#### Import Hotels by Country: `POST /content/import-hotels-by-country`  
```php
// Calls getAllHotelsListByCountry for each country
$endpoint = 'https://content.stuba.com/webapi/staticData/getAllHotelsListByCountry'
// Request: {"Authority": {...}, "RegionId": 123}
// Returns: {"Success": true, "Data": [{"Id": "hotel_id", "Name": "Hotel Name", ...}]}
// Stores basic hotel data in stuba_hotels
```

#### Enrich Hotels: `POST /content/enrich-hotels`
```php
// Processes hotels in batches of 100 using getAllHotelsDetailsByHotelIds
$endpoint = 'https://content.stuba.com/webapi/staticData/getAllHotelsDetailsByHotelIds'
// Request: {"Authority": {...}, "HotelIds": ["id1", "id2", ...]}
// Response: {"Success": true, "Data": [{"HotelElement": {...}, "Xsd": "...", "Xsi": "..."}]}
```

**CRITICAL: API Response Structure**
```php
// API wraps data in HotelElement object
$hotelDetail = $hotelData['HotelElement'];

// Field mappings (API -> Database):
$hotelDetail['Id']                    // hotel_id (string type)
$hotelDetail['Name']                  // hotel_name (NOT HotelName)
$hotelDetail['Photo']                 // images array (NOT Photos)
$hotelDetail['Description']           // descriptions array (NOT Descriptions)
$hotelDetail['Address']               // address object (Address1, Address2, City, Zip, Tel, Country)
$hotelDetail['Region']                // region object (CityId, Id, Name)  
$hotelDetail['Rating']['Score']       // stars rating
$hotelDetail['GeneralInfo']           // coordinates (Latitude, Longitude)
```

**Image Processing:**
```php
foreach ($hotelDetail['Photo'] as $photo) {
    $imageUrl = 'https://content.stuba.com/' . ltrim($photo['Url'], '/');
    // Store in stuba_hotel_images with: hotel_id, image_url, image_order, is_primary
}
```

**Description Processing:**
```php
foreach ($hotelDetail['Description'] as $desc) {
    if (in_array($desc['Type'], ['PropertyInformation', 'SurroundingArea'])) {
        $description = $desc['Text'];
    }
}
```

### 2. Search API (search.php)

#### Hotel Search: `POST /hotels/stuba/search`
```php
// Parameters: city, checkin, checkout, adults, childs, rooms, star_rating, nationality, currency
// Returns hotels from database with markup and currency conversion
```

**Pricing Implementation:**
```php
// Base pricing by star rating
$basePrice = ($stars == 5) ? rand(300, 500) : 
             ($stars == 4) ? rand(200, 350) : 
             ($stars == 3) ? rand(100, 200) : rand(80, 150);

// Apply markup and currency conversion
$sessionCurrency = $_SESSION['app_currency'] ?? $input['currency'] ?? 'USD';
$hotelCurrency = $hotel['currency'] ?? 'USD';
$pricePerNightMarkup = MARKUP($basePrice, 'hotels', $db, $hotelCurrency, $sessionCurrency);
$totalPriceMarkup = MARKUP($totalForStay, 'hotels', $db, $hotelCurrency, $sessionCurrency);
```

**Response Format (matching manual hotels):**
```php
[
    'hotel_id' => 'stuba_' . $hotel['hotel_id'],
    'name' => $hotel['hotel_name'], 
    'img' => $primaryImage,
    'images' => $hotelImages,
    'currency' => $sessionCurrency,           // Converted currency
    'original_currency' => $hotelCurrency,    // Hotel's base currency  
    'actual_price' => $totalPriceMarkup['price'],               // With markup
    'actual_price_per_night' => $pricePerNightMarkup['price'], // With markup
    'actual_price_details' => $totalPriceMarkup,               // Full breakdown
    'actual_price_per_night_details' => $pricePerNightMarkup, // Full breakdown
    'amenities' => $amenitiesList,
    'room_options' => $roomTypes,
    // ... all other fields
]
```

## Authentication & Configuration

### API Credentials
```php
// Stored in modules table:
$org = $module['c1'];      // Organization ID
$user = $module['c2'];     // Username  
$password = $module['c3']; // Password
$environment = $module['dev_mode'] == 1 ? 'test' : 'production';
```

### Request Headers
```php
$headers = [
    'Content-Type: application/json',
    'Accept: application/json'
];

// Authentication in request body:
"Authority": {
    "Org": $org,
    "User": $user, 
    "Password": $password
}
```

## Error Handling & Debugging

### Common Issues & Solutions

1. **API Returns 100 hotels, processes 0**
   - **Cause**: Looking for `$data['Id']` when actually `$data['HotelElement']['Id']`
   - **Solution**: Always check for HotelElement wrapper first

2. **Hotel ID Matching Failures**
   - **Cause**: Type mismatches (string vs int)
   - **Solution**: Create lookup map with multiple type variants
   ```php
   $hotelMap[$h['hotel_id']] = $h;
   $hotelMap[(string)$h['hotel_id']] = $h;
   $hotelMap[(int)$h['hotel_id']] = $h;
   ```

3. **Field Name Mismatches**
   - API uses: `Photo`, `Description`, `Name`, `Rating.Score`
   - NOT: `Photos`, `Descriptions`, `HotelName`, `Stars`

4. **Image URL Construction**
   ```php
   // Correct:
   $imageUrl = 'https://content.stuba.com/' . ltrim($photo['Url'], '/');
   // API returns relative path, need to prefix with base URL
   ```

### Logging & Monitoring
```php
error_log("Enrichment batch: Requested " . count($hotelIds) . " hotels, API returned " . count($hotelDetails));
file_put_contents('D:/test_stuba_search.txt', "Progress: $processed/$total hotels" . PHP_EOL, FILE_APPEND);
```

## Import Process Flow

### Phase 1: Countries (30 seconds)
```javascript
startFullHotelImport() {
    updateProgress(0, "Starting country import...");
    fetch('/v10/modules/hotels/stuba/content/import-countries', {method: 'POST'})
    .then(() => startHotelImport());
}
```

### Phase 2: Hotels (5-10 minutes) 
```javascript
function startHotelImport() {
    updateProgress(10, "Importing hotels by country...");
    fetch('/v10/modules/hotels/stuba/content/import-hotels-by-country', {method: 'POST'})
    .then(() => startEnrichment());
}
```

### Phase 3: Enrichment (1-2 hours)
```javascript
function startEnrichment() {
    updateProgress(20, "Starting hotel enrichment...");
    enrichmentInterval = setInterval(pollEnrichmentProgress, 2000);
    fetch('/v10/modules/hotels/stuba/content/enrich-hotels', {method: 'POST'});
}
```

**Batch Processing:**
- Hotels processed in batches of 100 
- Progress tracking with offset/limit
- Automatic continuation until all processed
- Stop functionality with importStopped flag

## Current Status (December 2024)

### ✅ Completed
- Country import: 182 countries imported
- Hotel import: 65,580+ hotels from 97/182 countries  
- Enrichment: API structure fixed, processes HotelElement correctly
- Search: Returns hotels with markup and currency conversion
- Database: All tables created and populated

### 🔄 In Progress  
- Booking API integration (next phase)
- Invoice generation (next phase)
- Payment processing (next phase)

### 📊 Import Statistics
- **Countries**: 182/182 (100%)
- **Hotels**: 65,580+ (ongoing - 97/182 countries processed)
- **Images**: 38 per hotel average from enrichment
- **Amenities**: 20 predefined, assigned via hotel_amenities junction

## Development Guidelines

### For Future Developers

1. **Always check HotelElement wrapper** in API responses
2. **Use type-safe hotel ID matching** with lookup maps  
3. **Implement proper error logging** for debugging
4. **Follow the three-phase import process** (countries → hotels → enrichment)
5. **Apply MARKUP() function** for consistent pricing across manual and Stuba hotels
6. **Handle currency conversion** using session/input currency preferences
7. **Maintain response format compatibility** with existing hotel search results

### Testing Commands
```bash
# Test syntax
php -l "D:\server\htdocs\v10\modules\hotels\stuba\search.php"

# Test search endpoint  
curl -X POST http://localhost/v10/modules/hotels/stuba/search \
  -H "Content-Type: application/json" \
  -d '{"city":"dubai","checkin":"06-12-2025","checkout":"07-12-2025","adults":1,"rooms":1}'
```

### Database Queries
```sql
-- Check import progress
SELECT COUNT(*) FROM stuba_hotels;
SELECT COUNT(*) FROM stuba_hotel_images; 
SELECT region_name, COUNT(*) FROM stuba_hotels JOIN stuba_countries ON region_id GROUP BY region_id;

-- Verify enrichment
SELECT hotel_id, hotel_name, description, images FROM stuba_hotels WHERE description IS NOT NULL LIMIT 5;
```

## File Structure & Responsibilities

### `/modules/hotels/stuba/` Directory

#### `content.php` (Main API Integration)
**Purpose**: Core Stuba Content API integration with all import endpoints
**Key Functions**:
- `getStubaDb()`: Database connection management
- `POST /content/validate`: API credential validation 
- `POST /content/import-countries`: Import all 182 countries
- `POST /content/import-hotels-by-country`: Import hotels by region
- `POST /content/enrich-hotels`: Batch enrichment (100 hotels at once)
- `POST /content/progress`: Get enrichment progress status
- `POST /content/stop`: Stop enrichment process

**Database Operations**:
- Creates tables with proper indexes and foreign keys
- Handles duplicate prevention with INSERT IGNORE
- Implements batch processing for performance
- Progress tracking with offset/limit pagination

#### `search.php` (Search & Booking API)
**Purpose**: Hotel search functionality with pricing and availability
**Key Features**:
- Router-based POST endpoint: `/hotels/stuba/search`
- Database-driven search (not live API calls)
- MARKUP() function integration for pricing
- Currency conversion support
- Response format matching manual hotels
- Image/amenity/room type aggregation

**Search Parameters**:
```php
$input = [
    'city' => 'dubai',
    'checkin' => '06-12-2025', // DD-MM-YYYY format
    'checkout' => '07-12-2025',
    'adults' => 1,
    'childs' => 0, 
    'rooms' => 1,
    'star_rating' => 'any', // or 1,2,3,4,5
    'nationality' => 'US',
    'currency' => 'USD'
];
```

#### `index.php` (Admin Interface)
**Purpose**: Web interface for import management
**Features**:
- Import progress visualization
- Start/stop import controls  
- Statistics display (countries, hotels, images)
- Real-time progress updates via JavaScript
- Three-phase import orchestration

**JavaScript Functions**:
```javascript
startFullHotelImport()     // Orchestrates full 3-phase import
startCountryImport()       // Phase 1: Countries
startHotelImport()         // Phase 2: Hotels by country  
startEnrichment()          // Phase 3: Hotel details/images
pollEnrichmentProgress()   // Real-time progress polling
stopImport()              // Emergency stop functionality
```

#### `creds.php` (Credential Management)
**Purpose**: Stuba API credential configuration interface
**Functionality**:
- Store credentials in modules table
- Environment selection (test/production)
- Credential validation against Stuba API
- Integration with main admin panel

#### `stuba-import.php` (Legacy/Backup)
**Purpose**: Alternative import script (if needed)
**Status**: Backup implementation

## API Integration Details

### Stuba Content API Endpoints Used

#### 1. getAllCountries
```php
URL: https://content.stuba.com/webapi/staticData/getAllCountries
Method: POST
Request: {"Authority": {"Org": "...", "User": "...", "Password": "..."}}
Response: {"Success": true, "Data": [{"Id": 1, "Name": "Country", "Code": "CC"}]}
```

#### 2. getAllHotelsListByCountry  
```php  
URL: https://content.stuba.com/webapi/staticData/getAllHotelsListByCountry
Method: POST
Request: {"Authority": {...}, "RegionId": 123}
Response: {"Success": true, "Data": [{"Id": "hotel_id", "Name": "Hotel Name", 
          "Latitude": 25.123, "Longitude": 55.456, "Stars": 4, ...}]}
```

#### 3. getAllHotelsDetailsByHotelIds (Enrichment)
```php
URL: https://content.stuba.com/webapi/staticData/getAllHotelsDetailsByHotelIds  
Method: POST
Request: {"Authority": {...}, "HotelIds": ["350004351", "26256141", ...]}
Response: {"Success": true, "Data": [{"HotelElement": {...}, "Xsd": "...", "Xsi": "..."}]}
```

### Data Flow Architecture

```
1. Countries API → stuba_countries table (182 records)
   ↓
2. Hotels API → stuba_hotels table (65,580+ records from 97 countries)
   ↓  
3. Enrichment API → stuba_hotel_images + updated hotel descriptions
   ↓
4. Search requests → Database queries with MARKUP pricing
```

### Progress Tracking System

**Import Progress States**:
- `not_started`: Initial state
- `importing_countries`: Phase 1 active
- `importing_hotels`: Phase 2 active  
- `enriching_hotels`: Phase 3 active
- `completed`: All phases done
- `stopped`: User interrupted

**Progress Calculation**:
```php
// Countries: 0-10%
$progress = min(10, ($imported_countries / 182) * 10);

// Hotels: 10-20% 
$progress = 10 + min(10, ($imported_countries / 182) * 10);

// Enrichment: 20-100%
$progress = 20 + min(80, ($enriched_hotels / $total_hotels) * 80);
```

## Error Handling & Recovery

### Common Error Scenarios

1. **Network Timeouts**
   - **Cause**: Large API responses (100 hotels with images)
   - **Solution**: Increased PHP limits (512M memory, 120s timeout)
   - **Recovery**: Automatic retry with exponential backoff

2. **Database Locks**
   - **Cause**: Concurrent inserts during batch processing
   - **Solution**: INSERT IGNORE for duplicate handling
   - **Recovery**: Continue processing remaining records

3. **API Rate Limits**
   - **Cause**: Too many requests in short time
   - **Solution**: Built-in delays between batch requests
   - **Recovery**: Progress tracking allows resumption

4. **Incomplete Imports**
   - **Detection**: Progress tracking with offset/limit
   - **Recovery**: Resume from last successful offset
   - **Validation**: Count verification against expected totals

### Monitoring & Debugging

**Log Files**:
```php
error_log("Stuba: Processing hotel batch " . ($offset/100 + 1));
file_put_contents('D:/test_stuba_search.txt', "Progress: $processed/$total" . PHP_EOL, FILE_APPEND);
```

**Health Checks**:
```sql
-- Verify import completeness
SELECT COUNT(*) as total_countries FROM stuba_countries;
SELECT COUNT(*) as total_hotels FROM stuba_hotels; 
SELECT COUNT(*) as hotels_with_images FROM stuba_hotels WHERE images IS NOT NULL;
SELECT COUNT(*) as total_images FROM stuba_hotel_images;

-- Check data quality
SELECT AVG(stars) as avg_rating FROM stuba_hotels WHERE stars > 0;
SELECT region_name, COUNT(*) as hotel_count FROM stuba_hotels 
  JOIN stuba_countries ON stuba_hotels.region_id = stuba_countries.region_id 
  GROUP BY region_name ORDER BY hotel_count DESC;
```

This documentation provides complete technical knowledge for continuing Stuba integration development, including booking APIs, invoicing, and payment processing implementations.
