<?php

declare(strict_types=1);

namespace Sinclear\Api\Dto;

/**
 * Strips sensitive fields from database rows before API output.
 */
final class SensitiveFields
{
    private const array GLOBAL_HIDDEN = [
        'passwordHash',
        'code',
        'publicKey',
        'challenge',
        'token_hash',
        'tokenHash',
        'p256dh',
        'auth',
    ];

    /**
     * @param array<string, mixed> $row
     * @param list<string> $additional
     * @return array<string, mixed>
     */
    public static function strip(array $row, array $additional = []): array
    {
        foreach (array_merge(self::GLOBAL_HIDDEN, $additional) as $field) {
            unset($row[$field]);
        }
        return $row;
    }
}
