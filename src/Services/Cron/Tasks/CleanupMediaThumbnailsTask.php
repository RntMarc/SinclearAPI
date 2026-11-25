<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;
use Sinclear\Api\Services\MediaService;

final class CleanupMediaThumbnailsTask implements CronTaskInterface
{
    public function getName(): string
    {
        return 'cleanup_media_thumbnails';
    }

    public function getDescription(): string
    {
        return 'Entfernt ungenutzte Medien-Thumbnails und -Originale aus dem Disk-Cache';
    }

    public function getIntervalSeconds(): int
    {
        return 86400; // 24 Stunden
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $media = $container->get(MediaService::class);

        // 30 Tage nicht angefasst = löschen (mtime, via touch aktualisiert).
        $count = $media->cleanup(30 * 86400);

        $logger->info("Media thumbnail cleanup: $count Dateien gelöscht");
    }
}
