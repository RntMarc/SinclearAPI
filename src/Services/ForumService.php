<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\FeedPostCommentRepository;
use Sinclear\Api\Repository\FeedPostRepository;
use Sinclear\Api\Repository\FeedPostVoteRepository;
use Sinclear\Api\Repository\ForumMemberRepository;
use Sinclear\Api\Repository\ForumRepository;

final readonly class ForumService
{
    private const VALID_TYPES = ['text', 'music', 'video', 'web'];

    private const VALID_MUSIC_PLATFORMS = ['spotify', 'apple_music', 'youtube_music', 'youtube', 'other'];
    private const VALID_VIDEO_PLATFORMS = ['youtube', 'peertube', 'odysee', 'tv_mediathek', 'other'];

    public function __construct(
        private ForumRepository $forumRepo,
        private ForumMemberRepository $memberRepo,
        private FeedPostRepository $postRepo,
        private FeedPostVoteRepository $voteRepo,
        private FeedPostCommentRepository $commentRepo,
        private ImageService $imageService,
    ) {}

    // ── Forum ──────────────────────────────────────────────

    public function createForum(string $name, ?string $description, ?string $image): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('name_required');
        }

        $validatedImage = null;
        if ($image !== null && $image !== '') {
            $validatedImage = $this->imageService->validate($image);
        }

        $id = $this->forumRepo->create([
            'name' => $name,
            'description' => $description !== null ? trim($description) : null,
            'image' => $validatedImage,
        ]);

        return $this->forumRepo->findById($id);
    }

    public function updateForum(string $id, array $data): array
    {
        $forum = $this->forumRepo->findById($id);
        if ($forum === null) {
            throw new \RuntimeException('forum_not_found');
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new \RuntimeException('name_required');
            }
            $data['name'] = $name;
        }

        if (isset($data['description'])) {
            $data['description'] = $data['description'] !== null
                ? trim((string) $data['description'])
                : null;
        }

        if (array_key_exists('image', $data)) {
            $image = $data['image'];
            $data['image'] = ($image !== null && $image !== '')
                ? $this->imageService->validate($image)
                : null;
        }

        $this->forumRepo->update($id, $data);
        return $this->forumRepo->findById($id);
    }

    public function deleteForum(string $id): void
    {
        $forum = $this->forumRepo->findById($id);
        if ($forum === null) {
            throw new \RuntimeException('forum_not_found');
        }

        $posts = $this->postRepo->listByForum($id, 1, 99999, null);
        foreach ($posts['data'] as $post) {
            $this->commentRepo->deleteByPost($post['id']);
        }

        $this->forumRepo->delete($id);
    }

    public function listForums(int $page, int $limit): array
    {
        $result = $this->forumRepo->list($page, $limit);
        $forumIds = array_column($result['data'], 'id');
        $memberCounts = $this->memberRepo->countByForums($forumIds);

        $result['data'] = array_map(
            fn(array $f) => [
                'id' => $f['id'],
                'name' => $f['name'],
                'description' => $f['description'],
                'image' => $f['image'],
                'memberCount' => $memberCounts[$f['id']] ?? 0,
                'createdAt' => $f['createdAt'],
                'updatedAt' => $f['updatedAt'],
            ],
            $result['data']
        );

        return $result;
    }

    public function getForum(string $id, ?string $userId): array
    {
        $forum = $this->forumRepo->findById($id);
        if ($forum === null) {
            throw new \RuntimeException('forum_not_found');
        }

        $result = [
            'id' => $forum['id'],
            'name' => $forum['name'],
            'description' => $forum['description'],
            'image' => $forum['image'],
            'memberCount' => $this->memberRepo->countByForum($id),
            'createdAt' => $forum['createdAt'],
            'updatedAt' => $forum['updatedAt'],
        ];

        if ($userId !== null) {
            $membership = $this->memberRepo->findByForumAndUser($id, $userId);
            $result['isMember'] = $membership !== null;
            if ($membership !== null) {
                $result['notificationsEnabled'] = (bool) $membership['notificationsEnabled'];
            }
        }

        return $result;
    }

    // ── Members ────────────────────────────────────────────

    public function joinForum(string $forumId, string $userId): void
    {
        $forum = $this->forumRepo->findById($forumId);
        if ($forum === null) {
            throw new \RuntimeException('forum_not_found');
        }

        $existing = $this->memberRepo->findByForumAndUser($forumId, $userId);
        if ($existing !== null) {
            throw new \RuntimeException('already_member');
        }

        $this->memberRepo->join($forumId, $userId);
    }

    public function leaveForum(string $forumId, string $userId): void
    {
        $forum = $this->forumRepo->findById($forumId);
        if ($forum === null) {
            throw new \RuntimeException('forum_not_found');
        }

        $existing = $this->memberRepo->findByForumAndUser($forumId, $userId);
        if ($existing === null) {
            throw new \RuntimeException('not_member');
        }

        $this->memberRepo->leave($forumId, $userId);
    }

    public function listMembers(string $forumId): array
    {
        $forum = $this->forumRepo->findById($forumId);
        if ($forum === null) {
            throw new \RuntimeException('forum_not_found');
        }

        return $this->memberRepo->listByForum($forumId);
    }

    public function updateNotifications(string $forumId, string $userId, bool $enabled): void
    {
        $existing = $this->memberRepo->findByForumAndUser($forumId, $userId);
        if ($existing === null) {
            throw new \RuntimeException('not_member');
        }

        $this->memberRepo->updateNotifications($forumId, $userId, $enabled);
    }

    // ── Posts ──────────────────────────────────────────────

    public function createPost(string $forumId, string $userId, string $type, mixed $content): array
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \RuntimeException('invalid_type');
        }

        $member = $this->memberRepo->findByForumAndUser($forumId, $userId);
        if ($member === null) {
            throw new \RuntimeException('not_member');
        }

        $content = $this->validateContent($type, $content);

        $id = $this->postRepo->create([
            'forumId' => $forumId,
            'userId' => $userId,
            'type' => $type,
            'content' => $content,
        ]);

        return $this->formatPost($this->postRepo->findById($id), $userId);
    }

    public function updatePost(string $forumId, string $postId, string $userId, mixed $content): array
    {
        $post = $this->postRepo->findById($postId);
        if ($post === null || $post['forumId'] !== $forumId) {
            throw new \RuntimeException('post_not_found');
        }

        if ($post['userId'] !== $userId) {
            throw new \RuntimeException('forbidden');
        }

        $content = $this->validateContent($post['type'], $content);

        $this->postRepo->update($postId, ['content' => $content]);
        return $this->formatPost($this->postRepo->findById($postId), $userId);
    }

    public function deletePost(string $forumId, string $postId): void
    {
        $post = $this->postRepo->findById($postId);
        if ($post === null || $post['forumId'] !== $forumId) {
            throw new \RuntimeException('post_not_found');
        }

        $this->commentRepo->deleteByPost($postId);
        $this->postRepo->delete($postId);
    }

    public function listPosts(string $forumId, int $page, int $limit, ?string $userId): array
    {
        $forum = $this->forumRepo->findById($forumId);
        if ($forum === null) {
            throw new \RuntimeException('forum_not_found');
        }

        $result = $this->postRepo->listByForum($forumId, $page, $limit, $userId);
        $result['data'] = array_map(
            fn(array $p) => $this->formatPost($p, $userId),
            $result['data']
        );

        return $result;
    }

    public function getPost(string $forumId, string $postId, ?string $userId): array
    {
        $post = $this->postRepo->findById($postId);
        if ($post === null || $post['forumId'] !== $forumId) {
            throw new \RuntimeException('post_not_found');
        }

        $formatted = $this->formatPost($post, $userId);
        $formatted['upvoteCount'] = $this->postRepo->getUpvoteCount($postId);
        $formatted['commentCount'] = $this->commentRepo->countByPost($postId);

        return $formatted;
    }

    // ── Votes ──────────────────────────────────────────────

    public function vote(string $postId, string $userId): void
    {
        $post = $this->postRepo->findById($postId);
        if ($post === null) {
            throw new \RuntimeException('post_not_found');
        }

        $existing = $this->voteRepo->findByPostAndUser($postId, $userId);
        if ($existing !== null) {
            throw new \RuntimeException('already_voted');
        }

        $this->voteRepo->create($postId, $userId);
    }

    public function removeVote(string $postId, string $userId): void
    {
        $post = $this->postRepo->findById($postId);
        if ($post === null) {
            throw new \RuntimeException('post_not_found');
        }

        $this->voteRepo->delete($postId, $userId);
    }

    // ── Comments ───────────────────────────────────────────

    public function listComments(string $forumId, string $postId): array
    {
        $post = $this->postRepo->findById($postId);
        if ($post === null || $post['forumId'] !== $forumId) {
            throw new \RuntimeException('post_not_found');
        }

        $comments = $this->commentRepo->listByPost($postId);
        $total = count(array_filter($comments, fn(array $c) => $c['text'] !== null));
        $tree = $this->buildCommentTree($comments);

        return [
            'data' => $tree,
            'meta' => ['total' => $total],
        ];
    }

    public function getComment(string $postId, string $commentId): ?array
    {
        $comment = $this->commentRepo->findById($commentId);
        if ($comment === null || $comment['postId'] !== $postId) {
            return null;
        }
        return $comment;
    }

    public function createComment(string $postId, string $userId, string $text, ?string $parentId): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('text_required');
        }

        $post = $this->postRepo->findById($postId);
        if ($post === null) {
            throw new \RuntimeException('post_not_found');
        }

        if ($parentId !== null) {
            $parent = $this->commentRepo->findById($parentId);
            if ($parent === null || $parent['postId'] !== $postId) {
                throw new \RuntimeException('comment_not_found');
            }
        }

        $id = $this->commentRepo->create([
            'postId' => $postId,
            'userId' => $userId,
            'parentId' => $parentId,
            'text' => $text,
        ]);

        $comment = $this->commentRepo->findById($id);
        return $this->formatComment($comment);
    }

    public function updateComment(string $postId, string $commentId, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('text_required');
        }

        $comment = $this->commentRepo->findById($commentId);
        if ($comment === null || $comment['postId'] !== $postId) {
            throw new \RuntimeException('comment_not_found');
        }

        $this->commentRepo->update($commentId, $text);
        $comment = $this->commentRepo->findById($commentId);
        return $this->formatComment($comment);
    }

    public function deleteComment(string $postId, string $commentId): void
    {
        $comment = $this->commentRepo->findById($commentId);
        if ($comment === null || $comment['postId'] !== $postId) {
            throw new \RuntimeException('comment_not_found');
        }

        $hasReplies = $this->commentRepo->hasReplies($commentId);

        if ($hasReplies) {
            $this->commentRepo->softDelete($commentId);
        } else {
            $this->commentRepo->hardDelete($commentId);

            $parentId = $comment['parentId'];
            while ($parentId !== null) {
                $parent = $this->commentRepo->findById($parentId);
                if ($parent === null || $parent['text'] !== null) {
                    break;
                }
                if ($this->commentRepo->hasReplies($parentId)) {
                    break;
                }
                $grandparentId = $parent['parentId'];
                $this->commentRepo->hardDelete($parentId);
                $parentId = $grandparentId;
            }
        }
    }

    // ── Helpers ────────────────────────────────────────────

    private function validateContent(string $type, mixed $content): array
    {
        if (!is_array($content)) {
            throw new \RuntimeException('invalid_content');
        }

        match ($type) {
            'text' => $this->validateTextContent($content),
            'music' => $this->validateMusicContent($content),
            'video' => $this->validateVideoContent($content),
            'web' => $this->validateWebContent($content),
        };

        return $content;
    }

    private function validateTextContent(array $content): void
    {
        if (!isset($content['text']) || !is_string($content['text']) || trim($content['text']) === '') {
            throw new \RuntimeException('content_text_required');
        }
        $content['text'] = trim($content['text']);
    }

    private function validateMusicContent(array $content): void
    {
        if (isset($content['text']) && is_string($content['text'])) {
            $content['text'] = trim($content['text']);
        }

        if (!isset($content['urls']) || !is_array($content['urls'])) {
            $content['urls'] = [];
            return;
        }

        foreach ($content['urls'] as $url) {
            if (!is_array($url) || !isset($url['url']) || !is_string($url['url']) || trim($url['url']) === '') {
                throw new \RuntimeException('invalid_url');
            }
            $url['url'] = trim($url['url']);
            if (!isset($url['platform']) || !in_array($url['platform'], self::VALID_MUSIC_PLATFORMS, true)) {
                $url['platform'] = 'other';
            }
        }
    }

    private function validateVideoContent(array $content): void
    {
        if (isset($content['text']) && is_string($content['text'])) {
            $content['text'] = trim($content['text']);
        }

        if (!isset($content['urls']) || !is_array($content['urls'])) {
            $content['urls'] = [];
            return;
        }

        foreach ($content['urls'] as $url) {
            if (!is_array($url) || !isset($url['url']) || !is_string($url['url']) || trim($url['url']) === '') {
                throw new \RuntimeException('invalid_url');
            }
            $url['url'] = trim($url['url']);
            if (!isset($url['platform']) || !in_array($url['platform'], self::VALID_VIDEO_PLATFORMS, true)) {
                $url['platform'] = 'other';
            }
        }
    }

    private function validateWebContent(array $content): void
    {
        if (isset($content['text']) && is_string($content['text'])) {
            $content['text'] = trim($content['text']);
        }

        if (!isset($content['urls']) || !is_array($content['urls'])) {
            $content['urls'] = [];
            return;
        }

        foreach ($content['urls'] as $i => $url) {
            if (!is_string($url) || trim($url) === '') {
                throw new \RuntimeException('invalid_url');
            }
            $content['urls'][$i] = trim($url);
        }
    }

    private function buildCommentTree(array $comments, ?string $parentId = null): array
    {
        $tree = [];
        foreach ($comments as $comment) {
            if ($comment['parentId'] === $parentId) {
                $node = $this->formatComment($comment);
                $node['children'] = $this->buildCommentTree($comments, $comment['id']);
                $tree[] = $node;
            }
        }
        return $tree;
    }

    private function formatPost(array $p, ?string $userId): array
    {
        $content = json_decode($p['content'], true);

        $result = [
            'id' => $p['id'],
            'forumId' => $p['forumId'],
            'userId' => $p['userId'],
            'type' => $p['type'],
            'content' => $content,
            'createdAt' => $p['createdAt'],
            'updatedAt' => $p['updatedAt'],
        ];

        if (isset($p['hasVoted'])) {
            $result['hasVoted'] = (bool) $p['hasVoted'];
        } elseif ($userId !== null) {
            $vote = $this->voteRepo->findByPostAndUser($p['id'], $userId);
            $result['hasVoted'] = $vote !== null;
        }

        if (isset($p['upvoteCount'])) {
            $result['upvoteCount'] = (int) $p['upvoteCount'];
        }
        if (isset($p['commentCount'])) {
            $result['commentCount'] = (int) $p['commentCount'];
        }

        return $result;
    }

    private function formatComment(array $c): array
    {
        return [
            'id' => $c['id'],
            'postId' => $c['postId'],
            'userId' => $c['userId'],
            'parentId' => $c['parentId'],
            'text' => $c['text'],
            'createdAt' => $c['createdAt'],
            'updatedAt' => $c['updatedAt'],
        ];
    }
}
