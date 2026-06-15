<?php

use Psr\Container\ContainerInterface;
use Sinclear\Api\Controllers\AuthController;
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
};
