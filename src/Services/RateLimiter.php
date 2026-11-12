<?php

namespace Sinclear\Api\Services;

final class RateLimiter
{
    private string $storageDir;

    /**
     * Letzter Zeitpunkt eines `cleanExpired`-Laufs. Das Aufräumen per `glob()`
     * über alle Rate-Limit-Dateien ist teuer und darf nicht bei jedem Request
     * laufen — daher nur einmal pro Intervall.
     */
    private int $lastCleanupAt = 0;

    private const int CLEANUP_INTERVAL_SECONDS = 60;

    public function __construct(
        ?string $storageDir = null,
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
        $now = time();
        $cutoff = $now - $windowSeconds;

        // Atomarer Read-Modify-Write: flock um Lesen UND Schreiben, damit
        // parallele Requests sich nicht gegenseitig den Zähler überschreiben
        // (TOCTOU-Race-Condition der vorherigen Implementierung).
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Fail-open: Bei Dateisystemfehlern nicht versehentlich blockieren.
            return true;
        }

        try {
            flock($handle, LOCK_EX);

            $content = stream_get_contents($handle);
            $data = [];
            if ($content !== false && $content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            $requests = array_values(array_filter($data, fn(int $timestamp) => $timestamp > $cutoff));

            if (count($requests) >= $maxRequests) {
                $this->writeHandle($handle, $requests);
                return false;
            }

            $requests[] = $now;
            $this->writeHandle($handle, $requests);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function writeHandle($handle, array $data): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data));
    }

    private function cleanExpired(int $windowSeconds): void
    {
        $now = time();
        if ($now - $this->lastCleanupAt < self::CLEANUP_INTERVAL_SECONDS) {
            return;
        }
        $this->lastCleanupAt = $now;

        $files = glob($this->storageDir . '/ratelimit_*.json');
        if ($files === false) {
            return;
        }

        $cutoff = $now - $windowSeconds * 2;

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
}
