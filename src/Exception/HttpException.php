<?php

declare(strict_types=1);

namespace Sinclear\Api\Exception;

use Exception;

/**
 * Maps domain errors to HTTP status codes.
 */
class HttpException extends Exception
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message = ''
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function unauthorized(string $code = 'unauthorized'): self
    {
        return new self(401, $code);
    }

    public static function forbidden(string $code = 'forbidden'): self
    {
        return new self(403, $code);
    }

    public static function notFound(string $code = 'not_found'): self
    {
        return new self(404, $code);
    }

    public static function badRequest(string $code = 'bad_request', string $message = ''): self
    {
        return new self(400, $code, $message);
    }

    public static function tooManyRequests(string $code = 'rate_limit_exceeded'): self
    {
        return new self(429, $code);
    }

    public static function conflict(string $code = 'conflict'): self
    {
        return new self(409, $code);
    }
}
