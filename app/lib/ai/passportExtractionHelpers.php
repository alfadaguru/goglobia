<?php

namespace App\lib\ai;

/**
 * Shared passport extraction prompt + field normalization for AI providers.
 */
trait passportExtractionHelpers
{
    private function extractionPrompt(): string
    {
        return <<<'PROMPT'
You are a passport MRZ and visual data extraction system.
Read the passport information page image and return ONLY valid JSON (no markdown).
Use ISO 3166-1 alpha-2 country codes for nationality and issuing_country.
Use dates as YYYY-MM-DD.
Gender must be M, F, or X.
If a field cannot be read confidently, use an empty string — never invent data.
Prefer Machine Readable Zone (MRZ) values when visual text conflicts.

JSON schema:
{
  "document_type": "P",
  "passport_number": "",
  "first_name": "",
  "middle_name": "",
  "last_name": "",
  "full_name": "",
  "gender": "",
  "date_of_birth": "",
  "nationality": "",
  "issuing_country": "",
  "place_of_birth": "",
  "issue_date": "",
  "expiry_date": "",
  "personal_number": "",
  "mrz_line_1": "",
  "mrz_line_2": "",
  "confidence": 0.0,
  "warnings": []
}
PROMPT;
    }

    private function parseJsonContent(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        // Strip markdown fences if the model wraps JSON.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $m)) {
            $content = trim($m[1]);
        } elseif (!str_starts_with($content, '{')) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $content = substr($content, $start, $end - $start + 1);
            }
        }

        $parsed = json_decode($content, true);
        return is_array($parsed) ? $parsed : null;
    }

    /**
     * @param array<string,mixed> $usage Unused (kept for call-site compatibility).
     * @return array<string,mixed>
     */
    private function failWithUsage(string $code, string $message, array $usage = []): array
    {
        return $this->fail($code, $message);
    }

    /**
     * @param array<string,mixed> $usage Unused (kept for call-site compatibility).
     */
    private function successFromParsed(array $parsed, array $usage = []): array
    {
        $data = $this->normalizeData($parsed);
        if ($data['passport_number'] === '' && $data['last_name'] === '' && $data['first_name'] === '') {
            return $this->failWithUsage(
                'AI_DOCUMENT_NOT_DETECTED',
                'We could not clearly read this passport. Please take another photo with better lighting and make sure the complete passport page is visible.'
            );
        }

        $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.0;
        $warnings = [];
        if (isset($parsed['warnings']) && is_array($parsed['warnings'])) {
            foreach ($parsed['warnings'] as $w) {
                $w = trim((string) $w);
                if ($w !== '') {
                    $warnings[] = $w;
                }
            }
        }

        return [
            'status' => true,
            'message' => 'Passport details extracted successfully.',
            'confidence' => $confidence > 0 ? $confidence : null,
            'data' => $data,
            'warnings' => $warnings,
        ];
    }

    private function normalizeData(array $parsed): array
    {
        $first = $this->cleanName($parsed['first_name'] ?? '');
        $middle = $this->cleanName($parsed['middle_name'] ?? '');
        $last = $this->cleanName($parsed['last_name'] ?? '');
        $full = $this->cleanName($parsed['full_name'] ?? '');

        if ($first === '' && $last === '' && $full !== '') {
            $parts = preg_split('/\s+/', $full) ?: [];
            if (count($parts) === 1) {
                $first = $parts[0];
            } elseif (count($parts) >= 2) {
                $last = array_pop($parts);
                $first = array_shift($parts);
                $middle = implode(' ', $parts);
            }
        }

        if ($full === '') {
            $full = trim(implode(' ', array_filter([$first, $middle, $last])));
        }

        if ($middle !== '') {
            $first = trim($first . ' ' . $middle);
            $middle = '';
        }

        return [
            'document_type' => strtoupper(substr(trim((string) ($parsed['document_type'] ?? 'P')), 0, 5)),
            'passport_number' => strtoupper(preg_replace('/\s+/', '', (string) ($parsed['passport_number'] ?? '')) ?? ''),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'full_name' => $full,
            'gender' => $this->normalizeGender($parsed['gender'] ?? ''),
            'date_of_birth' => $this->normalizeDate($parsed['date_of_birth'] ?? ''),
            'nationality' => $this->normalizeCountry($parsed['nationality'] ?? ''),
            'issuing_country' => $this->normalizeCountry($parsed['issuing_country'] ?? ''),
            'place_of_birth' => $this->cleanName($parsed['place_of_birth'] ?? ''),
            'issue_date' => $this->normalizeDate($parsed['issue_date'] ?? ''),
            'expiry_date' => $this->normalizeDate($parsed['expiry_date'] ?? ''),
            'personal_number' => strtoupper(trim((string) ($parsed['personal_number'] ?? ''))),
            'mrz_line_1' => trim((string) ($parsed['mrz_line_1'] ?? '')),
            'mrz_line_2' => trim((string) ($parsed['mrz_line_2'] ?? '')),
        ];
    }

    private function cleanName(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = preg_replace("/[^A-Z '\\-]/", '', $value) ?? '';
        return trim($value);
    }

    private function normalizeGender(string $value): string
    {
        $value = strtoupper(trim($value));
        if (in_array($value, ['M', 'MALE', 'MR'], true)) {
            return 'M';
        }
        if (in_array($value, ['F', 'FEMALE', 'MS', 'MRS', 'MISS'], true)) {
            return 'F';
        }
        if ($value === 'X') {
            return 'X';
        }
        return '';
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            }
            return '';
        }
        if (preg_match('/^(\d{2})[\/\-.](\d{2})[\/\-.](\d{4})$/', $value, $m)) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = (int) $m[3];
            if (checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }
        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        return '';
    }

    private function normalizeCountry(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }
        static $alpha3 = [
            'USA' => 'US', 'GBR' => 'GB', 'ARE' => 'AE', 'PAK' => 'PK', 'IND' => 'IN',
            'SAU' => 'SA', 'CAN' => 'CA', 'AUS' => 'AU', 'DEU' => 'DE', 'FRA' => 'FR',
            'ITA' => 'IT', 'ESP' => 'ES', 'NLD' => 'NL', 'TUR' => 'TR', 'EGY' => 'EG',
            'CHN' => 'CN', 'JPN' => 'JP', 'KOR' => 'KR', 'SGP' => 'SG', 'MYS' => 'MY',
            'IDN' => 'ID', 'PHL' => 'PH', 'THA' => 'TH', 'VNM' => 'VN', 'BGD' => 'BD',
            'LKA' => 'LK', 'NPL' => 'NP', 'IRN' => 'IR', 'IRQ' => 'IQ', 'JOR' => 'JO',
            'LBN' => 'LB', 'SYR' => 'SY', 'KWT' => 'KW', 'QAT' => 'QA', 'BHR' => 'BH',
            'OMN' => 'OM', 'YEM' => 'YE', 'MAR' => 'MA', 'DZA' => 'DZ', 'TUN' => 'TN',
            'ZAF' => 'ZA', 'NGA' => 'NG', 'KEN' => 'KE', 'ETH' => 'ET', 'RUS' => 'RU',
            'UKR' => 'UA', 'POL' => 'PL', 'SWE' => 'SE', 'NOR' => 'NO', 'DNK' => 'DK',
            'FIN' => 'FI', 'IRL' => 'IE', 'PRT' => 'PT', 'GRC' => 'GR', 'CHE' => 'CH',
            'AUT' => 'AT', 'BEL' => 'BE', 'NZL' => 'NZ', 'MEX' => 'MX', 'BRA' => 'BR',
            'ARG' => 'AR', 'CHL' => 'CL', 'COL' => 'CO', 'PER' => 'PE', 'D<<' => '',
        ];
        if (isset($alpha3[$value])) {
            return $alpha3[$value];
        }
        if (preg_match('/^[A-Z]{2}$/', $value)) {
            return $value;
        }
        if (preg_match('/^[A-Z]{3}$/', $value)) {
            return $alpha3[$value] ?? '';
        }
        return '';
    }

    private function fail(string $code, string $message): array
    {
        return [
            'status' => false,
            'message' => $message,
            'error_code' => $code,
        ];
    }
}
