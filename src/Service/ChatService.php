<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use PDO;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Repository\GenericRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Chat operations including rooms, messages, read receipts and SSE.
 */
final class ChatService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRooms(AuthenticatedUser $user): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.* FROM `ChatRooms` r
             INNER JOIN `ChatRoomMembers` m ON m.chat_room_id = r.id
             WHERE m.user_id = :userId
             ORDER BY r.updated_at DESC'
        );
        $stmt->execute(['userId' => $user->id]);
        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMessages(AuthenticatedUser $user, string $chatId, string $chatType, int $limit = 50): array
    {
        $this->assertChatAccess($user, $chatId, $chatType);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM `ChatMessages`
             WHERE chat_id = :chatId AND chat_type = :chatType
             ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':chatId', $chatId);
        $stmt->bindValue(':chatType', $chatType);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendMessage(AuthenticatedUser $user, array $data): array
    {
        $chatId = (string) ($data['chat_id'] ?? $data['chatId'] ?? '');
        $chatType = (string) ($data['chat_type'] ?? $data['chatType'] ?? 'direct');
        $this->assertChatAccess($user, $chatId, $chatType);

        $uuid = Uuid::uuid4();
        $binaryId = $uuid->getBytes();

        $stmt = $this->pdo->prepare(
            'INSERT INTO `ChatMessages` (id, user_id, chat_id, chat_type, body, attachment_type, attachment_body, created_at)
             VALUES (:id, :userId, :chatId, :chatType, :body, :attachmentType, :attachmentBody, NOW(6))'
        );
        $stmt->execute([
            'id' => $binaryId,
            'userId' => $user->id,
            'chatId' => $chatId,
            'chatType' => $chatType,
            'body' => (string) ($data['body'] ?? ''),
            'attachmentType' => $data['attachment_type'] ?? $data['attachmentType'] ?? null,
            'attachmentBody' => $data['attachment_body'] ?? $data['attachmentBody'] ?? null,
        ]);

        $this->enqueueSseEvent($user->id, 'message_sent', [
            'chatId' => $chatId,
            'chatType' => $chatType,
        ]);

        return [
            'id' => $uuid->toString(),
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'chat_type' => $chatType,
            'body' => (string) ($data['body'] ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function markRead(AuthenticatedUser $user, string $chatId, string $chatType): void
    {
        $this->assertChatAccess($user, $chatId, $chatType);

        $repo = new GenericRepository($this->pdo, 'chat_read_receipts', []);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM `chat_read_receipts` WHERE user_id = :userId AND chat_id = :chatId AND chat_type = :chatType LIMIT 1'
        );
        $stmt->execute(['userId' => $user->id, 'chatId' => $chatId, 'chatType' => $chatType]);
        $existing = $stmt->fetch();

        if ($existing) {
            $repo->update((string) $existing['id'], ['last_read_at' => date('Y-m-d H:i:s')]);
        } else {
            $repo->create([
                'id' => Uuid::uuid4()->toString(),
                'user_id' => $user->id,
                'chat_id' => $chatId,
                'chat_type' => $chatType,
                'last_read_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    public function unreadCounts(AuthenticatedUser $user): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.chat_id, m.chat_type, COUNT(*) as cnt
             FROM `ChatMessages` m
             LEFT JOIN `chat_read_receipts` r
               ON r.user_id = :userId AND r.chat_id = m.chat_id AND r.chat_type = m.chat_type
             WHERE m.user_id != :userId2
               AND m.created_at > COALESCE(r.last_read_at, "1970-01-01")
             GROUP BY m.chat_id, m.chat_type'
        );
        $stmt->execute(['userId' => $user->id, 'userId2' => $user->id]);
        $rows = $stmt->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['chat_type'] . ':' . $row['chat_id']] = (int) $row['cnt'];
        }
        return $counts;
    }

    public function getOrCreateDirectChat(AuthenticatedUser $user, string $otherUserId): array
    {
        $a = min($user->id, $otherUserId);
        $b = max($user->id, $otherUserId);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM `direct_chats` WHERE user_a_id = :a AND user_b_id = :b LIMIT 1'
        );
        $stmt->execute(['a' => $a, 'b' => $b]);
        $existing = $stmt->fetch();
        if ($existing !== false) {
            return $existing;
        }

        $repo = new GenericRepository($this->pdo, 'direct_chats', []);
        return $repo->create([
            'id' => Uuid::uuid4()->toString(),
            'user_a_id' => $a,
            'user_b_id' => $b,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updatePresence(AuthenticatedUser $user, string $status): array
    {
        $repo = new GenericRepository($this->pdo, 'user_presence', [], 'user_id');
        $existing = $repo->findById($user->id);
        $data = ['status' => $status, 'last_seen_at' => date('Y-m-d H:i:s')];

        if ($existing) {
            return $repo->update($user->id, $data) ?? $existing;
        }

        return $repo->create(array_merge(['user_id' => $user->id], $data));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchPendingSseEvents(AuthenticatedUser $user, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `sse_events` WHERE user_id = :userId AND delivered_at IS NULL
             ORDER BY created_at ASC LIMIT :limit'
        );
        $stmt->bindValue(':userId', $user->id);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $events = $stmt->fetchAll();

        if ($events !== []) {
            $ids = array_column($events, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $mark = $this->pdo->prepare(
                "UPDATE `sse_events` SET delivered_at = NOW() WHERE id IN ({$placeholders})"
            );
            $mark->execute($ids);
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function enqueueSseEvent(string $userId, string $type, array $payload): void
    {
        $repo = new GenericRepository($this->pdo, 'sse_events', []);
        $repo->create([
            'id' => Uuid::uuid4()->toString(),
            'user_id' => $userId,
            'event_type' => $type,
            'payload' => json_encode($payload),
            'delivered_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function assertChatAccess(AuthenticatedUser $user, string $chatId, string $chatType): void
    {
        if ($user->isAdmin) {
            return;
        }

        if ($chatType === 'group') {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM `ChatRoomMembers` WHERE chat_room_id = :chatId AND user_id = :userId LIMIT 1'
            );
            $stmt->execute(['chatId' => $chatId, 'userId' => $user->id]);
            if ($stmt->fetchColumn() === false) {
                throw HttpException::forbidden();
            }
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM `direct_chats` WHERE id = :chatId
             AND (user_a_id = :userId OR user_b_id = :userId2) LIMIT 1'
        );
        $stmt->execute(['chatId' => $chatId, 'userId' => $user->id, 'userId2' => $user->id]);
        if ($stmt->fetchColumn() === false) {
            throw HttpException::forbidden();
        }
    }
}
