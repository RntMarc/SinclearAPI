<?php

declare(strict_types=1);

namespace Sinclear\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Stream;
use Sinclear\Api\Services\MediaService;

/**
 * Reduziert Medien in JSON-Responses, wenn der Client wenig Bandbreite hat.
 *
 * Aktiviert über Header `X-Media-Quality: low`, den Standard-Header
 * `Save-Data: on` oder den Query-Parameter `?media=low`. Ersetzt dann jedes
 * Base64-Bildfeld (Avatare, Rezept-/Forum-/Story-Bilder, …) durch ein winziges
 * Vorschaubild plus Inhalts-Hash; die volle Qualität lädt der Client über
 * `GET /media/{hash}` nach.
 *
 * Wirkt global: jeder zukünftige Endpunkt, der Medienfelder nach der
 * Namenskonvention (`image`, `photo`, `avatar`, …) ausliefert, profitiert
 * automatisch ohne Zusatzcode im Controller oder Repository.
 */
final class MediaQualityMiddleware implements MiddlewareInterface
{
    private const array MEDIA_KEYS = [
        'image',
        'photo',
        'avatar',
        'avatarUrl',
        'banner',
        'thumbnailUrl',
        'userImage',
        'creatorImage',
        'senderImage',
        'otherUserImage',
    ];

    private const int MAX_GENERATE_PER_REQUEST = 10;

    private int $generated = 0;

    public function __construct(
        private MediaService $media,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Nur JSON-Responses betrachten — HTML-Seiten, Redirects und DAV bleiben
        // unangetastet.
        if (!str_contains($response->getHeaderLine('Content-Type'), 'application/json')) {
            return $response;
        }

        if (!$this->isLowData($request)) {
            return $response->withAddedHeader('Vary', 'X-Media-Quality');
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return $response;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $response;
        }

        $this->generated = 0;
        $transformed = $this->transform($decoded);

        $newBody = (string) json_encode(
            $transformed,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        // Body durch einen frischen Stream ersetzen: `rewind()` + `write()`
        // auf dem alten Stream würde bei kürzerem Inhalt Restbytes des
        // ursprünglichen Bodys hinterlassen.
        $stream = new Stream(fopen('php://temp', 'r+'));
        $stream->write($newBody);
        $response = $response->withBody($stream);

        return $response->withAddedHeader('Vary', 'X-Media-Quality');
    }

    private function isLowData(ServerRequestInterface $request): bool
    {
        if (strtolower($request->getHeaderLine('X-Media-Quality')) === 'low') {
            return true;
        }
        if (strtolower($request->getHeaderLine('Save-Data')) === 'on') {
            return true;
        }
        return ($request->getQueryParams()['media'] ?? null) === 'low';
    }

    /** @param array<mixed> $node */
    private function transform(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->transform($value);
            } elseif (is_string($value)) {
                $node[$key] = $this->transformValue((string) $key, $value);
            }
        }
        return $node;
    }

    private function transformValue(string $key, string $value): ?string
    {
        if (!in_array($key, self::MEDIA_KEYS, true)) {
            return $value;
        }

        // Externe URLs und leere Felder unangetastet lassen (URLs behandelt
        // der Client über seine eigene Bildqualitäts-Logik).
        if ($value === '' || str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        // Bereits umgewandelt oder ohnehin winzig.
        if (str_contains($value, ';preview=1') || strlen($value) < 128) {
            return $value;
        }

        // Budget erschöpft: Feld leeren, statt 270 KB zu senden.
        if ($this->generated >= self::MAX_GENERATE_PER_REQUEST) {
            return null;
        }

        $result = $this->media->thumbnail($value, persistFull: true);
        if ($result === null) {
            $this->logger->debug('MediaQualityMiddleware: unbrauchbares Bild geleert', [
                'field' => $key,
            ]);
            return null;
        }

        if ($result['generated']) {
            $this->generated++;
        }

        return sprintf(
            'data:%s;preview=1;hash=%s;base64,%s',
            $result['mime'],
            $result['hash'],
            $result['thumbnail'],
        );
    }
}
