<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Mcp;

/**
 * Core of the MCP (Model Context Protocol) documentation server.
 *
 * Implements the JSON-RPC 2.0 basics of the MCP protocol over Streamable HTTP:
 *  - initialize / notifications/initialized (handshake)
 *  - ping
 *  - tools/list
 *  - tools/call
 *
 * The server is stateless: every request is answered with a complete
 * JSON-RPC response. It only exposes documentation, no API interaction.
 *
 * @see https://modelcontextprotocol.io/specification/2025-06-18
 */
final class McpServer
{
    public const SERVER_NAME = 'sinclear-docs-mcp';
    public const SERVER_VERSION = '1.0.0';

    /** @var string[] */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-06-18',
        '2025-03-26',
    ];

    private string $negotiatedProtocolVersion = '2025-06-18';

    public function __construct(
        private DocumentationProvider $provider,
    ) {}

    public function getProtocolVersion(): string
    {
        return $this->negotiatedProtocolVersion;
    }

    /**
     * Processes a decoded JSON-RPC message.
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>|null A JSON-RPC response, or null for notifications (HTTP 202).
     */
    public function handle(array $message): ?array
    {
        $isRequest = array_key_exists('method', $message)
            && (array_key_exists('id', $message));

        if (!$isRequest) {
            if (array_key_exists('method', $message)) {
                return null;
            }
            return $this->error(null, -32600, 'Invalid Request');
        }

        $id = $message['id'];
        $method = is_string($message['method']) ? $message['method'] : '';
        $params = $message['params'] ?? [];

        if (!is_array($params)) {
            $params = [];
        }

        return match ($method) {
            'initialize' => $this->initialize($id, $params),
            'notifications/initialized' => $this->error($id, -32601, 'Method not found'),
            'ping' => $this->success($id, new \stdClass()),
            'tools/list' => $this->toolsList($id),
            'tools/call' => $this->toolsCall($id, $params),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(mixed $id, array $params): array
    {
        $clientVersion = $params['protocolVersion'] ?? null;
        $clientVersion = is_string($clientVersion) ? $clientVersion : null;

        $this->negotiatedProtocolVersion = $this->negotiate($clientVersion);

        $result = [
            'protocolVersion' => $this->negotiatedProtocolVersion,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];

        return $this->success($id, $result);
    }

    private function negotiate(?string $clientVersion): string
    {
        if ($clientVersion !== null && in_array($clientVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            return $clientVersion;
        }
        return self::SUPPORTED_PROTOCOL_VERSIONS[0];
    }

    /**
     * @param mixed $id
     *
     * @return array<string, mixed>
     */
    private function toolsList(mixed $id): array
    {
        return $this->success($id, [
            'tools' => [
                $this->toolDefinition(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolDefinition(): array
    {
        return [
            'name' => 'get_documentation',
            'description' => 'Ruft die Dokumentation der Sinclear Beyond API ab. '
                . 'Quellen: openapi.yaml (strukturierte Endpunkt-Übersicht) und die Markdown-Dokumentation in docs/. '
                . 'Kann jede im Verzeichnis docs/ vorhandene Markdown-Datei liefern, z.B. "travel", "auth/login", '
                . '"user", "calendar", "cron", "mcp" oder "openapi". "index" liefert die Übersicht aller Themen. '
                . 'Rein lesend, keine Interaktion mit der API.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'topic' => [
                        'type' => 'string',
                        'description' => 'Dokumentationsthema. Kern-Themen: index (Übersicht), openapi (OpenAPI-Spezifikation), mcp (MCP-Server), cron (Cron-Jobs). Zusätzlich jede Markdown-Datei aus docs/, z.B. "travel", "auth/login", "user", "calendar", "recipes".',
                        'enum' => $this->provider->coreTopics(),
                    ],
                    'format' => [
                        'type' => 'string',
                        'enum' => ['markdown', 'json'],
                        'description' => 'Ausgabeformat: markdown (Standard, lesefreundlich) oder json (strukturiert).',
                        'default' => 'markdown',
                    ],
                ],
                'required' => ['topic'],
            ],
        ];
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function toolsCall(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (!is_string($name) || $name === '') {
            return $this->error($id, -32602, 'Invalid params: tool "name" is required');
        }

        if (!is_array($arguments)) {
            $arguments = [];
        }

        if ($name !== 'get_documentation') {
            return $this->success($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Unbekanntes Tool: ' . $name,
                    ],
                ],
                'isError' => true,
            ]);
        }

        $topic = $arguments['topic'] ?? null;
        if (!is_string($topic) || trim($topic) === '') {
            return $this->error($id, -32602, 'Invalid params: argument "topic" is required');
        }

        $format = $arguments['format'] ?? 'markdown';
        if (!is_string($format) || !in_array($format, ['markdown', 'json'], true)) {
            $format = 'markdown';
        }

        $entry = $this->provider->resolve($topic, $format);

        return $this->success($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $entry['content'],
                ],
            ],
            'isError' => $entry['source'] === 'error',
        ]);
    }

    /**
     * @param mixed $id
     * @param mixed $result
     *
     * @return array<string, mixed>
     */
    private function success(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param mixed $id
     *
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
