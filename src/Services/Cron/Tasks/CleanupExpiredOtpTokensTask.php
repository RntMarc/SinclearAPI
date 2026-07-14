<?php

namespace Sinclear\Api\Services\Cron\Tasks;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Cron\CronTaskInterface;

final class CleanupExpiredOtpTokensTask implements CronTaskInterface
{
    public function getName(): string
    {
        return 'cleanup_otp_tokens';
    }

    public function getDescription(): string
    {
        return 'Löscht abgelaufene und benutzte OTP-Codes';
    }

    public function getIntervalSeconds(): int
    {
        return 3600; // 1 Stunde
    }

    public function execute(ContainerInterface $container, LoggerInterface $logger): void
    {
        $pdo = $container->get(PDO::class);
        $stmt = $pdo->prepare('DELETE FROM OtpToken WHERE expiresAt < NOW()');
        $stmt->execute();
        $count = $stmt->rowCount();

        $logger->info("OTP Cleanup: $count Einträge gelöscht");
    }
}
