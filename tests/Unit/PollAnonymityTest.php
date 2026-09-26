<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Support\PollAnonymity;

class PollAnonymityTest extends TestCase
{
    private const SECRET = 'test-secret';

    public function testHashIsDeterministic(): void
    {
        $anonymity = new PollAnonymity(self::SECRET);

        $this->assertSame(
            $anonymity->participantHash('poll-1', 'user-1'),
            $anonymity->participantHash('poll-1', 'user-1'),
        );
    }

    public function testHashIsSha256Hex(): void
    {
        $anonymity = new PollAnonymity(self::SECRET);
        $hash = $anonymity->participantHash('poll-1', 'user-1');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame(hash_hmac('sha256', 'poll-1:user-1', self::SECRET), $hash);
    }

    public function testHashDoesNotContainPlainUserId(): void
    {
        $anonymity = new PollAnonymity(self::SECRET);
        $hash = $anonymity->participantHash('poll-1', 'user-1');

        $this->assertStringNotContainsString('user-1', $hash);
        $this->assertStringNotContainsString('poll-1', $hash);
    }

    public function testDifferentPollsProduceDifferentHashes(): void
    {
        $anonymity = new PollAnonymity(self::SECRET);

        $this->assertNotSame(
            $anonymity->participantHash('poll-1', 'user-1'),
            $anonymity->participantHash('poll-2', 'user-1'),
        );
    }

    public function testDifferentUsersProduceDifferentHashes(): void
    {
        $anonymity = new PollAnonymity(self::SECRET);

        $this->assertNotSame(
            $anonymity->participantHash('poll-1', 'user-1'),
            $anonymity->participantHash('poll-1', 'user-2'),
        );
    }

    public function testDifferentSecretProducesDifferentHash(): void
    {
        $a = new PollAnonymity('secret-a');
        $b = new PollAnonymity('secret-b');

        $this->assertNotSame(
            $a->participantHash('poll-1', 'user-1'),
            $b->participantHash('poll-1', 'user-1'),
        );
    }
}
