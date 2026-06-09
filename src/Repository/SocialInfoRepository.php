<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for SocialInfo table.
 */
final class SocialInfoRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'SocialInfo';
    }

    protected function columns(): array
    {
        return ['id', 'userId', 'twitter', 'instagram', 'linkedin', 'github', 'updatedAt', 'createdAt'];
    }
}
