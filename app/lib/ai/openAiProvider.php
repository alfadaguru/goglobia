<?php

namespace App\lib\ai;

class openAiProvider implements aiProviderInterface
{
    use passportExtractionHelpers;

    public function extract(string $imageBinary, string $mimeType, array $config): array
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        $model = trim((string) ($config['model'] ?? 'gpt-4o'));
        $timeout = max(5, (int) ($config['timeout'] ?? 30));

        if ($apiKey === '') {
            return $this->fail('AI_NOT_CONFIGURED', 'Passport AI is not configured.');
        }
        if ($endpoint === '') {
            $endpoint = 'https://api.openai.com/v1/chat/completions';
        }

        $base64 = base64_encode($imageBinary);
        $dataUrl = 'data:' . $mimeType . ';base64,' . $base64;

        $payload = [
            'model' => $model,
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $this->extractionPrompt()],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUrl,
                                'detail' => 'high',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $http = function_exists('aiHttpPost')
            ? aiHttpPost($endpoint, $payload, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ], $timeout)
            : ['ok' => false, 'body' => '', 'json' => null, 'http' => 0, 'errno' => 1, 'error' => 'HTTP helper missing', 'latency_ms' => 0, 'headers' => []];
        $httpCode = (int) ($http['http'] ?? 0);
        $errno = (int) ($http['errno'] ?? 0);
        $response = is_array($http['json'] ?? null) ? $http['json'] : null;

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return $this->failWithUsage('AI_PROVIDER_TIMEOUT', 'The passport service timed out. Please try again.');
        }
        if (empty($http['ok'])) {
            error_log('[ai][openai] curl_error errno=' . $errno . ' msg=' . (string) ($http['error'] ?? ''));
            return $this->failWithUsage('AI_PROVIDER_UNAVAILABLE', 'Passport service is temporarily unavailable.');
        }

        if (!is_array($response)) {
            error_log('[ai][openai] invalid_json http=' . $httpCode);
            return $this->failWithUsage('AI_INVALID_RESPONSE', 'Invalid response from passport service.');
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return $this->failWithUsage('AI_INVALID_API_KEY', 'Passport AI credentials are invalid. Please check the API key in AI settings.');
        }

        if ($httpCode >= 400) {
            $apiError = is_array($response['error'] ?? null) ? $response['error'] : [];
            $apiCode = (string) ($apiError['code'] ?? '');
            $apiType = (string) ($apiError['type'] ?? '');
            error_log('[ai][openai] http_error http=' . $httpCode . ' code=' . $apiCode . ' type=' . $apiType);

            if ($httpCode === 429 || $apiCode === 'insufficient_quota' || $apiType === 'insufficient_quota') {
                return $this->failWithUsage(
                    'AI_QUOTA_EXCEEDED',
                    'OpenAI quota exceeded. Please add billing/credits to your OpenAI account, or use another API key in AI settings.',
                    $usage
                );
            }
            if ($apiCode === 'model_not_found' || str_contains(strtolower((string) ($apiError['message'] ?? '')), 'model')) {
                return $this->failWithUsage(
                    'AI_INVALID_MODEL',
                    'The configured AI model is invalid or unavailable. Please update the model in AI settings (e.g. gpt-4o).',
                    $usage
                );
            }

            return $this->failWithUsage('AI_PROVIDER_UNAVAILABLE', 'Passport service is temporarily unavailable.');
        }

        $content = $response['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $content = json_encode($content);
        }
        $content = trim((string) $content);
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
