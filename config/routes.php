<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Slim\App;
use Sinclear\Api\Application\ResourceRouteRegistrar;
use Sinclear\Api\Http\Controllers\AuthController;
use Sinclear\Api\Http\Controllers\CalendarController;
use Sinclear\Api\Http\Controllers\ChatController;
use Sinclear\Api\Http\Controllers\NotificationController;
use Sinclear\Api\Http\Controllers\PollController;
use Sinclear\Api\Http\Controllers\TravelController;
use Sinclear\Api\Http\Controllers\UserController;
use Sinclear\Api\Http\Middleware\AuthenticationMiddleware;
use Sinclear\Api\Http\Middleware\OptionalAuthenticationMiddleware;
use Sinclear\Api\Http\Middleware\LoginThrottleMiddleware;
use Sinclear\Api\Service\CalendarService;
use Sinclear\Api\Service\ChatService;
use Sinclear\Api\Service\NotificationService;
use Sinclear\Api\Service\PollService;
use Sinclear\Api\Service\UserExportService;

return static function (App $app): void {
    $container = $app->getContainer();
    assert($container instanceof ContainerInterface);

    $authController = $container->get(AuthController::class);
    $loginThrottle = $container->get(LoginThrottleMiddleware::class);
    $authMiddleware = $container->get(AuthenticationMiddleware::class);
    $optionalAuth = $container->get(OptionalAuthenticationMiddleware::class);

    // Auth routes handling both guest and authenticated users
    $app->group('/auth', function ($group) use ($authController): void {
        $group->post('/otp/request', [$authController, 'otpRequest']);
        $group->post('/otp/verify', [$authController, 'otpVerify']);
        $group->post('/logout', [$authController, 'logout']);
    })->add($optionalAuth)->add($loginThrottle);

    // Public auth routes
    $app->group('/auth', function ($group) use ($authController): void {
        $group->post('/login', [$authController, 'login']);
        $group->post('/passkey/login/begin', [$authController, 'passkeyLoginBegin']);
        $group->post('/passkey/login/finish', [$authController, 'passkeyLoginFinish']);
        $group->get('/discord/start', [$authController, 'discordStart']);
        $group->get('/discord/callback', [$authController, 'discordCallback']);
        $group->post('/refresh', [$authController, 'refresh']);
    })->add($loginThrottle);

    // Auth routes requiring access token
    $app->group('/auth', function ($group) use ($authController): void {
        $group->get('/me', [$authController, 'me']);
        $group->post('/passkey/register/begin', [$authController, 'passkeyRegisterBegin']);
        $group->post('/passkey/register/finish', [$authController, 'passkeyRegisterFinish']);
        $group->get('/passkey/list', [$authController, 'passkeyList']);
        $group->delete('/passkey/{id}', [$authController, 'passkeyDelete']);
        $group->delete('/passkey/delete/{id}', [$authController, 'passkeyDelete']);
    })->add($authMiddleware);

    // Specialized domain routes
    $pollController = new PollController($container->get(PollService::class));
    $chatController = new ChatController($container->get(ChatService::class));
    $calendarController = new CalendarController($container->get(CalendarService::class));
    $notificationController = new NotificationController($container->get(NotificationService::class));
    $travelController = new TravelController($container->get(TravelService::class));
    $userController = $container->get(UserController::class);

    $app->group('', function ($group) use (
        $pollController,
        $chatController,
        $calendarController,
        $notificationController,
        $travelController,
        $userController
    ): void {
        $group->post('/polls/{id}/votes', [$pollController, 'vote']);
        $group->post('/polls/{id}/vote', [$pollController, 'vote']);
        $group->post('/polls/{id}/counter-proposals', [$pollController, 'counterProposal']);
        $group->post('/polls/{id}/finalize', [$pollController, 'finalize']);

        $group->get('/chat/rooms', [$chatController, 'rooms']);
        $group->get('/chat/messages', [$chatController, 'messages']);
        $group->post('/chat/messages', [$chatController, 'sendMessage']);
        $group->post('/chat/read', [$chatController, 'markRead']);
        $group->get('/chat/unread', [$chatController, 'unread']);
        $group->get('/chat/direct', [$chatController, 'directChats']);
        $group->post('/chat/direct', [$chatController, 'directChats']);
        $group->get('/chat/presence', [$chatController, 'presence']);
        $group->patch('/chat/presence', [$chatController, 'presence']);
        $group->get('/chat/events/stream', [$chatController, 'sseStream']);

        $group->get('/calendar/combined', [$calendarController, 'combined']);
        $group->get('/notifications/badges', [$notificationController, 'badges']);
        $group->post('/notifications/read-type', [$notificationController, 'readByType']);
        $group->get('/users/{id}/export', [$userController, 'export']);
        $group->get('/subscriptions/user/{userId}', [$userController, 'subscriptions']);
        $group->get('/travel/my-trips', [$travelController, 'myTrips']);
        $group->get('/travel/my-events', [$travelController, 'myEvents']);
        $group->get('/travel/standalone-events', [$travelController, 'standaloneEvents']);
        $group->get('/travel/trips/{id}/details', [$travelController, 'tripDetails']);
        $group->get('/travel/trips/{id}/participants', [$travelController, 'tripParticipants']);
        $group->post('/travel/trips/{id}/participants', [$travelController, 'addParticipant']);
        $group->patch('/travel/trips/{id}/participants/{userId}', [$travelController, 'updateParticipant']);
        $group->delete('/travel/trips/{id}/participants/{userId}', [$travelController, 'removeParticipant']);
        $group->post('/travel/events', [$travelController, 'createEvent']);
        $group->get('/travel/events/{id}', [$travelController, 'getEventDetails']);
        $group->patch('/travel/events/{id}', [$travelController, 'updateEvent']);
        $group->delete('/travel/events/{id}', [$travelController, 'deleteEvent']);

        $group->get('/close-friends/incoming', [$userController, 'incomingCloseFriends']);
        $group->get('/close-friends/{userId}/{friendId}', [$userController, 'isCloseFriend']);
        $group->post('/close-friends/{userId}/{friendId}', [$userController, 'addCloseFriend']);
        $group->delete('/close-friends/{userId}/{friendId}', [$userController, 'removeCloseFriend']);
    })->add($authMiddleware);

    // Generic CRUD for all registered resources
    ResourceRouteRegistrar::register($app, $container);

    // Health check (still requires auth per spec - use auth/me instead)
    $app->get('/health', function ($request, $response) {
        $response->getBody()->write((string) json_encode(['status' => 'ok']));
        return $response->withHeader('Content-Type', 'application/json');
    })->add($authMiddleware);
};
