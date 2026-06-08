<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\CalendarService;

/**
 * Combined calendar endpoint.
 */
final class CalendarController
{
    public function __construct(
        private readonly CalendarService $calendarService
    ) {
    }

    public function combined(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return ResponseFactory::json(
            ['data' => $this->calendarService->combined($user)],
            200,
            $response
        );
    }
}
