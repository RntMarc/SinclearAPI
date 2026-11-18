<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\FcmPushService;

/**
 * DB-freier Test für das requestübergreifende OAuth-Token-Caching von FCM.
 *
 * Ohne den Dateicache würde jeder PHP-Request einen neuen JWT signieren und
 * einen blockierenden Token-Request zu Google auslösen.
 */
final class FcmTokenCacheTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir()
            . '/fcm-token-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
    }

    /** @return array{token: string, expiresAt: int}|null */
    private function read(string $clientEmail): ?array
    {
        $method = new \ReflectionMethod(FcmPushService::class, 'readCachedToken');
        /** @var array{token: string, expiresAt: int}|null $result */
        $result = $method->invoke(null, $this->cacheFile, $clientEmail);

        return $result;
    }

    private function write(string $token, int $expiresAt, string $clientEmail): void
    {
        $method = new \ReflectionMethod(FcmPushService::class, 'writeCachedToken');
        $method->invoke(null, $this->cacheFile, $token, $expiresAt, $clientEmail);
    }

    public function testWrittenTokenIsReadBack(): void
    {
        $expiresAt = time() + 600;
        $this->write('access-token-123', $expiresAt, 'svc@example.com');

        $cached = $this->read('svc@example.com');

        $this->assertSame('access-token-123', $cached['token'] ?? null);
        $this->assertSame($expiresAt, $cached['expiresAt'] ?? null);
    }

    public function testTokenFromAnotherServiceAccountIsIgnored(): void
    {
        $this->write('access-token-123', time() + 600, 'old@example.com');

        $this->assertNull($this->read('new@example.com'));
    }

    public function testExpiredTokenIsIgnored(): void
    {
        $this->write('access-token-123', time() - 1, 'svc@example.com');

        $this->assertNull($this->read('svc@example.com'));
    }
}
