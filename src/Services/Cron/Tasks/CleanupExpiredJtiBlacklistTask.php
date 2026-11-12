<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Repository\JtiBlacklistRepository;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class CleanupExpiredJtiBlacklistTask implements CronTaskInterface
{
    public function getName(): string
    {
        return 'cleanup_jti_blacklist';
    }

    public function getDescription(): string
    {
        return 'Löscht abgelaufene JTI-Blacklist-Einträge';
    }

    public function getIntervalSeconds(): int
    {
        return 3600; // 1 Stunde
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $repo = $container->get(JtiBlacklistRepository::class);
        $count = $repo->deleteExpired();

        $logger->info("JTI Cleanup: $count Einträge gelöscht");
    }
}
