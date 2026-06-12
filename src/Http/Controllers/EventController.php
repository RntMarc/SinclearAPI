<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\EventService;

final class EventController
{
    public function __construct(
        private readonly EventService $eventService
    ) {
    }

    private function getUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return $user;
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getUser($request);
        $body = (array) $request->getParsedBody();
        $event = $this->eventService->create($user, $body);
        return ResponseFactory::json(['data' => $event], 201, $response);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->getUser($request);
        $id = (string) ($args['id'] ?? '');
        if ($id === '') {
            throw HttpException::badRequest('missing_id', 'Event ID is required');
        }
        $body = (array) $request->getParsedBody();
        $event = $this->eventService->update($user, $id, $body);
        return ResponseFactory::json(['data' => $event], 200, $response);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->getUser($request);
        $id = (string) ($args['id'] ?? '');
        if ($id === '') {
            throw HttpException::badRequest('missing_id', 'Event ID is required');
        }
        $this->eventService->delete($user, $id);
        return ResponseFactory::json(['ok' => true], 200, $response);
    }

    public function listPermissions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->getUser($request);
        $id = (string) ($args['id'] ?? '');
        if ($id === '') {
            throw HttpException::badRequest('missing_id', 'Event ID is required');
        }
        $permissions = $this->eventService->getPermissions($user, $id);
        return ResponseFactory::json($permissions, 200, $response);
    }

    public function setPermissions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->getUser($request);
        $id = (string) ($args['id'] ?? '');
        if ($id === '') {
            throw HttpException::badRequest('missing_id', 'Event ID is required');
        }
        $body = (array) $request->getParsedBody();
        $permissions = isset($body['permissions']) && is_array($body['permissions'])
            ? $body['permissions']
            : [];
        $this->eventService->setPermissions($user, $id, $permissions);
        return ResponseFactory::json(['ok' => true], 200, $response);
    }
}
