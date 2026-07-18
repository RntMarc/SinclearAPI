<?php

use Psr\Container\ContainerInterface;
use Sinclear\Api\Controllers\AdminController;
use Sinclear\Api\Controllers\AppController;
use Sinclear\Api\Controllers\AuthController;
use Sinclear\Api\Controllers\CalendarEventController;
use Sinclear\Api\Controllers\ExploreController;
use Sinclear\Api\Controllers\FeedbackController;
use Sinclear\Api\Controllers\ForumController;
use Sinclear\Api\Controllers\NotificationController;
use Sinclear\Api\Controllers\ProfileController;
use Sinclear\Api\Controllers\LocationSharingController;
use Sinclear\Api\Controllers\LocationSharingIngressController;
use Sinclear\Api\Controllers\RecipeController;
use Sinclear\Api\Controllers\PtController;
use Sinclear\Api\Controllers\ReviewController;
use Sinclear\Api\Controllers\SubscriptionController;
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

    // Public app routes (no auth required)
    $app->get('/app/version', [AppController::class, 'version']);

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

    $app->group('/recipes', function (RouteCollectorProxy $group) {
        // List bookmarks MUST come before /{id} to avoid route capture
        $group->get('/bookmarks', [RecipeController::class, 'listBookmarks']);

        $group->get('', [RecipeController::class, 'list']);
        $group->post('', [RecipeController::class, 'create']);
        $group->get('/{id}', [RecipeController::class, 'get']);
        $group->patch('/{id}', [RecipeController::class, 'update']);
        $group->delete('/{id}', [RecipeController::class, 'delete']);

        // Bookmarks
        $group->get('/{id}/bookmark', [RecipeController::class, 'getBookmark']);
        $group->post('/{id}/bookmark', [RecipeController::class, 'setBookmark']);
        $group->delete('/{id}/bookmark', [RecipeController::class, 'removeBookmark']);

        // Reviews
        $group->get('/{id}/reviews', [RecipeController::class, 'listReviews']);
        $group->post('/{id}/reviews', [RecipeController::class, 'createReview']);
        $group->patch('/{id}/reviews/{reviewId}', [RecipeController::class, 'updateReview']);
        $group->delete('/{id}/reviews/{reviewId}', [RecipeController::class, 'deleteReview']);
    })->add($container->get(AuthenticationMiddleware::class));

    $app->group('/location-sharing', function (RouteCollectorProxy $group) {
        // Static route MUST come before /{id}
        $group->get('/active', [LocationSharingController::class, 'listActive']);

        $group->get('/sessions', [LocationSharingController::class, 'listSessions']);
        $group->post('/sessions', [LocationSharingController::class, 'create']);
        $group->get('/sessions/{id}', [LocationSharingController::class, 'get']);
        $group->patch('/sessions/{id}', [LocationSharingController::class, 'update']);
        $group->delete('/sessions/{id}', [LocationSharingController::class, 'delete']);

        $group->post('/sessions/{id}/locations', [LocationSharingController::class, 'createLocation']);
        $group->get('/sessions/{id}/locations', [LocationSharingController::class, 'listLocations']);
    })->add($container->get(AuthenticationMiddleware::class));

    $app->group('/location-sharing/log', function (RouteCollectorProxy $group) {
        $group->get('/osmand/{token}[/{name}]', [LocationSharingIngressController::class, 'handleOsmAnd']);
        $group->get('/gpslogger/{token}[/{name}]', [LocationSharingIngressController::class, 'handleGpsLogger']);
        $group->post('/owntracks/{token}[/{name}]', [LocationSharingIngressController::class, 'handleOwntracks']);
        $group->get('/ulogger/{token}[/{name}]', [LocationSharingIngressController::class, 'handleUlogger']);
        $group->get('/traccar/{token}[/{name}]', [LocationSharingIngressController::class, 'handleTraccar']);
        $group->post('/traccar/{token}[/{name}]', [LocationSharingIngressController::class, 'handleTraccar']);
        $group->get('/opengts/{token}[/{name}]', [LocationSharingIngressController::class, 'handleOpenGTS']);
        $group->post('/overland/{token}[/{name}]', [LocationSharingIngressController::class, 'handleOverland']);
        $group->get('/locusmap/{token}[/{name}]', [LocationSharingIngressController::class, 'handleLocusMap']);
        $group->get('/get/{token}[/{name}]', [LocationSharingIngressController::class, 'handleGenericGet']);
        $group->post('/post/{token}[/{name}]', [LocationSharingIngressController::class, 'handleGenericPost']);
    });

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

    $app->group('/subscriptions', function (RouteCollectorProxy $group) {
        $group->get('', [SubscriptionController::class, 'list']);
        $group->get('/{id}', [SubscriptionController::class, 'get']);
        $group->get('/{id}/participants', [SubscriptionController::class, 'getParticipants']);
    })->add($container->get(AuthenticationMiddleware::class));

    $app->group('/feedback', function (RouteCollectorProxy $group) {
        $group->post('/bug-report', [FeedbackController::class, 'bugReport']);
        $group->get('/suggestions', [FeedbackController::class, 'list']);
        $group->post('/suggestions', [FeedbackController::class, 'create']);
        $group->delete('/suggestions/{id}', [FeedbackController::class, 'delete']);
        $group->post('/suggestions/{id}/vote', [FeedbackController::class, 'vote']);
        $group->delete('/suggestions/{id}/vote', [FeedbackController::class, 'removeVote']);
        $group->put('/suggestions/{id}/status', [FeedbackController::class, 'updateStatus']);
        $group->get('/suggestions/{id}/comments', [FeedbackController::class, 'listComments']);
        $group->post('/suggestions/{id}/comments', [FeedbackController::class, 'createComment']);
        $group->put('/suggestions/{id}/comments/{commentId}', [FeedbackController::class, 'updateComment']);
        $group->delete('/suggestions/{id}/comments/{commentId}', [FeedbackController::class, 'deleteComment']);
    })->add($container->get(AuthenticationMiddleware::class));

    $app->group('/forums', function (RouteCollectorProxy $group) {
        // Forum CRUD
        $group->get('', [ForumController::class, 'list']);
        $group->post('', [ForumController::class, 'create']);
        $group->get('/{id}', [ForumController::class, 'get']);
        $group->put('/{id}', [ForumController::class, 'update']);
        $group->delete('/{id}', [ForumController::class, 'delete']);

        // Members
        $group->get('/{id}/members', [ForumController::class, 'listMembers']);
        $group->post('/{id}/members', [ForumController::class, 'join']);
        $group->delete('/{id}/members', [ForumController::class, 'leave']);
        $group->put('/{id}/members/notifications', [ForumController::class, 'updateNotifications']);

        // Posts
        $group->get('/{id}/posts', [ForumController::class, 'listPosts']);
        $group->post('/{id}/posts', [ForumController::class, 'createPost']);
        $group->get('/{id}/posts/{postId}', [ForumController::class, 'getPost']);
        $group->put('/{id}/posts/{postId}', [ForumController::class, 'updatePost']);
        $group->delete('/{id}/posts/{postId}', [ForumController::class, 'deletePost']);

        // Votes
        $group->post('/{id}/posts/{postId}/vote', [ForumController::class, 'vote']);
        $group->delete('/{id}/posts/{postId}/vote', [ForumController::class, 'removeVote']);

        // Comments
        $group->get('/{id}/posts/{postId}/comments', [ForumController::class, 'listComments']);
        $group->post('/{id}/posts/{postId}/comments', [ForumController::class, 'createComment']);
        $group->put('/{id}/posts/{postId}/comments/{commentId}', [ForumController::class, 'updateComment']);
        $group->delete('/{id}/posts/{postId}/comments/{commentId}', [ForumController::class, 'deleteComment']);
    })->add($container->get(AuthenticationMiddleware::class));

    // Public Transport (Transitious API)
    $app->group('/public-transport', function (RouteCollectorProxy $group) {
        // Stations
        $group->get('/stations', [PtController::class, 'searchStations']);
        $group->get('/stations/{id}/departures', [PtController::class, 'getDepartures']);

        // Journey search
        $group->get('/journeys', [PtController::class, 'findJourneys']);

        // Saved journeys
        $group->post('/journeys', [PtController::class, 'saveJourney']);
        $group->get('/journeys/list', [PtController::class, 'listJourneys']);
        $group->get('/journeys/{id}', [PtController::class, 'getJourney']);
        $group->delete('/journeys/{id}', [PtController::class, 'deleteJourney']);

        // Refresh
        $group->post('/journeys/{id}/refresh', [PtController::class, 'refreshJourney']);

        // Participants
        $group->post('/journeys/{id}/participants', [PtController::class, 'addParticipant']);
        $group->delete('/journeys/{id}/participants/{userId}', [PtController::class, 'removeParticipant']);
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
        $group->get('/forums', [AdminController::class, 'forums']);
        $group->get('/forums/json', [AdminController::class, 'adminForumsJson']);
        $group->post('/forums', [AdminController::class, 'createForum']);
        $group->put('/forums/{id}', [AdminController::class, 'updateForum']);
        $group->delete('/forums/{id}', [AdminController::class, 'deleteForum']);
        $group->get('/travel', [AdminController::class, 'travel']);
        $group->post('/travel/trips', [AdminController::class, 'createTrip']);
        $group->put('/travel/trips/{id}', [AdminController::class, 'updateTrip']);
        $group->delete('/travel/trips/{id}', [AdminController::class, 'deleteTrip']);
        $group->get('/travel/trips/{id}', [AdminController::class, 'tripDetail']);
        $group->post('/travel/trips/{id}/participants', [AdminController::class, 'addTripParticipant']);
        $group->delete('/travel/trips/{id}/participants/{userId}', [AdminController::class, 'removeTripParticipant']);
        $group->put('/travel/trips/{id}/participants/{userId}/accommodation', [AdminController::class, 'updateParticipantAccommodation']);
        $group->post('/travel/trips/{id}/accommodations', [AdminController::class, 'createTripAccommodation']);
        $group->put('/travel/trips/{id}/accommodations/{accId}', [AdminController::class, 'updateTripAccommodation']);
        $group->delete('/travel/trips/{id}/accommodations/{accId}', [AdminController::class, 'deleteTripAccommodation']);
        $group->post('/travel/events', [AdminController::class, 'createEvent']);
        $group->put('/travel/events/{id}', [AdminController::class, 'updateEvent']);
        $group->delete('/travel/events/{id}', [AdminController::class, 'deleteEvent']);
        $group->get('/travel/events/{id}', [AdminController::class, 'eventDetail']);
        $group->post('/travel/events/{id}/participants', [AdminController::class, 'addEventParticipant']);
        $group->delete('/travel/events/{id}/participants/{userId}', [AdminController::class, 'removeEventParticipant']);
        $group->get('/notifications', [AdminController::class, 'notifications']);
        $group->post('/notifications/send', [AdminController::class, 'sendNotification']);
        $group->get('/subscriptions', [AdminController::class, 'subscriptions']);
        $group->get('/subscriptions/json', [AdminController::class, 'adminSubscriptionsJson']);
        $group->post('/subscriptions', [AdminController::class, 'createSubscription']);
        $group->get('/subscriptions/{id}', [AdminController::class, 'subscriptionDetail']);
        $group->put('/subscriptions/{id}', [AdminController::class, 'updateSubscription']);
        $group->delete('/subscriptions/{id}', [AdminController::class, 'deleteSubscription']);
        $group->post('/subscriptions/{id}/participants', [AdminController::class, 'addSubscriptionParticipant']);
        $group->put('/subscriptions/{id}/participants/{participantId}', [AdminController::class, 'updateSubscriptionParticipant']);
        $group->delete('/subscriptions/{id}/participants/{participantId}', [AdminController::class, 'removeSubscriptionParticipant']);
    })->add($container->get(AdminMiddleware::class));
};
