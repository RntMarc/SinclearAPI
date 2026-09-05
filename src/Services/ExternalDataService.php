<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\ExternalDataCacheRepository;

final readonly class ExternalDataService
{
    private Client $httpClient;

    private const int HTTP_TIMEOUT = 10;
    private const string USER_AGENT = 'SinclearBeyondAPI/2.0 (https://sinclear.app)';

    public function __construct(
        private ExternalDataCacheRepository $cacheRepo,
        private Settings $settings,
        private LoggerInterface $logger,
    ) {
        $this->httpClient = new Client([
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ],
        ]);
    }

    // =========================================================================
    // Weather
    // =========================================================================

    public function getWeather(?string $citySlug, ?float $lat, ?float $lon): array
    {
        $locationKey = $this->buildLocationKey($citySlug, $lat, $lon);
        $cached = $this->cacheRepo->find('weather', $locationKey);
        if ($cached !== null) {
            return $this->buildResponse($cached, 'cache');
        }

        $data = $this->fetchWeatherFromInfraNode($citySlug);
        $source = 'infranode';

        if ($data === null && $lat !== null && $lon !== null) {
            $data = $this->fetchWeatherFromOpenMeteo($lat, $lon);
            $source = 'open-meteo';
        } elseif ($data !== null && $lat !== null && $lon !== null) {
            $openMeteo = $this->fetchWeatherFromOpenMeteo($lat, $lon);
            if ($openMeteo !== null) {
                $data = $this->mergeWeatherData($data, $openMeteo);
                $source = 'mixed';
            }
        }

        if ($data === null) {
            return $this->emptyResponse('weather', $locationKey);
        }

        $this->cacheRepo->save('weather', $locationKey, $source, $data, $this->getCacheTtl('weather'));

        return $this->buildFreshResponse($data, $source, $locationKey);
    }

    public function getWeatherWarnings(?string $citySlug, ?float $lat, ?float $lon): array
    {
        $locationKey = $this->buildLocationKey($citySlug, $lat, $lon);
        $cached = $this->cacheRepo->find('weather_warnings', $locationKey);
        if ($cached !== null) {
            return $this->buildResponse($cached, 'cache');
        }

        $data = $this->fetchWeatherWarningsFromInfraNode($citySlug);
        $source = 'infranode';

        if ($data === null) {
            return $this->emptyResponse('weather_warnings', $locationKey, ['warnings' => []]);
        }

        $this->cacheRepo->save('weather_warnings', $locationKey, $source, $data, $this->getCacheTtl('weather_warnings'));

        return $this->buildFreshResponse($data, $source, $locationKey);
    }

    public function getPollenUv(?string $citySlug, ?float $lat, ?float $lon): array
    {
        $locationKey = $this->buildLocationKey($citySlug, $lat, $lon);
        $cached = $this->cacheRepo->find('pollen_uv', $locationKey);
        if ($cached !== null) {
            return $this->buildResponse($cached, 'cache');
        }

        $data = $this->fetchPollenUvFromInfraNode($citySlug);
        $source = 'infranode';

        if ($data !== null && $lat !== null && $lon !== null) {
            $uvFromOpenMeteo = $this->fetchUvIndexFromOpenMeteo($lat, $lon);
            if ($uvFromOpenMeteo !== null) {
                $data['uv_index'] = $uvFromOpenMeteo;
                $source = 'mixed';
            }
        } elseif ($data === null && $lat !== null && $lon !== null) {
            $uvIndex = $this->fetchUvIndexFromOpenMeteo($lat, $lon);
            if ($uvIndex !== null) {
                $data = ['pollen' => [], 'uv_index' => $uvIndex];
                $source = 'open-meteo';
            }
        }

        if ($data === null) {
            return $this->emptyResponse('pollen_uv', $locationKey, ['pollen' => [], 'uv_index' => null]);
        }

        $this->cacheRepo->save('pollen_uv', $locationKey, $source, $data, $this->getCacheTtl('pollen_uv'));

        return $this->buildFreshResponse($data, $source, $locationKey);
    }

    public function getAirQuality(?string $citySlug, ?float $lat, ?float $lon): array
    {
        $locationKey = $this->buildLocationKey($citySlug, $lat, $lon);
        $cached = $this->cacheRepo->find('air_quality', $locationKey);
        if ($cached !== null) {
            return $this->buildResponse($cached, 'cache');
        }

        $data = $this->fetchAirQualityFromInfraNode($citySlug);
        $source = 'infranode';

        if ($data === null && $lat !== null && $lon !== null) {
            $data = $this->fetchAirQualityFromOpenMeteo($lat, $lon);
            $source = 'open-meteo';
        }

        if ($data === null) {
            return $this->emptyResponse('air_quality', $locationKey);
        }

        $this->cacheRepo->save('air_quality', $locationKey, $source, $data, $this->getCacheTtl('air_quality'));

        return $this->buildFreshResponse($data, $source, $locationKey);
    }

    // =========================================================================
    // Available Types
    // =========================================================================

    public function getAvailableTypes(): array
    {
        return [
            'weather' => [
                'label' => 'Wetter',
                'label_en' => 'Weather',
                'sections' => ['current', 'hourly', 'daily'],
                'sources' => ['infranode', 'open-meteo'],
                'requires' => 'city_slug oder lat+lon',
            ],
            'weather_warnings' => [
                'label' => 'Wetterwarnungen',
                'label_en' => 'Weather warnings',
                'sections' => ['warnings'],
                'sources' => ['infranode'],
                'requires' => 'city_slug',
            ],
            'pollen_uv' => [
                'label' => 'Pollenbelastung & UV-Index',
                'label_en' => 'Pollen & UV index',
                'sections' => ['pollen', 'uv'],
                'sources' => ['infranode', 'open-meteo'],
                'requires' => 'city_slug oder lat+lon',
            ],
            'air_quality' => [
                'label' => 'Luftqualität',
                'label_en' => 'Air quality',
                'sections' => ['current'],
                'sources' => ['infranode', 'open-meteo'],
                'requires' => 'city_slug oder lat+lon',
            ],
        ];
    }

    // =========================================================================
    // InfraNode Fetchers
    // =========================================================================

    private function fetchWeatherFromInfraNode(?string $citySlug): ?array
    {
        if ($citySlug === null || $citySlug === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->settings->external_data['infranode_base_url'] . '/cities/' . $citySlug . '/weather'
            );
            $body = json_decode((string) $response->getBody(), true);

            if (!is_array($body) || !isset($body['data']['payload'])) {
                return null;
            }

            return $this->normalizeInfraNodeWeather($body['data']);
        } catch (GuzzleException $e) {
            $this->logger->warning('[EXTERNAL_DATA] InfraNode weather failed: ' . $e->getMessage());
            return null;
        }
    }

    private function fetchWeatherWarningsFromInfraNode(?string $citySlug): ?array
    {
        if ($citySlug === null || $citySlug === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->settings->external_data['infranode_base_url'] . '/cities/' . $citySlug . '/weather-warnings'
            );
            $body = json_decode((string) $response->getBody(), true);

            if (!is_array($body) || !isset($body['data']['payload'])) {
                return null;
            }

            return $this->normalizeInfraNodeWarnings($body['data']);
        } catch (GuzzleException $e) {
            $this->logger->warning('[EXTERNAL_DATA] InfraNode weather-warnings failed: ' . $e->getMessage());
            return null;
        }
    }

    private function fetchPollenUvFromInfraNode(?string $citySlug): ?array
    {
        if ($citySlug === null || $citySlug === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->settings->external_data['infranode_base_url'] . '/cities/' . $citySlug . '/pollen-uv'
            );
            $body = json_decode((string) $response->getBody(), true);

            if (!is_array($body) || !isset($body['data']['payload'])) {
                return null;
            }

            return $this->normalizeInfraNodePollenUv($body['data']);
        } catch (GuzzleException $e) {
            $this->logger->warning('[EXTERNAL_DATA] InfraNode pollen-uv failed: ' . $e->getMessage());
            return null;
        }
    }

    private function fetchAirQualityFromInfraNode(?string $citySlug): ?array
    {
        if ($citySlug === null || $citySlug === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->settings->external_data['infranode_base_url'] . '/cities/' . $citySlug . '/air'
            );
            $body = json_decode((string) $response->getBody(), true);

            if (!is_array($body) || !isset($body['data']['payload'])) {
                return null;
            }

            return $this->normalizeInfraNodeAirQuality($body['data']);
        } catch (GuzzleException $e) {
            $this->logger->warning('[EXTERNAL_DATA] InfraNode air failed: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // Open-Meteo Fetchers
    // =========================================================================

    private function fetchWeatherFromOpenMeteo(float $lat, float $lon): ?array
    {
        try {
            $baseUrl = $this->settings->external_data['open_meteo_base_url'];
            $response = $this->httpClient->request('GET', $baseUrl . '/forecast', [
                'query' => [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,cloud_cover,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
                    'hourly' => 'temperature_2m,precipitation_probability,weather_code,wind_speed_10m',
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,sunrise,sunset,uv_index_max,wind_speed_10m_max',
                    'timezone' => 'UTC',
                    'forecast_days' => 3,
                ],
            ]);
            $body = json_decode((string) $response->getBody(), true);

            if (!is_array($body)) {
                return null;
            }

            return $this->normalizeOpenMeteoWeather($body);
        } catch (GuzzleException $e) {
            $this->logger->warning('[EXTERNAL_DATA] Open-Meteo weather failed: ' . $e->getMessage());
            return null;
        }
    }

    private function fetchUvIndexFromOpenMeteo(float $lat, float $lon): ?float
    {
        try {
            $baseUrl = $this->settings->external_data['open_meteo_base_url'];
            $response = $this->httpClient->request('GET', $baseUrl . '/forecast', [
                'query' => [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'daily' => 'uv_index_max',
                    'timezone' => 'UTC',
                    'forecast_days' => 1,
                ],
            ]);
            $body = json_decode((string) $response->getBody(), true);

            if (!is_array($body) || !isset($body['daily']['uv_index_max'][0])) {
                return null;
            }

            return (float) $body['daily']['uv_index_max'][0];
        } catch (GuzzleException $e) {
            $this->logger->warning('[EXTERNAL_DATA] Open-Meteo UV failed: ' . $e->getMessage());
            return null;
        }
    }

    private function fetchAirQualityFromOpenMeteo(float $lat, float $lon): ?array
    {
        try {
            $baseUrl = $this->settings->external_data['open_meteo_base_url'];
            $response = $this->httpClient->request('GET', $baseUrl . '/air-quality', [
                'query' => [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'current' => 'pm10,pm2_5,nitrogen_dioxide,ozone,sulphur_dioxide',
                    'timezone' => 'UTC',
                ],
            ]);
            $body = json_decode((string) $response->getBody(), true);

            if (!is_array($body)) {
                return null;
            }

            return $this->normalizeOpenMeteoAirQuality($body);
        } catch (GuzzleException $e) {
            $this->logger->warning('[EXTERNAL_DATA] Open-Meteo air-quality failed: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // Normalization
    // =========================================================================

    private function normalizeInfraNodeWeather(array $data): array
    {
        $payload = $data['payload'] ?? [];
        $attribution = $data['attribution'] ?? [];

        return [
            'current' => [
                'temperature_c' => $payload['temperature_c'] ?? null,
                'humidity' => $payload['humidity'] ?? null,
                'wind_speed' => $payload['wind_speed'] ?? null,
                'condition' => $payload['condition'] ?? null,
                'observed_at' => $this->formatUtcTime($data['observed_at'] ?? null),
            ],
            'hourly' => [],
            'daily' => [],
            '_attribution' => [
                'text' => $attribution['text'] ?? '',
                'url' => $attribution['license_url'] ?? '',
            ],
        ];
    }

    private function normalizeInfraNodeWarnings(array $data): array
    {
        $payload = $data['payload'] ?? [];
        $warnings = [];

        foreach ($payload['warnings'] ?? [] as $w) {
            $warnings[] = [
                'event' => $w['event'] ?? '',
                'level' => $w['level'] ?? 0,
                'headline' => $w['headline'] ?? '',
                'start' => $this->formatUtcTime($w['start'] ?? null),
                'end' => $this->formatUtcTime($w['end'] ?? null),
            ];
        }

        return [
            'warnings' => $warnings,
            'max_level' => $payload['max_level'] ?? 0,
        ];
    }

    private function normalizeInfraNodePollenUv(array $data): array
    {
        $payload = $data['payload'] ?? [];
        $pollen = [];

        foreach ($payload['pollen'] ?? [] as $type => $values) {
            $pollen[strtolower($type)] = [
                'today' => $values['today'] ?? '0',
                'tomorrow' => $values['tomorrow'] ?? '0',
                'day_after_tomorrow' => $values['dayafter_to'] ?? '0',
            ];
        }

        return [
            'pollen' => $pollen,
            'uv_index' => $payload['uv_index'] ?? null,
            'region_name' => $payload['region_name'] ?? null,
        ];
    }

    private function normalizeInfraNodeAirQuality(array $data): array
    {
        $payload = $data['payload'] ?? [];

        return [
            'pm10' => $payload['pm10'] ?? null,
            'pm25' => $payload['pm25'] ?? null,
            'no2' => $payload['no2'] ?? null,
            'o3' => $payload['o3'] ?? null,
            'so2' => $payload['so2'] ?? null,
            'station_id' => $payload['station_id'] ?? null,
            'observed_at' => $this->formatUtcTime($data['observed_at'] ?? null),
            '_attribution' => [
                'text' => ($data['attribution'] ?? [])['text'] ?? '',
                'url' => ($data['attribution'] ?? [])['license_url'] ?? '',
            ],
        ];
    }

    private function normalizeOpenMeteoWeather(array $body): array
    {
        $current = $body['current'] ?? [];
        $hourly = $body['hourly'] ?? [];
        $daily = $body['daily'] ?? [];

        $hourlyData = [];
        if (isset($hourly['time'])) {
            $count = count($hourly['time']);
            for ($i = 0; $i < $count; $i++) {
                $hourlyData[] = [
                    'time' => $this->formatUtcTime($hourly['time'][$i] ?? null),
                    'temperature_c' => $hourly['temperature_2m'][$i] ?? null,
                    'precipitation_probability' => $hourly['precipitation_probability'][$i] ?? null,
                    'weather_code' => $hourly['weather_code'][$i] ?? null,
                    'wind_speed' => $hourly['wind_speed_10m'][$i] ?? null,
                ];
            }
        }

        $dailyData = [];
        if (isset($daily['time'])) {
            $count = count($daily['time']);
            for ($i = 0; $i < $count; $i++) {
                $dailyData[] = [
                    'date' => substr($daily['time'][$i] ?? '', 0, 10),
                    'weather_code' => $daily['weather_code'][$i] ?? null,
                    'temperature_max_c' => $daily['temperature_2m_max'][$i] ?? null,
                    'temperature_min_c' => $daily['temperature_2m_min'][$i] ?? null,
                    'precipitation_sum' => $daily['precipitation_sum'][$i] ?? null,
                    'precipitation_probability_max' => $daily['precipitation_probability_max'][$i] ?? null,
                    'sunrise' => $this->formatUtcTime($daily['sunrise'][$i] ?? null),
                    'sunset' => $this->formatUtcTime($daily['sunset'][$i] ?? null),
                    'uv_index_max' => $daily['uv_index_max'][$i] ?? null,
                    'wind_speed_max' => $daily['wind_speed_10m_max'][$i] ?? null,
                ];
            }
        }

        return [
            'current' => [
                'temperature_c' => $current['temperature_2m'] ?? null,
                'humidity' => $current['relative_humidity_2m'] ?? null,
                'apparent_temperature' => $current['apparent_temperature'] ?? null,
                'precipitation' => $current['precipitation'] ?? null,
                'weather_code' => $current['weather_code'] ?? null,
                'cloud_cover' => $current['cloud_cover'] ?? null,
                'wind_speed' => $current['wind_speed_10m'] ?? null,
                'wind_direction' => $current['wind_direction_10m'] ?? null,
                'wind_gusts' => $current['wind_gusts_10m'] ?? null,
                'observed_at' => $this->formatUtcTime($body['current']['time'] ?? null),
            ],
            'hourly' => $hourlyData,
            'daily' => $dailyData,
            '_attribution' => [
                'text' => 'Open-Meteo (open-meteo.com) — Non-commercial use',
                'url' => 'https://open-meteo.com/en/docs',
            ],
        ];
    }

    private function normalizeOpenMeteoAirQuality(array $body): array
    {
        $current = $body['current'] ?? [];

        return [
            'pm10' => $current['pm10'] ?? null,
            'pm25' => $current['pm2_5'] ?? null,
            'no2' => $current['nitrogen_dioxide'] ?? null,
            'o3' => $current['ozone'] ?? null,
            'so2' => $current['sulphur_dioxide'] ?? null,
            'observed_at' => $this->formatUtcTime($current['time'] ?? null),
            '_attribution' => [
                'text' => 'Open-Meteo (open-meteo.com) — Non-commercial use',
                'url' => 'https://open-meteo.com/en/docs',
            ],
        ];
    }

    // =========================================================================
    // Merge / Helpers
    // =========================================================================

    private function mergeWeatherData(array $infraNode, array $openMeteo): array
    {
        $result = $infraNode;

        if (empty($result['hourly']) && !empty($openMeteo['hourly'])) {
            $result['hourly'] = $openMeteo['hourly'];
        }

        if (empty($result['daily']) && !empty($openMeteo['daily'])) {
            $result['daily'] = $openMeteo['daily'];
        }

        $openCurrent = $openMeteo['current'] ?? [];
        if (isset($openCurrent['apparent_temperature'])) {
            $result['current']['apparent_temperature'] = $openCurrent['apparent_temperature'];
        }

        return $result;
    }

    private function buildLocationKey(?string $citySlug, ?float $lat, ?float $lon): string
    {
        if ($citySlug !== null && $citySlug !== '') {
            return $citySlug;
        }

        if ($lat !== null && $lon !== null) {
            return round($lat, 4) . ',' . round($lon, 4);
        }

        return 'unknown';
    }

    private function buildResponse(array $cached, string $source): array
    {
        $payload = $cached['payload'];
        if (!is_array($payload)) {
            $payload = json_decode($cached['payload'], true) ?? [];
        }

        $locationKey = $cached['location_key'];
        $coords = $this->parseLocationKey($locationKey);

        return [
            'data' => $payload,
            'meta' => [
                'source' => $source,
                'data_type' => $cached['data_type'],
                'city_slug' => !str_contains($locationKey, ',') ? $locationKey : null,
                'coordinates' => $coords,
                'available_sections' => array_keys($payload),
                'missing_sections' => [],
                'cached_at' => $cached['created_at'],
                'expires_at' => $cached['expires_at'],
                'retrieved_at' => $this->nowUtc(),
            ],
        ];
    }

    private function buildFreshResponse(array $data, string $source, string $locationKey): array
    {
        $coords = $this->parseLocationKey($locationKey);

        return [
            'data' => $data,
            'meta' => [
                'source' => $source,
                'city_slug' => !str_contains($locationKey, ',') ? $locationKey : null,
                'coordinates' => $coords,
                'available_sections' => array_keys(array_filter($data, fn($v) => $v !== null && $v !== [])),
                'missing_sections' => $this->findMissingSections($data),
                'retrieved_at' => $this->nowUtc(),
            ],
        ];
    }

    private function emptyResponse(string $dataType, string $locationKey, array $extra = []): array
    {
        $coords = $this->parseLocationKey($locationKey);

        return [
            'data' => array_merge([
                'current' => null,
                'hourly' => [],
                'daily' => [],
            ], $extra),
            'meta' => [
                'source' => null,
                'data_type' => $dataType,
                'city_slug' => !str_contains($locationKey, ',') ? $locationKey : null,
                'coordinates' => $coords,
                'available_sections' => [],
                'missing_sections' => array_keys(array_merge([
                    'current' => null,
                    'hourly' => [],
                    'daily' => [],
                ], $extra)),
                'retrieved_at' => $this->nowUtc(),
            ],
        ];
    }

    private function findMissingSections(array $data): array
    {
        $missing = [];
        foreach (['current', 'hourly', 'daily', 'warnings', 'pollen', 'uv'] as $section) {
            if (!array_key_exists($section, $data)) {
                $missing[] = $section;
            } elseif (is_array($data[$section]) && $data[$section] === []) {
                // Empty array is still "available", just no data
            }
        }
        return $missing;
    }

    private function parseLocationKey(string $locationKey): ?array
    {
        if (str_contains($locationKey, ',')) {
            $parts = explode(',', $locationKey);
            if (count($parts) === 2) {
                return [
                    'lat' => (float) $parts[0],
                    'lon' => (float) $parts[1],
                ];
            }
        }

        return null;
    }

    private function getCacheTtl(string $dataType): int
    {
        return $this->settings->external_data['cache_ttl'][$dataType]
            ?? $this->settings->external_data['cache_ttl']['default'];
    }

    private function formatUtcTime(?string $isoTime): ?string
    {
        if ($isoTime === null || $isoTime === '') {
            return null;
        }

        $dt = new \DateTime($isoTime, new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    private function nowUtc(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
