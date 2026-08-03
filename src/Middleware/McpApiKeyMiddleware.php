<?php

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinclear\Api\Services\McpApiKeyService;

final readonly class McpApiKeyMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'mcp_api_key_user_id';

    public function __construct(
        private McpApiKeyService $keyService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $apiKey = $request->getHeaderLine('X-Mcp-Key');

        if ($apiKey !== '') {
            $userId = $this->keyService->validateKey($apiKey);
            if ($userId !== null) {
                $request = $request->withAttribute(self::ATTRIBUTE, $userId);
            }
        }

        return $handler->handle($request);
    }
}
