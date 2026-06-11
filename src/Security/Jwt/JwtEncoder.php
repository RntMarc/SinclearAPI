<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Jwt;

/**
 * Minimal JWT encoder/decoder supporting HS256 and RS256.
 */
final class JwtEncoder
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload, string $key, string $algorithm = 'HS256'): string
    {
        $header = ['typ' => 'JWT', 'alg' => $algorithm];
        $segments = [
            self::base64UrlEncode((string) json_encode($header)),
            self::base64UrlEncode((string) json_encode($payload)),
        ];
        $signingInput = implode('.', $segments);
        $segments[] = self::base64UrlEncode(self::sign($signingInput, $key, $algorithm));
        return implode('.', $segments);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $token, string $key, string $algorithm = 'HS256'): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid token format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $signingInput = $headerB64 . '.' . $payloadB64;
        $actual = self::base64UrlDecode($signatureB64);
        if (!self::verify($signingInput, $actual, $key, $algorithm)) {
            throw new \InvalidArgumentException('Invalid signature');
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload');
        }

        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            throw new \InvalidArgumentException('Token expired');
        }

        return $payload;
    }

    private static function sign(string $data, string $key, string $algorithm): string
    {
        return match ($algorithm) {
            'HS256' => hash_hmac('sha256', $data, $key, true),
            'RS256' => self::rsaSign($data, $key),
            default => throw new \InvalidArgumentException('Unsupported algorithm'),
        };
    }

    private static function verify(string $data, string $signature, string $key, string $algorithm): bool
    {
        return match ($algorithm) {
            'HS256' => hash_equals(hash_hmac('sha256', $data, $key, true), $signature),
            'RS256' => self::rsaVerify($data, $signature, $key),
            default => false,
        };
    }

    private static function rsaSign(string $data, string $privateKey): string
    {
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            error_log("[JWT DEBUG] rsaSign FAILED - openssl_pkey_get_private returned false. keyPreview: " . mb_substr($privateKey, 0, 60) . '...');
            throw new \RuntimeException('Invalid private key');
        }
        $details = openssl_pkey_get_details($key);
        error_log("[JWT DEBUG] rsaSign OK - key type: " . ($details['type'] ?? 'unknown') . ", bits: " . ($details['bits'] ?? 'unknown'));
        $signature = '';
        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        return $signature;
    }

    private static function rsaVerify(string $data, string $signature, string $publicKey): bool
    {
        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            error_log("[JWT DEBUG] rsaVerify FAILED - openssl_pkey_get_public returned false. keyPreview: " . mb_substr($publicKey, 0, 60) . '...');
            return false;
        }
        $details = openssl_pkey_get_details($key);
        $result = openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            error_log("[JWT DEBUG] rsaVerify FAILED - openssl_verify returned: " . ($result === false ? 'false' : $result) . ", openssl_error: " . openssl_error_string());
        } else {
            error_log("[JWT DEBUG] rsaVerify OK - key type: " . ($details['type'] ?? 'unknown') . ", bits: " . ($details['bits'] ?? 'unknown'));
        }
        return $result === 1;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
