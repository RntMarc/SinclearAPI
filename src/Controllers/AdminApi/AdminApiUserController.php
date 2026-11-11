<?php

namespace Sinclear\Api\Controllers\AdminApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Support\DateTimeValue;

final readonly class AdminApiUserController
{
    use AdminApiConcern;

    public function __construct(
        private UserRepository $userRepo,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));

        if ($query !== '') {
            $result = $this->userRepo->search($query);
            $data = array_map(static fn(array $u) => [
                'id' => $u['id'],
                'email' => $u['email'],
                'displayName' => $u['displayName'],
                'image' => $u['image'] ?? null,
            ], $result);
        } else {
            $data = array_map(static fn(array $u) => [
                'id' => $u['id'],
                'email' => $u['email'],
                'displayName' => $u['displayName'],
                'image' => $u['image'] ?? null,
                'isAdmin' => (bool) ($u['isAdmin'] ?? false),
                'createdAt' => self::formatTimestamp($u['createdAt'] ?? null),
            ], $this->userRepo->findAll());
        }

        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    private static function formatTimestamp(mixed $value): ?string
    {
        $instant = DateTimeValue::fromDatabase(is_string($value) ? $value : null);
        if ($instant === null) {
            return null;
        }

        return $instant->format('Y-m-d\TH:i:s.v\Z');
    }
}
