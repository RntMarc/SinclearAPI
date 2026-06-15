<?php

namespace Sinclear\Api\Security\Auth;

final readonly class AuthenticatedUser
{
    public function __construct(
        public string $id,
        public string $email,
        public bool $isAdmin,
        public string $jti,
    ) {}
}
