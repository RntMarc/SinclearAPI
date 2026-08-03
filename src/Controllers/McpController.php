<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Middleware\McpApiKeyMiddleware;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\McpApiKeyService;
use Sinclear\Api\Services\Mcp\McpServer;

/**
 * MCP endpoint (Streamable HTTP transport).
 *
 * Exposes the API documentation as a single HTTP/JSON endpoint for MCP
 * clients (e.g. OpenCode). Supports optional API key authentication for
 * recipe draft creation via the create_recipe_draft tool.
 *
 * URL: {base}/mcp
 *
 * @see https://modelcontextprotocol.io/specification/2025-06-18/basic/transports#streamable-http
 */
final class McpController
{
    public function __construct(
        private McpServer $server,
        private McpApiKeyService $keyService,
        private LoggerInterface $logger,
    ) {}

    public function get(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        return $response
            ->withStatus(405)
            ->withHeader('Allow', 'POST')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Protocol-Version', $this->server->getProtocolVersion());
    }

    public function post(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body = (string) $request->getBody();

        if (trim($body) === '') {
            return $this->jsonErrorResponse($response, -32700, 'Parse error', null);
        }

        try {
            $message = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('MCP: invalid JSON body', ['error' => $e->getMessage()]);
            return $this->jsonErrorResponse($response, -32700, 'Parse error', null);
        }

        if (!is_array($message)) {
            return $this->jsonErrorResponse($response, -32600, 'Invalid Request', null);
        }

        $authenticatedUserId = $request->getAttribute(McpApiKeyMiddleware::ATTRIBUTE);

        $result = $this->server->handle($message, $authenticatedUserId);

        $protocolVersion = $this->server->getProtocolVersion();

        if ($result === null) {
            return $response
                ->withStatus(202)
                ->withHeader('Mcp-Protocol-Version', $protocolVersion);
        }

        $payload = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $accept = $request->getHeaderLine('Accept');
        $wantsSse = str_contains($accept, 'text/event-stream')
            && !str_contains($accept, 'application/json');

        if ($wantsSse) {
            $response->getBody()->write("event: message\ndata: {$payload}\n\n");
            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/event-stream')
                ->withHeader('Cache-Control', 'no-cache')
                ->withHeader('Connection', 'close')
                ->withHeader('Mcp-Protocol-Version', $protocolVersion)
                ->withHeader('X-Accel-Buffering', 'no');
        }

        $response->getBody()->write($payload);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Protocol-Version', $protocolVersion);
    }

    public function createKey(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (empty($body['label']) || !is_string($body['label'])) {
            return ResponseFactory::json(['error' => 'label_required'], 400, $response);
        }

        $label = trim($body['label']);
        if ($label === '') {
            return ResponseFactory::json(['error' => 'label_required'], 400, $response);
        }

        try {
            $keyData = $this->keyService->createKey($user->id, $label);
            return ResponseFactory::json(['data' => $keyData], 201, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'key_limit_reached' ? 409 : 400;
            return ResponseFactory::json(['error' => $e->getMessage()], $code, $response);
        }
    }

    public function listKeys(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->keyService->listKeys($user->id, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function deleteKey(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $deleted = $this->keyService->revokeKey($id, $user->id);
        if (!$deleted) {
            return ResponseFactory::json(['error' => 'key_not_found'], 404, $response);
        }

        return ResponseFactory::noContent($response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }

    private function jsonErrorResponse(
        ResponseInterface $response,
        int $code,
        string $message,
        mixed $id,
    ): ResponseInterface {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $status = $code === -32700 || $code === -32600 ? 400 : 200;

        $response->getBody()->write($payload);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Protocol-Version', $this->server->getProtocolVersion());
    }
}
