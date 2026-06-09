<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Repository for ContactInfo table.
 */
final class ContactInfoRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'ContactInfo';
    }

    protected function columns(): array
    {
        return ['id', 'userId', 'phone', 'address', 'updatedAt', 'createdAt'];
    }
}
