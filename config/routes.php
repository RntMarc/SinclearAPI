<?php

use Psr\Container\ContainerInterface;
use Sinclear\Api\Controllers\AuthController;
use Sinclear\Api\Controllers\ExploreController;
use Sinclear\Api\Controllers\TravelController;
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
    });

    $app->group('/explore', function (RouteCollectorProxy $group) use ($container) {
        $group->get('/search', [ExploreController::class, 'search']);
        $group->get('/random', [ExploreController::class, 'random']);
        $group->get('/bookmarks', [ExploreController::class, 'listBookmarks']);
        $group->get('', [ExploreController::class, 'list']);
        $group->post('', [ExploreController::class, 'create']);
        $group->get('/{id}', [ExploreController::class, 'get']);
        $group->put('/{id}', [ExploreController::class, 'update']);
        $group->delete('/{id}', [ExploreController::class, 'delete']);
        $group->get('/{id}/bookmark', [ExploreController::class, 'getBookmark']);
        $group->post('/{id}/bookmark', [ExploreController::class, 'setBookmark']);
        $group->delete('/{id}/bookmark', [ExploreController::class, 'removeBookmark']);
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

};
