<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Application\ResponseFactory;

final readonly class RequireHttpsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $serverParams = $request->getServerParams();
        $https = $serverParams['HTTPS'] ?? '';
        $forwardedProto = $serverParams['HTTP_X_FORWARDED_PROTO'] ?? '';

        $isSecure = $https === 'on'
            || $https === '1'
            || strtolower($forwardedProto) === 'https';

        if (!$isSecure) {
            return ResponseFactory::json(
                ['error' => 'ssl_required'],
                403,
            );
        }

        return $handler->handle($request);
    }
}
