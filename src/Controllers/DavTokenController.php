<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\DavTokenService;

final readonly class DavTokenController
{
    public function __construct(
        private DavTokenService $tokenService,
    ) {}

    public function createToken(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body) || empty($body['label']) || !is_string($body['label'])) {
            return ResponseFactory::json(['error' => 'label_required'], 400, $response);
        }

        $label = trim($body['label']);
        if ($label === '') {
            return ResponseFactory::json(['error' => 'label_required'], 400, $response);
        }

        try {
            $tokenData = $this->tokenService->createToken($user->id, $label);
            return ResponseFactory::json(['data' => $tokenData], 201, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'token_limit_reached' ? 409 : 400;
            return ResponseFactory::json(['error' => $e->getMessage()], $code, $response);
        }
    }

    public function listTokens(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->tokenService->listTokens($user->id, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    /** @param array<string, string> $args */
    public function deleteToken(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $deleted = $this->tokenService->revokeToken($id, $user->id);
        if (!$deleted) {
            return ResponseFactory::json(['error' => 'token_not_found'], 404, $response);
        }

        return ResponseFactory::noContent($response);
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
