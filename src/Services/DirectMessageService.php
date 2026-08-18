<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\ChatConversationRepository;
use Sinclear\Api\Repository\ChatParticipantRepository;
use Sinclear\Api\Repository\ChatPresenceRepository;
use Sinclear\Api\Repository\ChatTypingRepository;
use Sinclear\Api\Repository\DirectMessageRepository;
use Sinclear\Api\Repository\UserRepository;

final readonly class DirectMessageService
{
    private const int MAX_CONTENT_LENGTH = 2000;
    private const int EDIT_WINDOW_SECONDS = 600; // 10 minutes
    private const int MESSAGES_PER_PAGE = 50;
    private const int SYNC_LIMIT = 200;

    private const array VALID_TYPES = ['text'];

    public function __construct(
        private ChatConversationRepository $conversationRepo,
        private ChatParticipantRepository $participantRepo,
        private DirectMessageRepository $messageRepo,
        private ChatPresenceRepository $presenceRepo,
        private ChatTypingRepository $typingRepo,
        private UserRepository $userRepo,
        private NotificationService $notificationService,
    ) {}

    /**
     * List conversations for the current user.
     */
    public function listConversations(string $userId, int $page = 1, int $limit = 20): array
    {
        $limit = min(100, max(1, $limit));
        $offset = max(0, ($page - 1) * $limit);
        $total = $this->conversationRepo->countForUser($userId);
        $conversations = $this->conversationRepo->listForUser($userId, $limit, $offset);

        $data = array_map(function (array $row) {
            $lastMessage = null;
            if ($row['lastMessageCreatedAt'] !== null) {
                $lastMessage = [
                    'content' => $row['lastMessageDeletedAt'] !== null ? '' : $row['lastMessageContent'],
                    'senderId' => $row['lastMessageSenderId'],
                    'createdAt' => self::stripFractionalSeconds($row['lastMessageCreatedAt']),
                    'deleted' => $row['lastMessageDeletedAt'] !== null,
                ];
            }

            return [
                'id' => $row['id'],
                'type' => $row['type'],
                'name' => $row['name'],
                'otherUser' => [
                    'id' => $row['otherUserId'],
                    'displayName' => $row['otherUserDisplayName'] ?? null,
                    'avatar' => $row['otherUserImage'] ?? null,
                ],
                'lastMessage' => $lastMessage,
                'unreadCount' => (int) $row['unreadCount'],
                'lastSeenAt' => $row['lastSeenAt'] !== null ? self::stripFractionalSeconds($row['lastSeenAt']) : null,
                'updatedAt' => self::stripFractionalSeconds($row['updatedAt']),
            ];
        }, $conversations);

        $totalPages = (int) ceil($total / $limit);

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * Open (get-or-create) a 1:1 conversation with another user.
     */
    public function openConversation(string $userId, string $otherUserId): array
    {
        if ($userId === $otherUserId) {
            throw new \RuntimeException('cannot_chat_self');
        }

        $otherUser = $this->userRepo->findById($otherUserId);
        if ($otherUser === null) {
            throw new \RuntimeException('user_not_found');
        }

        // Check for existing conversation
        $existing = $this->conversationRepo->findDirectConversation($userId, $otherUserId);
        if ($existing !== null) {
            return $this->formatConversation($existing, $userId);
        }

        // Create new conversation
        $conversationId = $this->conversationRepo->create('direct');
        $this->participantRepo->add($conversationId, $userId);
        $this->participantRepo->add($conversationId, $otherUserId);

        $conversation = $this->conversationRepo->findById($conversationId);
        return $this->formatConversation($conversation, $userId);
    }

    /**
     * Get a single conversation with details.
     */
    public function getConversation(string $userId, string $conversationId): array
    {
        $conversation = $this->conversationRepo->findById($conversationId);
        if ($conversation === null) {
            throw new \RuntimeException('conversation_not_found');
        }

        return $this->formatConversation($conversation, $userId);
    }

    /**
     * Get messages for a conversation (cursor-based pagination).
     */
    public function getMessages(string $userId, string $conversationId, ?int $beforeSeq = null, int $limit = self::MESSAGES_PER_PAGE): array
    {
        $limit = min(100, max(1, $limit));
        $before = $beforeSeq ?? 0;
        $messages = $this->messageRepo->findByConversation($conversationId, $before, $limit);

        $data = array_map(function (array $row) {
            return $this->formatMessage($row);
        }, $messages);

        // Update lastSeenAt
        $this->participantRepo->updateLastSeenAt($conversationId, $userId);

        return [
            'data' => $data,
            'meta' => [
                'hasMore' => count($data) === $limit,
            ],
        ];
    }

    /**
     * Send a new message.
     */
    public function sendMessage(string $userId, string $conversationId, array $body): array
    {
        $conversation = $this->conversationRepo->findById($conversationId);
        if ($conversation === null) {
            throw new \RuntimeException('conversation_not_found');
        }

        $content = trim((string) ($body['content'] ?? ''));
        if ($content === '') {
            throw new \RuntimeException('content_required');
        }
        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new \RuntimeException('content_too_long');
        }

        $type = $body['type'] ?? 'text';
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \RuntimeException('invalid_type');
        }

        $clientId = $body['clientId'] ?? null;
        if ($clientId !== null) {
            $clientId = trim((string) $clientId);
            if ($clientId === '') {
                $clientId = null;
            }
        }

        // Idempotency check
        if ($clientId !== null) {
            $existing = $this->messageRepo->findByClientId($userId, $clientId);
            if ($existing !== null) {
                $msg = $this->messageRepo->findById($existing['id']);
                return $this->formatMessage($msg);
            }
        }

        $result = $this->messageRepo->create([
            'conversationId' => $conversationId,
            'senderId' => $userId,
            'type' => $type,
            'content' => $content,
            'payload' => $body['payload'] ?? null,
            'clientId' => $clientId,
        ]);

        $this->conversationRepo->updateTimestamp($conversationId);

        $message = $this->messageRepo->findById($result['id']);
        $formatted = $this->formatMessage($message);

        // Send notification to other participants (async, fire-and-forget)
        $this->notifyParticipants($userId, $conversationId, $formatted, $conversation);

        return $formatted;
    }

    /**
     * Edit a message (within 10-minute window).
     */
    public function editMessage(string $userId, string $messageId, string $newContent): array
    {
        $message = $this->messageRepo->findById($messageId);
        if ($message === null) {
            throw new \RuntimeException('message_not_found');
        }

        if ($message['senderId'] !== $userId) {
            throw new \RuntimeException('forbidden');
        }

        if ($message['deletedAt'] !== null) {
            throw new \RuntimeException('message_deleted');
        }

        $createdAt = new \DateTimeImmutable($message['createdAt'], new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $elapsed = $now->getTimestamp() - $createdAt->getTimestamp();
        if ($elapsed > self::EDIT_WINDOW_SECONDS) {
            throw new \RuntimeException('edit_window_expired');
        }

        $newContent = trim($newContent);
        if ($newContent === '') {
            throw new \RuntimeException('content_required');
        }
        if (mb_strlen($newContent) > self::MAX_CONTENT_LENGTH) {
            throw new \RuntimeException('content_too_long');
        }

        $this->messageRepo->updateContent($messageId, $newContent);

        $updated = $this->messageRepo->findById($messageId);
        return $this->formatMessage($updated);
    }

    /**
     * Delete a message (for all). Clears content/payload, sets deletedAt.
     */
    public function deleteMessage(string $userId, string $messageId): void
    {
        $message = $this->messageRepo->findById($messageId);
        if ($message === null) {
            throw new \RuntimeException('message_not_found');
        }

        if ($message['senderId'] !== $userId) {
            throw new \RuntimeException('forbidden');
        }

        if ($message['deletedAt'] !== null) {
            return; // Already deleted, idempotent
        }

        $this->messageRepo->markDeleted($messageId, $userId);
    }

    /**
     * Set read status for a conversation.
     */
    public function markRead(string $userId, string $conversationId, int $seq): void
    {
        $this->participantRepo->updateLastReadSeq($conversationId, $userId, $seq);
    }

    /**
     * Set typing indicator.
     */
    public function setTyping(string $userId, string $conversationId, bool $typing): void
    {
        if ($typing) {
            $this->typingRepo->touch($conversationId, $userId);
        } else {
            $this->typingRepo->clear($conversationId, $userId);
        }
    }

    /**
     * Sync endpoint: get all new data since a seq cursor.
     * Returns new messages across all conversations, read receipts, and typing states.
     */
    public function sync(string $userId, int $afterSeq = 0, int $limit = self::SYNC_LIMIT): array
    {
        // Touch presence (push suppression)
        $this->presenceRepo->touchActiveUntil($userId);

        $limit = min(500, max(1, $limit));

        // Get new messages across all conversations
        $messages = $this->messageRepo->findNewForUser($userId, $afterSeq, $limit);

        // Get typing states
        $typingMap = $this->typingRepo->findTypingForUser($userId);

        // Get conversations with updated read status
        $conversations = $this->conversationRepo->listForUser($userId, 100, 0);

        $conversationUpdates = array_map(function (array $conv) use ($userId) {
            return [
                'conversationId' => $conv['id'],
                'unreadCount' => (int) $conv['unreadCount'],
                'lastSeenAt' => $conv['lastSeenAt'] !== null ? self::stripFractionalSeconds($conv['lastSeenAt']) : null,
            ];
        }, $conversations);

        $newMaxSeq = $afterSeq;
        $formattedMessages = array_map(function (array $row) use (&$newMaxSeq) {
            if ((int) $row['seq'] > $newMaxSeq) {
                $newMaxSeq = (int) $row['seq'];
            }
            return $this->formatMessage($row);
        }, $messages);

        return [
            'data' => [
                'messages' => $formattedMessages,
                'conversations' => $conversationUpdates,
                'typing' => $typingMap,
            ],
            'meta' => [
                'seq' => $newMaxSeq,
                'hasMore' => count($messages) === $limit,
            ],
        ];
    }

    private function formatConversation(array $conversation, string $userId): array
    {
        $participant = $this->participantRepo->find($conversation['id'], $userId);
        $otherParticipant = $this->participantRepo->findOtherParticipant($conversation['id'], $userId);

        return [
            'id' => $conversation['id'],
            'type' => $conversation['type'],
            'name' => $conversation['name'],
            'otherUser' => $otherParticipant !== null ? [
                'id' => $otherParticipant['userId'],
                'displayName' => $otherParticipant['displayName'] ?? null,
                'avatar' => $otherParticipant['userImage'] ?? null,
            ] : null,
            'lastReadSeq' => $participant !== null ? (int) $participant['lastReadSeq'] : 0,
            'createdAt' => self::stripFractionalSeconds($conversation['createdAt']),
            'updatedAt' => self::stripFractionalSeconds($conversation['updatedAt']),
        ];
    }

    private function formatMessage(array $message): array
    {
        $payload = null;
        if (isset($message['payload']) && $message['payload'] !== null) {
            $payload = is_string($message['payload'])
                ? json_decode($message['payload'], true)
                : $message['payload'];
        }

        return [
            'id' => $message['id'],
            'seq' => (int) $message['seq'],
            'conversationId' => $message['conversationId'],
            'senderId' => $message['senderId'],
            'sender' => [
                'id' => $message['senderId'],
                'displayName' => $message['senderDisplayName'] ?? null,
                'avatar' => $message['senderImage'] ?? null,
            ],
            'type' => $message['type'],
            'content' => $message['deletedAt'] !== null ? '' : $message['content'],
            'payload' => $message['deletedAt'] !== null ? null : $payload,
            'clientId' => $message['clientId'],
            'editedAt' => $message['editedAt'] !== null ? self::stripFractionalSeconds($message['editedAt']) : null,
            'deleted' => $message['deletedAt'] !== null,
            'createdAt' => self::stripFractionalSeconds($message['createdAt']),
        ];
    }

    private function notifyParticipants(string $senderId, string $conversationId, array $formattedMessage, array $conversation): void
    {
        $participants = $this->participantRepo->findByConversation($conversationId);

        $sender = $this->userRepo->findById($senderId);
        $senderName = $sender['displayName'] ?? 'Jemand';

        foreach ($participants as $participant) {
            if ($participant['userId'] === $senderId) {
                continue;
            }

            // Push suppression: skip if recipient is active (polling)
            if ($this->presenceRepo->isActive($participant['userId'])) {
                continue;
            }

            $this->notificationService->create(
                userId: $participant['userId'],
                type: 'direct_message',
                title: '',
                body: '',
                data: [
                    ['relation' => 'sender', 'object' => 'User', 'identifier' => $senderId],
                    ['relation' => 'conversation', 'object' => 'ChatConversation', 'identifier' => $conversationId],
                    ['relation' => 'message', 'object' => 'DirectMessage', 'identifier' => $formattedMessage['id']],
                ],
                dedupeKey: 'chat:' . $conversationId,
            );
        }
    }

    private static function stripFractionalSeconds(string $datetime): string
    {
        $dot = strpos($datetime, '.');
        return $dot !== false ? substr($datetime, 0, $dot) : $datetime;
    }
}
