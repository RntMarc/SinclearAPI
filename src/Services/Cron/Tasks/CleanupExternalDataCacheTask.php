<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class CleanupExternalDataCacheTask implements CronTaskInterface
{
    public function getName(): string
    {
        return 'cleanup_external_data_cache';
    }

    public function getDescription(): string
    {
        return 'Entfernt abgelaufene Einträge aus dem ExternalDataCache';
    }

    public function getIntervalSeconds(): int
    {
        return 86400; // 24 Stunden
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $pdo = $container->get(PDO::class);

        $stmt = $pdo->prepare('DELETE FROM ExternalDataCache WHERE expires_at < NOW()');
        $stmt->execute();

        $count = $stmt->rowCount();
        $logger->info("External Data Cache Cleanup: $count abgelaufene Einträge gelöscht");
    }
}
