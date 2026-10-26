<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games;

/**
 * Dateisystem-Cache fuer Spiele-Statistiken.
 *
 * Struktur:
 *   var/cache/game-stats/{game}/_meta.json
 *   var/cache/game-stats/{game}/{accountId}.json
 *   var/cache/game-stats/{game}/_heroes.json   (optionaler Aux-Cache)
 *   var/cache/game-stats/{game}/_ranks.json    (optionaler Aux-Cache)
 *
 * Der Cache wird ausschliesslich vom Cronjob geschrieben. Eintraege, die
 * aelter als die konfigurierte TTL sind, werden nicht mehr ausgeliefert.
 * Die Cache-Metadaten zeigen den Zeitpunkt der letzten Erneuerung.
 *
 * Aux-Caches (Dateien mit fuehrendem Unterstrich) speichern langjaehrige
 * Stammdaten wie Helden- und Rang-Assets und haben eine eigene, deutlich
 * laengere TTL. Sie werden von `clean()` nicht entfernt.
 */
final readonly class GameStatsCache
{
    private const int DEFAULT_AUX_TTL = 86400;

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
     * Liest einen Aux-Cache (z. B. `heroes` aus `_heroes.json`).
     *
     * @return array<array-key, mixed>|null
     */
    public function readAux(string $game, string $name, ?int $maxAge = null): ?array
    {
        $maxAge ??= self::DEFAULT_AUX_TTL;
        $path = $this->auxPath($game, $name);
        if (!is_file($path)) {
            return null;
        }

        if (time() - filemtime($path) > $maxAge) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<array-key, mixed> $data */
    public function writeAux(string $game, string $name, array $data): void
    {
        $path = $this->auxPath($game, $name);
        $this->ensureDirectory(dirname($path));

        file_put_contents(
            $path,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
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
            // Metadaten und Aux-Caches (_meta, _heroes, _ranks, ...) haben
            // eigene Aktualisierungszyklen und bleiben erhalten.
            if (str_starts_with(basename($file), '_')) {
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

    private function auxPath(string $game, string $name): string
    {
        return $this->gameDir($game) . '/_' . $this->sanitize(ltrim($name, '_')) . '.json';
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
