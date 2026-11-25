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
    private function read(string $credentialsHash): ?array
    {
        $method = new \ReflectionMethod(FcmPushService::class, 'readCachedToken');
        /** @var array{token: string, expiresAt: int}|null $result */
        $result = $method->invoke(null, $this->cacheFile, $credentialsHash);

        return $result;
    }

    private function write(string $token, int $expiresAt, string $credentialsHash): void
    {
        $method = new \ReflectionMethod(FcmPushService::class, 'writeCachedToken');
        $method->invoke(null, $this->cacheFile, $token, $expiresAt, $credentialsHash);
    }

    public function testWrittenTokenIsReadBack(): void
    {
        $expiresAt = time() + 600;
        $this->write('access-token-123', $expiresAt, 'hash-a');

        $cached = $this->read('hash-a');

        $this->assertSame('access-token-123', $cached['token'] ?? null);
        $this->assertSame($expiresAt, $cached['expiresAt'] ?? null);
    }

    public function testTokenForRotatedCredentialsIsIgnored(): void
    {
        $this->write('access-token-123', time() + 600, 'hash-old-key');

        $this->assertNull($this->read('hash-new-key'));
    }

    public function testExpiredTokenIsIgnored(): void
    {
        $this->write('access-token-123', time() - 1, 'hash-a');

        $this->assertNull($this->read('hash-a'));
    }
}
