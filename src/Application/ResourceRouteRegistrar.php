<?php

declare(strict_types=1);

namespace Sinclear\Api\Application;

use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Sinclear\Api\Http\Controllers\ResourceController;
use Sinclear\Api\Http\Middleware\AuthenticationMiddleware;
use Sinclear\Api\Repository\GenericRepository;
use Sinclear\Api\Security\Policy\PolicyInterface;
use Sinclear\Api\Service\ResourceService;

/**
 * Registers generic CRUD routes from the resource registry.
 */
final class ResourceRouteRegistrar
{
    public static function register(App $app, ContainerInterface $container): void
    {
        /** @var list<array{route: string, table: string, pk?: string, policy: class-string<PolicyInterface>, mapper?: callable}> $registry */
        $registry = require dirname(__DIR__, 2) . '/config/ResourceRegistry.php';
        $settings = $container->get(Settings::class);
        $pdo = $container->get(\PDO::class);
        $authMiddleware = $container->get(AuthenticationMiddleware::class);

        $app->group('', function (RouteCollectorProxy $group) use ($registry, $pdo, $settings, $container): void {
            foreach ($registry as $resource) {
                $pk = $resource['pk'] ?? 'id';

                // Try to find a specialized repository in the container, otherwise use GenericRepository
                $repoClass = 'Sinclear\Api\Repository\\' . $resource['table'] . 'Repository';
                if ($container->has($repoClass)) {
                    $repository = $container->get($repoClass);
                } else {
                    $repository = new GenericRepository($pdo, $resource['table'], [], $pk);
                }

                $policy = new ($resource['policy'])();
                $mapper = $resource['mapper'] ?? null;
                $service = new ResourceService($repository, $policy, $mapper);
                $controller = new ResourceController($service, $settings);
                $route = $resource['route'];

                $group->get('/' . $route, [$controller, 'list']);
                $group->get('/' . $route . '/{id}', [$controller, 'get']);
                $group->post('/' . $route, [$controller, 'create']);
                $group->put('/' . $route . '/{id}', [$controller, 'update']);
                $group->patch('/' . $route . '/{id}', [$controller, 'update']);
                $group->delete('/' . $route . '/{id}', [$controller, 'delete']);
            }
        })->add($authMiddleware);
    }
}
