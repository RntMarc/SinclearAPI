<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use Psr\Log\LoggerInterface;

/**
 * Erzeugt und cached winzige Vorschaubilder (Thumbnails) für Base64-Bilder.
 *
 * Bilder kommen in der API als Base64 in den JSON-Responses vor und sind bei
 * schwachem Mobilfunk der Hauptgrund für lange Ladezeiten. Dieser Service
 * rechnet ein Bild einmalig auf ein kleines WebP/JPEG-Vorschaubild herunter und
 * legt es — adressiert über den xxh128-Hash des Original-Base64 — im
 * Disk-Cache unter `var/cache/media/` ab. Wiederholte Anfragen (derselbe Avatar
 * in einer Liste) sind damit reine Datei-Lesezugriffe ohne CPU-Last.
 *
 * Der Hash ist gleichzeitig die öffentliche Adresse für das Nachladen der
 * vollen Qualität über `GET /api/v2/media/{hash}` (dazu wird das Original als
 * `.full`-Datei vorgehalten).
 */
final readonly class MediaService
{
    private const int THUMB_MAX_DIMENSION = 256;
    private const int THUMB_QUALITY = 45;

    private string $cacheDir;

    public function __construct(
        private LoggerInterface $logger,
    ) {
        $this->cacheDir = dirname(__DIR__, 2) . '/var/cache/media';
    }

    /**
     * Liefert das Vorschaubild für ein Base64-Bild.
     *
     * @param string $base64 Raw Base64 (ohne `data:`-Präfix) oder Data-URI.
     * @param bool $persistFull Ob das Original zusätzlich für
     *        `GET /media/{hash}` abgelegt werden soll. Nur beim Ausliefern im
     *        Low-Data-Modus nötig; Uploads brauchen das Original nicht erneut,
     *        da es bereits in der Datenbank liegt.
     * @return array{hash: string, thumbnail: string, mime: string, generated: bool}|null
     *         `null`, wenn der String kein dekodierbares Bild ist.
     */
    public function thumbnail(string $base64, bool $persistFull = false): ?array
    {
        $raw = $this->stripDataUri($base64);
        if ($raw === '') {
            return null;
        }

        $hash = hash('xxh128', $raw);
        $dir = $this->dirFor($hash);
        $thumbFile = "$dir/$hash.thumb";
        $fullFile = "$dir/$hash.full";

        $generated = false;

        if (!is_file($thumbFile)) {
            $thumb = $this->generateThumbnail($raw);
            if ($thumb === null) {
                return null;
            }
            $this->write($thumbFile, $thumb['base64']);
            $generated = true;
        }

        if ($persistFull && !is_file($fullFile)) {
            $this->write($fullFile, $raw);
        }

        // mtime aktualisieren, damit der Cleanup-Task weiß, dass die Datei
        // kürzlich benutzt wurde.
        @touch($thumbFile);
        if ($persistFull) {
            @touch($fullFile);
        }

        $thumbBase64 = file_get_contents($thumbFile);
        if ($thumbBase64 === false) {
            return null;
        }

        return [
            'hash' => $hash,
            'thumbnail' => $thumbBase64,
            'mime' => $this->thumbMime(),
            'generated' => $generated,
        ];
    }

    /**
     * Liefert das Original-Base64 für einen Hash (für `GET /media/{hash}`).
     */
    public function full(string $hash): ?string
    {
        if (!preg_match('/^[0-9a-f]{32}$/i', $hash)) {
            return null;
        }

        $file = $this->dirFor($hash) . '/' . $hash . '.full';
        if (!is_file($file)) {
            return null;
        }

        @touch($file);
        $content = file_get_contents($file);
        return $content === false ? null : $content;
    }

    /**
     * Entfernt Dateien aus dem Cache, die länger als `$olderThanSeconds` nicht
     * mehr benutzt wurden. Gibt die Anzahl gelöschter Dateien zurück.
     */
    public function cleanup(int $olderThanSeconds): int
    {
        $removed = 0;
        $cutoff = time() - $olderThanSeconds;
        $this->cleanupDir($this->cacheDir, $cutoff, $removed);
        return $removed;
    }

    private function cleanupDir(string $dir, int $cutoff, int &$removed): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = "$dir/$entry";
            if (is_dir($path)) {
                $this->cleanupDir($path, $cutoff, $removed);
                @rmdir($path);
                continue;
            }

            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                $removed++;
            }
        }
    }

    private function stripDataUri(string $value): string
    {
        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');
            if ($comma !== false) {
                return substr($value, $comma + 1);
            }
        }
        return $value;
    }

    /**
     * @return array{base64: string}|null
     */
    private function generateThumbnail(string $raw): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            $this->logger->debug('MediaService: GD not available, skipping thumbnail');
            return null;
        }

        $decoded = base64_decode($raw, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        $image = @imagecreatefromstring($decoded);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > self::THUMB_MAX_DIMENSION || $height > self::THUMB_MAX_DIMENSION) {
            $ratio = min(
                self::THUMB_MAX_DIMENSION / $width,
                self::THUMB_MAX_DIMENSION / $height,
            );
            $newWidth = max(1, (int) round($width * $ratio));
            $newHeight = max(1, (int) round($height * $ratio));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($resized === false) {
                imagedestroy($image);
                return null;
            }

            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefill($resized, 0, 0, $transparent);
            }

            imagecopyresampled(
                $resized,
                $image,
                0,
                0,
                0,
                0,
                $newWidth,
                $newHeight,
                $width,
                $height,
            );
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        if (function_exists('imagewebp')) {
            $ok = imagewebp($image, null, self::THUMB_QUALITY);
        } else {
            $ok = imagejpeg($image, null, self::THUMB_QUALITY);
        }
        $encoded = ob_get_clean();
        imagedestroy($image);

        if ($ok === false || $encoded === false || $encoded === '') {
            return null;
        }

        return ['base64' => base64_encode($encoded)];
    }

    private function thumbMime(): string
    {
        return function_exists('imagewebp') ? 'image/webp' : 'image/jpeg';
    }

    private function dirFor(string $hash): string
    {
        return $this->cacheDir . '/' . substr($hash, 0, 2);
    }

    private function write(string $file, string $contents): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($file, $contents, LOCK_EX);
    }
}
