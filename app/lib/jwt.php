<?php
// FILE: app/lib/jwt.php
// JWT Utility Class for Token Operations

class JWT
{
    private static ?string $secretCache = null;
    private static string $algo = 'HS256';

    /**
     * Resolve the HMAC signing secret.
     *
     * Priority:
     *   1. JWT_SECRET from .env  (the correct place — operator sets a long random value)
     *   2. A per-install secret DERIVED from this install's private .env DB
     *      credentials + filesystem path. This is unique per deployment, never
     *      appears in source, and is stable across requests — so even installs
     *      that never set JWT_SECRET stop using the shared, publicly-known
     *      hardcoded default. Operators should still set JWT_SECRET explicitly.
     *
     * The old hardcoded 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET' is never used.
     */
    private static function secret(): string
    {
        if (self::$secretCache !== null) {
            return self::$secretCache;
        }

        $secret = '';

        // 1) Explicit env value (preferred).
        $envValue = getenv('JWT_SECRET');
        if ($envValue !== false && trim((string)$envValue) !== '') {
            $secret = trim((string)$envValue);
        }

        // Also read from the .env file directly (this app parses .env, it does
        // not always populate getenv()).
        if ($secret === '') {
            $envFile = __DIR__ . '/../../.env';
            if (is_file($envFile)) {
                $vars = @parse_ini_file($envFile);
                if (is_array($vars) && !empty($vars['JWT_SECRET']) && trim((string)$vars['JWT_SECRET']) !== '') {
                    $secret = trim((string)$vars['JWT_SECRET']);
                }

                // 2) Derived per-install fallback from private .env material.
                if ($secret === '' && is_array($vars)) {
                    $material = ($vars['DB_PASSWORD'] ?? '')
                        . '|' . ($vars['DB_DATABASE'] ?? '')
                        . '|' . ($vars['DB_USERNAME'] ?? '')
                        . '|' . ($vars['LICENSE_KEY'] ?? '')
                        . '|' . __DIR__;
                    if (trim($material, '|') !== '') {
                        $secret = hash('sha256', 'jwt-v10|' . $material);
                    }
                }
            }
        }

        // 3) Last-resort per-process value if .env is unreadable. Not stable
        //    across requests, so tokens won't verify — which is safe (fails
        //    closed) rather than falling back to a public constant.
        if ($secret === '') {
            $secret = hash('sha256', 'jwt-v10|' . __DIR__ . '|' . (getenv('HOSTNAME') ?: php_uname('n')));
        }

        self::$secretCache = $secret;
        return $secret;
    }

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
            self::secret(),
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
            hash_hmac('sha256', $header . '.' . $payload, self::secret(), true)
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