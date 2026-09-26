<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\NotificationService;

/**
 * DB-freier Test der Poll-Relations-Normalisierung im NotificationService.
 * Die private Methode wird per Reflection aufgerufen, ohne den Konstruktor
 * (und damit ohne PDO/Repositories) zu benötigen.
 */
class PollNotificationDataTest extends TestCase
{
    private NotificationService $service;

    protected function setUp(): void
    {
        $reflection = new \ReflectionClass(NotificationService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
    }

    private function normalize(string $type, ?array $data): array
    {
        $method = new \ReflectionMethod(NotificationService::class, 'normalizeData');
        return $method->invoke($this->service, $type, $data);
    }

    public function testPollInviteNormalization(): void
    {
        $result = $this->normalize('poll_invite', [
            ['relation' => 'poll', 'object' => 'Poll', 'identifier' => 'p1'],
            ['relation' => 'inviter', 'object' => 'User', 'identifier' => 'u1'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('poll', $result[0]['relation']);
    }

    public function testPollInviteMissingRelationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalize('poll_invite', [
            ['relation' => 'inviter', 'object' => 'User', 'identifier' => 'u1'],
        ]);
    }

    public function testPollInviteUnsupportedPairThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalize('poll_invite', [
            ['relation' => 'poll', 'object' => 'Forum', 'identifier' => 'p1'],
            ['relation' => 'inviter', 'object' => 'User', 'identifier' => 'u1'],
        ]);
    }

    public function testPollFinalizedAcceptsOptionalOption(): void
    {
        $result = $this->normalize('poll_finalized', [
            ['relation' => 'poll', 'object' => 'Poll', 'identifier' => 'p1'],
            ['relation' => 'finalized_option', 'object' => 'PollOption', 'identifier' => 'o1'],
        ]);

        $this->assertCount(2, $result);
    }

    public function testPollFinalizedWithoutOption(): void
    {
        $result = $this->normalize('poll_finalized', [
            ['relation' => 'poll', 'object' => 'Poll', 'identifier' => 'p1'],
        ]);

        $this->assertCount(1, $result);
    }

    public function testPollCounterProposalNormalization(): void
    {
        $result = $this->normalize('poll_counter_proposal', [
            ['relation' => 'poll', 'object' => 'Poll', 'identifier' => 'p1'],
            ['relation' => 'proposer', 'object' => 'User', 'identifier' => 'u1'],
            ['relation' => 'option', 'object' => 'PollOption', 'identifier' => 'o1'],
        ]);

        $this->assertCount(3, $result);
    }

    public function testPollDeadlineReminderNormalization(): void
    {
        $result = $this->normalize('poll_deadline_reminder', [
            ['relation' => 'poll', 'object' => 'Poll', 'identifier' => 'p1'],
        ]);

        $this->assertCount(1, $result);
    }

    public function testPollDataRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalize('poll_deadline_reminder', null);
    }
}
