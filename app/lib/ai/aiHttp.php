<?php

/**
 * Shared timed JSON POST for trip chat + passport extract.
 *
 * @param string[] $headers
 * @return array{ok:bool,body:string,json:?array,http:int,errno:int,error:string,latency_ms:int,headers:array<string,string>}
 */
function aiHttpPost(string $endpoint, array $payload, array $headers, int $timeout): array
{
    $responseHeaders = [];
    $started = microtime(true);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(5, $timeout),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        },
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = (string) curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $latencyMs = (int) max(0, round((microtime(true) - $started) * 1000));

    $body = is_string($raw) ? $raw : '';
    $json = $body !== '' ? json_decode($body, true) : null;

    return [
        'ok' => $errno === 0 && $raw !== false,
        'body' => $body,
        'json' => is_array($json) ? $json : null,
        'http' => $http,
        'errno' => $errno,
        'error' => $error,
        'latency_ms' => $latencyMs,
        'headers' => $responseHeaders,
    ];
}

/** @deprecated Use aiHttpPost() */
function aiUsageHttpPost(string $endpoint, array $payload, array $headers, int $timeout): array
{
    return aiHttpPost($endpoint, $payload, $headers, $timeout);
}
