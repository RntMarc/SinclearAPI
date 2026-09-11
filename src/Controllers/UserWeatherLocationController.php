<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\UserWeatherLocationService;

final readonly class UserWeatherLocationController
{
    public function __construct(
        private UserWeatherLocationService $service,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $locations = $this->service->list($user->id);

        return ResponseFactory::json(['data' => $locations], 200, $response);
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body) || empty($body['name']) || (!isset($body['lat']) && empty($body['slug']))) {
            return ResponseFactory::json(
                ['error' => 'invalid_request', 'message' => 'Provide name and (slug or lat+lon)'],
                400,
                $response,
            );
        }

        $name = trim((string) $body['name']);
        $slug = !empty($body['slug']) ? trim((string) $body['slug']) : null;
        $lat = isset($body['lat']) ? (float) $body['lat'] : 0.0;
        $lon = isset($body['lon']) ? (float) $body['lon'] : 0.0;
        $source = trim((string) ($body['source'] ?? 'nominatim'));

        if ($name === '' || mb_strlen($name) > 255) {
            return ResponseFactory::json(
                ['error' => 'invalid_name', 'message' => 'Name is required (max 255 chars)'],
                400,
                $response,
            );
        }

        if ($source === '' || mb_strlen($source) > 50) {
            return ResponseFactory::json(
                ['error' => 'invalid_source', 'message' => 'Source is required (max 50 chars)'],
                400,
                $response,
            );
        }

        try {
            $location = $this->service->create(
                userId: $user->id,
                name: $name,
                slug: $slug,
                lat: $lat,
                lon: $lon,
                source: $source,
            );
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::json(
                ['error' => $e->getMessage()],
                400,
                $response,
            );
        }

        return ResponseFactory::json(['data' => $location], 201, $response);
    }

    /** @param array<string, string> $args */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $id = (string) $args['id'];
        $body = $request->getParsedBody();

        if (!is_array($body) || $body === []) {
            return ResponseFactory::json(['error' => 'invalid_request'], 400, $response);
        }

        try {
            $location = $this->service->update($id, $user->id, $body);
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::json(
                ['error' => $e->getMessage()],
                400,
                $response,
            );
        }

        return ResponseFactory::json(['data' => $location], 200, $response);
    }

    /** @param array<string, string> $args */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $id = (string) $args['id'];

        try {
            $this->service->delete($id, $user->id);
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::json(
                ['error' => $e->getMessage()],
                400,
                $response,
            );
        }

        return ResponseFactory::json(null, 204, $response);
    }

    public function replaceAll(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body) || !isset($body['locations']) || !is_array($body['locations'])) {
            return ResponseFactory::json(
                ['error' => 'invalid_request', 'message' => 'Provide locations array'],
                400,
                $response,
            );
        }

        $locations = [];
        foreach ($body['locations'] as $index => $loc) {
            if (!is_array($loc) || empty($loc['name'])) {
                return ResponseFactory::json(
                    ['error' => 'invalid_location', 'message' => "Location at index $index missing name"],
                    400,
                    $response,
                );
            }

            $locations[] = [
                'name' => trim((string) $loc['name']),
                'slug' => !empty($loc['slug']) ? trim((string) $loc['slug']) : null,
                'lat' => isset($loc['lat']) ? (float) $loc['lat'] : 0.0,
                'lon' => isset($loc['lon']) ? (float) $loc['lon'] : 0.0,
                'source' => trim((string) ($loc['source'] ?? 'nominatim')),
            ];
        }

        try {
            $result = $this->service->replaceAll($user->id, $locations);
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::json(
                ['error' => $e->getMessage()],
                400,
                $response,
            );
        }

        return ResponseFactory::json(['data' => $result], 200, $response);
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
