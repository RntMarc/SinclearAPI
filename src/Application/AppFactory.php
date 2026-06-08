<?php

declare(strict_types=1);

namespace Sinclear\Api\Application;

use DI\Container;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Http\Middleware\AuthenticationMiddleware;
use Sinclear\Api\Http\Middleware\CorsMiddleware;
use Sinclear\Api\Http\Middleware\RateLimitMiddleware;
use Sinclear\Api\Http\Middleware\RequestSizeLimitMiddleware;
use Sinclear\Api\Http\Middleware\SecurityHeadersMiddleware;
use Throwable;

/**
 * Creates and configures the Slim application.
 */
final class AppFactory
{
    public static function create(ContainerInterface $container): App
    {
        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->setBasePath('/api/v1');

        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();

        $app->add($container->get(CorsMiddleware::class));
        $app->add($container->get(SecurityHeadersMiddleware::class));
        $app->add($container->get(RequestSizeLimitMiddleware::class));
        $app->add($container->get(RateLimitMiddleware::class));

        self::registerErrorHandler($app, $container);

        return $app;
    }

    private static function registerErrorHandler(App $app, ContainerInterface $container): void
    {
        $settings = $container->get(Settings::class);
        $logger = $container->get(LoggerInterface::class);

        $errorMiddleware = $app->addErrorMiddleware(
            $settings->isDebug(),
            true,
            true,
            $logger
        );

        $errorMiddleware->setDefaultErrorHandler(
            function ($request, Throwable $exception, bool $displayErrorDetails) use ($settings, $logger) {
                if ($exception instanceof HttpException) {
                    $payload = ['error' => $exception->getErrorCode()];
                    if ($settings->isDebug() && $exception->getMessage() !== '') {
                        $payload['message'] = $exception->getMessage();
                    }
                    $response = new \Slim\Psr7\Response($exception->getStatusCode());
                    $response->getBody()->write((string) json_encode($payload));
                    return $response->withHeader('Content-Type', 'application/json');
                }

                $logger->error($exception->getMessage(), ['exception' => $exception]);

                $payload = ['error' => 'internal_error'];
                if ($settings->isDebug()) {
                    $payload['message'] = $exception->getMessage();
                }

                $response = new \Slim\Psr7\Response(500);
                $response->getBody()->write((string) json_encode($payload));
                return $response->withHeader('Content-Type', 'application/json');
            }
        );
    }
}
