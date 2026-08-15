<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\ModerationRequestService;

final readonly class ModerationRequestController
{
    private const array ERROR_MAP = [
        'message_required' => ['error' => 'message_required', 'status' => 400],
        'invalid_request_type' => ['error' => 'invalid_request_type', 'status' => 400],
        'invalid_object_type' => ['error' => 'invalid_object_type', 'status' => 400],
        'object_not_found' => ['error' => 'object_not_found', 'status' => 404],
        'cannot_report_own' => ['error' => 'cannot_report_own', 'status' => 403],
        'cannot_request_deletion_foreign' => ['error' => 'cannot_request_deletion_foreign', 'status' => 403],
        'deletion_not_supported' => ['error' => 'deletion_not_supported', 'status' => 400],
        'invalid_status' => ['error' => 'invalid_status', 'status' => 400],
        'request_not_found' => ['error' => 'request_not_found', 'status' => 404],
    ];

    public function __construct(
        private ModerationRequestService $moderationRequestService,
    ) {}

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $requestType = isset($body['requestType']) ? trim((string) $body['requestType']) : '';
        $objectType = isset($body['objectType']) ? trim((string) $body['objectType']) : '';
        $objectId = isset($body['objectId']) ? trim((string) $body['objectId']) : '';
        $message = isset($body['message']) && is_string($body['message']) ? trim($body['message']) : '';

        try {
            $created = $this->moderationRequestService->createRequest(
                userId: $user->id,
                requestType: $requestType,
                objectType: $objectType,
                objectId: $objectId,
                message: $message,
            );
            return ResponseFactory::json(['data' => $created], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function listMine(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->moderationRequestService->listMine($user->id, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    private function errorResponse(string $message, ResponseInterface $response): ResponseInterface
    {
        $mapped = self::ERROR_MAP[$message] ?? null;
        if ($mapped !== null) {
            return ResponseFactory::json(['error' => $mapped['error']], $mapped['status'], $response);
        }
        return ResponseFactory::json(['error' => 'internal_error'], 500, $response);
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
