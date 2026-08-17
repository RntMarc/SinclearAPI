<?php

use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$container = require __DIR__ . '/../config/container.php';

$app = AppFactory::createFromContainer($container);

$app->addBodyParsingMiddleware();

$app->add($container->get(\Sinclear\Api\Middleware\SecurityHeadersMiddleware::class));
$app->add($container->get(\Sinclear\Api\Middleware\CorsMiddleware::class));
$app->add($container->get(\Sinclear\Api\Middleware\RequireHttpsMiddleware::class));

$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: ($_ENV['APP_DEBUG'] ?? false),
    logErrors: true,
    logErrorDetails: true,
);

$customErrorHandler = function (
    Psr\Http\Message\ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails,
) use ($app, $container) {
    $logger = $container->get(LoggerInterface::class);

    $statusCode = 500;
    if ($exception instanceof \Slim\Exception\HttpNotFoundException) {
        $statusCode = 404;
        $logger->info($exception->getMessage(), ['exception' => $exception]);
    } elseif ($exception instanceof \Slim\Exception\HttpMethodNotAllowedException) {
        $statusCode = 405;
        $logger->error($exception->getMessage(), ['exception' => $exception]);
    } elseif ($exception instanceof \Slim\Exception\HttpBadRequestException) {
        $statusCode = 400;
        $logger->error($exception->getMessage(), ['exception' => $exception]);
    } else {
        $logger->error($exception->getMessage(), ['exception' => $exception]);
    }

    $payload = match ($statusCode) {
        404 => ['error' => 'not_found'],
        405 => ['error' => 'method_not_allowed'],
        400 => ['error' => 'bad_request'],
        default => ['error' => 'internal_error'],
    };
    if ($displayErrorDetails) {
        $payload['message'] = $exception->getMessage();
    }

    $response = $app->getResponseFactory()->createResponse($statusCode);
    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
};

$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

(require dirname(__DIR__) . '/config/routes.php')($app);

$app->run();
