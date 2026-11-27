<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games\Minecraft;

use RuntimeException;

/**
 * Fehlerantwort der Mojang-API.
 *
 * Bei Rate-Limits (429), Serverfehlern (5xx) oder Netzwerkfehlern (status 0)
 * wird der Vorgang vom Aufrufer als `game_api_unavailable` behandelt.
 */
final class MinecraftApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?int $retryAfter,
        string $message,
    ) {
        parent::__construct($message);
    }
}
