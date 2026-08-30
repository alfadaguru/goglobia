<?php
// FILE: app/lib/jwt.php
// JWT Utility Class for Token Operations

class JWT
{
    private static string $secret = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET';
    private static string $algo = 'HS256';

    public static function generate(array $payload, int $expirySeconds = 86400): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => self::$algo
        ];

        $payload['iat'] = time();
        $payload['exp'] = time() + $expirySeconds;

        $base64Header  = self::base64UrlEncode(json_encode($header));
        $base64Payload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            $base64Header . '.' . $base64Payload,
            self::$secret,
            true
        );

        return $base64Header . '.' . $base64Payload . '.' . self::base64UrlEncode($signature);
    }

    public static function decode(string $token, bool $checkExpiry = true): array|false
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return false;

        [$header, $payload, $signature] = $parts;

        $expected = self::base64UrlEncode(
            hash_hmac('sha256', $header . '.' . $payload, self::$secret, true)
        );

        if (!hash_equals($expected, $signature)) return false;

        $data = json_decode(self::base64UrlDecode($payload), true);

        if (!$data) return false;

        if ($checkExpiry && ($data['exp'] ?? 0) < time()) return false;

        return $data;
    }

    public static function verify(string $token): array|false
    {
        return self::decode($token, true);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
?>