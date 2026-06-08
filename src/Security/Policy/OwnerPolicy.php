<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

/**
 * Policy: owner via userId column.
 */
final class OwnerPolicy extends AbstractPolicy
{
    protected function ownerColumn(): string
    {
        return 'userId';
    }
}
