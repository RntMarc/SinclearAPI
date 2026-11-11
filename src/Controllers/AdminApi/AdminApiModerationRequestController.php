<?php

namespace Sinclear\Api\Controllers\AdminApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Services\AdminAuditService;
use Sinclear\Api\Services\ModerationRequestService;

final readonly class AdminApiModerationRequestController
{
    use AdminApiConcern;

    public function __construct(
        private ModerationRequestService $moderationRequestService,
        private AdminAuditService $auditService,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $params = $request->getQueryParams();
        $status = !empty($params['status']) ? trim($params['status']) : null;
        $objectType = !empty($params['objectType']) ? trim($params['objectType']) : null;
        $requestType = !empty($params['requestType']) ? trim($params['requestType']) : null;
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        try {
            $result = $this->moderationRequestService->listAll($page, $limit, $status, $objectType, $requestType);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }

        return ResponseFactory::json([
            'data' => $result['data'],
            'meta' => $result['meta'],
            'counts' => $this->moderationRequestService->getStatusCounts(),
        ], 200, $response);
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);

        $r = $this->moderationRequestService->getById($args['id']);
        if ($r === null) {
            return ResponseFactory::json(['error' => 'request_not_found'], 404, $response);
        }

        return ResponseFactory::json(['data' => $r], 200, $response);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $status = isset($body['status']) && is_string($body['status']) ? trim($body['status']) : '';
        $adminComment = isset($body['adminComment']) && is_string($body['adminComment'])
            ? trim($body['adminComment']) : null;

        if ($status === '') {
            return ResponseFactory::json(['error' => 'status_required'], 400, $response);
        }

        try {
            $result = $this->moderationRequestService->updateRequest($args['id'], $status, $adminComment);
            $this->auditService->record($user, 'update', 'moderation_request', $args['id'], $request);
            return ResponseFactory::json(['data' => $result], 200, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'request_not_found' ? 404 : 400;
            return ResponseFactory::json(['error' => $e->getMessage()], $code, $response);
        }
    }
}
