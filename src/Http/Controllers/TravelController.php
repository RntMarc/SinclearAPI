<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\TravelService;

final class TravelController
{
    public function __construct(
        private readonly TravelService $travelService
    ) {
    }

    public function myTrips(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return ResponseFactory::json(
            ['data' => $this->travelService->myTrips($user)],
            200,
            $response
        );
    }

    public function myEvents(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return ResponseFactory::json(
            ['data' => $this->travelService->myEvents($user)],
            200,
            $response
        );
    }

    public function standaloneEvents(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return ResponseFactory::json(
            ['data' => $this->travelService->standaloneEvents($user)],
            200,
            $response
        );
    }

    public function tripDetails(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        $tripId = (string) ($args['id'] ?? '');
        if ($tripId === '') {
            throw HttpException::badRequest('missing_id', 'Trip ID is required');
        }

        $trip = $this->travelService->tripDetails($user, $tripId);

        if ($trip === null) {
            throw HttpException::notFound();
        }

        if (isset($trip['error'])) {
            throw HttpException::forbidden();
        }

        return ResponseFactory::json(['data' => $trip], 200, $response);
    }

    public function tripParticipants(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        $tripId = (string) ($args['id'] ?? '');
        $participants = $this->travelService->tripParticipants($user, $tripId);

        return ResponseFactory::json(['data' => $participants], 200, $response);
    }

    public function addParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        if (!$user->isAdmin) {
            throw HttpException::forbidden();
        }

        $tripId = (string) ($args['id'] ?? '');
        $body = (array) $request->getParsedBody();
        $userId = (string) ($body['userId'] ?? '');
        $userId = (string) ($args['userId'] ?? $userId);

        if ($tripId === '' || $userId === '') {
            throw HttpException::badRequest('missing_fields', 'Trip ID and userId are required');
        }

        $this->travelService->addParticipant($user, $tripId, $userId);

        return ResponseFactory::json(['ok' => true], 201, $response);
    }

    public function updateParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        if (!$user->isAdmin) {
            throw HttpException::forbidden();
        }

        $tripId = (string) ($args['id'] ?? '');
        $userId = (string) ($args['userId'] ?? '');
        $body = (array) $request->getParsedBody();
        $accommodationId = isset($body['accommodationId']) ? (string) $body['accommodationId'] : null;

        $this->travelService->updateParticipantAccommodation($tripId, $userId, $accommodationId);

        return ResponseFactory::json(['ok' => true], 200, $response);
    }

    public function removeParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        if (!$user->isAdmin) {
            throw HttpException::forbidden();
        }

        $tripId = (string) ($args['id'] ?? '');
        $userId = (string) ($args['userId'] ?? '');

        $this->travelService->removeParticipant($tripId, $userId);

        return ResponseFactory::json(['ok' => true], 200, $response);
    }

    public function createEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        if (!$user->isAdmin) {
            throw HttpException::forbidden();
        }

        $body = (array) $request->getParsedBody();
        $participantIds = isset($body['participantIds']) && is_array($body['participantIds'])
            ? array_map('strval', $body['participantIds'])
            : [];

        $result = $this->travelService->createEvent($body, $participantIds);

        return ResponseFactory::json(['data' => $result], 201, $response);
    }

    public function getEventDetails(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        $eventId = (string) ($args['id'] ?? '');
        $event = $this->travelService->getEventWithParticipants($eventId);

        if ($event === null) {
            throw HttpException::notFound();
        }

        return ResponseFactory::json(['data' => $event], 200, $response);
    }

    public function updateEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        if (!$user->isAdmin) {
            throw HttpException::forbidden();
        }

        $eventId = (string) ($args['id'] ?? '');
        $body = (array) $request->getParsedBody();
        $participantIds = isset($body['participantIds']) && is_array($body['participantIds'])
            ? array_map('strval', $body['participantIds'])
            : null;

        $this->travelService->updateEvent($eventId, $body, $participantIds);

        return ResponseFactory::json(['ok' => true], 200, $response);
    }

    public function deleteEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        if (!$user->isAdmin) {
            throw HttpException::forbidden();
        }

        $eventId = (string) ($args['id'] ?? '');
        $this->travelService->deleteEvent($eventId);

        return ResponseFactory::json(['ok' => true], 200, $response);
    }
}
