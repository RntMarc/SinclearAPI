<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Services\ExternalDataService;

final readonly class ExternalDataController
{
    public function __construct(
        private ExternalDataService $externalDataService,
    ) {}

    public function weather(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $citySlug = $params['city_slug'] ?? null;
        $lat = isset($params['lat']) ? (float) $params['lat'] : null;
        $lon = isset($params['lon']) ? (float) $params['lon'] : null;

        if ($citySlug === null && ($lat === null || $lon === null)) {
            return ResponseFactory::json(
                ['error' => 'location_required', 'message' => 'Provide city_slug or lat+lon'],
                400,
                $response,
            );
        }

        $result = $this->externalDataService->getWeather($citySlug, $lat, $lon);

        return ResponseFactory::json($result, 200, $response);
    }

    public function weatherWarnings(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $citySlug = $params['city_slug'] ?? null;
        $lat = isset($params['lat']) ? (float) $params['lat'] : null;
        $lon = isset($params['lon']) ? (float) $params['lon'] : null;

        if ($citySlug === null && ($lat === null || $lon === null)) {
            return ResponseFactory::json(
                ['error' => 'location_required', 'message' => 'Provide city_slug or lat+lon'],
                400,
                $response,
            );
        }

        $result = $this->externalDataService->getWeatherWarnings($citySlug, $lat, $lon);

        return ResponseFactory::json($result, 200, $response);
    }

    public function pollenUv(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $citySlug = $params['city_slug'] ?? null;
        $lat = isset($params['lat']) ? (float) $params['lat'] : null;
        $lon = isset($params['lon']) ? (float) $params['lon'] : null;

        if ($citySlug === null && ($lat === null || $lon === null)) {
            return ResponseFactory::json(
                ['error' => 'location_required', 'message' => 'Provide city_slug or lat+lon'],
                400,
                $response,
            );
        }

        $result = $this->externalDataService->getPollenUv($citySlug, $lat, $lon);

        return ResponseFactory::json($result, 200, $response);
    }

    public function airQuality(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $citySlug = $params['city_slug'] ?? null;
        $lat = isset($params['lat']) ? (float) $params['lat'] : null;
        $lon = isset($params['lon']) ? (float) $params['lon'] : null;

        if ($citySlug === null && ($lat === null || $lon === null)) {
            return ResponseFactory::json(
                ['error' => 'location_required', 'message' => 'Provide city_slug or lat+lon'],
                400,
                $response,
            );
        }

        $result = $this->externalDataService->getAirQuality($citySlug, $lat, $lon);

        return ResponseFactory::json($result, 200, $response);
    }

    public function availableTypes(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $result = $this->externalDataService->getAvailableTypes();

        return ResponseFactory::json(['data' => $result], 200, $response);
    }
}
