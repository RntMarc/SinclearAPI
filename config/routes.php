<?php

use Psr\Container\ContainerInterface;
use Sinclear\Api\Controllers\AuthController;
use Sinclear\Api\Controllers\ExploreController;
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
        $group->get('', [ExploreController::class, 'list']);
        $group->post('', [ExploreController::class, 'create']);
        $group->get('/{id}', [ExploreController::class, 'get']);
        $group->put('/{id}', [ExploreController::class, 'update']);
        $group->delete('/{id}', [ExploreController::class, 'delete']);
    })->add($container->get(AuthenticationMiddleware::class));
};
