<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\Centrifugo\CentrifugoTokenService;

class CentrifugoTokenServiceTest extends TestCase
{
    private const string SECRET = 'test-secret-key-for-hmac';
    private const int TTL = 900;
    private const string ISSUER = 'sinclear-api';
    private const string AUDIENCE = 'centrifugo';

    private CentrifugoTokenService $service;

    protected function setUp(): void
    {
        $this->service = new CentrifugoTokenService(
            hmacSecret: self::SECRET,
            tokenTtl: self::TTL,
            issuer: self::ISSUER,
            audience: self::AUDIENCE,
        );
    }

    public function testGenerateConnectionTokenReturnsJwtFormat(): void
    {
        $token = $this->service->generateConnectionToken('user-123');

        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'JWT must have 3 parts');
    }

    public function testGenerateConnectionTokenContainsCorrectClaims(): void
    {
        $token = $this->service->generateConnectionToken('user-456');
        $payload = $this->service->parseToken($token);

        $this->assertNotNull($payload);
        $this->assertSame('user-456', $payload->sub);
        $this->assertSame(self::ISSUER, $payload->iss);
        $this->assertSame(self::AUDIENCE, $payload->aud);
        $this->assertObjectHasProperty('iat', $payload);
        $this->assertObjectHasProperty('exp', $payload);
    }

    public function testGenerateConnectionTokenHasCorrectExpiry(): void
    {
        $before = time();
        $token = $this->service->generateConnectionToken('user-1');
        $after = time();

        $payload = $this->service->parseToken($token);
        $this->assertNotNull($payload);

        $this->assertGreaterThanOrEqual($before + self::TTL, $payload->exp);
        $this->assertLessThanOrEqual($after + self::TTL, $payload->exp);
    }

    public function testParseTokenReturnsNullForInvalidToken(): void
    {
        $result = $this->service->parseToken('invalid.token.here');
        $this->assertNull($result);
    }

    public function testParseTokenReturnsNullForTamperedSignature(): void
    {
        $token = $this->service->generateConnectionToken('user-1');
        $parts = explode('.', $token);
        // Tamper with signature
        $parts[2] = $this->base64urlEncode('tampered-signature');
        $tamperedToken = implode('.', $parts);

        $result = $this->service->parseToken($tamperedToken);
        $this->assertNull($result);
    }

    public function testParseTokenReturnsNullForExpiredToken(): void
    {
        // Create a service with TTL=0 so token expires immediately
        $service = new CentrifugoTokenService(
            hmacSecret: self::SECRET,
            tokenTtl: 0,
            issuer: self::ISSUER,
            audience: self::AUDIENCE,
        );

        $token = $service->generateConnectionToken('user-1');
        // Wait a bit to ensure expiry
        sleep(1);

        $result = $service->parseToken($token);
        $this->assertNull($result);
    }

    public function testParseTokenReturnsNullForWrongAlgorithm(): void
    {
        // Manually create a token with wrong algorithm
        $header = $this->base64urlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64urlEncode(json_encode([
            'sub' => 'user-1',
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'iat' => time(),
            'exp' => time() + self::TTL,
        ]));
        $signature = $this->base64urlEncode('fake-signature');
        $token = "$header.$payload.$signature";

        $result = $this->service->parseToken($token);
        $this->assertNull($result);
    }

    public function testGetTokenTtlReturnsConfiguredValue(): void
    {
        $this->assertSame(self::TTL, $this->service->getTokenTtl());
    }

    public function testGenerateConnectionTokenWithDifferentUsers(): void
    {
        $token1 = $this->service->generateConnectionToken('user-A');
        $token2 = $this->service->generateConnectionToken('user-B');

        $payload1 = $this->service->parseToken($token1);
        $payload2 = $this->service->parseToken($token2);

        $this->assertNotNull($payload1);
        $this->assertNotNull($payload2);
        $this->assertSame('user-A', $payload1->sub);
        $this->assertSame('user-B', $payload2->sub);
        $this->assertNotSame($token1, $token2);
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
