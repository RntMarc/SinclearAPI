<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Slim\App;
use Sinclear\Api\Application\ResourceRouteRegistrar;
use Sinclear\Api\Http\Controllers\AuthController;
use Sinclear\Api\Http\Controllers\CalendarController;
use Sinclear\Api\Http\Controllers\ChatController;
use Sinclear\Api\Http\Controllers\DiscoverController;
use Sinclear\Api\Http\Controllers\FeedbackController;
use Sinclear\Api\Http\Controllers\NotificationController;
use Sinclear\Api\Http\Controllers\PollController;
use Sinclear\Api\Http\Controllers\EventController;
use Sinclear\Api\Http\Controllers\ForumController;
use Sinclear\Api\Http\Controllers\HomeController;
use Sinclear\Api\Http\Controllers\MediaController;
use Sinclear\Api\Http\Controllers\NewsController;
use Sinclear\Api\Http\Controllers\RecipeController;
use Sinclear\Api\Http\Controllers\SocialController;
use Sinclear\Api\Http\Controllers\TravelController;
use Sinclear\Api\Http\Controllers\UserController;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Http\Middleware\AuthenticationMiddleware;
use Sinclear\Api\Http\Middleware\OptionalAuthenticationMiddleware;
use Sinclear\Api\Http\Middleware\LoginThrottleMiddleware;
use Sinclear\Api\Service\CalendarService;
use Sinclear\Api\Service\ChatService;
use Sinclear\Api\Service\EventService;
use Sinclear\Api\Service\NewsService;
use Sinclear\Api\Service\NotificationService;
use Sinclear\Api\Service\PollService;
use Sinclear\Api\Service\TravelService;
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
        $group->get('/discord/find-user', [$authController, 'discordFindUser']);
        $group->post('/discord/issue-token', [$authController, 'discordIssueToken']);
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
    $pollController = new PollController($container->get(PollService::class), $container->get(\PDO::class));
    $chatController = new ChatController($container->get(ChatService::class));
    $calendarController = new CalendarController($container->get(CalendarService::class));
    $notificationController = new NotificationController($container->get(NotificationService::class));
    $travelController = new TravelController($container->get(TravelService::class));
    $eventController = new EventController($container->get(EventService::class));
    $forumController = new ForumController($container->get(\PDO::class));
    $homeController = new HomeController($container->get(\PDO::class));
    $newsController = new NewsController($container->get(NewsService::class));
    $socialController = new SocialController($container->get(\PDO::class), $container->get(CloseFriendRepository::class));
    $mediaController = new MediaController($container->get(\PDO::class));
    $discoverController = new DiscoverController($container->get(\PDO::class));
    $recipeController = new RecipeController($container->get(\PDO::class));
    $feedbackController = new FeedbackController($container->get(\PDO::class));
    $userController = $container->get(UserController::class);

    $app->group('', function ($group) use (
        $pollController,
        $chatController,
        $calendarController,
        $notificationController,
        $travelController,
        $eventController,
        $forumController,
        $homeController,
        $mediaController,
        $discoverController,
        $recipeController,
        $feedbackController,
        $socialController,
        $newsController,
        $userController
    ): void {
        $group->post('/polls/{id}/votes', [$pollController, 'vote']);
        $group->post('/polls/{id}/vote', [$pollController, 'vote']);
        $group->post('/polls/{id}/counter-proposals', [$pollController, 'counterProposal']);
        $group->post('/polls/{id}/finalize', [$pollController, 'finalize']);
        $group->get('/polls/list', [$pollController, 'list']);
        $group->get('/polls/{id}/detail', [$pollController, 'detail']);
        $group->post('/polls', [$pollController, 'create']);
        $group->patch('/polls/{id}', [$pollController, 'update']);
        $group->delete('/polls/{id}', [$pollController, 'delete']);

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
        $group->post('/events', [$eventController, 'create']);
        $group->put('/events/{id}', [$eventController, 'update']);
        $group->patch('/events/{id}', [$eventController, 'update']);
        $group->delete('/events/{id}', [$eventController, 'delete']);
        $group->get('/events/{id}/permissions', [$eventController, 'listPermissions']);
        $group->post('/events/{id}/permissions', [$eventController, 'setPermissions']);
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

        $group->get('/forums/my', [$forumController, 'myForums']);
        $group->get('/forums/{forumId}/posts', [$forumController, 'forumPosts']);
        $group->get('/forums/{forumId}/detail', [$forumController, 'forumDetail']);
        $group->get('/home/media-reviews', [$homeController, 'recentMediaReviews']);
        $group->get('/home/discover-reviews', [$homeController, 'recentDiscoverReviews']);
        $group->get('/home/polls', [$homeController, 'homePolls']);
        $group->get('/home/feed-posts', [$homeController, 'homeFeedPosts']);
        $group->get('/home/feed-posts-list', [$homeController, 'feedPostsList']);
        $group->get('/home/birthdays', [$homeController, 'birthdays']);
        $group->get('/media/list', [$mediaController, 'list']);
        $group->get('/media/{id}/detail', [$mediaController, 'detail']);
        $group->get('/media/{id}/reviews', [$mediaController, 'reviews']);
        $group->post('/media/{id}/reviews', [$mediaController, 'upsertReview']);
        $group->get('/discover/list', [$discoverController, 'list']);
        $group->get('/discover/random', [$discoverController, 'random']);
        $group->get('/discover/map', [$discoverController, 'map']);
        $group->get('/discover/bookmarked', [$discoverController, 'bookmarked']);
        $group->get('/discover/places-search', [$discoverController, 'search']);
        $group->get('/discover/{id}/detail', [$discoverController, 'detail']);
        $group->get('/recipes/list', [$recipeController, 'list']);
        $group->get('/recipes/{id}/detail', [$recipeController, 'detail']);
        $group->get('/feedback/list', [$feedbackController, 'list']);
        $group->get('/social-info/unsplash-visible', [$socialController, 'visibleUnsplashHandles']);

        $group->get('/rss-sources', [$newsController, 'listRssSources']);
        $group->post('/rss-sources', [$newsController, 'createRssSource']);
        $group->patch('/rss-sources/{id}', [$newsController, 'updateRssSource']);
        $group->delete('/rss-sources/{id}', [$newsController, 'deleteRssSource']);
        $group->get('/news/important', [$newsController, 'important']);
        $group->get('/news/archived', [$newsController, 'archived']);
        $group->post('/news/upvote', [$newsController, 'upvoteArticle']);
        $group->get('/news/upvoted', [$newsController, 'upvotedUrls']);
        $group->get('/news/upvote-counts', [$newsController, 'upvoteCounts']);

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
