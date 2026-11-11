<?php

namespace Sinclear\Api\Controllers\AdminApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\ExternalDataCacheRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Services\ModerationRequestService;

final readonly class AdminApiDashboardController
{
    use AdminApiConcern;

    public function __construct(
        private UserRepository $userRepo,
        private TravelTripRepository $tripRepo,
        private ModerationRequestService $moderationRequestService,
        private ExternalDataCacheRepository $externalDataCacheRepo,
    ) {}

    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $moderationCounts = $this->moderationRequestService->getStatusCounts();
        $openModerationCount = $moderationCounts['unread'] + $moderationCounts['read'] + $moderationCounts['in_work'];
        $cacheStats = $this->externalDataCacheRepo->getStats();

        return ResponseFactory::json([
            'data' => [
                'userCount' => $this->userRepo->countAll(),
                'tripCount' => $this->tripRepo->countAll(),
                'openModerationCount' => $openModerationCount,
                'externalDataCacheCount' => $cacheStats['total'],
            ],
        ], 200, $response);
    }
}
