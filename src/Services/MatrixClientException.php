<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

/**
 * Typisierter Fehler eines Matrix-API-Aufrufs.
 *
 * Unterscheidet zwischen transienten (wiederholbaren) und permanenten Fehlern:
 * - transient: Verbindungsfehler, Timeout, HTTP 5xx, 429, M_UNKNOWN, M_LIMIT_EXCEEDED
 * - permanent: HTTP 4xx (außer M_USER_IN_USE, das beim register als Erfolg gilt),
 *   z.B. ungültiger displayName oder ungültiges/abgelaufenes as_token (401/403).
 */
final class MatrixClientException extends \RuntimeException
{
    private function __construct(
        private readonly bool $permanent,
        string $message,
        private readonly string $errcode,
        private readonly int $statusCode,
    ) {
        parent::__construct($message);
    }

    public static function transient(string $message, string $errcode = '', int $statusCode = 0): self
    {
        return new self(false, $message, $errcode, $statusCode);
    }

    public static function permanent(string $message, string $errcode = '', int $statusCode = 0): self
    {
        return new self(true, $message, $errcode, $statusCode);
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }

    public function errcode(): string
    {
        return $this->errcode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
