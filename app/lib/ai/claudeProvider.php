<?php

namespace App\lib\ai;

class claudeProvider implements aiProviderInterface
{
    use passportExtractionHelpers;

    public function extract(string $imageBinary, string $mimeType, array $config): array
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        $model = $this->resolveModel(trim((string) ($config['model'] ?? '')));
        $timeout = max(5, (int) ($config['timeout'] ?? 30));

        if ($apiKey === '') {
            return $this->fail('AI_NOT_CONFIGURED', 'Passport AI is not configured.');
        }
        if ($endpoint === '') {
            $endpoint = 'https://api.anthropic.com/v1/messages';
        }

        $mediaType = $this->normalizeMediaType($mimeType);
        if ($mediaType === null) {
            return $this->fail('AI_INVALID_FILE_TYPE', 'Unsupported image type for Claude.');
        }

        // Do not send temperature/top_p — newer Claude models reject non-default sampling params.
        $payload = [
            'model' => $model,
            'max_tokens' => 2048,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mediaType,
                                'data' => base64_encode($imageBinary),
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $this->extractionPrompt(),
                        ],
                    ],
                ],
            ],
        ];

        $http = function_exists('aiHttpPost')
            ? aiHttpPost($endpoint, $payload, [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ], $timeout)
            : ['ok' => false, 'body' => '', 'json' => null, 'http' => 0, 'errno' => 1, 'error' => 'HTTP helper missing', 'latency_ms' => 0, 'headers' => []];
        $httpCode = (int) ($http['http'] ?? 0);
        $errno = (int) ($http['errno'] ?? 0);
        $response = is_array($http['json'] ?? null) ? $http['json'] : null;

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return $this->failWithUsage('AI_PROVIDER_TIMEOUT', 'The passport service timed out. Please try again.');
        }
        if (empty($http['ok'])) {
            error_log('[ai][claude] curl_error errno=' . $errno . ' msg=' . (string) ($http['error'] ?? ''));
            return $this->failWithUsage('AI_PROVIDER_UNAVAILABLE', 'Passport service is temporarily unavailable.');
        }

        if (!is_array($response)) {
            error_log('[ai][claude] invalid_json http=' . $httpCode);
            return $this->failWithUsage('AI_INVALID_RESPONSE', 'Invalid response from passport service.');
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return $this->failWithUsage('AI_INVALID_API_KEY', 'Claude API credentials are invalid. Please check the API key in AI settings.');
        }

        if ($httpCode >= 400) {
            $apiError = is_array($response['error'] ?? null) ? $response['error'] : [];
            $apiType = strtolower((string) ($apiError['type'] ?? ''));
            $apiMessage = trim((string) ($apiError['message'] ?? ''));
            error_log('[ai][claude] http_error http=' . $httpCode . ' type=' . $apiType . ' model=' . $model . ' msg=' . $apiMessage);

            if ($httpCode === 429 || str_contains($apiType . ' ' . strtolower($apiMessage), 'rate')) {
                return $this->failWithUsage('AI_QUOTA_EXCEEDED', 'Claude rate limit or quota exceeded. Please try again later or check your Anthropic billing.');
            }

            $looksLikeBadModel = $apiType === 'not_found_error'
                || str_contains(strtolower($apiMessage), 'model:')
                || (str_contains(strtolower($apiMessage), 'model') && str_contains(strtolower($apiMessage), 'not'));

            if ($looksLikeBadModel) {
                return $this->failWithUsage(
                    'AI_INVALID_MODEL',
                    'The configured Claude model ("' . $model . '") is invalid or unavailable. Update AI settings to a current model such as claude-sonnet-4-6 or claude-sonnet-5.',
                    $usage
                );
            }

            if ($apiMessage !== '') {
                return $this->failWithUsage(
                    'AI_PROVIDER_UNAVAILABLE',
                    'Claude request failed: ' . $this->safeApiMessage($apiMessage),
                    $usage
                );
            }

            return $this->failWithUsage('AI_PROVIDER_UNAVAILABLE', 'Passport service is temporarily unavailable.');
        }

        $content = '';
        $blocks = $response['content'] ?? [];
        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $content .= (string) ($block['text'] ?? '');
                }
            }
        }
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

    /**
     * Map deprecated / alias model ids to current Anthropic API ids.
     */
    private function resolveModel(string $model): string
    {
        if ($model === '') {
            return 'claude-sonnet-4-6';
        }

        $aliases = [
            'claude-sonnet-4-20250514' => 'claude-sonnet-4-6',
            'claude-sonnet-4' => 'claude-sonnet-4-6',
            'claude-sonnet-4.0' => 'claude-sonnet-4-6',
            'claude-3-5-sonnet-latest' => 'claude-sonnet-4-6',
            'claude-3-5-sonnet-20241022' => 'claude-sonnet-4-6',
            'claude-3-7-sonnet-20250219' => 'claude-sonnet-4-6',
            'claude-sonnet-4-5-20250929' => 'claude-sonnet-4-5',
        ];

        return $aliases[$model] ?? $model;
    }

    private function normalizeMediaType(string $mimeType): ?string
    {
        $mimeType = strtolower(trim($mimeType));
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            'image/gif' => 'image/gif',
            default => null,
        };
    }

    private function safeApiMessage(string $message): string
    {
        $message = preg_replace('/sk-ant-[A-Za-z0-9_-]+/', '[redacted]', $message) ?? $message;
        $message = trim($message);
        if (strlen($message) > 180) {
            $message = substr($message, 0, 177) . '...';
        }
        return $message;
    }
}
