<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Middleware\LaMetricTokenMiddleware;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\LaMetricSummaryService;
use Sinclear\Api\Services\LaMetricTokenService;

final class LaMetricController
{
    private const string ERROR_FRAME_MISSING = 'Kein LaMetric-Token konfiguriert. Bitte in Sinclear Beyond erzeugen.';
    private const string ERROR_FRAME_INVALID = 'LaMetric-Token ungültig oder abgelaufen. Bitte in Sinclear Beyond neu erzeugen.';

    public function __construct(
        private LaMetricTokenService $tokenService,
        private LaMetricSummaryService $summaryService,
    ) {}

    /**
     * GET /lametric/token – vorhandenen Token (inkl. Klartext) abrufen.
     */
    public function getToken(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);

        return ResponseFactory::json(['token' => $this->tokenService->getToken($user->id)], 200, $response);
    }

    /**
     * PUT /lametric/token – Token erzeugen oder ersetzen (genau eines pro Nutzer).
     */
    public function saveToken(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);

        $body = $request->getParsedBody();
        $label = null;
        if (is_array($body) && isset($body['label']) && is_string($body['label'])) {
            $label = $body['label'];
        }

        return ResponseFactory::json(['token' => $this->tokenService->saveToken($user->id, $label)], 200, $response);
    }

    /**
     * DELETE /lametric/token – Token widerrufen.
     */
    public function deleteToken(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $this->tokenService->deleteToken($user->id);

        return ResponseFactory::noContent($response);
    }

    /**
     * GET /lametric/notification – Poll-Endpunkt der Notification-App.
     *
     * Antwortet immer mit HTTP 200 und LaMetric-Frames. Bei fehlendem oder
     * ungültigem Token wird ein aussagekräftiger Fehler-Frame geliefert.
     */
    public function notification(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $userId = $request->getAttribute(LaMetricTokenMiddleware::ATTRIBUTE);

        if (!is_string($userId) || $userId === '') {
            $error = $request->getAttribute(LaMetricTokenMiddleware::ERROR_ATTRIBUTE);
            $text = $error === 'invalid' ? self::ERROR_FRAME_INVALID : self::ERROR_FRAME_MISSING;
            $payload = ['frames' => [['text' => $text]]];
        } else {
            $payload = $this->summaryService->buildSummary($userId);
        }

        return ResponseFactory::json($payload, 200, $response)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }

        return $user;
    }
}
