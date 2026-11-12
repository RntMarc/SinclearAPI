<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Repository\RefreshTokenRepository;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class CleanupExpiredRefreshTokensTask implements CronTaskInterface
{
    /**
     * Aufbewahrungsfrist: Tokens, deren Ablauf oder Widerruf älter als dieser
     * Zeitraum ist, werden gelöscht. Verwaiste Familien werden danach entfernt.
     */
    private const string RETENTION = '-30 days';

    public function getName(): string
    {
        return 'cleanup_refresh_tokens';
    }

    public function getDescription(): string
    {
        return 'Löscht abgelaufene/widerrufene Refresh-Tokens und verwaiste Familien';
    }

    public function getIntervalSeconds(): int
    {
        return 86400; // 24 Stunden
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $repo = $container->get(RefreshTokenRepository::class);
        $olderThan = new DateTimeImmutable(self::RETENTION);

        $tokens = $repo->deleteExpiredTokens($olderThan);
        $families = $repo->deleteOrphanedFamilies();

        $logger->info("RefreshToken Cleanup: $tokens Tokens, $families Familien gelöscht");
    }
}
