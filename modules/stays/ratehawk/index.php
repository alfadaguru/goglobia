<?php

include "creds.php";
include "search.php";
include "details.php";
include "rooms.php";

// ACTIONS
include "actions/issue.php";
include "actions/cancel.php";
include "actions/void.php";
include "actions/refund.php";

// function  for creating logs in modules/stays/ratehawk/logs/
function ratehawk_log($category, $action, $requestData, $responseData, $extraId = '', $meta = []) {
    // Cert / support workflow logs — always write under logs/
    $allowedCategories = ['issue', 'cancel', 'void', 'refund', 'creds', 'search', 'rooms'];
    if (!in_array((string) $category, $allowedCategories, true)) {
        return;
    }

    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            error_log('RateHawk log: failed to create logs directory: ' . $dir);
            return;
        }
        @chmod($dir, 0777);
    }

    // Ensure Apache/PHP-FPM can write even if folder was created by another user
    if (!is_writable($dir)) {
        @chmod($dir, 0777);
        if (!is_writable($dir)) {
            error_log('RateHawk log: logs directory not writable: ' . $dir);
            return;
        }
    }

    $timestamp = date('Y-m-d_H-i-s');
    $uniq = uniqid();
    $prefix = $category;
    if ($extraId !== '' && $extraId !== null) {
        $prefix .= '_' . $extraId;
    }
    if ($action) {
        $prefix .= '_' . $action;
    }

    $reqFile = "{$dir}/{$prefix}_request_{$timestamp}_{$uniq}.json";
    $resFile = "{$dir}/{$prefix}_response_{$timestamp}_{$uniq}.json";

    // Truncate huge SERP bodies so logging itself doesn't add seconds
    $parseBody = function ($data) {
        if (is_string($data) && strlen($data) > 200000) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $hotels = $decoded['data']['hotels'] ?? null;
                if (is_array($hotels)) {
                    $decoded['data']['hotels'] = array_slice($hotels, 0, 5);
                    $decoded['_log_truncated'] = true;
                    $decoded['_hotels_total'] = count($hotels);
                }
                return $decoded;
            }
            return substr($data, 0, 200000) . '...[truncated]';
        }
        if (is_array($data) || is_object($data)) {
            return $data;
        }
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            return $data;
        }
        return $data;
    };

    $reqLog = [
        'url' => $meta['url'] ?? '',
        'method' => $meta['method'] ?? 'POST',
        'headers' => $meta['headers'] ?? [],
        'request' => $parseBody($requestData),
    ];

    $resLog = [
        'response_headers' => $meta['response_headers'] ?? '',
        'response_body' => $parseBody($responseData),
    ];

    $reqWritten = @file_put_contents($reqFile, json_encode($reqLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $resWritten = @file_put_contents($resFile, json_encode($resLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($reqWritten === false || $resWritten === false) {
        error_log('RateHawk log: failed writing log files in ' . $dir);
        return;
    }
    @chmod($reqFile, 0666);
    @chmod($resFile, 0666);
}
