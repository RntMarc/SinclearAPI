<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

/**
 * File-based rate limiting (Redis-free fallback).
 */
final class RateLimitService
{
    private readonly string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? dirname(__DIR__, 2) . '/var/rate-limit';
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }
    }

    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $file = $this->storageDir . '/' . hash('sha256', $key) . '.json';
        $now = time();
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];

        if (is_file($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        if ($now > ($data['reset'] ?? 0)) {
            $data = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        $data['count'] = ($data['count'] ?? 0) + 1;
        file_put_contents($file, (string) json_encode($data), LOCK_EX);

        return $data['count'] <= $maxRequests;
    }
}
