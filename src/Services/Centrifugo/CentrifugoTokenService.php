<?php

namespace Sinclear\Api\Services\Centrifugo;

/**
 * Generates and validates Centrifugo connection tokens (HS256 JWTs).
 *
 * Centrifugo expects HMAC-based tokens (not RSA) with audience "centrifugo"
 * and issuer matching client.token.issuer in the Centrifugo config.
 */
final readonly class CentrifugoTokenService
{
    public function __construct(
        private string $hmacSecret,
        private int $tokenTtl,
        private string $issuer = 'sinclear-api',
        private string $audience = 'centrifugo',
    ) {}

    public function generateConnectionToken(string $userId): string
    {
        $now = time();
        $payload = [
            'iss' => $this->issuer,
            'sub' => (string) $userId,
            'aud' => $this->audience,
            'iat' => $now,
            'exp' => $now + $this->tokenTtl,
        ];

        return $this->encodeJwt($payload);
    }

    public function getTokenTtl(): int
    {
        return $this->tokenTtl;
    }

    /**
     * Decode and verify a Centrifugo connection token.
     *
     * Returns the payload object on success, null on failure.
     * Useful for tests and server-side validation.
     */
    public function parseToken(string $token): ?object
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        // Verify header algorithm
        $headerDecoded = json_decode($this->base64urlDecode($header));
        if (!$headerDecoded instanceof \stdClass || ($headerDecoded->alg ?? '') !== 'HS256') {
            return null;
        }

        // Verify signature
        $expectedSig = hash_hmac('sha256', "$header.$payload", $this->hmacSecret, true);
        $actualSig = $this->base64urlDecode($signature);

        if (!hash_equals($expectedSig, $actualSig)) {
            return null;
        }

        $payloadDecoded = json_decode($this->base64urlDecode($payload));
        if (!$payloadDecoded instanceof \stdClass) {
            return null;
        }

        // Verify expiry
        if (isset($payloadDecoded->exp) && $payloadDecoded->exp < time()) {
            return null;
        }

        return $payloadDecoded;
    }

    private function encodeJwt(array $payload): string
    {
        $header = $this->base64urlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payloadEncoded = $this->base64urlEncode(json_encode($payload));

        $signature = $this->base64urlEncode(
            hash_hmac('sha256', "$header.$payloadEncoded", $this->hmacSecret, true),
        );

        return "$header.$payloadEncoded.$signature";
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
