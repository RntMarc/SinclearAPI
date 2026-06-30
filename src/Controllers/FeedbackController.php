<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\FeedbackPolicy;
use Sinclear\Api\Services\FeedbackService;

final readonly class FeedbackController
{
    private const array ERROR_MAP = [
        'title_required' => ['error' => 'title_required', 'status' => 400],
        'text_required' => ['error' => 'text_required', 'status' => 400],
        'suggestion_not_found' => ['error' => 'suggestion_not_found', 'status' => 404],
        'comment_not_found' => ['error' => 'comment_not_found', 'status' => 404],
        'forbidden' => ['error' => 'forbidden', 'status' => 403],
        'edit_window_expired' => ['error' => 'edit_window_expired', 'status' => 403],
        'already_voted' => ['error' => 'already_voted', 'status' => 409],
        'invalid_status' => ['error' => 'invalid_status', 'status' => 400],
        'mail_failed' => ['error' => 'mail_failed', 'status' => 500],
        'invalid_image' => ['error' => 'invalid_image', 'status' => 400],
        'image_too_large' => ['error' => 'image_too_large', 'status' => 400],
        'invalid_image_format' => ['error' => 'invalid_image_format', 'status' => 400],
        'unsupported_image_format' => ['error' => 'unsupported_image_format', 'status' => 400],
        'image_dimensions_too_large' => ['error' => 'image_dimensions_too_large', 'status' => 400],
    ];

    public function __construct(
        private FeedbackService $feedbackService,
        private FeedbackPolicy $policy,
    ) {}

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        $description = isset($body['description']) && is_string($body['description'])
            ? trim($body['description'])
            : null;

        try {
            $suggestion = $this->feedbackService->createSuggestion($user->id, $title, $description);
            return ResponseFactory::json(['data' => $suggestion], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->feedbackService->listSuggestions($page, $limit, $user->id);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        try {
            $this->feedbackService->deleteSuggestion($id, $user->id, $user->isAdmin);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function vote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $suggestionId = $args['id'];

        try {
            $this->feedbackService->vote($suggestionId, $user->id);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function removeVote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $suggestionId = $args['id'];

        try {
            $this->feedbackService->removeVote($suggestionId, $user->id);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function updateStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];
        $body = $request->getParsedBody();

        if (!$this->policy->canUpdateStatus($user)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $status = isset($body['status']) ? trim((string) $body['status']) : '';
        if ($status === '') {
            return ResponseFactory::json(['error' => 'status_required'], 400, $response);
        }

        try {
            $this->feedbackService->updateStatus($id, $status);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function listComments(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $suggestionId = $args['id'];

        try {
            $result = $this->feedbackService->listComments($suggestionId);
            return ResponseFactory::json($result, 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function createComment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $suggestionId = $args['id'];
        $body = $request->getParsedBody();

        $text = isset($body['text']) && is_string($body['text']) ? trim($body['text']) : '';
        $parentId = isset($body['parentId']) && is_string($body['parentId']) ? $body['parentId'] : null;

        try {
            $comment = $this->feedbackService->createComment($suggestionId, $user->id, $text, $parentId);
            return ResponseFactory::json(['data' => $comment], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function updateComment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $suggestionId = $args['id'];
        $commentId = $args['commentId'];
        $body = $request->getParsedBody();

        $text = isset($body['text']) && is_string($body['text']) ? trim($body['text']) : '';

        try {
            $comment = $this->feedbackService->getComment($suggestionId, $commentId);
            if ($comment === null) {
                return ResponseFactory::json(['error' => 'comment_not_found'], 404, $response);
            }

            if (!$this->policy->canEditComment($user, $comment['userId'], $comment['createdAt'])) {
                if ($user->id !== $comment['userId']) {
                    return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
                }
                return ResponseFactory::json(['error' => 'edit_window_expired'], 403, $response);
            }

            $updated = $this->feedbackService->updateComment($suggestionId, $commentId, $user->id, $text, $user->isAdmin);
            return ResponseFactory::json(['data' => $updated], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function deleteComment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $suggestionId = $args['id'];
        $commentId = $args['commentId'];

        try {
            $this->feedbackService->deleteComment($suggestionId, $commentId, $user->id, $user->isAdmin);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function bugReport(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $text = isset($body['text']) && is_string($body['text']) ? trim($body['text']) : '';
        $version = isset($body['version']) && is_string($body['version']) ? trim($body['version']) : null;
        $buildNumber = isset($body['buildNumber']) ? (int) $body['buildNumber'] : null;
        $image = isset($body['image']) && is_string($body['image']) ? $body['image'] : null;

        try {
            $this->feedbackService->sendBugReport(
                userId: $user->id,
                userEmail: $user->email,
                userDisplayName: $user->email,
                text: $text,
                version: $version,
                buildNumber: $buildNumber,
                image: $image,
            );
            return ResponseFactory::json(['data' => ['sent' => true]], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
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
