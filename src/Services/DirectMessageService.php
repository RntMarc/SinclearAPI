<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\ChatConversationRepository;
use Sinclear\Api\Repository\ChatEventRepository;
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
    private const int SEND_RATE_LIMIT = 20;       // Nachrichten pro Minute
    private const int SEND_RATE_WINDOW = 60;
    private const int TYPING_RATE_LIMIT = 30;     // Tippindikatoren pro Minute
    private const int TYPING_RATE_WINDOW = 60;
    private const int NOTIFICATION_PREVIEW_LENGTH = 160;

    private const array VALID_TYPES = ['text'];

    public function __construct(
        private ChatConversationRepository $conversationRepo,
        private ChatParticipantRepository $participantRepo,
        private DirectMessageRepository $messageRepo,
        private ChatPresenceRepository $presenceRepo,
        private ChatTypingRepository $typingRepo,
        private ChatEventRepository $eventRepo,
        private UserRepository $userRepo,
        private NotificationService $notificationService,
        private RateLimiter $rateLimiter,
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

            $isGroup = $row['type'] === 'group';

            return [
                'id' => $row['id'],
                'type' => $row['type'],
                'name' => $row['name'],
                'otherUser' => !$isGroup && $row['otherUserId'] !== null ? [
                    'id' => $row['otherUserId'],
                    'displayName' => $row['otherUserDisplayName'] ?? null,
                    'avatar' => $row['otherUserImage'] ?? null,
                ] : null,
                'lastMessage' => $lastMessage,
                'unreadCount' => (int) $row['unreadCount'],
                'lastSeenAt' => !$isGroup && $row['lastSeenAt'] !== null ? self::stripFractionalSeconds($row['lastSeenAt']) : null,
                'lastReadSeq' => (int) $row['lastReadSeq'],
                'otherLastReadSeq' => !$isGroup ? (int) ($row['otherLastReadSeq'] ?? 0) : null,
                'memberCount' => $isGroup ? $this->conversationRepo->countParticipants($row['id']) : null,
                'createdAt' => self::stripFractionalSeconds($row['createdAt']),
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
     *
     * @return array{conversation: array, created: bool}
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
            return [
                'conversation' => $this->formatConversation($existing, $userId),
                'created' => false,
            ];
        }

        // Create new conversation
        $conversationId = $this->conversationRepo->create('direct');
        $this->participantRepo->add($conversationId, $userId);
        $this->participantRepo->add($conversationId, $otherUserId);

        $conversation = $this->conversationRepo->findById($conversationId);
        return [
            'conversation' => $this->formatConversation($conversation, $userId),
            'created' => true,
        ];
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

        if (isset($body['payload'])) {
            throw new \RuntimeException('invalid_payload');
        }

        if (!$this->rateLimiter->isAllowed('chat_send:' . $userId, self::SEND_RATE_LIMIT, self::SEND_RATE_WINDOW)) {
            throw new \RuntimeException('rate_limit_exceeded');
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
            $existing = $this->messageRepo->findByClientId($conversationId, $userId, $clientId);
            if ($existing !== null) {
                $msg = $this->messageRepo->findById($existing['id']);
                return $this->formatMessage($msg);
            }
        }

        try {
            $result = $this->messageRepo->create([
                'conversationId' => $conversationId,
                'senderId' => $userId,
                'type' => $type,
                'content' => $content,
                'payload' => null,
                'clientId' => $clientId,
            ]);
        } catch (\PDOException $e) {
            // Unique (senderId, clientId): concurrent duplicate → return existing
            if ($clientId !== null && $e->getCode() === '23000') {
                $existing = $this->messageRepo->findByClientId($conversationId, $userId, $clientId);
                if ($existing !== null) {
                    $msg = $this->messageRepo->findById($existing['id']);
                    return $this->formatMessage($msg);
                }
            }
            throw $e;
        }

        $this->conversationRepo->updateTimestamp($conversationId);
        $this->eventRepo->create($conversationId, $userId, 'message_created', $result['id']);

        $message = $this->messageRepo->findById($result['id']);
        $formatted = $this->formatMessage($message);

        // Send notification to other participants
        $this->notifyParticipants($userId, $conversationId, $formatted);

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
        $this->eventRepo->create($message['conversationId'], $userId, 'message_edited', $messageId);

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
        $this->eventRepo->create($message['conversationId'], $userId, 'message_deleted', $messageId);
    }

    /**
     * Set read status for a conversation.
     */
    public function markRead(string $userId, string $conversationId, int $seq): void
    {
        $maxSeq = $this->messageRepo->getMaxSeq($conversationId);
        $seq = min(max($seq, 0), $maxSeq);
        $this->participantRepo->updateLastReadSeq($conversationId, $userId, $seq);
    }

    /**
     * Set typing indicator.
     */
    public function setTyping(string $userId, string $conversationId, bool $typing): void
    {
        if (!$this->rateLimiter->isAllowed('chat_typing:' . $userId, self::TYPING_RATE_LIMIT, self::TYPING_RATE_WINDOW)) {
            throw new \RuntimeException('rate_limit_exceeded');
        }

        if ($typing) {
            $this->typingRepo->touch($conversationId, $userId);
        } else {
            $this->typingRepo->clear($conversationId, $userId);
        }
    }

    /**
     * Sync endpoint: get all new data since a seq cursor.
     * Returns new/changed messages (as events), read status, and typing states.
     */
    public function sync(string $userId, int $afterSeq = 0, int $limit = self::SYNC_LIMIT): array
    {
        // Touch presence (push suppression)
        $this->presenceRepo->touchActiveUntil($userId);

        $limit = min(500, max(1, $limit));

        // Get new/changed messages (events) across all conversations
        $events = $this->eventRepo->findNewForUser($userId, $afterSeq, $limit);

        // Get typing states
        $typingMap = $this->typingRepo->findTypingForUser($userId);

        // Get conversations with updated read status
        $conversations = $this->conversationRepo->listForUser($userId, 100, 0);

        $conversationUpdates = array_map(function (array $conv) {
            $isGroup = $conv['type'] === 'group';
            return [
                'conversationId' => $conv['id'],
                'unreadCount' => (int) $conv['unreadCount'],
                'lastSeenAt' => !$isGroup && $conv['lastSeenAt'] !== null ? self::stripFractionalSeconds($conv['lastSeenAt']) : null,
                'otherLastReadSeq' => !$isGroup && $conv['otherLastReadSeq'] !== null ? (int) $conv['otherLastReadSeq'] : null,
            ];
        }, $conversations);

        $newMaxSeq = $afterSeq;
        $formattedEvents = array_map(function (array $row) use (&$newMaxSeq) {
            $eventSeq = (int) $row['eventSeq'];
            if ($eventSeq > $newMaxSeq) {
                $newMaxSeq = $eventSeq;
            }
            return [
                'seq' => $eventSeq,
                'conversationId' => $row['conversationId'],
                'actorId' => $row['actorId'],
                'type' => $row['type'],
                'messageId' => $row['messageId'],
                'message' => $row['messageId'] !== null ? $this->formatMessage($row) : null,
            ];
        }, $events);

        return [
            'data' => [
                'events' => $formattedEvents,
                'conversations' => $conversationUpdates,
                'typing' => $typingMap,
            ],
            'meta' => [
                'seq' => $newMaxSeq,
                'hasMore' => count($events) === $limit,
            ],
        ];
    }

    private function formatConversation(array $conversation, string $userId): array
    {
        $participant = $this->participantRepo->find($conversation['id'], $userId);

        $lastMessage = null;
        $dm = $this->messageRepo->findLastMessage($conversation['id']);
        if ($dm !== null) {
            $lastMessage = [
                'content' => $dm['deletedAt'] !== null ? '' : $dm['content'],
                'senderId' => $dm['senderId'],
                'createdAt' => self::stripFractionalSeconds($dm['createdAt']),
                'deleted' => $dm['deletedAt'] !== null,
            ];
        }

        $isGroup = $conversation['type'] === 'group';
        $otherParticipant = null;
        if (!$isGroup) {
            $otherParticipant = $this->participantRepo->findOtherParticipant($conversation['id'], $userId);
        }

        return [
            'id' => $conversation['id'],
            'type' => $conversation['type'],
            'name' => $conversation['name'],
            'otherUser' => $otherParticipant !== null ? [
                'id' => $otherParticipant['userId'],
                'displayName' => $otherParticipant['displayName'] ?? null,
                'avatar' => $otherParticipant['userImage'] ?? null,
            ] : null,
            'lastMessage' => $lastMessage,
            'unreadCount' => $participant !== null ? (int) $this->messageRepo->countUnread($conversation['id'], $userId, $participant['lastReadSeq']) : 0,
            'lastSeenAt' => !$isGroup && $otherParticipant !== null && $otherParticipant['lastSeenAt'] !== null
                ? self::stripFractionalSeconds($otherParticipant['lastSeenAt']) : null,
            'lastReadSeq' => $participant !== null ? (int) $participant['lastReadSeq'] : 0,
            'otherLastReadSeq' => !$isGroup && $otherParticipant !== null ? (int) $otherParticipant['lastReadSeq'] : null,
            'memberCount' => $isGroup ? $this->conversationRepo->countParticipants($conversation['id']) : null,
            'createdAt' => self::stripFractionalSeconds($conversation['createdAt']),
            'updatedAt' => self::stripFractionalSeconds($conversation['updatedAt']),
        ];
    }

    private function formatMessage(array $message): array
    {
        $payload = null;
        if (isset($message['payload'])) {
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

    private function notifyParticipants(string $senderId, string $conversationId, array $formattedMessage): void
    {
        $participants = $this->participantRepo->findByConversation($conversationId);

        $sender = $this->userRepo->findById($senderId);
        $senderName = $sender['displayName'] ?? 'Jemand';
        $body = $this->buildNotificationBody($senderName, $formattedMessage['content'] ?? '');

        foreach ($participants as $participant) {
            if ($participant['userId'] === $senderId) {
                continue;
            }

            // Push suppression: skip only the push if recipient is actively polling,
            // but still create the (coalesced) in-app notification list entry.
            $suppressPush = $this->presenceRepo->isActive($participant['userId']);

            $this->notificationService->create(
                userId: $participant['userId'],
                type: 'direct_message',
                title: '',
                body: $body,
                data: [
                    ['relation' => 'sender', 'object' => 'User', 'identifier' => $senderId],
                    ['relation' => 'conversation', 'object' => 'ChatConversation', 'identifier' => $conversationId],
                    ['relation' => 'message', 'object' => 'DirectMessage', 'identifier' => $formattedMessage['id']],
                ],
                dedupeKey: 'chat:' . $conversationId,
                suppressPush: $suppressPush,
            );
        }
    }

    private function buildNotificationBody(string $senderName, string $content): string
    {
        $preview = trim(preg_replace('/\s+/', ' ', $content) ?? '');
        if ($preview === '') {
            return $senderName . ' hat dir eine Nachricht geschickt.';
        }
        if (mb_strlen($preview) > self::NOTIFICATION_PREVIEW_LENGTH) {
            $preview = mb_substr($preview, 0, self::NOTIFICATION_PREVIEW_LENGTH) . '…';
        }
        return $senderName . ': ' . $preview;
    }

    private static function stripFractionalSeconds(string $datetime): string
    {
        $dot = strpos($datetime, '.');
        return $dot !== false ? substr($datetime, 0, $dot) : $datetime;
    }
}
