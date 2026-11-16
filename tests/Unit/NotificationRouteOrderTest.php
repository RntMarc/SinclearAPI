<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sinclear\Api\Controllers\NotificationController;

/**
 * Regression: Eine statische Route hinter einer Variablen-Route lässt
 * FastRoute beim Dispatch mit "Static route ... is shadowed ..." abbrechen.
 * Das legt die komplette API mit HTTP 500 lahm (auch Login).
 */
final class NotificationRouteOrderTest extends TestCase
{
    public function testVapidPublicKeyRouteIsReachable(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === NotificationController::class) {
                    return new class {
                        public function vapidPublicKey(
                            ServerRequestInterface $request,
                            ResponseInterface $response,
                        ): ResponseInterface {
                            $response->getBody()->write('{"key":"test"}');

                            return $response->withHeader('Content-Type', 'application/json');
                        }
                    };
                }

                return new class implements MiddlewareInterface {
                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        return $handler->handle($request);
                    }
                };
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $app = AppFactory::createFromContainer($container);
        $app->addRoutingMiddleware();
        (require dirname(__DIR__, 2) . '/config/routes.php')($app);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v2/notifications/vapid-public-key');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"key":"test"}', (string) $response->getBody());
    }
}
