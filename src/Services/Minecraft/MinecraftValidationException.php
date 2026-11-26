<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Minecraft;

use RuntimeException;

/**
 * Validierungsfehler eines Minecraft-Ingest-Snapshots.
 *
 * `errorCode` ist entweder `unsupported_schema_version` oder `validation_failed`;
 * `details` enthaelt bei `validation_failed` bis zu 20 Eintraege der Form
 * `{field, error}`.
 */
final class MinecraftValidationException extends RuntimeException
{
    /**
     * @param list<array{field: string, error: string}> $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($errorCode);
    }
}
