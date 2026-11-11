<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Zentraler Autorisierungspunkt fuer die Admin-JSON-API.
 *
 * Zugriff auf Admin-Funktionen steht ausschliesslich Nutzern mit
 * isAdmin=true zu. Die eigentliche Durchsetzung erfolgt bereits auf
 * Middleware-Ebene (AdminApiMiddleware); diese Policy dokumentiert die
 * Semantik und dient Controllern, die zusaetzliche, feingranulare
 * Admin-Entscheidungen treffen muessen.
 */
final readonly class AdminPolicy
{
    public function canAccess(AuthenticatedUser $user): bool
    {
        return $user->isAdmin;
    }
}
