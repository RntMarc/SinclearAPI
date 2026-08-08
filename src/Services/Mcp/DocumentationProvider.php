<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Mcp;

/**
 * Resolves documentation topics and generates their content.
 *
 * Sources:
 *  - `openapi.yaml` (root of the project)
 *  - Markdown files in `docs/`
 *
 * The MCP endpoint serves exactly this content. It is read-only and never
 * interacts with the API itself.
 */
final class DocumentationProvider
{
    private string $projectDir;

    public function __construct(?string $projectDir = null)
    {
        $this->projectDir = $projectDir ?? dirname(__DIR__, 3);
    }

    /**
     * Static topics offered to MCP clients via the tool input schema.
     * Additional dynamic topics are resolved from the docs/ tree.
     *
     * @return string[]
     */
    public function coreTopics(): array
    {
        return [
            'index',
            'openapi',
            'mcp',
            'cron',
        ];
    }

    /**
     * @return array{title: string, topics: string[]}
     */
    public function index(): array
    {
        return [
            'title' => 'Sinclear Beyond API — Dokumentation',
            'topics' => $this->availableTopics(),
        ];
    }

    /**
     * @return string[]
     */
    public function availableTopics(): array
    {
        $topics = $this->coreTopics();

        foreach ($this->collectMarkdownFiles() as $relative) {
            $relative = strtolower($relative);
            $topic = str_starts_with($relative, 'readme.md')
                ? 'readme'
                : substr($relative, 0, -3);
            $topics[] = $topic;

            if (str_ends_with($relative, '/readme.md')) {
                $topics[] = rtrim(substr($relative, 0, -9), '/');
            }
        }

        sort($topics, SORT_STRING);

        return array_values(array_unique($topics));
    }

    /**
     * Resolves a topic string to its content.
     *
     * @return array{topic: string, title: string, format: string, content: string, source: string, size: int} The resolved content entry.
     */
    public function resolve(string $topic, string $format = 'markdown'): array
    {
        $format = $format === 'json' ? 'json' : 'markdown';
        $topic = strtolower(trim($topic));
        $topic = str_replace('\\', '/', $topic);

        return match (true) {
            $topic === '' => $this->entry(
                'index',
                'Dokumentation — Übersicht',
                $this->indexMarkdown(),
                'generated',
                $format,
            ),
            $topic === 'index', $topic === 'overview', $topic === 'readme' => $this->entry(
                'index',
                'Dokumentation — Übersicht',
                $this->indexMarkdown(),
                'generated',
                $format,
            ),
            $topic === 'openapi', $topic === 'openapi.yaml', $topic === 'spec' => $this->openApiEntry($format),
            $topic === 'mcp' => $this->markdownFileEntry('mcp/readme.md', $format),
            $topic === 'cron', $topic === 'cron.md' => $this->markdownFileEntry('CRON.md', $format),
            default => $this->markdownFileEntry($topic, $format),
        };
    }

    /**
     * Builds a content entry for a response.
     *
     * @return array{topic: string, title: string, format: string, content: string, source: string, size: int}
     */
    private function entry(string $topic, string $title, string $content, string $source, string $format): array
    {
        return [
            'topic' => $topic,
            'title' => $title,
            'format' => $format,
            'content' => $content,
            'source' => $source,
            'size' => strlen($content),
        ];
    }

    private function indexMarkdown(): string
    {
        $lines = [
            '# Sinclear Beyond API — Dokumentation',
            '',
            'Diese Dokumentation wird über den MCP-Server bereitgestellt.',
            'Quellen: `openapi.yaml` und die Markdown-Dateien in `docs/`.',
            '',
            '## Verfügbare Themen',
            '',
        ];

        foreach ($this->availableTopics() as $topic) {
            $lines[] = "- `{$topic}`";
        }

        $lines[] = '';
        $lines[] = '## Verwendung';
        $lines[] = '';
        $lines[] = 'Über das MCP-Tool `get_documentation` mit `topic` abrufen.';
        $lines[] = 'Für die strukturierte OpenAPI-Übersicht: `topic=openapi` (optional `format=json`).';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{topic: string, title: string, format: string, content: string, source: string, size: int}
     */
    private function openApiEntry(string $format): array
    {
        $raw = $this->readFile($this->projectDir . '/openapi.yaml');
        $parsed = (new OpenApiParser())->parse($raw);

        if ($format === 'json') {
            return $this->entry(
                'openapi',
                'OpenAPI-Spezifikation (strukturiert)',
                json_encode(
                    [
                        'info' => $parsed['info'],
                        'endpoints' => $parsed['endpoints'],
                        'source' => 'openapi.yaml',
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                'openapi.yaml (geparst)',
                $format,
            );
        }

        $endpoints = $parsed['endpoints'];
        $authCount = count(array_filter($endpoints, static fn (array $e): bool => $e['auth']));

        $lines = [
            '# ' . ($parsed['info']['title'] ?? 'Sinclear Beyond API'),
            '',
            'OpenAPI-Spezifikation — strukturierte Übersicht aller Endpunkte.',
            '',
            '| Eigenschaft | Wert |',
            '|-------------|------|',
            '| Version | ' . ($parsed['info']['version'] ?? '?') . ' |',
            '| Endpunkte | ' . count($endpoints) . ' |',
            '| Authentifiziert | ' . $authCount . ' |',
            '',
        ];

        if (!empty($parsed['info']['description'])) {
            $lines[] = $parsed['info']['description'];
            $lines[] = '';
        }

        $lines[] = '## Endpunkte';
        $lines[] = '';
        $lines[] = '| Methode | Pfad | Auth | Beschreibung |';
        $lines[] = '|---------|------|------|--------------|';

        foreach ($endpoints as $endpoint) {
            $method = strtoupper($endpoint['method']);
            $auth = $endpoint['auth'] ? 'Ja (Bearer)' : 'Nein';
            $summary = $endpoint['summary'] ?? ($endpoint['description'] ?? '');
            $summary = str_replace('|', '\\|', (string) $summary);
            $lines[] = "| `{$method}` | `{$endpoint['path']}` | {$auth} | {$summary} |";
        }

        $lines[] = '';
        $lines[] = '> Detailierte Parameter, Request-/Response-Schemas und Fehlerfälle: `openapi.yaml` (Quell-Datei).';

        return $this->entry(
            'openapi',
            'OpenAPI-Spezifikation (Übersicht)',
            implode("\n", $lines) . "\n",
            'openapi.yaml (geparst)',
            $format,
        );
    }

    /**
     * @return array{topic: string, title: string, format: string, content: string, source: string, size: int}
     */
    private function markdownFileEntry(string $topic, string $format): array
    {
        $candidates = [
            $topic,
            $topic . '.md',
            $topic . '/readme.md',
        ];

        $absolute = null;
        foreach ($candidates as $candidate) {
            $path = $this->projectDir . '/docs/' . $candidate;
            if (is_file($path)) {
                $absolute = $path;
                break;
            }
        }

        if ($absolute === null) {
            $known = implode(', ', array_slice($this->availableTopics(), 0, 40));
            $content = "Unbekanntes Thema: `{$topic}`.\n\nVerfügbare Themen (Auszug): {$known}\n";
            return $this->entry($topic, 'Unbekanntes Thema', $content, 'error', $format);
        }

        $markdown = $this->readFile($absolute);
        $title = $this->extractTitle($markdown) ?? $absolute;
        $source = substr($absolute, strlen($this->projectDir) + 1);

        if ($format === 'json') {
            return $this->entry(
                $topic,
                $title,
                json_encode(
                    [
                        'topic' => $topic,
                        'title' => $title,
                        'source' => $source,
                        'content' => $markdown,
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                $source,
                $format,
            );
        }

        return $this->entry($topic, $title, $markdown, $source, $format);
    }

    private function extractTitle(string $markdown): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $markdown, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function readFile(string $path): string
    {
        $raw = file_get_contents($path);
        return $raw === false ? '' : $raw;
    }

    /**
     * @return string[]
     */
    private function collectMarkdownFiles(): array
    {
        $docsDir = $this->projectDir . '/docs';
        if (!is_dir($docsDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($docsDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($docsDir) + 1);
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
