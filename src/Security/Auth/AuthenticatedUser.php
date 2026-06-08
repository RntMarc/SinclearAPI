<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Auth;

/**
 * Represents the currently authenticated user from JWT claims.
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public string $id,
        public string $email,
        public bool $isAdmin,
        public string $jti
    ) {
    }
}
