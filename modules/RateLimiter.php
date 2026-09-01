<?php
/**
 * Rate Limiter Class
 * Protects API endpoints from abuse, brute force attacks, and unauthorized access
 * Uses file-based storage for simplicity and performance
 * 
 * Features:
 * - IP-based rate limiting (requests per minute)
 * - Origin/Referer validation (blocks external requests)
 * - Automatic cleanup of expired entries
 * - Configurable limits per endpoint
 * - Brute force protection
 */

class RateLimiter {
    private $storageDir;
    private $defaultLimit = 60;        // Max requests per minute
    private $defaultWindow = 60;        // Time window in seconds
    private $cleanupChance = 100;       // 1% chance to cleanup old files
    
    /**
     * Initialize rate limiter
     * @param string $storageDir Directory to store rate limit data
     */
    public function __construct($storageDir = null) {
        $this->storageDir = $storageDir ?? sys_get_temp_dir() . '/rate_limiter';
        
        // Create storage directory if it doesn't exist
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }
    
    /**
     * Check if request should be allowed
     * @param string $identifier Unique identifier (usually IP address)
     * @param int $limit Maximum requests allowed
     * @param int $window Time window in seconds
     * @return bool True if allowed, false if rate limit exceeded
     */
    public function attempt($identifier, $limit = null, $window = null) {
        $limit = $limit ?? $this->defaultLimit;
        $window = $window ?? $this->defaultWindow;
        
        $key = $this->getKey($identifier);
        $file = $this->getFilePath($key);
        $now = time();
        
        // Get current attempts
        $attempts = $this->getAttempts($file);
        
        // Filter attempts within the time window
        $attempts = array_filter($attempts, function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });
        
        // Check if limit exceeded
        if (count($attempts) >= $limit) {
            // Log blocked attempt (optional)
            $this->logBlockedAttempt($identifier, count($attempts), $limit);
            return false;
        }
        
        // Add current attempt
        $attempts[] = $now;
        
        // Save attempts
        $this->saveAttempts($file, $attempts);
        
        // Cleanup old files randomly
        $this->randomCleanup();
        
        return true;
    }
    
    /**
     * Check if origin is from same domain (prevent external requests)
     * @return bool True if valid origin, false otherwise
     */
    public function checkOrigin() {
        // Get current host
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        
        // Check Referer header
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (!empty($referer)) {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            if ($refererHost !== $currentHost) {
                return false;
            }
        }
        
        // Check Origin header
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!empty($origin)) {
            $originHost = parse_url($origin, PHP_URL_HOST);
            if ($originHost !== $currentHost) {
                return false;
            }
        }
        
        // SECURITY (L3): the previous User-Agent blocklist (curl/postman/python…)
        // was removed. Blocking by UA string is trivially bypassed (any client
        // can spoof a browser UA), so it provided false assurance while also
        // blocking legitimate API/mobile clients that carry no Referer/Origin.
        // Real API access control is the API key / JWT check (verifyApiKey),
        // and abuse is limited by the rate limiter below — not by UA sniffing.
        return true;
    }
    
    /**
     * Get client identifier (IP address with additional validation)
     * @return string Client identifier
     */
    public function getClientIdentifier() {
        // Try to get real IP behind proxies
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        
        // Validate IP address
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
        
        return $ip;
    }
    
    /**
     * Get remaining attempts for identifier
     * @param string $identifier Client identifier
     * @param int $limit Maximum requests allowed
     * @param int $window Time window in seconds
     * @return int Remaining attempts
     */
    public function getRemainingAttempts($identifier, $limit = null, $window = null) {
        $limit = $limit ?? $this->defaultLimit;
        $window = $window ?? $this->defaultWindow;
        
        $key = $this->getKey($identifier);
        $file = $this->getFilePath($key);
        $now = time();
        
        $attempts = $this->getAttempts($file);
        $attempts = array_filter($attempts, function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });
        
        return max(0, $limit - count($attempts));
    }
    
    /**
     * Reset attempts for identifier (useful for testing or manual reset)
     * @param string $identifier Client identifier
     */
    public function reset($identifier) {
        $key = $this->getKey($identifier);
        $file = $this->getFilePath($key);
        
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    /**
     * Generate unique key from identifier
     * @param string $identifier Client identifier
     * @return string Hashed key
     */
    private function getKey($identifier) {
        return hash('sha256', $identifier . $_SERVER['REQUEST_URI']);
    }
    
    /**
     * Get file path for key
     * @param string $key Hashed key
     * @return string File path
     */
    private function getFilePath($key) {
        return $this->storageDir . '/' . $key . '.json';
    }
    
    /**
     * Get attempts from file
     * @param string $file File path
     * @return array Array of timestamps
     */
    private function getAttempts($file) {
        if (!file_exists($file)) {
            return [];
        }
        
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        
        return is_array($data) ? $data : [];
    }
    
    /**
     * Save attempts to file
     * @param string $file File path
     * @param array $attempts Array of timestamps
     */
    private function saveAttempts($file, array $attempts) {
        file_put_contents($file, json_encode($attempts), LOCK_EX);
    }
    
    /**
     * Randomly cleanup old files (1% chance per request)
     */
    private function randomCleanup() {
        if (rand(1, 100) > $this->cleanupChance) {
            return;
        }
        
        $now = time();
        $files = glob($this->storageDir . '/*.json');
        
        foreach ($files as $file) {
            // Delete files older than 1 hour
            if (($now - filemtime($file)) > 3600) {
                unlink($file);
            }
        }
    }
    
    /**
     * Log blocked attempt (optional - for monitoring)
     * @param string $identifier Client identifier
     * @param int $attempts Current attempts
     * @param int $limit Limit exceeded
     */
    private function logBlockedAttempt($identifier, $attempts, $limit) {
        $logFile = $this->storageDir . '/blocked.log';
        $timestamp = date('Y-m-d H:i:s');
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        
        $logEntry = "[{$timestamp}] IP: {$identifier} | Attempts: {$attempts}/{$limit} | URI: {$uri} | UA: {$userAgent}\n";
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Send rate limit error response and exit
     * @param int $retryAfter Seconds until retry is allowed
     */
    public function sendRateLimitResponse($retryAfter = 60) {
        http_response_code(429);
        header('Content-Type: application/json');
        header("Retry-After: {$retryAfter}");
        
        echo json_encode([
            'error' => 'Rate limit exceeded',
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $retryAfter
        ]);
        
        exit;
    }
    
    /**
     * Send forbidden response for invalid origin and exit
     */
    public function sendForbiddenResponse() {
        http_response_code(403);
        header('Content-Type: application/json');
        
        echo json_encode([
            'error' => 'Forbidden',
            'message' => 'Access denied. Invalid request origin.'
        ]);
        
        exit;
    }
}
