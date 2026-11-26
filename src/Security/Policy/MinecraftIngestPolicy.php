<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

/**
 * Autorisierung fuer den Minecraft-Server-Ingest.
 *
 * Der Ingest arbeitet nicht mit einem Beyond-Nutzer, sondern mit einem Shared
 * Secret, das bereits in der {@see \Sinclear\Api\Middleware\MinecraftIngestMiddleware}
 * geprueft wird. Diese Policy bildet lediglich die Defense-in-Depth-Schicht im
 * Controller ab: Der Ingest darf nur laufen, wenn der Guard den Request
 * erfolgreich authentifiziert hat.
 */
final readonly class MinecraftIngestPolicy
{
    public function canIngest(bool $authenticated): bool
    {
        return $authenticated;
    }
}
