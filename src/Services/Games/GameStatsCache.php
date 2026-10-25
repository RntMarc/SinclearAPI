<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games;

/**
 * Dateisystem-Cache fuer Spiele-Statistiken.
 *
 * Struktur:
 *   var/cache/game-stats/{game}/_meta.json
 *   var/cache/game-stats/{game}/{accountId}.json
 *
 * Der Cache wird ausschliesslich vom Cronjob geschrieben. Eintraege, die
 * aelter als die konfigurierte TTL sind, werden nicht mehr ausgeliefert.
 * Die Cache-Metadaten zeigen den Zeitpunkt der letzten Erneuerung.
 */
final readonly class GameStatsCache
{
    public function __construct(
        private string $cacheDir = __DIR__ . '/../../../var/cache/game-stats',
        private int $ttl = 3600,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function read(string $game, string $accountId): ?array
    {
        $path = $this->statsPath($game, $accountId);
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

    /** @param array<string, mixed> $data */
    public function write(string $game, string $accountId, array $data): void
    {
        $path = $this->statsPath($game, $accountId);
        $this->ensureDirectory(dirname($path));

        file_put_contents(
            $path,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }

    /**
     * @return array{lastRefreshedAt: string|null, nextRefreshAt: string|null, intervalSeconds: int}
     */
    public function meta(string $game): array
    {
        $path = $this->metaPath($game);
        $default = [
            'lastRefreshedAt' => null,
            'nextRefreshAt' => null,
            'intervalSeconds' => $this->ttl,
        ];

        if (!is_file($path)) {
            return $default;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $default;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $default;
        }

        return [
            'lastRefreshedAt' => is_string($data['lastRefreshedAt'] ?? null) ? $data['lastRefreshedAt'] : null,
            'nextRefreshAt' => is_string($data['nextRefreshAt'] ?? null) ? $data['nextRefreshAt'] : null,
            'intervalSeconds' => (int) ($data['intervalSeconds'] ?? $this->ttl),
        ];
    }

    public function writeMeta(string $game, string $lastRefreshedAt, string $nextRefreshAt): void
    {
        $path = $this->metaPath($game);
        $this->ensureDirectory(dirname($path));

        file_put_contents(
            $path,
            json_encode([
                'lastRefreshedAt' => $lastRefreshedAt,
                'nextRefreshAt' => $nextRefreshAt,
                'intervalSeconds' => $this->ttl,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * Entfernt Cache-Dateien, die aelter als die angegebene Zeitspanne sind.
     */
    public function clean(string $game, ?int $maxAge = null): int
    {
        $maxAge ??= $this->ttl;
        $dir = $this->gameDir($game);
        if (!is_dir($dir)) {
            return 0;
        }

        $files = glob($dir . '/*.json');
        if ($files === false) {
            return 0;
        }

        $deleted = 0;
        foreach ($files as $file) {
            if (basename($file) === '_meta.json') {
                continue;
            }

            if (time() - filemtime($file) > $maxAge) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function statsPath(string $game, string $accountId): string
    {
        return $this->gameDir($game) . '/' . $this->sanitize($accountId) . '.json';
    }

    private function metaPath(string $game): string
    {
        return $this->gameDir($game) . '/_meta.json';
    }

    private function gameDir(string $game): string
    {
        return rtrim($this->cacheDir, '/') . '/' . $this->sanitize($game);
    }

    private function sanitize(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?? '';

        return $sanitized !== '' ? $sanitized : '_invalid';
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
