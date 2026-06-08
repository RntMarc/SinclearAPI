<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

/**
 * Policy: owner via user_id column (snake_case tables).
 */
final class SnakeOwnerPolicy extends AbstractPolicy
{
    protected function ownerColumn(): string
    {
        return 'user_id';
    }
}
