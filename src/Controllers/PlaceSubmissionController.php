<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\PlaceSubmissionService;

final readonly class PlaceSubmissionController
{
    private const array IMAGE_ERRORS = [
        'invalid_image' => 'invalid_photo',
        'invalid_image_encoding' => 'invalid_photo_encoding',
        'image_too_large' => 'photo_too_large',
        'invalid_image_format' => 'invalid_photo_format',
        'unsupported_image_format' => 'unsupported_photo_format',
        'image_dimensions_too_large' => 'photo_dimensions_too_large',
    ];

    public function __construct(
        private PlaceSubmissionService $submissionService,
    ) {}

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return ResponseFactory::json(['error' => 'invalid_body'], 400, $response);
        }

        try {
            $submission = $this->submissionService->createSubmission($user->id, $body);
            return ResponseFactory::json(['data' => $submission], 201, $response);
        } catch (\InvalidArgumentException $e) {
            $code = $this->mapImageError($e->getMessage());
            return ResponseFactory::json(['error' => $code], 400, $response);
        }
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->submissionService->listUserSubmissions($user->id, $page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $submission = $this->submissionService->getSubmission($args['id']);

        if ($submission === null) {
            return ResponseFactory::json(['error' => 'submission_not_found'], 404, $response);
        }

        if ($submission['userId'] !== $user->id) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        return ResponseFactory::json(['data' => $submission], 200, $response);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return ResponseFactory::json(['error' => 'invalid_body'], 400, $response);
        }

        try {
            $submission = $this->submissionService->updateSubmission($args['id'], $user->id, $body);
            return ResponseFactory::json(['data' => $submission], 200, $response);
        } catch (\RuntimeException $e) {
            $code = match ($e->getMessage()) {
                'Submission not found' => 'submission_not_found',
                'Forbidden' => 'forbidden',
                'Only pending submissions can be edited' => 'submission_not_pending',
                default => 'update_failed',
            };
            $status = $code === 'submission_not_found' ? 404 : ($code === 'forbidden' ? 403 : 400);
            return ResponseFactory::json(['error' => $code], $status, $response);
        } catch (\InvalidArgumentException $e) {
            $code = $this->mapImageError($e->getMessage());
            return ResponseFactory::json(['error' => $code], 400, $response);
        }
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }

    private function mapImageError(string $message): string
    {
        return self::IMAGE_ERRORS[$message] ?? match (true) {
            str_contains($message, 'Name is required') => 'name_required',
            str_contains($message, 'Latitude and longitude') => 'coordinates_required',
            str_contains($message, 'Rating is required') => 'rating_required',
            str_contains($message, 'rating must be 1-5') => 'invalid_rating',
            str_contains($message, 'Invalid coordinates') => 'invalid_coordinates',
            default => 'submission_failed',
        };
    }
}
