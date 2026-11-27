<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Services\Games\Minecraft\MinecraftClient;
use Sinclear\Api\Services\Games\Minecraft\MinecraftProfileResolver;

final class MinecraftProfileResolverTest extends TestCase
{
    private const string UUID = 'ca48cfb5-bfcb-4f25-bbbf-966cb5892c80';
    private const string AVATAR_BASE = 'https://mc-heads.net/avatar';

    public function testResolveByUuidNormalizesAndReturnsProfile(): void
    {
        // Eingabe ohne Bindestriche und in Grossbuchstaben.
        $resolver = $this->resolver([
            new Response(200, [], json_encode(['id' => self::UUID, 'name' => 'Notch'])),
        ]);

        $profile = $resolver->resolve('CA48CFB5BFCB4F25BBBF966CB5892C80');

        self::assertSame(self::UUID, $profile['accountId']);
        self::assertSame('Notch', $profile['displayName']);
        self::assertSame(self::AVATAR_BASE . '/' . self::UUID . '/64', $profile['avatarUrl']);
        self::assertSame(['username' => 'Notch'], $profile['profile']);
    }

    public function testResolveByUsername(): void
    {
        $resolver = $this->resolver([
            new Response(200, [], json_encode(['id' => self::UUID, 'name' => 'Notch'])),
        ]);

        $profile = $resolver->resolve('Notch');

        self::assertSame(self::UUID, $profile['accountId']);
        self::assertSame('Notch', $profile['displayName']);
    }

    public function testInvalidInputThrowsInvalidAccountId(): void
    {
        $resolver = $this->resolver([]);

        foreach (['', 'ab', 'name-with-dash', 'this-name-is-way-too-long'] as $input) {
            try {
                $resolver->resolve($input);
                self::fail('Expected InvalidArgumentException for: ' . var_export($input, true));
            } catch (InvalidArgumentException $e) {
                self::assertSame('invalid_account_id', $e->getMessage());
            }
        }
    }

    public function testUnknownAccountThrowsAccountNotFound(): void
    {
        $resolver = $this->resolver([new Response(204, [], '')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('account_not_found');

        $resolver->resolve('UnknownUser');
    }

    public function testMojangUnavailableThrowsGameApiUnavailable(): void
    {
        $resolver = $this->resolver([new Response(503, ['Retry-After' => '30'], '')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('game_api_unavailable');

        $resolver->resolve('Notch');
    }

    public function testNormalizeUuid(): void
    {
        self::assertSame(
            self::UUID,
            MinecraftProfileResolver::normalizeUuid(self::UUID),
        );
        self::assertSame(
            self::UUID,
            MinecraftProfileResolver::normalizeUuid('CA48CFB5BFCB4F25BBBF966CB5892C80'),
        );
        self::assertSame(
            self::UUID,
            MinecraftProfileResolver::normalizeUuid('ca48cfb5-bfcb-4f25-bbbf-966cb5892c80'),
        );
        self::assertNull(MinecraftProfileResolver::normalizeUuid('not-a-uuid'));
        self::assertNull(MinecraftProfileResolver::normalizeUuid('Notch'));
    }

    /**
     * @param list<Response> $responses
     */
    private function resolver(array $responses): MinecraftProfileResolver
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);

        $client = new MinecraftClient($this->settings(), new NullLogger(), new Client(['handler' => $stack]));

        return new MinecraftProfileResolver($client);
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
                'avatar_base_url' => self::AVATAR_BASE,
            ],
        );
    }
}
