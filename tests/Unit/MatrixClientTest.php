<?php

namespace Sinclear\Api\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Services\MatrixClient;
use Sinclear\Api\Services\MatrixClientException;

class MatrixClientTest extends TestCase
{
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
            matrix: [
                'homeserver_url' => 'https://matrix.test',
                'server_name' => 'matrix.test',
                'as_token' => 'as-token',
            ],
        );
    }

    /** @param list<Response> $responses */
    private function client(array $responses): MatrixClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $http = new Client(['handler' => $stack]);
        return new MatrixClient($http, $this->settings());
    }

    public function testRegisterAsReturnsUserIdOnSuccess(): void
    {
        $client = $this->client([
            new Response(200, [], '{"user_id":"@sb_abc:matrix.test"}'),
        ]);

        self::assertSame('@sb_abc:matrix.test', $client->registerAs('sb_abc', 'password123'));
    }

    public function testRegisterAsTreatsUserInUseAsSuccess(): void
    {
        $client = $this->client([
            new Response(400, [], '{"errcode":"M_USER_IN_USE","error":"User ID already taken"}'),
        ]);

        self::assertSame('@sb_abc:matrix.test', $client->registerAs('sb_abc', 'password123'));
    }

    public function testRegisterAsThrowsPermanentOn401(): void
    {
        $client = $this->client([
            new Response(401, [], '{"errcode":"M_UNKNOWN_TOKEN","error":"Invalid access token"}'),
        ]);

        try {
            $client->registerAs('sb_abc', 'password123');
            self::fail('Expected MatrixClientException');
        } catch (MatrixClientException $e) {
            self::assertTrue($e->isPermanent());
            self::assertSame('M_UNKNOWN_TOKEN', $e->errcode());
            self::assertSame(401, $e->statusCode());
        }
    }

    public function testRegisterAsThrowsTransientOn500(): void
    {
        $client = $this->client([
            new Response(500, [], '{"errcode":"M_UNKNOWN","error":"Internal error"}'),
        ]);

        try {
            $client->registerAs('sb_abc', 'password123');
            self::fail('Expected MatrixClientException');
        } catch (MatrixClientException $e) {
            self::assertFalse($e->isPermanent());
        }
    }

    public function testRegisterAsThrowsTransientOn429(): void
    {
        $client = $this->client([
            new Response(429, [], '{"errcode":"M_LIMIT_EXCEEDED","error":"Too many requests"}'),
        ]);

        try {
            $client->registerAs('sb_abc', 'password123');
            self::fail('Expected MatrixClientException');
        } catch (MatrixClientException $e) {
            self::assertFalse($e->isPermanent());
        }
    }

    public function testSetDisplayNameSucceedsOn200(): void
    {
        $client = $this->client([
            new Response(200, [], '{}'),
        ]);

        $client->setDisplayName('@sb_abc:matrix.test', 'Alice');
        $this->addToAssertionCount(1);
    }

    public function testSetDisplayNameThrowsTransientOn429(): void
    {
        $client = $this->client([
            new Response(429, [], '{"errcode":"M_LIMIT_EXCEEDED","error":"Too many requests"}'),
        ]);

        try {
            $client->setDisplayName('@sb_abc:matrix.test', 'Alice');
            self::fail('Expected MatrixClientException');
        } catch (MatrixClientException $e) {
            self::assertFalse($e->isPermanent());
        }
    }

    public function testSetDisplayNameThrowsPermanentOn400(): void
    {
        $client = $this->client([
            new Response(400, [], '{"errcode":"M_INVALID_PARAM","error":"Invalid displayname"}'),
        ]);

        try {
            $client->setDisplayName('@sb_abc:matrix.test', '');
            self::fail('Expected MatrixClientException');
        } catch (MatrixClientException $e) {
            self::assertTrue($e->isPermanent());
        }
    }
}
