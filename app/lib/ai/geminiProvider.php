<?php

namespace App\lib\ai;

class geminiProvider implements aiProviderInterface
{
    use passportExtractionHelpers;

    public function extract(string $imageBinary, string $mimeType, array $config): array
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        $model = trim((string) ($config['model'] ?? 'gemini-2.0-flash'));
        $timeout = max(5, (int) ($config['timeout'] ?? 30));

        if ($apiKey === '') {
            return $this->fail('AI_NOT_CONFIGURED', 'Passport AI is not configured.');
        }
        if ($model === '') {
            $model = 'gemini-2.0-flash';
        }
        if ($endpoint === '') {
            $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent';
        }
        $endpoint = str_replace('{model}', rawurlencode($model), $endpoint);
        if (!str_contains($endpoint, 'key=')) {
            $endpoint .= (str_contains($endpoint, '?') ? '&' : '?') . 'key=' . rawurlencode($apiKey);
        }

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $this->extractionPrompt()],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => base64_encode($imageBinary),
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
            ],
        ];

        $http = function_exists('aiHttpPost')
            ? aiHttpPost($endpoint, $payload, [
                'Content-Type: application/json',
            ], $timeout)
            : ['ok' => false, 'body' => '', 'json' => null, 'http' => 0, 'errno' => 1, 'error' => 'HTTP helper missing', 'latency_ms' => 0, 'headers' => []];
        $httpCode = (int) ($http['http'] ?? 0);
        $errno = (int) ($http['errno'] ?? 0);
        $response = is_array($http['json'] ?? null) ? $http['json'] : null;

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return $this->failWithUsage('AI_PROVIDER_TIMEOUT', 'The passport service timed out. Please try again.');
        }
        if (empty($http['ok'])) {
            error_log('[ai][gemini] curl_error errno=' . $errno . ' msg=' . (string) ($http['error'] ?? ''));
            return $this->failWithUsage('AI_PROVIDER_UNAVAILABLE', 'Passport service is temporarily unavailable.');
        }

        if (!is_array($response)) {
            error_log('[ai][gemini] invalid_json http=' . $httpCode);
            return $this->failWithUsage('AI_INVALID_RESPONSE', 'Invalid response from passport service.');
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return $this->failWithUsage('AI_INVALID_API_KEY', 'Gemini API credentials are invalid. Please check the API key in AI settings.');
        }

        if ($httpCode >= 400) {
            $apiError = is_array($response['error'] ?? null) ? $response['error'] : [];
            $apiMessage = (string) ($apiError['message'] ?? '');
            $apiStatus = (string) ($apiError['status'] ?? '');
            error_log('[ai][gemini] http_error http=' . $httpCode . ' status=' . $apiStatus . ' msg=' . $apiMessage);

            $msgLower = strtolower($apiStatus . ' ' . $apiMessage);
            if (
                $apiStatus === 'UNAUTHENTICATED'
                || $apiStatus === 'PERMISSION_DENIED'
                || str_contains($msgLower, 'api key')
                || str_contains($msgLower, 'api_key')
                || str_contains($msgLower, 'invalid api')
            ) {
                return $this->failWithUsage('AI_INVALID_API_KEY', 'Gemini API credentials are invalid. Please check the API key in AI settings.');
            }

            if ($httpCode === 429 || str_contains($msgLower, 'quota')) {
                return $this->failWithUsage('AI_QUOTA_EXCEEDED', 'Gemini quota exceeded. Please check your Google AI billing/credits.');
            }
            if (str_contains(strtolower($apiMessage), 'model') || $apiStatus === 'NOT_FOUND') {
                return $this->failWithUsage(
                    'AI_INVALID_MODEL',
                    'The configured Gemini model is invalid or unavailable. Please update the model in AI settings (e.g. gemini-2.0-flash).',
                    $usage
                );
            }

            return $this->failWithUsage('AI_PROVIDER_UNAVAILABLE', 'Passport service is temporarily unavailable.');
        }

        $content = (string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $content = trim($content);
        if ($content === '') {
            return $this->failWithUsage('AI_EXTRACTION_FAILED', 'The passport details could not be read. Please upload a clearer image.');
        }

        $parsed = $this->parseJsonContent($content);
        if ($parsed === null) {
            return $this->failWithUsage('AI_INVALID_RESPONSE', 'Could not parse passport details from the AI response.');
        }

        return $this->successFromParsed($parsed);
    }
}
