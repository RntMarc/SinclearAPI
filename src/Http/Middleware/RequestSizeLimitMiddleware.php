<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;

/**
 * Rejects requests exceeding a maximum body size.
 */
final class RequestSizeLimitMiddleware implements MiddlewareInterface
{
    private const int MAX_BYTES = 5_242_880; // 5 MB for base64 images

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentLength = (int) $request->getHeaderLine('Content-Length');
        if ($contentLength > self::MAX_BYTES) {
            throw HttpException::badRequest('payload_too_large');
        }

        return $handler->handle($request);
    }
}
