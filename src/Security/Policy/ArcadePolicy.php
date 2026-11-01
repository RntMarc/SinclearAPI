<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class ArcadePolicy
{
    /**
     * Alle eingeloggten Nutzer duerfen Katalog, Leaderboards und Historien
     * sehen. Die JWT-Pruefung selbst erfolgt in der AuthenticationMiddleware.
     */
    public function canView(AuthenticatedUser $requester): bool
    {
        return true;
    }

    /**
     * Spielverwaltung ist Administratoren vorbehalten.
     */
    public function canManage(AuthenticatedUser $requester): bool
    {
        return $requester->isAdmin;
    }
}
