<?php

namespace Sinclear\Api\Controllers\AdminApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\DiscoverPlaceSubmissionRepository;
use Sinclear\Api\Services\AdminAuditService;
use Sinclear\Api\Services\PlaceSubmissionService;

final readonly class AdminApiSubmissionController
{
    use AdminApiConcern;

    public function __construct(
        private PlaceSubmissionService $submissionService,
        private DiscoverPlaceSubmissionRepository $submissionRepo,
        private AdminAuditService $auditService,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $params = $request->getQueryParams();
        $status = !empty($params['status']) ? $params['status'] : null;
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->submissionRepo->findAll($status, $page, $limit);
        $counts = $this->submissionService->getStatusCounts();

        return ResponseFactory::json([
            'data' => array_map(self::format(...), $result['data']),
            'meta' => $result['meta'],
            'counts' => $counts,
        ], 200, $response);
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);

        $submission = $this->submissionRepo->findById($args['id']);
        if ($submission === null) {
            return ResponseFactory::json(['error' => 'submission_not_found'], 404, $response);
        }

        return ResponseFactory::json(['data' => self::format($submission)], 200, $response);
    }

    public function approve(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $osmId = (int) ($body['osmId'] ?? 0);
        $osmType = strtoupper(trim($body['osmType'] ?? ''));
        $adminNote = trim($body['adminNote'] ?? '');

        if ($osmId <= 0 || !in_array($osmType, ['N', 'W', 'R'], true)) {
            return ResponseFactory::json(['error' => 'invalid_osm_data'], 400, $response);
        }

        try {
            $result = $this->submissionService->approveSubmission($args['id'], $osmId, $osmType, $adminNote);
            $this->auditService->record($user, 'approve', 'submission', $args['id'], $request);
            return ResponseFactory::json(['data' => $result], 200, $response);
        } catch (\RuntimeException $e) {
            $code = match ($e->getMessage()) {
                'Submission not found' => 'submission_not_found',
                'Submission is not pending' => 'submission_not_pending',
                default => 'approve_failed',
            };
            $status = $code === 'submission_not_found' ? 404 : 400;
            return ResponseFactory::json(['error' => $code], $status, $response);
        }
    }

    public function reject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $adminNote = trim($body['adminNote'] ?? '');
        if ($adminNote === '') {
            return ResponseFactory::json(['error' => 'admin_note_required'], 400, $response);
        }

        try {
            $result = $this->submissionService->rejectSubmission($args['id'], $adminNote);
            $this->auditService->record($user, 'reject', 'submission', $args['id'], $request);
            return ResponseFactory::json(['data' => $result], 200, $response);
        } catch (\RuntimeException $e) {
            $code = match ($e->getMessage()) {
                'Submission not found' => 'submission_not_found',
                'Submission is not pending' => 'submission_not_pending',
                default => 'reject_failed',
            };
            $status = $code === 'submission_not_found' ? 404 : 400;
            return ResponseFactory::json(['error' => $code], $status, $response);
        }
    }

    /** @param array<string, mixed> $s */
    private static function format(array $s): array
    {
        return [
            'id' => $s['id'],
            'userId' => $s['userId'],
            'name' => $s['name'],
            'address' => $s['address'],
            'latitude' => (float) $s['latitude'],
            'longitude' => (float) $s['longitude'],
            'photo' => $s['photo'] ?? null,
            'mapLink' => $s['mapLink'] ?? null,
            'website' => $s['website'] ?? null,
            'rating' => $s['rating'] !== null ? (int) $s['rating'] : null,
            'comment' => $s['comment'] ?? null,
            'note' => $s['note'] ?? null,
            'status' => $s['status'],
            'adminNote' => $s['adminNote'] ?? null,
            'targetPlaceId' => $s['targetPlaceId'] ?? null,
            'createdAt' => $s['createdAt'],
            'updatedAt' => $s['updatedAt'],
        ];
    }
}
