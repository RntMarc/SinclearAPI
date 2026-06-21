<?php

namespace Sinclear\Api\Services;

final readonly class NominatimCache
{
    private const int DEFAULT_TTL = 86400;

    public function __construct(
        private string $cacheDir = __DIR__ . '/../../var/cache/nominatim',
        private int $ttl = self::DEFAULT_TTL,
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
    }

    public function get(string $key): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        if (time() - filemtime($path) > $this->ttl) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    public function set(string $key, array $data): void
    {
        $path = $this->path($key);
        file_put_contents($path, json_encode($data), LOCK_EX);
    }

    public function clean(?int $maxAge = null): int
    {
        $maxAge ??= $this->ttl;
        $deleted = 0;

        if (!is_dir($this->cacheDir)) {
            return 0;
        }

        $files = glob($this->cacheDir . '/*.json');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (time() - filemtime($file) > $maxAge) {
                unlink($file);
                $deleted++;
            }
        }

        $lockFile = $this->cacheDir . '/ratelimit.lock';
        if (is_file($lockFile)) {
            unlink($lockFile);
        }

        return $deleted;
    }

    private function path(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.json';
    }
}
