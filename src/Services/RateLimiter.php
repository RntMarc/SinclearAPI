<?php

namespace Sinclear\Api\Services;

final readonly class RateLimiter
{
    private string $storageDir;

    public function __construct(
        string $storageDir = null,
    ) {
        $this->storageDir = $storageDir ?? sys_get_temp_dir() . '/sinclear_rate_limits';
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0700, true);
        }
    }

    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $this->cleanExpired($windowSeconds);

        $file = $this->getFilePath($key);
        $data = $this->readFile($file);

        $now = time();
        $cutoff = $now - $windowSeconds;

        $requests = array_values(array_filter($data, fn(int $timestamp) => $timestamp > $cutoff));

        if (count($requests) >= $maxRequests) {
            $this->writeFile($file, $requests);
            return false;
        }

        $requests[] = $now;
        $this->writeFile($file, $requests);

        return true;
    }

    private function cleanExpired(int $windowSeconds): void
    {
        $files = glob($this->storageDir . '/ratelimit_*.json');
        if ($files === false) {
            return;
        }

        $cutoff = time() - $windowSeconds * 2;

        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function getFilePath(string $key): string
    {
        return $this->storageDir . '/ratelimit_' . md5($key) . '.json';
    }

    private function readFile(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function writeFile(string $file, array $data): void
    {
        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}
