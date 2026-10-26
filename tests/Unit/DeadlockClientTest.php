<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Services\Games\Deadlock\DeadlockApiException;
use Sinclear\Api\Services\Games\Deadlock\DeadlockClient;

final class DeadlockClientTest extends TestCase
{
    public function testRankBatchSendsAccountIdsAndApiKey(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode([['account_id' => 1, 'badge' => 12]])),
        ], $history);

        $result = $this->deadlockClient($client)->rankBatch(['1', '2']);

        self::assertSame([['account_id' => 1, 'badge' => 12]], $result);
        self::assertCount(1, $history);

        $request = $history[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);

        self::assertSame('1,2', $query['account_ids']);
        self::assertSame('secret-key', $request->getHeaderLine('X-API-KEY'));
        self::assertStringStartsWith('SinclearBeyondAPI/2.0', $request->getHeaderLine('User-Agent'));
    }

    public function testSteamProfilesRequestsRefresh(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode([['account_id' => 42, 'personaname' => 'Player']])),
        ], $history);

        $result = $this->deadlockClient($client)->steamProfiles(['42'], true);

        self::assertSame('Player', $result[0]['personaname']);

        parse_str($history[0]['request']->getUri()->getQuery(), $query);
        self::assertSame('42', $query['account_ids']);
        self::assertSame('true', $query['refresh']);
    }

    public function testSteamProfiles404ReturnsEmptyList(): void
    {
        $client = $this->client([new Response(404, [], '')]);

        self::assertSame([], $this->deadlockClient($client)->steamProfiles(['42'], true));
    }

    public function testAssetEndpoints(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode([['id' => 1, 'name' => 'Infernus']])),
            new Response(200, [], json_encode([['tier' => 0, 'name' => 'Obscurus']])),
        ], $history);

        $deadlock = $this->deadlockClient($client);

        self::assertSame([['id' => 1, 'name' => 'Infernus']], $deadlock->heroAssets());
        self::assertSame([['tier' => 0, 'name' => 'Obscurus']], $deadlock->rankAssets());
        self::assertSame('/v1/assets/heroes', $history[0]['request']->getUri()->getPath());
        self::assertSame('/v1/assets/ranks', $history[1]['request']->getUri()->getPath());
    }

    public function testServiceUnavailableExposesRetryAfter(): void
    {
        $client = $this->client([
            new Response(503, ['Retry-After' => '30'], ''),
        ]);

        try {
            $this->deadlockClient($client)->rankBatch(['42']);
            self::fail('Expected DeadlockApiException');
        } catch (DeadlockApiException $e) {
            self::assertSame(503, $e->status);
            self::assertSame(30, $e->retryAfter);
        }
    }

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history = []): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    private function deadlockClient(Client $client): DeadlockClient
    {
        return new DeadlockClient($this->settings(), new NullLogger(), $client);
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
            games: [
                'deadlock_base_url' => 'https://deadlock.test',
                'deadlock_api_key' => 'secret-key',
                'cache_ttl' => 3600,
            ],
        );
    }
}
