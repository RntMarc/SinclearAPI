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
        $apiKey = $this->extractApiKey($request);

        if ($apiKey !== null && $apiKey !== '') {
            $userId = $this->keyService->validateKey($apiKey);
            if ($userId !== null) {
                $request = $request->withAttribute(self::ATTRIBUTE, $userId);
            }
        }

        return $handler->handle($request);
    }

    private function extractApiKey(ServerRequestInterface $request): ?string
    {
        $apiKey = $request->getHeaderLine('X-Mcp-Key');
        if ($apiKey !== '') {
            return $apiKey;
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader === '') {
            return null;
        }

        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        if (str_starts_with($authHeader, 'Basic ')) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                return explode(':', $decoded, 2)[0];
            }
        }

        return null;
    }
}
