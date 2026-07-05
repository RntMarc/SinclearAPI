<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\ForumPolicy;
use Sinclear\Api\Services\ForumService;

final readonly class ForumController
{
    private const array ERROR_MAP = [
        'name_required' => ['error' => 'name_required', 'status' => 400],
        'forum_not_found' => ['error' => 'forum_not_found', 'status' => 404],
        'post_not_found' => ['error' => 'post_not_found', 'status' => 404],
        'comment_not_found' => ['error' => 'comment_not_found', 'status' => 404],
        'text_required' => ['error' => 'text_required', 'status' => 400],
        'forbidden' => ['error' => 'forbidden', 'status' => 403],
        'invalid_type' => ['error' => 'invalid_type', 'status' => 400],
        'invalid_content' => ['error' => 'invalid_content', 'status' => 400],
        'content_text_required' => ['error' => 'content_text_required', 'status' => 400],
        'invalid_url' => ['error' => 'invalid_url', 'status' => 400],
        'already_member' => ['error' => 'already_member', 'status' => 409],
        'not_member' => ['error' => 'not_member', 'status' => 409],
        'already_voted' => ['error' => 'already_voted', 'status' => 409],
        'edit_window_expired' => ['error' => 'edit_window_expired', 'status' => 403],
        'not_found' => ['error' => 'not_found', 'status' => 404],
        'invalid_image' => ['error' => 'invalid_image', 'status' => 400],
        'invalid_image_encoding' => ['error' => 'invalid_image_encoding', 'status' => 400],
        'image_too_large' => ['error' => 'image_too_large', 'status' => 400],
        'invalid_image_format' => ['error' => 'invalid_image_format', 'status' => 400],
        'unsupported_image_format' => ['error' => 'unsupported_image_format', 'status' => 400],
        'image_dimensions_too_large' => ['error' => 'image_dimensions_too_large', 'status' => 400],
    ];

    public function __construct(
        private ForumService $forumService,
        private ForumPolicy $policy,
    ) {}

    // ── Forum CRUD ─────────────────────────────────────────

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $result = $this->forumService->listForums($page, $limit);
        return ResponseFactory::paginated($result['data'], $result['meta'], $response);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $forum = $this->forumService->getForum($args['id'], $user->id);
            return ResponseFactory::json(['data' => $forum], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        if (!$this->policy->canCreateForum($user)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $body = $request->getParsedBody();
        $name = isset($body['name']) ? trim((string) $body['name']) : '';
        $description = isset($body['description']) && is_string($body['description'])
            ? trim($body['description']) : null;
        $image = isset($body['image']) && is_string($body['image']) ? $body['image'] : null;

        try {
            $forum = $this->forumService->createForum($name, $description, $image);
            return ResponseFactory::json(['data' => $forum], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        if (!$this->policy->canModifyForum($user)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $body = $request->getParsedBody();
        $data = [];

        if (isset($body['name'])) {
            $data['name'] = trim((string) $body['name']);
        }
        if (isset($body['description'])) {
            $data['description'] = $body['description'] !== null
                ? trim((string) $body['description']) : null;
        }
        if (array_key_exists('image', $body)) {
            $data['image'] = $body['image'] !== null ? (string) $body['image'] : null;
        }

        if ($data === []) {
            return ResponseFactory::json(['error' => 'no_fields_to_update'], 400, $response);
        }

        try {
            $forum = $this->forumService->updateForum($args['id'], $data);
            return ResponseFactory::json(['data' => $forum], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        if (!$this->policy->canDeleteForum($user)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        try {
            $this->forumService->deleteForum($args['id']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    // ── Members ────────────────────────────────────────────

    public function join(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->forumService->joinForum($args['id'], $user->id);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function leave(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->forumService->leaveForum($args['id'], $user->id);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function listMembers(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);

        try {
            $members = $this->forumService->listMembers($args['id']);
            return ResponseFactory::json(['data' => $members], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function updateNotifications(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $enabled = isset($body['notificationsEnabled'])
            ? (bool) $body['notificationsEnabled']
            : null;

        if ($enabled === null) {
            return ResponseFactory::json(['error' => 'notificationsEnabled_required'], 400, $response);
        }

        try {
            $this->forumService->updateNotifications($args['id'], $user->id, $enabled);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    // ── Posts ──────────────────────────────────────────────

    public function listPosts(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        try {
            $result = $this->forumService->listPosts($args['id'], $page, $limit, $user->id);
            return ResponseFactory::paginated($result['data'], $result['meta'], $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function getPost(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $post = $this->forumService->getPost($args['id'], $args['postId'], $user->id);
            return ResponseFactory::json(['data' => $post], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function createPost(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $type = isset($body['type']) ? trim((string) $body['type']) : 'text';
        $content = $body['content'] ?? null;

        try {
            $post = $this->forumService->createPost($args['id'], $user->id, $type, $content);
            return ResponseFactory::json(['data' => $post], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function updatePost(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $content = $body['content'] ?? null;

        try {
            $post = $this->forumService->updatePost($args['id'], $args['postId'], $user->id, $content);
            return ResponseFactory::json(['data' => $post], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function deletePost(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $post = $this->forumService->getPost($args['id'], $args['postId'], $user->id);
            $hasComments = ($post['commentCount'] ?? 0) > 0;

            if (!$this->policy->canDeletePost($user, $post['userId'], $hasComments, $post['createdAt'])) {
                if ($user->id !== $post['userId']) {
                    return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
                }
                return ResponseFactory::json(['error' => 'edit_window_expired'], 403, $response);
            }

            $this->forumService->deletePost($args['id'], $args['postId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    // ── Votes ──────────────────────────────────────────────

    public function vote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->forumService->vote($args['postId'], $user->id);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function removeVote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->forumService->removeVote($args['postId'], $user->id);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    // ── Comments ───────────────────────────────────────────

    public function listComments(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);

        try {
            $result = $this->forumService->listComments($args['id'], $args['postId']);
            return ResponseFactory::json($result, 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function createComment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $text = isset($body['text']) && is_string($body['text']) ? trim($body['text']) : '';
        $parentId = isset($body['parentId']) && is_string($body['parentId']) ? $body['parentId'] : null;

        try {
            $comment = $this->forumService->createComment($args['postId'], $user->id, $text, $parentId);
            return ResponseFactory::json(['data' => $comment], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function updateComment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        $text = isset($body['text']) && is_string($body['text']) ? trim($body['text']) : '';

        try {
            $comment = $this->forumService->getComment($args['postId'], $args['commentId']);
            if ($comment === null) {
                return ResponseFactory::json(['error' => 'comment_not_found'], 404, $response);
            }

            if (!$this->policy->canEditComment($user, $comment['userId'], $comment['createdAt'])) {
                if ($user->id !== $comment['userId']) {
                    return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
                }
                return ResponseFactory::json(['error' => 'edit_window_expired'], 403, $response);
            }

            $updated = $this->forumService->updateComment($args['postId'], $args['commentId'], $text);
            return ResponseFactory::json(['data' => $updated], 200, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function deleteComment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $comment = $this->forumService->getComment($args['postId'], $args['commentId']);
            if ($comment === null) {
                return ResponseFactory::json(['error' => 'comment_not_found'], 404, $response);
            }

            $created = new \DateTimeImmutable($comment['createdAt'], new \DateTimeZone('UTC'));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $diff = $now->getTimestamp() - $created->getTimestamp();

            if (!$this->policy->canDeleteComment($user, $comment['userId'])) {
                return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
            }

            if ($user->id === $comment['userId'] && $diff > 600) {
                $hasReplies = $comment['hasReplies'] ?? false;
                if ($hasReplies) {
                    return ResponseFactory::json(['error' => 'edit_window_expired'], 403, $response);
                }
            }

            $this->forumService->deleteComment($args['postId'], $args['commentId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    // ── Helpers ────────────────────────────────────────────

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
