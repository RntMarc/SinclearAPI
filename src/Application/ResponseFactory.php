<?php

declare(strict_types=1);

namespace Sinclear\Api\Application;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Builds standardized JSON API responses.
 */
final class ResponseFactory
{
    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    public static function json(
        array $data,
        int $status = 200,
        ?ResponseInterface $response = null
    ): ResponseInterface {
        $response ??= new Response($status);
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * @param list<mixed> $data
     * @param array<string, int|float> $meta
     */
    public static function paginated(
        array $data,
        array $meta,
        ?ResponseInterface $response = null
    ): ResponseInterface {
        return self::json(['data' => $data, 'meta' => $meta], 200, $response);
    }

    public static function noContent(?ResponseInterface $response = null): ResponseInterface
    {
        return ($response ?? new Response())->withStatus(204);
    }
}
