<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Services\Games\Minecraft\MinecraftApiException;
use Sinclear\Api\Services\Games\Minecraft\MinecraftClient;

final class MinecraftClientTest extends TestCase
{
    private const string UUID = 'ca48cfb5-bfcb-4f25-bbbf-966cb5892c80';

    public function testFindByNameReturnsProfile(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode(['id' => self::UUID, 'name' => 'Notch'])),
        ], $history);

        $result = $this->minecraftClient($client)->findByName('Notch');

        self::assertSame(['id' => self::UUID, 'name' => 'Notch'], $result);
        self::assertSame('/users/profiles/minecraft/Notch', $history[0]['request']->getUri()->getPath());
    }

    public function testFindByName404ReturnsNull(): void
    {
        $client = $this->client([new Response(404, [], '')]);

        self::assertNull($this->minecraftClient($client)->findByName('Unknown'));
    }

    public function testFindByName204ReturnsNull(): void
    {
        $client = $this->client([new Response(204, [], '')]);

        self::assertNull($this->minecraftClient($client)->findByName('Unknown'));
    }

    public function testFindByUuidReturnsProfile(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode(['id' => self::UUID, 'name' => 'Notch'])),
        ], $history);

        $result = $this->minecraftClient($client)->findByUuid(self::UUID);

        self::assertSame(['id' => self::UUID, 'name' => 'Notch'], $result);
        self::assertSame('/session/minecraft/profile/' . self::UUID, $history[0]['request']->getUri()->getPath());
    }

    public function testServiceUnavailableExposesRetryAfter(): void
    {
        $client = $this->client([new Response(503, ['Retry-After' => '30'], '')]);

        try {
            $this->minecraftClient($client)->findByName('Notch');
            self::fail('Expected MinecraftApiException');
        } catch (MinecraftApiException $e) {
            self::assertSame(503, $e->status);
            self::assertSame(30, $e->retryAfter);
        }
    }

    public function testAvatarUrl(): void
    {
        $client = $this->client([]);

        self::assertSame(
            'https://mc-heads.net/avatar/' . self::UUID . '/64',
            $this->minecraftClient($client)->avatarUrl(self::UUID),
        );
    }

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history = []): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    private function minecraftClient(Client $client): MinecraftClient
    {
        return new MinecraftClient($this->settings(), new NullLogger(), $client);
    }

    private function settings(): Settings
    {
        return new Settings(
            app: [],
            db: [],
            jwt: [],
            discord: [],
            smtp: [],
            cors: [],
            rate_limit: [],
            pagination: [],
            minecraft: [
                'profile_api_base_url' => 'https://api.mojang.com',
                'session_api_base_url' => 'https://sessionserver.mojang.com',
                'avatar_base_url' => 'https://mc-heads.net/avatar',
            ],
        );
    }
}
