<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\MinecraftStatsPolicy;
use Sinclear\Api\Services\Minecraft\MinecraftOverviewService;
use Sinclear\Api\Services\Minecraft\MinecraftStatsQuery;

/**
 * Lese-Endpoint fuer die aggregierte Minecraft-Quellen-Uebersicht.
 *
 * Liefert alle getrackten Quellen (Server + Welt) mit letztem Stand, Summen
 * ueber alle Spieler, Spieleranzahl und Rangliste – global oder per `serverId`.
 * Im Gegensatz zum Ingest (`POST /minecraft/stats`, Shared Secret) ist dieser
 * Endpoint JWT-geschuetzt.
 */
final readonly class MinecraftStatsController
{
    public function __construct(
        private MinecraftOverviewService $service,
        private MinecraftStatsPolicy $policy,
    ) {}

    public function sources(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }

        if (!$this->policy->canView($user)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        try {
            $query = MinecraftStatsQuery::fromQueryParams($request->getQueryParams());
        } catch (InvalidArgumentException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }

        return ResponseFactory::json($this->service->overview($query), 200, $response);
    }
}
