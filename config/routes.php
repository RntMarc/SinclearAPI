<?php

use Psr\Container\ContainerInterface;
use Sinclear\Api\Controllers\AdminController;
use Sinclear\Api\Controllers\AuthController;
use Sinclear\Api\Controllers\CalendarEventController;
use Sinclear\Api\Controllers\ExploreController;
use Sinclear\Api\Controllers\NotificationController;
use Sinclear\Api\Controllers\ProfileController;
use Sinclear\Api\Controllers\ReviewController;
use Sinclear\Api\Controllers\TravelController;
use Sinclear\Api\Controllers\UserController;
use Sinclear\Api\Middleware\AdminMiddleware;
use Sinclear\Api\Middleware\AuthenticationMiddleware;
use Sinclear\Api\Middleware\LoginThrottleMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $container = $app->getContainer();
    $app->setBasePath('/api/v2');

    $app->group('/auth', function (RouteCollectorProxy $group) use ($container) {
        $group->group('/login', function (RouteCollectorProxy $login) use ($container) {

            $login->group('/otp', function (RouteCollectorProxy $otp) use ($container) {
                $otp->post('/request', [AuthController::class, 'loginOtpRequest']);
                $otp->post('/verify', [AuthController::class, 'loginOtpVerify']);
            })->add($container->get(LoginThrottleMiddleware::class));

            $login->post('/discord/start', [AuthController::class, 'loginDiscordStart']);
            $login->get('/discord/callback', [AuthController::class, 'loginDiscordCallback']);

        });

        $group->post('/refresh', [AuthController::class, 'refresh'])
            ->add($container->get(LoginThrottleMiddleware::class));

        $group->group('/register', function (RouteCollectorProxy $register) use ($container) {
            $register->post('/discord/start', [AuthController::class, 'registerDiscordStart']);
            $register->get('/discord/callback', [AuthController::class, 'registerDiscordCallback']);
        });
    });

    $app->group('/explore', function (RouteCollectorProxy $group) use ($container) {
        $group->get('/search', [ExploreController::class, 'search']);
        $group->get('/random', [ExploreController::class, 'random']);
        $group->get('/bookmarks', [ExploreController::class, 'listBookmarks']);
        $group->get('', [ExploreController::class, 'list']);
        $group->post('', [ExploreController::class, 'create']);
        $group->get('/{placeId}/reviews', [ReviewController::class, 'list']);
        $group->post('/{placeId}/reviews', [ReviewController::class, 'create']);
        $group->put('/{placeId}/reviews/{reviewId}', [ReviewController::class, 'update']);
        $group->delete('/{placeId}/reviews/{reviewId}', [ReviewController::class, 'delete']);
        $group->get('/{id}', [ExploreController::class, 'get']);
        $group->put('/{id}', [ExploreController::class, 'update']);
        $group->delete('/{id}', [ExploreController::class, 'delete']);
        $group->get('/{id}/bookmark', [ExploreController::class, 'getBookmark']);
        $group->post('/{id}/bookmark', [ExploreController::class, 'setBookmark']);
        $group->delete('/{id}/bookmark', [ExploreController::class, 'removeBookmark']);
    })->add($container->get(AuthenticationMiddleware::class));

    $app->group('/user', function (RouteCollectorProxy $group) {
        $group->get('', [UserController::class, 'list']);
        $group->get('/me', [UserController::class, 'me']);
        $group->get('/me/base', [UserController::class, 'meBase']);
        $group->get('/me/social', [UserController::class, 'meSocial']);
        $group->get('/me/contact', [UserController::class, 'meContact']);
        $group->put('/me/profile', [ProfileController::class, 'update']);
        $group->post('/me/email/request', [ProfileController::class, 'requestEmailChange']);
        $group->post('/me/email/verify', [ProfileController::class, 'verifyEmailChange']);
        $group->post('/me/discord/start', [ProfileController::class, 'startDiscordRelink']);
        $group->get('/me/discord/callback', [ProfileController::class, 'discordCallback']);
        $group->post('/me/discord/verify', [ProfileController::class, 'verifyDiscordRelink']);
        $group->put('/me/visibility', [ProfileController::class, 'updateVisibility']);
        $group->put('/me/onboarding/complete', [ProfileController::class, 'completeOnboarding']);
        $group->get('/{userId}', [UserController::class, 'get']);
        $group->get('/{userId}/base', [UserController::class, 'getBase']);
        $group->get('/{userId}/social', [UserController::class, 'getSocial']);
        $group->get('/{userId}/contact', [UserController::class, 'getContact']);
    })->add($container->get(AuthenticationMiddleware::class));

    $app->group('/trips', function (RouteCollectorProxy $group) {
        $group->get('', [TravelController::class, 'listTrips']);

        // Standalone-Events MUST come before /{id} to avoid route capture
        $group->get('/standaloneevents', [TravelController::class, 'listStandaloneEvents']);
        $group->get('/standaloneevents/{eventId}', [TravelController::class, 'getStandaloneEvent']);

        $group->get('/{id}', [TravelController::class, 'getTrip']);
        $group->get('/{id}/events', [TravelController::class, 'listEvents']);
        $group->get('/{id}/events/{eventId}', [TravelController::class, 'getEvent']);
        $group->get('/{id}/accommodations', [TravelController::class, 'listAccommodations']);
        $group->get('/{id}/accommodations/{accommodationId}', [TravelController::class, 'getAccommodation']);
        $group->get('/{id}/participants', [TravelController::class, 'listParticipants']);
    })->add($container->get(AuthenticationMiddleware::class));

    $app->group('/calendar', function (RouteCollectorProxy $group) {
        $group->get('', [CalendarEventController::class, 'list']);
        $group->post('', [CalendarEventController::class, 'create']);

        $group->get('/{id}', [CalendarEventController::class, 'get']);
        $group->put('/{id}', [CalendarEventController::class, 'update']);
        $group->delete('/{id}', [CalendarEventController::class, 'delete']);

        $group->post('/{id}/participants', [CalendarEventController::class, 'addParticipant']);
        $group->delete('/{id}/participants/{userId}', [CalendarEventController::class, 'removeParticipant']);
    })->add($container->get(AuthenticationMiddleware::class));

    $app->group('/notifications', function (RouteCollectorProxy $group) {
        $group->get('', [NotificationController::class, 'list']);
        $group->delete('', [NotificationController::class, 'markAllRead']);

        // Static routes MUST come before /{id} to avoid route capture
        $group->get('/devices', [NotificationController::class, 'listDevices']);
        $group->post('/devices', [NotificationController::class, 'registerDevice']);
        $group->delete('/devices/{deviceId}', [NotificationController::class, 'unregisterDevice']);

        $group->get('/{id}', [NotificationController::class, 'get']);
        $group->delete('/{id}', [NotificationController::class, 'markRead']);
    })->add($container->get(AuthenticationMiddleware::class));

    // Admin routes (unprotected login/logout)
    $app->get('/admin/login', [AdminController::class, 'loginPage']);
    $app->post('/admin/login/otp/request', [AdminController::class, 'loginOtpRequest']);
    $app->post('/admin/login/otp/verify', [AdminController::class, 'loginOtpVerify']);
    $app->get('/admin/logout', [AdminController::class, 'logout']);

    // Admin routes (protected)
    $app->group('/admin', function (RouteCollectorProxy $group) {
        $group->get('[/]', [AdminController::class, 'dashboard']);
        $group->get('/users', [AdminController::class, 'users']);
        $group->get('/users/json', [AdminController::class, 'adminUsersJson']);
        $group->get('/travel', [AdminController::class, 'travel']);
        $group->get('/notifications', [AdminController::class, 'notifications']);
        $group->post('/notifications/send', [AdminController::class, 'sendNotification']);
    })->add($container->get(AdminMiddleware::class));
};
