<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Services\ExternalDataService;

/**
 * Unit-Tests fuer die Quelle/TTL-Logik des ExternalDataService.
 *
 * Der Service wird ohne Konstruktor instanziiert, weil der Konstruktor einen
 * Guzzle-Client und ein ExternalDataCacheRepository (PDO) benoetigt. Fuer die
 * getesteten Methoden genuegt die Settings-Property.
 */
class ExternalDataServiceTest extends TestCase
{
    public function testAvailableTypesExposeBrightSkyForWarnings(): void
    {
        $types = $this->service()->getAvailableTypes();

        self::assertSame(['warnings', 'special_warnings'], $types['weather_warnings']['sections']);
        self::assertSame(['infranode', 'brightsky'], $types['weather_warnings']['sources']);
        self::assertSame('city_slug oder lat+lon', $types['weather_warnings']['requires']);
    }

    public function testAvailableTypesKeepOtherSourcesUnchanged(): void
    {
        $types = $this->service()->getAvailableTypes();

        self::assertSame(['infranode', 'open-meteo'], $types['weather']['sources']);
        self::assertSame(['infranode', 'open-meteo'], $types['pollen_uv']['sources']);
        self::assertSame(['infranode', 'open-meteo'], $types['air_quality']['sources']);
    }

    public function testFallbackSourcesUseShortTtlForSlugKeys(): void
    {
        $service = $this->service();

        foreach (['open-meteo', 'brightsky'] as $source) {
            self::assertSame(
                60,
                $this->ttl($service, 'weather_warnings', $source, 'berlin'),
                $source . ' muss unter einem Slug-Key mit 60 s TTL gecached werden',
            );
        }
    }

    public function testFallbackSourceWithCoordinateKeyUsesConfiguredTtl(): void
    {
        self::assertSame(300, $this->ttl($this->service(), 'weather_warnings', 'brightsky', '52.5200,13.4050'));
        self::assertSame(600, $this->ttl($this->service(), 'weather', 'open-meteo', '52.5200,13.4050'));
    }

    public function testPrimarySourceUsesConfiguredTtlForSlugAndCoordinates(): void
    {
        self::assertSame(300, $this->ttl($this->service(), 'weather_warnings', 'infranode', 'berlin'));
        self::assertSame(600, $this->ttl($this->service(), 'weather', 'mixed', '52.5200,13.4050'));
    }

    public function testUnknownDataTypeUsesDefaultTtl(): void
    {
        self::assertSame(1800, $this->ttl($this->service(), 'future_type', 'infranode', 'berlin'));
    }

    private function service(): ExternalDataService
    {
        $settings = new Settings(
            app: [],
            db: [],
            jwt: [],
            discord: [],
            smtp: [],
            cors: [],
            rate_limit: [],
            pagination: [],
            external_data: [
                'cache_ttl' => [
                    'weather' => 600,
                    'weather_warnings' => 300,
                    'pollen_uv' => 1800,
                    'air_quality' => 600,
                    'default' => 1800,
                ],
            ],
        );

        $reflection = new \ReflectionClass(ExternalDataService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getProperty('settings');
        $property->setValue($service, $settings);

        return $service;
    }

    private function ttl(ExternalDataService $service, string $dataType, string $source, string $locationKey): int
    {
        $method = new \ReflectionMethod(ExternalDataService::class, 'getCacheTtl');

        return $method->invoke($service, $dataType, $source, $locationKey);
    }
}
