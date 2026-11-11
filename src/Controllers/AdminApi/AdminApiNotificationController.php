<?php

namespace Sinclear\Api\Controllers\AdminApi;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\FeedbackSuggestionRepository;
use Sinclear\Api\Repository\SubscriptionRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Services\AdminAuditService;
use Sinclear\Api\Services\NotificationService;

final readonly class AdminApiNotificationController
{
    use AdminApiConcern;

    public function __construct(
        private UserRepository $userRepo,
        private TravelTripRepository $tripRepo,
        private TravelEventRepository $eventRepo,
        private SubscriptionRepository $subscriptionRepo,
        private FeedbackSuggestionRepository $feedbackSuggestionRepo,
        private NotificationService $notificationService,
        private AdminAuditService $auditService,
        private PDO $pdo,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $users = array_map(fn(array $u) => [
            'id' => $u['id'],
            'displayName' => $u['displayName'],
            'email' => $u['email'],
        ], $this->userRepo->findAll());

        $trips = array_map(fn(array $t) => [
            'id' => $t['id'],
            'name' => $t['name'],
        ], $this->tripRepo->findAll());

        $events = array_map(fn(array $e) => [
            'id' => $e['ID'],
            'name' => $e['name'],
        ], $this->eventRepo->findAll());

        $subscriptions = array_map(fn(array $s) => [
            'id' => $s['id'],
            'name' => $s['name'],
        ], $this->subscriptionRepo->findAll());

        $feedbackResult = $this->feedbackSuggestionRepo->list(1, 100);
        $feedback = array_map(fn(array $s) => [
            'id' => $s['id'],
            'title' => $s['title'],
        ], $feedbackResult['data']);

        $calendarStmt = $this->pdo->query('SELECT id, title FROM CalendarEvent ORDER BY createdAt DESC LIMIT 100');
        $calendarEvents = array_map(fn(array $e) => [
            'id' => $e['id'],
            'title' => $e['title'],
        ], $calendarStmt->fetchAll(PDO::FETCH_ASSOC));

        $forumStmt = $this->pdo->query('SELECT id, forumId, userId, type, content FROM FeedPosts ORDER BY createdAt DESC LIMIT 100');
        $forumPosts = array_map(static function (array $p): array {
            $content = json_decode($p['content'], true);
            $label = match ($p['type']) {
                'text' => $content['text'] ?? '(Text-Beitrag)',
                'music' => 'Musik-Beitrag',
                'video' => 'Video-Beitrag',
                'web' => $content['urls'][0] ?? '(Web-Beitrag)',
                default => '(Beitrag)',
            };
            return [
                'id' => $p['id'],
                'forumId' => $p['forumId'],
                'userId' => $p['userId'],
                'title' => mb_strimwidth($label, 0, 60, '...'),
            ];
        }, $forumStmt->fetchAll(PDO::FETCH_ASSOC));

        $pollStmt = $this->pdo->query('SELECT id, title FROM Poll ORDER BY createdAt DESC LIMIT 100');
        $polls = array_map(fn(array $p) => [
            'id' => $p['id'],
            'title' => $p['title'],
        ], $pollStmt->fetchAll(PDO::FETCH_ASSOC));

        return ResponseFactory::json([
            'users' => $users,
            'trips' => $trips,
            'events' => $events,
            'subscriptions' => $subscriptions,
            'feedback' => $feedback,
            'calendarEvents' => $calendarEvents,
            'forumPosts' => $forumPosts,
            'polls' => $polls,
        ], 200, $response);
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $userId = trim((string) ($body['userId'] ?? ''));
        $type = trim((string) ($body['type'] ?? ''));
        $title = trim((string) ($body['title'] ?? ''));
        $bodyText = trim((string) ($body['body'] ?? ''));
        $data = $body['data'] ?? null;

        if ($userId === '' || $type === '') {
            return ResponseFactory::json(['error' => 'missing_required_fields'], 400, $response);
        }

        $targetUser = $this->userRepo->findById($userId);
        if ($targetUser === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        if ($data !== null && !is_array($data)) {
            return ResponseFactory::json(['error' => 'data_invalid'], 400, $response);
        }

        try {
            $notificationId = $this->notificationService->create(
                userId: $userId,
                type: $type,
                title: $title,
                body: $bodyText,
                data: $data,
                respectPreferences: false,
            );
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }

        $this->auditService->record($user, 'send', 'notification', $notificationId, $request);

        return ResponseFactory::json([
            'success' => true,
            'notificationId' => $notificationId,
            'sentTo' => $targetUser['displayName'],
        ], 201, $response);
    }
}
