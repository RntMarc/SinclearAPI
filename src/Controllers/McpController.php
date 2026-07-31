<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Services\Mcp\McpServer;

/**
 * MCP documentation endpoint (Streamable HTTP transport).
 *
 * Exposes the API documentation as a single HTTP/JSON endpoint for MCP
 * clients (e.g. OpenCode). No authentication required: documentation is
 * public by design. The endpoint is read-only and never interacts with the
 * API.
 *
 * URL: {base}/mcp
 *
 * @see https://modelcontextprotocol.io/specification/2025-06-18/basic/transports#streamable-http
 */
final class McpController
{
    public function __construct(
        private McpServer $server,
        private LoggerInterface $logger,
    ) {}

    public function get(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        // This server never pushes messages, so no persistent GET/SSE stream
        // is offered. Returning 405 is explicitly allowed by the MCP spec.
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

        $result = $this->server->handle($message);

        $protocolVersion = $this->server->getProtocolVersion();

        // Notifications (no id) are acknowledged with 202 and no body.
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
