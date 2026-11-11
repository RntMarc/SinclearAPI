<?php

namespace Sinclear\Api\Controllers\AdminApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\ForumMemberRepository;
use Sinclear\Api\Repository\ForumRepository;
use Sinclear\Api\Services\AdminAuditService;

final readonly class AdminApiForumController
{
    use AdminApiConcern;

    public function __construct(
        private ForumRepository $forumRepo,
        private ForumMemberRepository $forumMemberRepo,
        private AdminAuditService $auditService,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $result = $this->forumRepo->list(1, 99999);
        $forumIds = array_column($result['data'], 'id');
        $memberCounts = $this->forumMemberRepo->countByForums($forumIds);

        $data = array_map(fn(array $f) => [
            'id' => $f['id'],
            'name' => $f['name'],
            'description' => $f['description'] ?? null,
            'image' => $f['image'] ?? null,
            'memberCount' => $memberCounts[$f['id']] ?? 0,
            'createdAt' => $f['createdAt'],
        ], $result['data']);

        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return ResponseFactory::json(['error' => 'name_required'], 400, $response);
        }

        $description = isset($body['description']) && is_string($body['description'])
            ? trim($body['description']) : null;

        $id = $this->forumRepo->create([
            'name' => $name,
            'description' => $description,
        ]);

        $forum = $this->forumRepo->findById($id);
        $this->auditService->record($user, 'create', 'forum', $id, $request);

        return ResponseFactory::json(['data' => $forum], 201, $response);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];
        $body = $this->parsedBody($request);

        $forum = $this->forumRepo->findById($id);
        if ($forum === null) {
            return ResponseFactory::json(['error' => 'forum_not_found'], 404, $response);
        }

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return ResponseFactory::json(['error' => 'name_required'], 400, $response);
            }
            $data['name'] = $name;
        }
        if (isset($body['description'])) {
            $data['description'] = is_string($body['description']) ? trim($body['description']) : null;
        }

        if ($data === []) {
            return ResponseFactory::json(['error' => 'no_fields_to_update'], 400, $response);
        }

        $this->forumRepo->update($id, $data);
        $updated = $this->forumRepo->findById($id);
        $this->auditService->record($user, 'update', 'forum', $id, $request);

        return ResponseFactory::json(['data' => $updated], 200, $response);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $forum = $this->forumRepo->findById($id);
        if ($forum === null) {
            return ResponseFactory::json(['error' => 'forum_not_found'], 404, $response);
        }

        $this->forumRepo->delete($id);
        $this->auditService->record($user, 'delete', 'forum', $id, $request);

        return ResponseFactory::noContent($response);
    }
}
