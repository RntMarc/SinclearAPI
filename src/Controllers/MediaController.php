<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Services\MediaService;

/**
 * Lädt die volle Qualität eines zuvor als Vorschaubild ausgelieferten Bildes.
 *
 * Der Hash stammt aus dem `hash=`-Parameter des `data:`-URIs, den die
 * `MediaQualityMiddleware` im Low-Data-Modus in die JSON-Responses setzt. Der
 * Inhalt ist über den Hash adressierbar (content-addressed) und damit
 * unveränderlich — daher darf der Response dauerhaft privat gecacht werden.
 */
final readonly class MediaController
{
    public function __construct(
        private MediaService $media,
    ) {}

    /** @param array<string, string> $args */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $image = $this->media->full((string) ($args['hash'] ?? ''));

        if ($image === null) {
            return ResponseFactory::json(['error' => 'not_found'], 404, $response);
        }

        return ResponseFactory::json(['data' => ['image' => $image]], 200, $response)
            ->withHeader('Cache-Control', 'private, max-age=31536000, immutable');
    }
}
