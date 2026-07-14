<?php

namespace Sinclear\Api\Services\Cron;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

interface CronTaskInterface
{
    public function getName(): string;

    public function getDescription(): string;

    public function getIntervalSeconds(): int;

    public function execute(ContainerInterface $container, LoggerInterface $logger): void;
}
