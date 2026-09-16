<?php

namespace Sinclear\Api\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CentrifugoClientTest extends TestCase
{
    private string $publishedChannel = '';
    private array $publishedData = [];

    public function testPublishSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['result' => []])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client);
        $centrifugoClient->publish('chat:conv-1', ['type' => 'message_created']);

        // No exception means success
        $this->assertTrue(true);
    }

    public function testPublishNoopWhenDisabled(): void
    {
        $mock = new MockHandler([]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client, enabled: false);
        $centrifugoClient->publish('chat:conv-1', ['type' => 'message_created']);

        // No request should have been made
        $this->assertCount(0, $mock);
    }

    public function testPresenceReturnsData(): void
    {
        $presenceData = [
            'user-1' => ['client' => 'abc', 'user' => 'user-1'],
            'user-2' => ['client' => 'def', 'user' => 'user-2'],
        ];
        $mock = new MockHandler([
            new Response(200, [], json_encode(['result' => ['presence' => $presenceData]])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client);
        $result = $centrifugoClient->presence('chat:conv-1');

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('user-1', $result);
        $this->assertArrayHasKey('user-2', $result);
    }

    public function testPresenceReturnsEmptyArrayOnFailure(): void
    {
        $mock = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client);
        $result = $centrifugoClient->presence('chat:conv-1');

        $this->assertSame([], $result);
    }

    public function testPresenceReturnsEmptyArrayOnConnectionError(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'http://localhost')),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client);
        $result = $centrifugoClient->presence('chat:conv-1');

        $this->assertSame([], $result);
    }

    public function testPresenceNoopWhenDisabled(): void
    {
        $mock = new MockHandler([]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client, enabled: false);
        $result = $centrifugoClient->presence('chat:conv-1');

        $this->assertSame([], $result);
        $this->assertCount(0, $mock);
    }

    public function testUnsubscribeSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['result' => []])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client);
        $centrifugoClient->unsubscribe('chat:conv-1', 'user-1');

        $this->assertTrue(true);
    }

    public function testUnsubscribeNoopWhenDisabled(): void
    {
        $mock = new MockHandler([]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client, enabled: false);
        $centrifugoClient->unsubscribe('chat:conv-1', 'user-1');

        $this->assertCount(0, $mock);
    }

    public function testDisconnectSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['result' => []])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client);
        $centrifugoClient->disconnect('user-1');

        $this->assertTrue(true);
    }

    public function testPublishToleratesGuzzleException(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'http://localhost')),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client);
        // Should not throw
        $centrifugoClient->publish('chat:conv-1', ['type' => 'test']);

        $this->assertTrue(true);
    }

    public function testPublishSendsCorrectHeaders(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['result' => []])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client);
        $centrifugoClient->publish('chat:conv-1', ['type' => 'test']);

        $request = $mock->getLastRequest();
        $this->assertSame('test-api-key', $request->getHeaderLine('X-API-Key'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testPublishSendsCorrectBody(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['result' => []])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $centrifugoClient = $this->createClient($client);
        $data = ['type' => 'message_created', 'message' => ['id' => 'msg-1']];
        $centrifugoClient->publish('chat:conv-1', $data);

        $request = $mock->getLastRequest();
        $body = json_decode((string) $request->getBody(), true);

        // CentrifugoClient sends to /publish endpoint with channel and data
        $this->assertSame('chat:conv-1', $body['channel'] ?? null);
        $this->assertSame($data, $body['data'] ?? null);
    }

    private function createClient(Client $httpClient, bool $enabled = true): \Sinclear\Api\Services\Centrifugo\CentrifugoClient
    {
        return new \Sinclear\Api\Services\Centrifugo\CentrifugoClient(
            apiKey: 'test-api-key',
            apiUrl: 'http://localhost:8000/api',
            timeout: 2,
            enabled: $enabled,
            logger: new NullLogger(),
            httpClient: $httpClient,
        );
    }
}
