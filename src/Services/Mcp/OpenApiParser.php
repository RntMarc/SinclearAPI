<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Mcp;

/**
 * Lightweight parser for the project's openapi.yaml.
 *
 * Extracts only the parts needed for the MCP documentation endpoint:
 * info (title, version, description) and the operations
 * (method, path, summary, description, tags, security).
 *
 * The file is generated consistently, so a targeted line-based parser is
 * sufficient and avoids a heavy YAML dependency.
 */
final class OpenApiParser
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];

    /** @var string[] */
    private array $lines = [];

    public function parse(string $yaml): array
    {
        $this->lines = preg_split('/\r\n|\n|\r/', $yaml) ?: [];

        $info = $this->parseInfo();
        $endpoints = $this->parsePaths();

        return [
            'info' => $info,
            'endpoints' => $endpoints,
        ];
    }

    private function parseInfo(): array
    {
        $info = [
            'title' => null,
            'version' => null,
            'description' => null,
        ];

        $inInfo = false;
        $capturingDescription = false;
        $descriptionLines = [];

        foreach ($this->lines as $line) {
            if ($line === 'info:') {
                $inInfo = true;
                continue;
            }

            if (!$inInfo) {
                continue;
            }

            if (str_starts_with($line, 'paths:')) {
                break;
            }

            if (preg_match('/^  title:\s*(.+)$/', $line, $m)) {
                $info['title'] = trim($m[1]);
                continue;
            }

            if (preg_match('/^  version:\s*(.+)$/', $line, $m)) {
                $info['version'] = trim($m[1]);
                continue;
            }

            if (preg_match('/^  description:\s*>?\s*$/', $line)) {
                $capturingDescription = true;
                continue;
            }

            if (preg_match('/^  description:\s*(.+)$/', $line, $m)) {
                $info['description'] = trim($m[1]);
                continue;
            }

            if ($capturingDescription && preg_match('/^    (.+)$/', $line, $m)) {
                $descriptionLines[] = trim($m[1]);
                continue;
            }

            if ($capturingDescription && preg_match('/^  [a-zA-Z_]+:/', $line)) {
                $capturingDescription = false;
            }
        }

        if ($descriptionLines !== []) {
            $info['description'] = implode(' ', $descriptionLines);
        }

        return $info;
    }

    private function parsePaths(): array
    {
        $endpoints = [];
        $currentPath = null;
        $currentMethod = null;
        $current = null;
        $capturingDescription = false;
        $descriptionLines = [];
        $total = count($this->lines);

        $flush = function () use (&$endpoints, &$currentPath, &$currentMethod, &$current, &$capturingDescription, &$descriptionLines): void {
            if ($currentPath !== null && $currentMethod !== null && $current !== null) {
                if ($descriptionLines !== []) {
                    $current['description'] = implode(' ', $descriptionLines);
                }
                $current['path'] = $currentPath;
                $current['method'] = $currentMethod;
                $endpoints[] = $current;
            }
            $currentMethod = null;
            $current = null;
            $capturingDescription = false;
            $descriptionLines = [];
        };

        $inPaths = false;

        for ($i = 0; $i < $total; $i++) {
            $line = $this->lines[$i];

            if (!$inPaths) {
                if ($line === 'paths:') {
                    $inPaths = true;
                }
                continue;
            }

            if (preg_match('/^  (\/(?:.*)):$/', $line, $m)) {
                $flush();
                $currentPath = $m[1];
                continue;
            }

            if ($currentPath === null) {
                continue;
            }

            if (preg_match('/^    (' . implode('|', self::METHODS) . '):$/', $line, $m)) {
                $flush();
                $currentMethod = $m[1];
                $current = ['summary' => null, 'description' => null, 'tags' => [], 'auth' => false];
                continue;
            }

            if ($current === null || $currentMethod === null) {
                continue;
            }

            if (preg_match('/^      summary:\s*(.*)$/', $line, $m)) {
                $current['summary'] = $m[1] !== '' ? $m[1] : null;
                continue;
            }

            if (preg_match('/^      description:\s*>?\s*$/', $line)) {
                $capturingDescription = true;
                $descriptionLines = [];
                continue;
            }

            if (preg_match('/^      description:\s*(.+)$/', $line, $m)) {
                $current['description'] = trim($m[1]);
                continue;
            }

            if ($capturingDescription) {
                if (preg_match('/^        (.+)$/', $line, $m)) {
                    $descriptionLines[] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^      [a-zA-Z_]+:/', $line)) {
                    $capturingDescription = false;
                }
            }

            if (preg_match('/^      tags:\s*\[(.+)\]$/', $line, $m)) {
                $current['tags'] = array_map(trim(...), explode(',', $m[1]));
                continue;
            }

            if (preg_match('/^      tags:$/', $line)) {
                $tags = [];
                for ($j = $i + 1; $j < $total; $j++) {
                    $next = $this->lines[$j];
                    if (preg_match('/^        -\s*(.+)$/', $next, $m)) {
                        $tags[] = trim($m[1]);
                        continue;
                    }
                    break;
                }
                $current['tags'] = $tags;
                continue;
            }

            if ($line === '      security:') {
                $current['auth'] = true;
                continue;
            }
        }

        $flush();

        usort($endpoints, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $endpoints;
    }
}
