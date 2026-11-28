<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Autorisierung fuer den Minecraft-Lese-Endpoint (`GET /minecraft/sources`).
 *
 * Die JWT-Pruefung erfolgt bereits in der AuthenticationMiddleware; diese Policy
 * ist die Defense-in-Depth-Schicht im Controller. Alle eingeloggten Nutzer
 * duerfen die aggregierten Server-Statistiken sehen.
 */
final readonly class MinecraftStatsPolicy
{
    public function canView(AuthenticatedUser $requester): bool
    {
        return true;
    }
}
