<?php

namespace Sinclear\Api\Services;

final readonly class NominatimRateLimiter
{
    private string $lockFile;

    private const int MIN_INTERVAL_US = 1_100_000;

    public function __construct(
        string $cacheDir = __DIR__ . '/../../var/cache/nominatim',
    ) {
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $this->lockFile = rtrim($cacheDir, '/') . '/ratelimit.lock';
    }

    public function waitForSlot(): void
    {
        $lockHandle = fopen($this->lockFile, 'c+');
        if ($lockHandle === false) {
            return;
        }

        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            return;
        }

        $lastTimestamp = 0.0;
        $content = stream_get_contents($lockHandle);
        if ($content !== false && $content !== '') {
            $lastTimestamp = (float) $content;
        }

        $now = microtime(true);
        $elapsed = ($now - $lastTimestamp) * 1_000_000;
        $sleep = (int) (self::MIN_INTERVAL_US - $elapsed);

        if ($sleep > 0) {
            usleep($sleep);
        }

        rewind($lockHandle);
        fwrite($lockHandle, (string) microtime(true));
        fflush($lockHandle);

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
