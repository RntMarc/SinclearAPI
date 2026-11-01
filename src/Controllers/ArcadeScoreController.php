<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Middleware\ArcadeSessionMiddleware;
use Sinclear\Api\Services\ArcadeScoreService;

final readonly class ArcadeScoreController
{
    public function __construct(
        private ArcadeScoreService $scoreService,
    ) {}

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $request->getAttribute(ArcadeSessionMiddleware::ATTRIBUTE);
        if (!is_array($session)) {
            throw new \RuntimeException('Arcade session required');
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        try {
            $result = $this->scoreService->submit(
                (string) $session['userId'],
                (string) $session['gameId'],
                $body['score'] ?? null,
                $body['meta'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }

        return ResponseFactory::json($result, 201, $response);
    }

    private function errorResponse(string $error, ResponseInterface $response): ResponseInterface
    {
        $status = match ($error) {
            'unknown_game' => 404,
            'invalid_score', 'invalid_meta' => 400,
            default => 500,
        };

        return ResponseFactory::json(['error' => $error], $status, $response);
    }
}
