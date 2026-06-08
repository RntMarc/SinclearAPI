<?php

declare(strict_types=1);

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Authorization policy for a resource.
 */
interface PolicyInterface
{
    public function canList(AuthenticatedUser $user): bool;

    /**
     * @param array<string, mixed> $resource
     */
    public function canView(AuthenticatedUser $user, array $resource): bool;

    public function canCreate(AuthenticatedUser $user): bool;

    /**
     * @param array<string, mixed> $resource
     */
    public function canUpdate(AuthenticatedUser $user, array $resource): bool;

    /**
     * @param array<string, mixed> $resource
     */
    public function canDelete(AuthenticatedUser $user, array $resource): bool;

    /**
     * @return array<string, mixed>
     */
    public function listFilters(AuthenticatedUser $user): array;
}
