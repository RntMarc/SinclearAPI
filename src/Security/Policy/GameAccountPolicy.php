<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class GameAccountPolicy
{
    /**
     * Verknuepfte Accounts sind oeffentlich; der Endpoint selbst erfordert
     * dennoch ein gueltiges JWT.
     */
    public function canView(AuthenticatedUser $requester, string $targetUserId): bool
    {
        return true;
    }

    /**
     * Nur der Nutzer selbst (oder ein Admin) darf eigene Verknuepfungen
     * anlegen, ersetzen oder loesen.
     */
    public function canManage(AuthenticatedUser $requester, string $targetUserId): bool
    {
        return $requester->id === $targetUserId || $requester->isAdmin;
    }
}
