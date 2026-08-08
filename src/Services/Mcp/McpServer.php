<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Mcp;

use Sinclear\Api\Services\RecipeService;

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
 * JSON-RPC response. It exposes documentation (read-only) and optionally
 * recipe draft creation (authenticated via API key).
 *
 * @see https://modelcontextprotocol.io/specification/2025-06-18
 */
final class McpServer
{
    public const SERVER_NAME = 'sinclear-docs-mcp';
    public const SERVER_VERSION = '1.1.0';

    /** @var string[] */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-06-18',
        '2025-03-26',
    ];

    private string $negotiatedProtocolVersion = '2025-06-18';

    public function __construct(
        private DocumentationProvider $provider,
        private RecipeService $recipeService,
    ) {}

    public function getProtocolVersion(): string
    {
        return $this->negotiatedProtocolVersion;
    }

    /**
     * Processes a decoded JSON-RPC message.
     *
     * @param array<string, mixed> $message
     * @param string|null $authenticatedUserId User ID from API key auth (null if unauthenticated)
     *
     * @return array<string, mixed>|null A JSON-RPC response, or null for notifications (HTTP 202).
     */
    public function handle(array $message, ?string $authenticatedUserId = null): ?array
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
            'tools/list' => $this->toolsList($id, $authenticatedUserId),
            'tools/call' => $this->toolsCall($id, $params, $authenticatedUserId),
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
    private function toolsList(mixed $id, ?string $authenticatedUserId): array
    {
        $tools = [
            $this->toolDefinitionDocumentation(),
        ];

        if ($authenticatedUserId !== null) {
            $tools[] = $this->toolDefinitionCreateRecipeDraft();
        }

        return $this->success($id, [
            'tools' => $tools,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolDefinitionDocumentation(): array
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
                        'description' => 'Dokumentationsthema. Kern-Themen: index (Übersicht aller Themen), openapi (OpenAPI-Spezifikation), mcp (MCP-Server), cron (Cron-Jobs). Zusätzlich wird JEDE Markdown-Datei in docs/ als Thema unterstützt, z.B. "travel", "auth/login", "user", "calendar", "recipes", "notifications/list", "app/updates". "index" liefert die vollständige, dynamische Übersicht aller verfügbaren Themen.',
                        'enum' => $this->provider->availableTopics(),
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
     * @return array<string, mixed>
     */
    private function toolDefinitionCreateRecipeDraft(): array
    {
        return [
            'name' => 'create_recipe_draft',
            'description' => 'Erstellt einen neuen Rezept-Entwurf über die Sinclear Beyond API. '
                . 'Das Rezept wird als Entwurf gespeichert und ist nur für den Ersteller sichtbar. '
                . 'Der Ersteller kann den Entwurf später über die API prüfen und veröffentlichen. '
                . 'Erfordert eine API-Key-Authentifizierung über den X-Mcp-Key Header.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'Name des Rezepts (z.B. "Omas Apfelkuchen")',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Kategorie des Rezepts',
                        'enum' => [
                            'vorspeisen', 'hauptgerichte', 'desserts', 'salate',
                            'suppen', 'backen', 'fruehstueck', 'getraenke', 'sonstiges',
                        ],
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Kurze Beschreibung des Rezepts',
                    ],
                    'servings' => [
                        'type' => 'integer',
                        'description' => 'Anzahl der Portionen (1-127, Standard: 4)',
                        'minimum' => 1,
                        'maximum' => 127,
                    ],
                    'dietaryTags' => [
                        'type' => 'string',
                        'description' => 'Ernährungstaggs, komma-getrennt (z.B. "vegetarisch, glutenfrei")',
                    ],
                    'ingredients' => [
                        'type' => 'array',
                        'description' => 'Liste der Zutaten',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'amount' => [
                                    'type' => 'number',
                                    'description' => 'Menge',
                                ],
                                'unit' => [
                                    'type' => 'string',
                                    'description' => 'Maßeinheit',
                                    'enum' => [
                                        'g', 'kg', 'ml', 'l', 'tl', 'el', 'prise', 'stk',
                                        'bund', 'zehe', 'scheibe', 'tasse', 'dose', 'packung', 'tropfen',
                                    ],
                                ],
                                'name' => [
                                    'type' => 'string',
                                    'description' => 'Name der Zutat',
                                ],
                            ],
                            'required' => ['unit', 'name'],
                        ],
                    ],
                    'steps' => [
                        'type' => 'array',
                        'description' => 'Liste der Zubereitungsschritte',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'category' => [
                                    'type' => 'string',
                                    'description' => 'Kategorie des Schritts',
                                    'enum' => [
                                        'vorbereitung', 'hauptgang', 'beilage', 'garnierung', 'sonstiges',
                                    ],
                                ],
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'Optionale Überschrift für den Schritt',
                                ],
                                'description' => [
                                    'type' => 'string',
                                    'description' => 'Beschreibung des Schritts',
                                ],
                            ],
                            'required' => ['description'],
                        ],
                    ],
                ],
                'required' => ['title', 'category'],
            ],
        ];
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function toolsCall(mixed $id, array $params, ?string $authenticatedUserId): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (!is_string($name) || $name === '') {
            return $this->error($id, -32602, 'Invalid params: tool "name" is required');
        }

        if (!is_array($arguments)) {
            $arguments = [];
        }

        return match ($name) {
            'get_documentation' => $this->callGetDocumentation($id, $arguments),
            'create_recipe_draft' => $this->callCreateRecipeDraft($id, $arguments, $authenticatedUserId),
            default => $this->success($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Unbekanntes Tool: ' . $name,
                    ],
                ],
                'isError' => true,
            ]),
        };
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callGetDocumentation(mixed $id, array $arguments): array
    {
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
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callCreateRecipeDraft(mixed $id, array $arguments, ?string $authenticatedUserId): array
    {
        if ($authenticatedUserId === null) {
            return $this->success($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Authentifizierung erforderlich. Bitte senden Sie einen gültigen API-Key im Authorization Header (Bearer <key>) oder X-Mcp-Key Header.',
                    ],
                ],
                'isError' => true,
            ]);
        }

        $title = $arguments['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            return $this->error($id, -32602, 'Invalid params: argument "title" is required');
        }

        $category = $arguments['category'] ?? null;
        if (!is_string($category) || trim($category) === '') {
            return $this->error($id, -32602, 'Invalid params: argument "category" is required');
        }

        $validCategories = ['vorspeisen', 'hauptgerichte', 'desserts', 'salate', 'suppen', 'backen', 'fruehstueck', 'getraenke', 'sonstiges'];
        if (!in_array($category, $validCategories, true)) {
            return $this->error($id, -32602, 'Invalid params: argument "category" must be one of: ' . implode(', ', $validCategories));
        }

        $data = [
            'title' => trim($title),
            'category' => $category,
            'description' => isset($arguments['description']) && is_string($arguments['description'])
                ? trim($arguments['description'])
                : null,
            'servings' => isset($arguments['servings']) && is_int($arguments['servings'])
                ? $arguments['servings']
                : 4,
            'dietaryTags' => isset($arguments['dietaryTags']) && is_string($arguments['dietaryTags'])
                ? trim($arguments['dietaryTags'])
                : null,
            'isDraft' => 1,
        ];

        if (!empty($arguments['ingredients']) && is_array($arguments['ingredients'])) {
            $data['ingredients'] = $arguments['ingredients'];
        }

        if (!empty($arguments['steps']) && is_array($arguments['steps'])) {
            $data['steps'] = $arguments['steps'];
        }

        try {
            $recipe = $this->recipeService->createRecipe($data, $authenticatedUserId);

            return $this->success($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'success' => true,
                            'recipeId' => $recipe['id'],
                            'message' => 'Rezept-Entwurf erfolgreich erstellt. Das Rezept ist ein Entwurf und kann über die API eingesehen, bearbeitet oder veröffentlicht werden.',
                            'recipe' => [
                                'id' => $recipe['id'],
                                'title' => $recipe['title'],
                                'category' => $recipe['category'],
                                'isDraft' => $recipe['isDraft'],
                                'createdAt' => $recipe['createdAt'],
                            ],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'isError' => false,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->success($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Validierungsfehler: ' . $e->getMessage(),
                    ],
                ],
                'isError' => true,
            ]);
        } catch (\RuntimeException $e) {
            return $this->success($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Fehler beim Erstellen des Rezepts: ' . $e->getMessage(),
                    ],
                ],
                'isError' => true,
            ]);
        }
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
