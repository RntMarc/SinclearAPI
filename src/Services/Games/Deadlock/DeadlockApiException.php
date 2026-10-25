<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games\Deadlock;

use RuntimeException;

/**
 * Fehlerantwort der Deadlock API.
 *
 * Bei 503 (Load Shedding) wird der Retry-After-Header ausgewertet und der
 * Vorgang beim naechsten Cron-Lauf wiederholt.
 */
final class DeadlockApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?int $retryAfter,
        string $message,
    ) {
        parent::__construct($message);
    }
}
