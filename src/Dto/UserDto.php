<?php

declare(strict_types=1);

namespace Sinclear\Api\Dto;

/**
 * Safe user representation without sensitive fields.
 */
final class UserDto
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function fromRow(array $row): array
    {
        unset($row['passwordHash']);
        return $row;
    }
}
