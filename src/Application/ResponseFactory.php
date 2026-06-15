<?php

namespace Sinclear\Api\Application;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

final class ResponseFactory
{
    public static function json(mixed $data, int $status = 200, ?ResponseInterface $response = null): ResponseInterface
    {
        $response ??= new Response();
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public static function noContent(?ResponseInterface $response = null): ResponseInterface
    {
        $response ??= new Response();
        return $response->withStatus(204);
    }

    public static function paginated(array $data, array $meta, ?ResponseInterface $response = null): ResponseInterface
    {
        return self::json(
            ['data' => $data, 'meta' => $meta],
            200,
            $response
        );
    }
}
