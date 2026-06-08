<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\UserExportService;

/**
 * User-specific endpoints beyond CRUD.
 */
final class UserController
{
    public function __construct(
        private readonly UserExportService $exportService
    ) {
    }

    public function export(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        $data = $this->exportService->export($user, $args['id']);
        return ResponseFactory::json(['data' => $data], 200, $response);
    }
}
