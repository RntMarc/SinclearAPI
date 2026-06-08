<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Domain\Pagination;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\ResourceService;

/**
 * Generic REST controller for CRUD resources.
 */
final class ResourceController
{
    public function __construct(
        private readonly ResourceService $service,
        private readonly Settings $settings
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getUser($request);
        $pagination = Pagination::fromQuery($request->getQueryParams(), $this->settings);
        $filters = $this->extractFilters($request->getQueryParams());
        $result = $this->service->list($user, $pagination, $filters);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->getUser($request);
        $data = $this->service->get($user, $args['id']);
        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getUser($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $data = $this->service->create($user, $body);
        return ResponseFactory::json(['data' => $data], 201, $response);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->getUser($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $data = $this->service->update($user, $args['id'], $body);
        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->getUser($request);
        $this->service->delete($user, $args['id']);
        return ResponseFactory::noContent($response);
    }

    private function getUser(ServerRequestInterface $request): AuthenticatedUser
    {
        return $request->getAttribute(AuthenticatedUser::class);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function extractFilters(array $params): array
    {
        $filters = [];
        foreach ($params as $key => $value) {
            if (str_starts_with($key, 'filter[') && str_ends_with($key, ']')) {
                $field = substr($key, 7, -1);
                $filters[$field] = $value;
            }
        }
        return $filters;
    }
}
