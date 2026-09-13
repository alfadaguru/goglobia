<?php

// Global helper functions for all modules

if (!function_exists('REQUIRED')) {
    function REQUIRED($keys,$src=null) {
        $src = $src ?? $_POST;
        foreach((array)$keys as $k){
            if(!isset($src[$k]) || trim($src[$k])===""){
                echo "$k : param missing"; http_response_code(400); exit;
            }
        }
        return $src;
    }
}

if (!function_exists('timetodate')) {
    function timetodate($departure, $arrival) {
        $datetime1 = new DateTime($departure);
        $datetime2 = new DateTime($arrival);
        $interval = $datetime1->diff($datetime2);
        return $interval->format('%h:%i');
    }
}

if (!function_exists('dd')) {
    function dd($data) {
        echo "<pre>";
        print_r($data);
        echo "</pre>";
        die;
    }
}

if (!function_exists('supplier_timeout_config')) {
    function supplier_timeout_config() {
        return [
            'connect' => defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10,
            'request' => defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 30,
        ];
    }
}

if (!function_exists('MARKUP')) {
    function MARKUP($price, $module, $db, $fromCurrency = null, $toCurrency = null) {
        $isAgent = false;
        $customMarkup = false;
        $userMarkupValue = 0;
        $userMarkupType = 'percentage';

        $userId = $_SESSION['user_id'] ?? null;
        $sessionRole = $_SESSION['user_role'] ?? null;

        // JWT verification fallback for Mobile/API requests
        if (empty($userId) || empty($sessionRole)) {
            if (!class_exists('JWT')) {
                $jwtPath = dirname(__DIR__) . '/app/lib/jwt.php';
                if (file_exists($jwtPath)) {
                    require_once $jwtPath;
                }
            }
            if (class_exists('JWT')) {
                $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
                $headersLower = [];
                foreach ($allHeaders as $k => $v) {
                    $headersLower[strtolower($k)] = $v;
                }
                foreach ($_SERVER as $k => $v) {
                    if (str_starts_with($k, 'HTTP_')) {
                        $hKey = strtolower(str_replace('_', '-', substr($k, 5)));
                        $headersLower[$hKey] = $v;
                    }
                }
                if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                    $headersLower['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
                }
                
                // 1. Standard Authorization Header
                $authHeader = $headersLower['authorization'] ?? '';

                $token = '';
                if (!empty($authHeader)) {
                    if (preg_match('/Bearer\s(\S+)/i', $authHeader, $jwtMatches)) {
                        $token = $jwtMatches[1];
                    } else {
                        $token = trim($authHeader);
                    }
                }

                // 2. Custom headers fallback
                if (empty($token)) {
                    $token = $headersLower['token']
                          ?? $headersLower['jwt']
                          ?? $headersLower['x-access-token']
                          ?? '';
                }

                // 3. Request body / GET fallback
                if (empty($token)) {
                    $token = $_POST['token'] ?? $_POST['access_token'] ?? $_POST['jwt']
                          ?? $_GET['token'] ?? $_GET['access_token'] ?? $_GET['jwt']
                          ?? '';
                }

                if (!empty($token)) {
                    try {
                        $tokenData = JWT::verify($token);
                        if (!$tokenData && method_exists('JWT', 'decode')) {
                            $tokenData = JWT::decode($token, false);
                        }
                        if ($tokenData && !empty($tokenData['user_id'])) {
                            $userId = $tokenData['user_id'];
                            if (!empty($tokenData['role'])) {
                                $sessionRole = $tokenData['role'];
                            }
                        }
                    } catch (Exception $e) {
                        // Silent fail
                    }
                }
            }
        }

        // Fetch user data for role identification & custom user/agent markup
        if ($userId) {
            $whereClause = ['user_id' => (string)$userId];
            if (is_numeric($userId)) {
                $whereClause = [
                    'OR' => [
                        'user_id' => (string)$userId,
                        'id'      => (int)$userId
                    ]
                ];
            }
            $userData = $db->get('users', ['role', 'apply_markup', 'markup_type', 'markup_value'], $whereClause);

            if ($userData) {
                if (!empty($userData['role'])) {
                    $sessionRole = $userData['role'];
                }
                if (strtolower($sessionRole ?? '') === 'agent') {
                    $isAgent = true;
                }
                if (($userData['apply_markup'] ?? 'global') === 'custom') {
                    $customMarkup = true;
                    $userMarkupValue = floatval($userData['markup_value'] ?? 0);
                    $userMarkupType = $userData['markup_type'] ?? 'percentage';
                }
            }
        }
        
        // If $module is an array, use it directly (module data passed in)
        // Otherwise, query database by module type
        if (is_array($module)) {
            $moduleData = $module;
        } else {
            $moduleData = $db->get('modules', ['markup_b2b', 'markup_b2c', 'markup_type_b2b', 'markup_type_b2c'], ['type' => $module, 'status' => '1']);
        }
        
        if (!$moduleData) {
            $convertedPrice = $price;
            $currencyConverted = true;

            if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $price > 0) {
                $fromRate = $db->get('currencies', 'rate', ['name' => $fromCurrency, 'status' => '1']);
                $toRate = $db->get('currencies', 'rate', ['name' => $toCurrency, 'status' => '1']);

                if ($fromRate && $toRate) {
                    $convertedPrice = ($price / $fromRate) * $toRate;
                    $convertedPrice = round($convertedPrice, 2);
                } else {
                    $currencyConverted = false;
                    error_log("MARKUP: currency rate missing for {$fromCurrency}->{$toCurrency}, returning unconverted price ({$fromCurrency} {$price}) labeled as {$toCurrency}");
                }
            }

            return [
                'price' => $convertedPrice,
                'markup' => 0,
                'markup_percentage' => 0,
                'markup_type' => 'percentage',
                'markup_value' => 0,
                'base_price' => round($price, 2),
                'converted_base_price' => $convertedPrice,
                'currency_converted' => $currencyConverted
            ];
        }
        
        // Markup logic: Custom Overrides Platform
        // If Custom Markup is enabled for the agent, we use THAT and THAT ONLY.
        // If not, we use the Platform (B2B/B2C) markup.
        
        $finalMarkupValue = 0;
        $finalMarkupType = 'percentage';
        
        if ($customMarkup) {
            $finalMarkupValue = $userMarkupValue;
            $finalMarkupType = $userMarkupType;
        } else {
            $finalMarkupValue = $isAgent ? floatval($moduleData['markup_b2b'] ?? 0) : floatval($moduleData['markup_b2c'] ?? 0);
            $finalMarkupType = $isAgent ? ($moduleData['markup_type_b2b'] ?? 'percentage') : ($moduleData['markup_type_b2c'] ?? 'percentage');
        }

        // AGENT MEMBER TIER discount (docs/MONEY-WALLET-AUDIT.md §C.4 step 4) —
        // MUST mirror the app/lib/functions.php copy of MARKUP() so agents get the
        // same tier-reduced rate whether they price via the main app or the
        // /modules/* supplier gateway (this file's MARKUP wins in the gateway
        // context, which does not load wallet.php — so load it on demand).
        if ($isAgent && $finalMarkupType === 'percentage' && !empty($userId)) {
            if (!function_exists('agent_tier_discount_percent')) {
                $walletLib = dirname(__DIR__) . '/app/lib/wallet.php';
                if (file_exists($walletLib)) { require_once $walletLib; }
            }
            if (function_exists('agent_tier_discount_percent')) {
                $tierDiscount = agent_tier_discount_percent($db, (string) $userId);
                if ($tierDiscount > 0) {
                    $finalMarkupValue = max(0.0, (float) $finalMarkupValue - (float) $tierDiscount);
                }
            }
        }

        // Apply the chosen markup
        // STEP 1: Apply markup to ORIGINAL currency price first
        $markupAmount = 0;
        $markupPercentage = 0;
        $priceWithMarkup = $price;
        
        if ($finalMarkupType === 'fixed') {
            // Fixed markup is always in DEFAULT currency (e.g. USD)
            // We must convert it to the Item's Currency ($fromCurrency) if they differ
            
            $markupValueInItemCurrency = $finalMarkupValue;
            
            if ($fromCurrency) {
                $defaultCurrency = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
                
                if ($defaultCurrency && $defaultCurrency['name'] !== $fromCurrency) {
                    // We need to convert the Markup Value (Default Currency) -> Item Currency ($fromCurrency)
                    // Formula: (Value / DefaultRate) * ItemRate
                    $itemCurrencyRate = $db->get('currencies', 'rate', ['name' => $fromCurrency]);
                    
                    if ($itemCurrencyRate) {
                         $markupValueInItemCurrency = ($finalMarkupValue / $defaultCurrency['rate']) * $itemCurrencyRate;
                    }
                }
            }

            $markupAmount = $markupValueInItemCurrency;
            // Calculate percentage for reference (markup / base * 100)
            $markupPercentage = $price > 0 ? round(($markupAmount / $price) * 100, 2) : 0;
            $priceWithMarkup = $price + $markupAmount;
            
        } else {
            // Percentage
            $markupAmount = ($price * $finalMarkupValue) / 100;
            $markupPercentage = $finalMarkupValue;
            $priceWithMarkup = $price + $markupAmount;
        }
        
        $markupValue = $finalMarkupValue;
        $markupType = $finalMarkupType;

        
        // STEP 2: Convert the marked-up price to target currency
        $convertedPrice = $priceWithMarkup;
        $convertedBasePrice = $price;
        $currencyConverted = true;

        if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $price > 0) {
            // Optimization: We might have already fetched $itemCurrencyRate (as $fromRate) if we did the fixed markup conversion
            // But for code cleanliness/safety, we'll fetch or rely on DB caching (Medoo doesn't cache by default but it's fast)
            // actually let's just fetch them.

            $fromRate = $db->get('currencies', 'rate', ['name' => $fromCurrency, 'status' => '1']);
            $toRate = $db->get('currencies', 'rate', ['name' => $toCurrency, 'status' => '1']);

            if ($fromRate && $toRate) {
                $convertedPrice = ($priceWithMarkup / $fromRate) * $toRate;
                $convertedPrice = round($convertedPrice, 2);

                $convertedBasePrice = ($price / $fromRate) * $toRate;
                $convertedBasePrice = round($convertedBasePrice, 2);

                // Convert markup amount to target currency
                $markupAmount = ($markupAmount / $fromRate) * $toRate;
                $markupAmount = round($markupAmount, 2);
            } else {
                $currencyConverted = false;
                error_log("MARKUP: currency rate missing for {$fromCurrency}->{$toCurrency}, returning unconverted price ({$fromCurrency} {$priceWithMarkup}) labeled as {$toCurrency}");
            }
        }

        $finalPrice = $convertedPrice;

        return [
            'price' => round($finalPrice, 2),
            'markup' => round($markupAmount, 2),
            'markup_percentage' => $markupPercentage,
            'markup_type' => $markupType,
            'markup_value' => $markupValue,
            'base_price' => round($price, 2),
            'converted_base_price' => round($convertedBasePrice, 2),
            'currency_converted' => $currencyConverted
        ];
    }
}

/**
 * CURRENCY CONVERSION FUNCTION
 * Converts price from one currency to another without markup
 * 
 * @param float $price - Original price
 * @param object $db - Database connection
 * @param string $fromCurrency - Source currency code
 * @param string $toCurrency - Target currency code
 * @return array - Converted price with details
 */
if (!function_exists('CURRENCY_CONVERT')) {
    function CURRENCY_CONVERT($price, $db, $fromCurrency = null, $toCurrency = null) {
        // Default values
        $convertedPrice = $price;
        $conversionRate = 1.0;
        $isConverted = false;
        
        // Check if conversion is needed
        if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $price > 0) {
            $fromRate = $db->get('currencies', 'rate', ['name' => $fromCurrency, 'status' => '1']);
            $toRate = $db->get('currencies', 'rate', ['name' => $toCurrency, 'status' => '1']);
            
            if ($fromRate && $toRate && $fromRate > 0) {
                $convertedPrice = ($price / $fromRate) * $toRate;
                $convertedPrice = round($convertedPrice, 2);
                $conversionRate = $toRate / $fromRate;
                $isConverted = true;
            }
        }
        
        return [
            'price' => $convertedPrice,
            'converted' => $isConverted,
            'conversion_rate' => round($conversionRate, 4),
            'original_price' => round($price, 2),
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency
        ];
    }
}

// ============================================================================
// API LOGGING HELPER FUNCTION - LOG API PAYLOAD & RESPONSE
// ============================================================================
function logApiCall($apiType, $payload, $response, $httpCode, $path = '', $type = '') {
    try {
        // Create logs directory if it doesn't exist
        $logsDir = empty($path) ? __DIR__ . '/logs' : $path;
        
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
        
        $currentDate = date('Y-m-d');
        $currentTime = date('H:i:s');
        
        // Add prefix for type if provided
        $filePrefix = !empty($type) ? $type . '_' : '';
        
        // ============================================
        // LOG REQUEST PAYLOAD - OVERWRITE MODE
        // ============================================
        $requestLogFile = $logsDir . '/' . $filePrefix . 'requests_' . $currentDate . '.json';
        
        $requestLogEntry = [
            'Time' => $currentTime,
            'Date' => $currentDate,
            'Api_type' => $apiType,
            'Data' => $payload
        ];
        
        // Write request log (overwrite, not append)
        file_put_contents(
            $requestLogFile, 
            json_encode([$requestLogEntry], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 
            LOCK_EX
        );
        
        // ============================================
        // LOG RESPONSE - OVERWRITE MODE
        // ============================================
        $responseLogFile = $logsDir . '/' . $filePrefix . 'responses_' . $currentDate . '.json';
        
        $responseLogEntry = [
            'Time' => $currentTime,
            'Date' => $currentDate,
            'Api_type' => $apiType,
            'Data' => [
                'http_code' => $httpCode,
                'response' => $response
            ]
        ];
        
        // Write response log (overwrite, not append)
        file_put_contents(
            $responseLogFile, 
            json_encode([$responseLogEntry], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 
            LOCK_EX
        );
        
    } catch (Exception $e) {
        error_log('API Logging failed: ' . $e->getMessage());
    }
}

function log_setting($db,$module){
    $setting = $db->get('modules','logging_enabled',['name' => $module]);
    return $setting;
}

// ============================================================================
// IMAGE CACHING FUNCTIONS
// ============================================================================

/**
 * Download and cache Stuba images locally
 * @param string $imageUrl - Original Stuba image URL
 * @param string $hotelId - Hotel ID for folder organization
 * @param string $city - City name for cache management
 * @return string - Local cached image URL or fallback
 */
function cacheImage($imageUrl, $hotelId, $city = '') {
    // Create base images directory
    $baseDir = __DIR__ . "/stays/stuba/images";
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0777, true);
    }
    
    // Create hotel-specific directory
    $hotelDir = $baseDir . "/" . $hotelId;
    if (!is_dir($hotelDir)) {
        mkdir($hotelDir, 0777, true);
    }
    
    // Generate filename from URL
    $filename = basename(parse_url($imageUrl, PHP_URL_PATH));
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    
    // If no extension, default to jpg
    if (empty($extension)) {
        $filename .= '.jpg';
    }
    
    $localPath = $hotelDir . "/" . $filename;
    $localUrl = root . "stays/stuba/images/" . $hotelId . "/" . $filename;
    
    // Check if image already exists
    if (file_exists($localPath)) {
        return $localUrl;
    }

    // Download image
    try {
        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode == 200 && $imageData !== false) {
            file_put_contents($localPath, $imageData);
            return $localUrl;
        }
    } catch (Exception $e) {
        // Log error but continue
        $logDir = __DIR__ . "/logs";
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        file_put_contents("logs/IMAGE_CACHE_ERROR.log", date('Y-m-d H:i:s') . " - " . $imageUrl . " - " . $e->getMessage() . "\n", FILE_APPEND);
    }
    
    // Return original URL if download fails
    return $imageUrl;
}

/**
 * Clear cached images for hotels not in current city
 * @param string $currentCity - City name to keep
 * @param object $stubaDb - Medoo database connection
 */
function clearOtherCityImages($currentCity, $stubaDb) {
    $baseDir = __DIR__ . "/images";
    
    if (!is_dir($baseDir)) {
        return;
    }
    
    // Get all hotel IDs for current city
    $formatted = ucwords(str_replace('-', ' ', $currentCity));
    
    try {
        $currentCityHotels = $stubaDb->select('stuba_hotels', 'hotel_id', [
            'city[~]' => $formatted
        ]);
        
        // Get all directories in images folder
        $directories = glob($baseDir . '/*', GLOB_ONLYDIR);
        
        foreach ($directories as $dir) {
            $hotelId = basename($dir);
            
            // If this hotel is not in current city, delete its images
            if (!in_array($hotelId, $currentCityHotels)) {
                deleteDirectory($dir);
            }
        }
    } catch (Exception $e) {
        error_log('Error clearing city images: ' . $e->getMessage());
    }
}

/**
 * Recursively delete directory
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Check if ancillaries are enabled for a specific module
 */
if (!function_exists('isAncillariesEnabled')) {
    function isAncillariesEnabled($module_name, $db) {
        $status = $db->get('modules', 'ancillaries_enabled', ['name' => $module_name]);
        return ($status == 1);
    }
}

/**
 * Check if EMD is enabled for a specific module
 */
if (!function_exists('isEmdEnabled')) {
    function isEmdEnabled($module_name, $db) {
        $status = $db->get('modules', 'emd_enabled', ['name' => $module_name]);
        return ($status == 1);
    }
}

// Phone helpers for supplier modules (issue/booking APIs)
if (!function_exists('getPhoneCode')) {
    function getPhoneCode($isoOrCode, $db)
    {
        if (empty($isoOrCode)) {
            return '';
        }
        $clean = preg_replace('/[^\d]/', '', $isoOrCode);
        if (strlen($isoOrCode) === 2 && !is_numeric($isoOrCode)) {
            $country = $db->get('countries', ['phonecode'], ['iso' => strtoupper($isoOrCode)]);
            return preg_replace('/[^\d]/', '', $country['phonecode'] ?? '');
        }
        return !empty($clean) ? $clean : '';
    }
}

if (!function_exists('resolveCountryIso')) {
    function resolveCountryIso($isoOrCode, $db)
    {
        if (empty($isoOrCode)) {
            return '';
        }
        $isoOrCode = trim((string)$isoOrCode);
        if (strlen($isoOrCode) === 2 && !is_numeric($isoOrCode)) {
            return strtoupper($isoOrCode);
        }
        $clean = preg_replace('/[^\d]/', '', $isoOrCode);
        if ($clean === '') {
            return '';
        }
        $country = $db->get('countries', ['iso'], ['phonecode' => $clean]);
        if (!empty($country['iso'])) {
            return strtoupper($country['iso']);
        }
        $country = $db->get('countries', ['iso'], ['phonecode' => ltrim($clean, '0')]);
        return !empty($country['iso']) ? strtoupper($country['iso']) : '';
    }
}

if (!function_exists('buildE164PhoneNumber')) {
    function buildE164PhoneNumber($phone, $countryIso, $db)
    {
        $phone = trim((string)$phone);
        if ($phone === '') {
            return '';
        }
        if (strpos($phone, '+') === 0) {
            return '+' . preg_replace('/[^\d]/', '', substr($phone, 1));
        }
        $countryIso  = resolveCountryIso($countryIso, $db) ?: 'US';
        $callingCode = getPhoneCode($countryIso, $db);
        $digits      = preg_replace('/[^\d]/', '', $phone);
        $digits      = ltrim($digits, '0');
        if ($callingCode !== '' && strpos($digits, $callingCode) === 0) {
            return '+' . $digits;
        }
        return '+' . $callingCode . $digits;
    }
}

if (!function_exists('isValidE164Phone')) {
    function isValidE164Phone($e164)
    {
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $e164)) {
            return false;
        }
        $digits = substr($e164, 1);
        if (preg_match('/^(\d)\1{6,}$/', $digits)) {
            return false;
        }
        $fakePatterns = ['1234567890', '123456789', '0123456789', '0000000000', '1111111111'];
        foreach ($fakePatterns as $pattern) {
            if (strpos($digits, $pattern) !== false) {
                return false;
            }
        }
        if (strpos($e164, '+1') === 0 && strlen($digits) === 11) {
            $area     = substr($digits, 1, 3);
            $exchange = substr($digits, 4, 3);
            if ($area[0] === '0' || $area[0] === '1' || $exchange[0] === '0' || $exchange[0] === '1') {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('validatePhoneForCountry')) {
    function validatePhoneForCountry($phone, $countryIso, $db)
    {
        $phone = trim((string)$phone);
        if ($phone === '') {
            return false;
        }
        if (substr_count($phone, '+') > 1) {
            return false;
        }
        if (($plusPos = strpos($phone, '+')) !== false && $plusPos > 0) {
            return false;
        }
        if (preg_match('/\+\d+\s+\+/', $phone)) {
            return false;
        }
        if (preg_match('/^\+\d+\s+\(/', $phone)) {
            return false;
        }
        $countryIso   = resolveCountryIso($countryIso, $db) ?: 'US';
        $expectedCode = getPhoneCode($countryIso, $db);
        if ($expectedCode === '') {
            return false;
        }
        $e164 = buildE164PhoneNumber($phone, $countryIso, $db);
        if (!isValidE164Phone($e164)) {
            return false;
        }
        $digits = substr($e164, 1);
        if (strpos($digits, $expectedCode) !== 0) {
            return false;
        }
        $national    = substr($digits, strlen($expectedCode));
        $nationalLen = strlen($national);
        if ($nationalLen < 7 || $nationalLen > 12) {
            return false;
        }
        if ($expectedCode === '1' && $nationalLen !== 10) {
            return false;
        }
        if ($expectedCode === '254' && $nationalLen !== 9) {
            return false;
        }
        if ($expectedCode === '92' && ($nationalLen < 10 || $nationalLen > 11)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('normalizePhoneForStorage')) {
    function normalizePhoneForStorage($phone, $countryIso, $db)
    {
        $countryIso   = resolveCountryIso($countryIso, $db) ?: 'US';
        $expectedCode = getPhoneCode($countryIso, $db);
        $e164         = buildE164PhoneNumber($phone, $countryIso, $db);
        $digits       = substr($e164, 1);
        if ($expectedCode !== '' && strpos($digits, $expectedCode) === 0) {
            return substr($digits, strlen($expectedCode));
        }
        return preg_replace('/[^\d]/', '', $phone);
    }
}

/**
 * Verify API Key for external clients / mobile apps, bypassing for own web client.
 */
if (!function_exists('verifyApiKey')) {
    function verifyApiKey($db) {
        $settingsData = $db->get('settings', 'app_settings');
        $appSettings = json_decode($settingsData ?: '{}', true) ?: [];
        $serverApiKey = $appSettings['api_key'] ?? '';

        if (empty($serverApiKey)) {
            return; // No API key configured on server, permit access
        }

        // Start session if not already active to read session flag
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // The site's own frontend JS calls /api/* without embedding the key,
        // identified by $_SESSION['is_web_client']. SECURITY (H1): to stop a
        // stolen session cookie being replayed from an attacker's page, only
        // honour that bypass for genuinely SAME-ORIGIN requests — i.e. the
        // Origin/Referer host must match this site's host. A cross-site replay
        // (no/foreign Origin) must still present the API key.
        $isOwnWeb = !empty($_SESSION['is_web_client']);
        if ($isOwnWeb) {
            $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
            $originHost = '';
            if (!empty($_SERVER['HTTP_ORIGIN'])) {
                $originHost = strtolower((string) parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST));
            } elseif (!empty($_SERVER['HTTP_REFERER'])) {
                $originHost = strtolower((string) parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST));
            }
            // Same-origin (or a plain navigation with no Origin/Referer, which a
            // cross-site fetch cannot suppress) is trusted; a foreign Origin is not.
            if ($originHost !== '' && $host !== '' && $originHost !== $host) {
                $isOwnWeb = false;
            }
        }

        if (!$isOwnWeb) {
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);
            $clientApiKey = $normalizedHeaders['x-api-key'] ?? '';

            if (empty($clientApiKey)) {
                foreach ($_SERVER as $key => $val) {
                    $cleanKey = strtoupper($key);
                    if ($cleanKey === 'HTTP_X_API_KEY' ||
                        $cleanKey === 'REDIRECT_HTTP_X_API_KEY' ||
                        $cleanKey === 'HTTP_X_APIKEY' ||
                        $cleanKey === 'REDIRECT_HTTP_X_APIKEY') {
                        $clientApiKey = $val;
                        break;
                    }
                }
            }
            if (empty($clientApiKey)) {
                $clientApiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
            }

            // SECURITY (H1): constant-time comparison (hash_equals) instead of !==.
            if (empty($clientApiKey) || !hash_equals((string) $serverApiKey, (string) $clientApiKey)) {
                header('Content-Type: application/json');
                http_response_code(426);
                echo json_encode([
                    'status' => 'error',
                    'error_code' => 'APP_INVALID_KEY',
                    'message' => 'App invalid key: Invalid or missing API Key'
                ]);
                exit;
            }
        }
    }
}

// =============================================================================
// Hotelbeds module helpers (modules API does not load app/lib/functions.php)
// =============================================================================

if (!function_exists('getHotelbedsSettingsPath')) {
    function getHotelbedsSettingsPath(): string
    {
        return __DIR__ . '/stays/hotelbeds/settings.json';
    }
}

if (!function_exists('readHotelbedsSettings')) {
    function readHotelbedsSettings(): array
    {
        $settingsPath = getHotelbedsSettingsPath();
        if (!file_exists($settingsPath)) {
            return ['use_mtls' => 0];
        }

        $json = file_get_contents($settingsPath);
        $settings = json_decode($json, true);

        return is_array($settings) ? $settings : ['use_mtls' => 0];
    }
}

if (!function_exists('hotelbedsLogDir')) {
    function hotelbedsLogDir(): string
    {
        return __DIR__ . '/stays/hotelbeds/logs';
    }
}

if (!function_exists('hotelbedsMtlsCertPaths')) {
    function hotelbedsMtlsCertPaths(): array
    {
        $dir = __DIR__ . '/stays/hotelbeds/certs';

        return [
            'cert' => $dir . '/client.pem',
            'key' => $dir . '/client.key',
            'ca' => $dir . '/ca_bundle.crt',
        ];
    }
}

if (!function_exists('hotelbedsMtlsCertsAvailable')) {
    function hotelbedsMtlsCertsAvailable(): bool
    {
        $paths = hotelbedsMtlsCertPaths();

        return is_readable($paths['cert'])
            && is_readable($paths['key'])
            && is_readable($paths['ca']);
    }
}

if (!function_exists('hotelbedsResolveBookingEnvironment')) {
    function hotelbedsResolveBookingEnvironment(array $module): string
    {
        return (($module['dev_mode'] ?? '1') == '0') ? 'live' : 'test';
    }
}

if (!function_exists('hotelbedsResolveUseMtls')) {
    function hotelbedsResolveUseMtls(array $module, ?array $settings = null): array
    {
        $settings = $settings ?? readHotelbedsSettings();
        $requested = (($settings['use_mtls'] ?? 0) == 1);

        if (!$requested) {
            return ['use_mtls' => false, 'error' => null];
        }

        if (hotelbedsMtlsCertsAvailable()) {
            return ['use_mtls' => true, 'error' => null];
        }

        return [
            'use_mtls' => false,
            'error' => 'mTLS is enabled in Hotelbeds settings but client certificates are missing or incomplete. '
                . 'Upload client.pem, client.key, and ca_bundle.crt to modules/stays/hotelbeds/certs/.',
        ];
    }
}

if (!function_exists('hotelbedsResolveBookingTransport')) {
    function hotelbedsResolveBookingTransport(array $module, ?array $settings = null): array
    {
        $environment = hotelbedsResolveBookingEnvironment($module);
        $mtls = hotelbedsResolveUseMtls($module, $settings);

        return [
            'environment' => $environment,
            'use_mtls' => $mtls['use_mtls'],
            'error' => $mtls['error'],
        ];
    }
}

if (!function_exists('hotelbedsBookingApiBaseUrl')) {
    function hotelbedsBookingApiBaseUrl(string $environment, bool $useMtls): string
    {
        $isLive = (strtolower(trim($environment)) === 'live');

        if ($useMtls) {
            return $isLive
                ? 'https://api-mtls.hotelbeds.com/hotel-api/1.0'
                : 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0';
        }

        return $isLive
            ? 'https://api.hotelbeds.com/hotel-api/1.0'
            : 'https://api.test.hotelbeds.com/hotel-api/1.0';
    }
}

if (!function_exists('hotelbedsApplyMtlsCurlOptions')) {
    function hotelbedsApplyMtlsCurlOptions($ch, bool $useMtls): bool
    {
        if ($useMtls && hotelbedsMtlsCertsAvailable()) {
            $paths = hotelbedsMtlsCertPaths();
            curl_setopt($ch, CURLOPT_SSLCERT, $paths['cert']);
            curl_setopt($ch, CURLOPT_SSLKEY, $paths['key']);
            curl_setopt($ch, CURLOPT_CAINFO, $paths['ca']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            return true;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        return false;
    }
}

if (!function_exists('hotelbedsNormalizeMarketCode')) {
    function hotelbedsNormalizeMarketCode($code): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return '';
        }

        if (strlen($code) > 2) {
            $code = substr($code, 0, 2);
        }

        return preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
    }
}

if (!function_exists('hotelbedsParseStayDates')) {
    /**
     * Parse stay dates from search/detail input into API (Y-m-d) + display (d-m-Y) forms.
     * Accepts dd-mm-yyyy (primary), yyyy-mm-dd, and common display formats.
     *
     * @return array{
     *   checkin_ymd: string,
     *   checkout_ymd: string,
     *   checkin_dmY: string,
     *   checkout_dmY: string,
     *   nights: int
     * }|null
     */
    function hotelbedsParseStayDates(string $checkin, string $checkout): ?array
    {
        $checkin = trim($checkin);
        $checkout = trim($checkout);
        if ($checkin === '' || $checkout === '') {
            return null;
        }

        $parseOne = static function (string $date): ?DateTime {
            $date = trim($date);
            if ($date === '') {
                return null;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $dt = DateTime::createFromFormat('Y-m-d', $date);
                return ($dt instanceof DateTime) ? $dt : null;
            }

            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
                $parts = explode('-', $date);
                $dt = DateTime::createFromFormat('Y-m-d', "{$parts[2]}-{$parts[1]}-{$parts[0]}");
                return ($dt instanceof DateTime) ? $dt : null;
            }

            foreach (['d-m-Y', 'd/m/Y', 'M d, Y', 'M j, Y'] as $fmt) {
                $dt = DateTime::createFromFormat($fmt, $date);
                if ($dt instanceof DateTime) {
                    $errors = DateTime::getLastErrors();
                    if (empty($errors['warning_count']) && empty($errors['error_count'])) {
                        return $dt;
                    }
                }
            }

            return null;
        };

        $checkinDt = $parseOne($checkin);
        $checkoutDt = $parseOne($checkout);
        if (!$checkinDt instanceof DateTime || !$checkoutDt instanceof DateTime) {
            return null;
        }

        $checkinDt->setTime(0, 0, 0);
        $checkoutDt->setTime(0, 0, 0);

        if ($checkoutDt <= $checkinDt) {
            return null;
        }

        $nights = max(1, (int) $checkinDt->diff($checkoutDt)->days);

        return [
            'checkin_ymd' => $checkinDt->format('Y-m-d'),
            'checkout_ymd' => $checkoutDt->format('Y-m-d'),
            'checkin_dmY' => $checkinDt->format('d-m-Y'),
            'checkout_dmY' => $checkoutDt->format('d-m-Y'),
            'nights' => $nights,
        ];
    }
}

if (!function_exists('hotelbedsApplyAvailabilityMarketFields')) {
    function hotelbedsApplyAvailabilityMarketFields(array $apiPayload, $db, $nationality = ''): array
    {
        $nationality = hotelbedsNormalizeMarketCode($nationality);
        if ($nationality !== '') {
            $apiPayload['nationality'] = $nationality;
        }

        return $apiPayload;
    }
}

if (!function_exists('hotelbedsShouldSkipPackagingRate')) {
    function hotelbedsShouldSkipPackagingRate(array $rate): bool
    {
        if (!isset($rate['packaging'])) {
            return false;
        }

        $settings = readHotelbedsSettings();
        if (!empty($settings['allow_packaging_rates'])) {
            return false;
        }

        return $rate['packaging'] === true || $rate['packaging'] === 'true' || $rate['packaging'] === 1 || $rate['packaging'] === '1';
    }
}

if (!function_exists('hotelbedsClientReference')) {
    function hotelbedsClientReference(string $invoiceId): string
    {
        return 'INV-' . $invoiceId;
    }
}

if (!function_exists('hotelbedsNormalizePoliciesForCompare')) {
    /**
     * Canonical cancellation-policy shape so CheckRate does not flag format-only
     * differences (string vs number amounts, +02:00 vs +0200, key order).
     */
    function hotelbedsNormalizePoliciesForCompare($policies): array
    {
        if (!is_array($policies)) {
            return [];
        }

        $normalized = [];
        foreach ($policies as $policy) {
            if (is_object($policy)) {
                $policy = (array) $policy;
            }
            if (!is_array($policy)) {
                continue;
            }

            $from = trim((string) ($policy['from'] ?? $policy['dateFrom'] ?? $policy['fromDate'] ?? ''));
            $amount = round((float) ($policy['amount'] ?? $policy['hotelAmount'] ?? 0), 2);

            $fromKey = $from;
            if ($from !== '') {
                try {
                    $fromKey = (string) (new DateTime($from))->getTimestamp();
                } catch (Exception $e) {
                    $fromKey = strtolower($from);
                }
            }

            $normalized[] = [
                'amount' => $amount,
                'from' => $fromKey,
            ];
        }

        usort($normalized, static function ($a, $b) {
            $fromCmp = strcmp((string) $a['from'], (string) $b['from']);
            return $fromCmp !== 0 ? $fromCmp : ($a['amount'] <=> $b['amount']);
        });

        return $normalized;
    }
}

if (!function_exists('hotelbedsPolicySignals')) {
    /**
     * Guest-facing summary of a cancellation-policy set: the largest fee and the
     * moment fees start to apply.
     *
     * Zero-fee entries are ignored on purpose. Availability and CheckRate do not
     * always list the same number of policy rows for one rate (a 0.00 row is
     * frequently present on one side only), and a 0.00 row costs the guest
     * nothing, so it must never count as a changed condition.
     */
    function hotelbedsPolicySignals($policies): array
    {
        $normalized = hotelbedsNormalizePoliciesForCompare($policies);

        $maxFee = 0.0;
        $feeStartsAt = null;
        foreach ($normalized as $policy) {
            $amount = (float) ($policy['amount'] ?? 0);
            if ($amount <= 0.009) {
                continue;
            }
            if ($amount > $maxFee) {
                $maxFee = $amount;
            }
            $from = $policy['from'] ?? '';
            if (is_numeric($from)) {
                $timestamp = (int) $from;
                if ($feeStartsAt === null || $timestamp < $feeStartsAt) {
                    $feeStartsAt = $timestamp;
                }
            }
        }

        return [
            'max_fee' => $maxFee,
            'fee_starts_at' => $feeStartsAt,
            'has_fee' => $maxFee > 0,
        ];
    }
}

if (!function_exists('hotelbedsCheckRateChangeSummary')) {
    /**
     * Compare the rate the guest selected against the CheckRate response.
     *
     * Two levels of result:
     *  - `price_changed` / `policy_changed` are raw diffs and drive the mechanics
     *    (re-scaling displayed prices, replacing stored policies, the 2% tolerance
     *    guard).
     *  - `changed` (with `price_changed_material` / `policy_changed_material`) is
     *    what the guest is asked to confirm, and is only true when the rate got
     *    worse for them: a higher price, a higher cancellation fee, a free
     *    cancellation window that now ends earlier, or free cancellation lost.
     *    Supplier-side noise (cent rounding, 0.00 policy rows, reordered or
     *    reformatted entries) must not interrupt the booking.
     */
    function hotelbedsCheckRateChangeSummary($oldNet, $newNet, $oldPolicies, $newPolicies): array
    {
        $oldNet = $oldNet !== null ? (float) $oldNet : null;
        $newNet = $newNet !== null ? (float) $newNet : null;
        $priceChanged = ($oldNet !== null && $newNet !== null && abs($newNet - $oldNet) > 0.01);

        $priceChangedMaterial = false;
        if ($oldNet !== null && $newNet !== null && $oldNet > 0) {
            $priceTolerance = max(0.01, $oldNet * 0.001);
            $priceChangedMaterial = ($newNet - $oldNet) > $priceTolerance;
        }

        $oldNormalized = hotelbedsNormalizePoliciesForCompare($oldPolicies);
        $newNormalized = hotelbedsNormalizePoliciesForCompare($newPolicies);
        $policyChanged = json_encode($oldNormalized) !== json_encode($newNormalized);

        $oldSignals = hotelbedsPolicySignals($oldPolicies);
        $newSignals = hotelbedsPolicySignals($newPolicies);

        $policyChangedMaterial = false;
        // With no policies on the selected rate there is no baseline to compare
        // against (the snapshot may simply not carry them), so stay quiet.
        if (!empty($oldNormalized)) {
            if (!$oldSignals['has_fee'] && $newSignals['has_fee']) {
                // Free cancellation replaced by a chargeable policy.
                $policyChangedMaterial = true;
            }

            $feeTolerance = max(0.01, $oldSignals['max_fee'] * 0.01);
            if (($newSignals['max_fee'] - $oldSignals['max_fee']) > $feeTolerance) {
                $policyChangedMaterial = true;
            }

            // Fees now start earlier — the guest loses free-cancellation time.
            // One hour of slack absorbs timezone/format drift between endpoints;
            // genuine deadline moves are days apart.
            if (
                $oldSignals['fee_starts_at'] !== null
                && $newSignals['fee_starts_at'] !== null
                && ($oldSignals['fee_starts_at'] - $newSignals['fee_starts_at']) > 3600
            ) {
                $policyChangedMaterial = true;
            }
        }

        return [
            'changed' => $priceChangedMaterial || $policyChangedMaterial,
            'price_changed' => $priceChanged,
            'policy_changed' => $policyChanged,
            'price_changed_material' => $priceChangedMaterial,
            'policy_changed_material' => $policyChangedMaterial,
            'old_net' => $oldNet,
            'new_net' => $newNet,
            'old_policy_signals' => $oldSignals,
            'new_policy_signals' => $newSignals,
        ];
    }
}

if (!function_exists('hotelbedsExtractCancellationFee')) {
    function hotelbedsExtractCancellationFee($bookingResponse): array
    {
        $fee = null;
        $currency = null;
        if (is_object($bookingResponse)) {
            $currency = $bookingResponse->currency ?? null;
            if (isset($bookingResponse->hotel->cancellationAmount)) {
                $fee = (float) $bookingResponse->hotel->cancellationAmount;
            }
        }

        return [
            'cancellation_fee' => $fee,
            'currency' => $currency,
            'booking_status' => is_object($bookingResponse) ? ($bookingResponse->status ?? null) : null,
            'booking_reference' => is_object($bookingResponse) ? ($bookingResponse->reference ?? null) : null,
        ];
    }
}

if (!function_exists('hotelbedsBookingApiRequest')) {
    function hotelbedsBookingApiRequest(array $module, string $method, string $path, array $query = [], $jsonBody = null): array
    {
        $settings = readHotelbedsSettings();
        $transport = hotelbedsResolveBookingTransport($module, $settings);
        if (!empty($transport['error'])) {
            return ['success' => false, 'message' => $transport['error'], 'http_code' => 0];
        }

        $apiKey = $module['c1'] ?? '';
        $apiSecret = $module['c2'] ?? '';
        if ($apiKey === '' || $apiSecret === '') {
            return ['success' => false, 'message' => 'Hotelbeds API credentials missing', 'http_code' => 0];
        }

        $baseUrl = hotelbedsBookingApiBaseUrl($transport['environment'], $transport['use_mtls']);
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $signature = hash('sha256', $apiKey . $apiSecret . time());
        $ch = curl_init();
        hotelbedsApplyMtlsCurlOptions($ch, $transport['use_mtls']);
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => [
                'Api-key: ' . $apiKey,
                'X-Signature: ' . $signature,
                'Accept: application/json',
                'Content-Type: application/json',
                'Accept-Encoding: gzip',
            ],
        ];
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_string($jsonBody) ? $jsonBody : json_encode($jsonBody);
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200 || $body === false || $body === '') {
            $decodedError = is_string($body) ? json_decode($body, true) : null;
            $apiMessage = '';
            if (is_array($decodedError) && isset($decodedError['error'])) {
                $err = $decodedError['error'];
                $apiMessage = is_array($err)
                    ? trim((string) ($err['message'] ?? $err['description'] ?? ''))
                    : trim((string) $err);
            }

            return [
                'success' => false,
                'message' => $apiMessage !== '' ? $apiMessage : ($curlError ?: ('HTTP ' . $httpCode)),
                'http_code' => $httpCode,
                'raw' => $body,
            ];
        }

        $decoded = json_decode($body, true);

        return [
            'success' => true,
            'http_code' => $httpCode,
            'data' => $decoded,
            'raw' => $body,
        ];
    }
}

if (!function_exists('hotelbedsExtractRateFromCheckRateResponse')) {
    function hotelbedsExtractRateFromCheckRateResponse($data, string $requestedKey): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        $requestedKey = trim($requestedKey);
        $rates = [];
        foreach (($data['hotel']['rooms'] ?? []) as $room) {
            if (empty($room['rates']) || !is_array($room['rates'])) {
                continue;
            }
            foreach ($room['rates'] as $rate) {
                if (!is_array($rate)) {
                    continue;
                }
                $rates[] = $rate;
                $returnedKey = trim((string) ($rate['rateKey'] ?? ''));
                if ($requestedKey !== '' && $returnedKey === $requestedKey) {
                    return $rate;
                }
            }
        }

        return count($rates) === 1 ? $rates[0] : null;
    }
}

if (!function_exists('hotelbedsCheckRateOneKey')) {
    /**
     * One rateKey CheckRate (BOOKABLE or RECHECK). Hotelbeds recommends one key per call.
     */
    function hotelbedsCheckRateOneKey(array $module, string $rateKey): ?array
    {
        $rateKey = trim($rateKey);
        if ($rateKey === '') {
            return null;
        }

        $result = hotelbedsBookingApiRequest($module, 'POST', 'checkrates', [], [
            'rooms' => [['rateKey' => $rateKey]],
        ]);
        if (empty($result['success']) || !is_array($result['data'] ?? null)) {
            return null;
        }

        return hotelbedsExtractRateFromCheckRateResponse($result['data'], $rateKey);
    }
}

if (!function_exists('hotelbedsRevalidateSelectedRoomsForPayment')) {
    /**
     * CheckRate every selected Hotelbeds rate (BOOKABLE and RECHECK) and write
     * the fresh rateKeys/nets back onto booking_data. Throws if a rate is gone
     * or supplier net moved more than 2%. Call this on Make Payment, before
     * creating the invoice, so the guest is not charged for a dead rate.
     */
    function hotelbedsRevalidateSelectedRoomsForPayment(array &$bookingData, $db, float $tolerancePct = 2.0): array
    {
        $module = $db->get('modules', '*', [
            'name' => 'hotelbeds',
            'type' => 'stays',
        ]);
        if (!$module) {
            throw new Exception('Hotelbeds module is not configured');
        }

        if (empty($bookingData['selected_rooms']) || !is_array($bookingData['selected_rooms'])) {
            throw new Exception('No rooms found to revalidate');
        }

        $expectedNet = 0.0;
        $revalidatedNet = 0.0;

        foreach ($bookingData['selected_rooms'] as $index => $room) {
            if (!isset($bookingData['selected_rooms'][$index]['option']) || !is_array($bookingData['selected_rooms'][$index]['option'])) {
                $bookingData['selected_rooms'][$index]['option'] = [];
            }
            $option = &$bookingData['selected_rooms'][$index]['option'];

            $oldKey = trim((string) ($option['rate_key'] ?? $option['id'] ?? $room['rate_key'] ?? ''));
            if ($oldKey === '') {
                throw new Exception('Missing rate key for a selected room. Please search again.');
            }

            $quantity = max(1, (int) ($room['quantity'] ?? 1));
            $oldNet = (float) ($option['supplier_net'] ?? $option['availability_net'] ?? 0);

            $updated = hotelbedsCheckRateOneKey($module, $oldKey);
            if (!is_array($updated) || empty($updated['rateKey'])) {
                throw new Exception('One or more rates are no longer available. Please search again.');
            }

            $newNet = isset($updated['net']) ? (float) $updated['net'] : 0.0;
            $expectedNet += $oldNet * $quantity;
            $revalidatedNet += $newNet * $quantity;

            if ($oldNet > 0 && $newNet > 0) {
                $deltaPct = abs($newNet - $oldNet) / $oldNet * 100;
                if ($deltaPct > $tolerancePct) {
                    throw new Exception(
                        'Rate price changed beyond the allowed 2% tolerance. Please search again.'
                    );
                }
            }

            $option['rate_key'] = $updated['rateKey'];
            $option['id'] = $updated['rateKey'];
            $option['rate_type'] = $updated['rateType'] ?? 'BOOKABLE';
            $option['needs_recheck'] = 0;
            $option['supplier_net'] = $newNet;
            if (!empty($updated['currency'])) {
                $option['supplier_currency'] = $updated['currency'];
            }
            if (!empty($updated['rateComments'])) {
                $option['rate_comments'] = $updated['rateComments'];
            }

            if (!empty($updated['cancellationPolicies']) && is_array($updated['cancellationPolicies'])) {
                $supplierPolicies = [];
                foreach ($updated['cancellationPolicies'] as $policy) {
                    if (!is_array($policy)) {
                        continue;
                    }
                    $supplierPolicies[] = [
                        'amount' => round((float) ($policy['amount'] ?? $policy['hotelAmount'] ?? 0), 4),
                        'from' => trim((string) ($policy['from'] ?? $policy['dateFrom'] ?? $policy['fromDate'] ?? '')),
                    ];
                }
                $option['supplier_cancellation_policies'] = $supplierPolicies;
            }

            unset($option);
        }

        $bookingData['checkrate_accepted_at'] = date('c');

        return [
            'expected_net' => $expectedNet,
            'revalidated_net' => $revalidatedNet,
        ];
    }
}

if (!function_exists('hotelbedsNormalizeBookingListRows')) {
    function hotelbedsNormalizeBookingListRows($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        if (isset($data['bookings']['bookings']) && is_array($data['bookings']['bookings'])) {
            return $data['bookings']['bookings'];
        }

        if (isset($data['booking']) && is_array($data['booking'])) {
            return [$data['booking']];
        }

        return [];
    }
}

if (!function_exists('hotelbedsParseBookingErrorResponse')) {
    function hotelbedsParseBookingErrorResponse(array $booking): array
    {
        if (empty($booking['error_response'])) {
            return [];
        }

        $decoded = json_decode($booking['error_response'], true);

        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('hotelbedsBookingNeedsReconcile')) {
    function hotelbedsBookingNeedsReconcile(array $booking): bool
    {
        if (strtolower((string) ($booking['module'] ?? '')) !== 'hotelbeds') {
            return false;
        }

        if (!empty($booking['pnr'])) {
            return false;
        }

        $status = strtolower((string) ($booking['booking_status'] ?? ''));
        if ($status === 'booking_unknown') {
            return true;
        }

        $err = hotelbedsParseBookingErrorResponse($booking);
        $type = strtoupper((string) ($err['type'] ?? ''));

        return $type === 'BOOKING_TIMEOUT_OR_TRANSPORT';
    }
}

if (!function_exists('hotelbedsBookingIssueBlocked')) {
    function hotelbedsBookingIssueBlocked(array $booking): bool
    {
        return hotelbedsBookingNeedsReconcile($booking);
    }
}

if (!function_exists('hotelbedsViewerIsAdmin')) {
    function hotelbedsViewerIsAdmin(): bool
    {
        if (strtolower((string) ($_SESSION['user_role'] ?? '')) === 'admin') {
            return true;
        }

        return !empty($_SESSION['admin_logged_in']);
    }
}

if (!function_exists('hotelbedsUserFacingError')) {
    /**
     * Admins see the supplier/API error. Everyone else gets a safe fallback.
     */
    function hotelbedsUserFacingError($apiMessage, $fallback = null): string
    {
        $fallback = trim((string) $fallback);
        if ($fallback === '') {
            $fallback = 'One or more rates are no longer available. Please search again.';
        }

        $apiMessage = trim((string) $apiMessage);
        if (hotelbedsViewerIsAdmin() && $apiMessage !== '') {
            return $apiMessage;
        }

        return $fallback;
    }
}

// ============================================================================
// SUPPLIER ACTION GUARD (Finding E — auth on state-changing supplier routes)
// ----------------------------------------------------------------------------
// issue/cancel/refund/void routes create/cancel/refund REAL supplier bookings
// and money. They previously had NO auth (CORS '*'), so anyone who knew an
// invoice_id could trigger them. This guard permits ONLY legitimate callers:
//   1. The payment gateway's server-side auto-issue loopback, which sends an
//      X-Internal-Token header = HMAC-SHA256(invoice_id, internal secret).
//   2. A logged-in admin ($_SESSION user_role='admin') — admin panel + AJAX.
//   3. A valid CSRF token (admin-panel browser AJAX) via CSRF::validateToken.
// Anonymous external callers have none of these → 403. Secret = server-only
// .env JWT_SECRET (never sent to clients); falls back to a DB-derived value so
// the guard is never keyless.
// ============================================================================
if (!function_exists('supplier_internal_secret')) {
    function supplier_internal_secret(): string
    {
        $env = @parse_ini_file(dirname(__DIR__) . '/.env');
        $secret = is_array($env) ? trim((string)($env['JWT_SECRET'] ?? '')) : '';
        if ($secret === '') {
            $secret = hash('sha256', 'v10-supplier|' . (string)($env['DB_DATABASE'] ?? '') . '|' . (string)($env['DB_PASSWORD'] ?? ''));
        }
        return $secret;
    }
}

if (!function_exists('supplier_internal_token')) {
    function supplier_internal_token(string $invoiceId): string
    {
        return hash_hmac('sha256', 'supplier-action:' . $invoiceId, supplier_internal_secret());
    }
}

if (!function_exists('supplier_action_guard')) {
    /**
     * Authorize a state-changing supplier action. Returns true if allowed;
     * otherwise emits 403 JSON and returns false (caller should return/exit).
     */
    function supplier_action_guard(string $invoiceId): bool
    {
        // 1. Admin session
        $role = strtolower((string)($_SESSION['user_role'] ?? ''));
        if ($role === 'admin' || !empty($_SESSION['admin_logged_in'])) {
            return true;
        }
        // 2. Internal loopback token (constant-time compare)
        $provided = $_SERVER['HTTP_X_INTERNAL_TOKEN']
            ?? ($_POST['_internal_token'] ?? ($_GET['_internal_token'] ?? ''));
        if (is_string($provided) && $provided !== '' && $invoiceId !== ''
            && hash_equals(supplier_internal_token($invoiceId), $provided)) {
            return true;
        }
        // 3. Valid CSRF token (admin-panel browser AJAX)
        if (class_exists('CSRF')) {
            $csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if ($csrf !== '' && CSRF::validateToken($csrf)) {
                return true;
            }
        }
        // Denied.
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json');
        }
        error_log('supplier_action_guard: DENIED for invoice ' . $invoiceId);
        echo json_encode([
            'status'  => false,
            'success' => false,
            'message' => 'Unauthorized: this action requires an authenticated operator or the internal booking flow.',
        ], JSON_UNESCAPED_SLASHES);
        return false;
    }
}

// ============================================================================
// §19 E2E FIX — post-payment price reconciliation in the MODULES context.
//
// Every supplier issue.php calls reconcilePostPaymentPrice() behind a
// function_exists() guard. That function is defined in app/lib/functions.php,
// which the modules API gateway (modules/index.php) deliberately does NOT load
// (see the note near the top of this file). Result: function_exists() was
// ALWAYS false in the issue flow, so the post-payment price-check was silently
// skipped for ALL providers — the safety net was dead code here.
//
// These are byte-for-byte the same implementations as app/lib/functions.php
// (self-contained: only $db (Medoo) + standard PHP). Guarded so that if
// functions.php ever is loaded, there is no redeclare conflict.
// ============================================================================
if (!function_exists('reconcilePostPaymentPrice')) {
    function reconcilePostPaymentPrice($db, $booking, $supplierTotal, $supplierCurrency = null, $tolerancePct = 2.0)
    {
        $paid         = (float) ($booking['price_markup'] ?? 0);
        $paidCurrency = strtoupper(trim((string) ($booking['currency_markup'] ?? '')));
        $supplier     = (float) $supplierTotal;
        $supCurrency  = strtoupper(trim((string) ($supplierCurrency ?? $paidCurrency)));
        $invoiceId    = (string) ($booking['invoice_id'] ?? '');

        if ($paid <= 0) {
            return _reconcile_block($db, $invoiceId, 'Paid amount is zero/unknown — cannot reconcile price', $paid, $supplier, 0.0);
        }
        if ($supplier <= 0) {
            return _reconcile_block($db, $invoiceId, 'Supplier returned no/zero price — cannot reconcile', $paid, $supplier, 0.0);
        }
        if ($paidCurrency !== '' && $supCurrency !== '' && $paidCurrency !== $supCurrency) {
            return _reconcile_block($db, $invoiceId, "Currency mismatch (paid {$paidCurrency} vs supplier {$supCurrency})", $paid, $supplier, 0.0);
        }

        $deltaPct = (($supplier - $paid) / $paid) * 100.0;

        if ($deltaPct <= $tolerancePct) {
            $note = '';
            if ($deltaPct < -$tolerancePct) {
                $note = 'Supplier price is lower than paid by ' . round(abs($deltaPct), 2) . '% (customer not harmed).';
            }
            return ['ok' => true, 'reason' => $note, 'paid' => $paid, 'supplier' => $supplier, 'delta_pct' => round($deltaPct, 2)];
        }

        return _reconcile_block(
            $db,
            $invoiceId,
            'Supplier price rose ' . round($deltaPct, 2) . '% above the amount paid (tolerance ' . $tolerancePct . '%). Auto-issue held for review.',
            $paid,
            $supplier,
            $deltaPct
        );
    }
}

if (!function_exists('_reconcile_block')) {
    function _reconcile_block($db, $invoiceId, $reason, $paid, $supplier, $deltaPct)
    {
        if ($invoiceId !== '' && $db) {
            try {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'review_state' => 'price_mismatch',
                        'reason'       => $reason,
                        'paid'         => $paid,
                        'supplier'     => $supplier,
                        'delta_pct'    => round($deltaPct, 2),
                        'flagged_at'   => date('Y-m-d H:i:s'),
                    ]),
                ], ['invoice_id' => $invoiceId]);
            } catch (\Throwable $e) {
                error_log('reconcilePostPaymentPrice flag error (' . $invoiceId . '): ' . $e->getMessage());
            }
        }
        error_log('PRICE RECONCILE BLOCK (' . $invoiceId . '): ' . $reason . " | paid={$paid} supplier={$supplier}");
        return ['ok' => false, 'reason' => $reason, 'paid' => $paid, 'supplier' => $supplier, 'delta_pct' => round($deltaPct, 2)];
    }
}
