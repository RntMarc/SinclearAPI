<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sinclear\Api\Services\Centrifugo\ChatEventPublisher;

class ChatEventPublisherTest extends TestCase
{
    private FakeCentrifugoClient $fakeClient;
    private ChatEventPublisher $publisher;

    protected function setUp(): void
    {
        $this->fakeClient = new FakeCentrifugoClient();
        $this->publisher = new ChatEventPublisher(
            client: $this->fakeClient,
            logger: new NullLogger(),
        );
    }

    public function testPublishMessageCreatedPublishesToCorrectChannel(): void
    {
        $message = $this->createFormattedMessage('conv-1');

        $this->publisher->publishMessageCreated($message);

        $this->assertSame('chat:conv-1', $this->fakeClient->publishedChannel);
    }

    public function testPublishMessageCreatedPayload(): void
    {
        $message = $this->createFormattedMessage('conv-1');

        $this->publisher->publishMessageCreated($message);

        $this->assertSame('message_created', $this->fakeClient->publishedData['type']);
        $this->assertArrayHasKey('message', $this->fakeClient->publishedData);
        $this->assertSame('msg-1', $this->fakeClient->publishedData['message']['id']);
    }

    public function testPublishMessageEditedPayload(): void
    {
        $message = $this->createFormattedMessage('conv-2');

        $this->publisher->publishMessageEdited($message);

        $this->assertSame('chat:conv-2', $this->fakeClient->publishedChannel);
        $this->assertSame('message_edited', $this->fakeClient->publishedData['type']);
        $this->assertArrayHasKey('message', $this->fakeClient->publishedData);
    }

    public function testPublishMessageDeletedPayload(): void
    {
        $this->publisher->publishMessageDeleted('conv-3', 'msg-3', 42);

        $this->assertSame('chat:conv-3', $this->fakeClient->publishedChannel);
        $this->assertSame('message_deleted', $this->fakeClient->publishedData['type']);
        $this->assertSame('msg-3', $this->fakeClient->publishedData['messageId']);
        $this->assertSame(42, $this->fakeClient->publishedData['seq']);
    }

    public function testPublishReadPayload(): void
    {
        $this->publisher->publishRead('conv-4', 10, 10, 'user-1');

        $this->assertSame('chat:conv-4', $this->fakeClient->publishedChannel);
        $this->assertSame('read', $this->fakeClient->publishedData['type']);
        $this->assertSame(10, $this->fakeClient->publishedData['seq']);
        $this->assertSame(10, $this->fakeClient->publishedData['lastReadSeq']);
        $this->assertSame('user-1', $this->fakeClient->publishedData['userId']);
    }

    public function testPublishReadSkipsHistory(): void
    {
        $this->publisher->publishRead('conv-4', 10, 10, 'user-1');

        $this->assertTrue($this->fakeClient->publishedSkipHistory);
    }

    public function testPublishMessageCreatedDoesNotSkipHistory(): void
    {
        $this->publisher->publishMessageCreated($this->createFormattedMessage('conv-1'));

        $this->assertFalse($this->fakeClient->publishedSkipHistory);
    }

    public function testPublishMessageCreatedToleratesClientException(): void
    {
        $this->fakeClient->publishException = new \RuntimeException('Centrifugo unavailable');

        $message = $this->createFormattedMessage('conv-err');

        // Must not throw
        $this->publisher->publishMessageCreated($message);
        $this->assertTrue(true);
    }

    public function testPublishMessageDeletedToleratesClientException(): void
    {
        $this->fakeClient->publishException = new \RuntimeException('Connection refused');

        // Must not throw
        $this->publisher->publishMessageDeleted('conv-err', 'msg-err', 1);
        $this->assertTrue(true);
    }

    public function testPublishReadToleratesClientException(): void
    {
        $this->fakeClient->publishException = new \RuntimeException('Timeout');

        // Must not throw
        $this->publisher->publishRead('conv-err', 1, 1, 'user-1');
        $this->assertTrue(true);
    }

    private function createFormattedMessage(string $conversationId): array
    {
        return [
            'id' => 'msg-1',
            'seq' => 1,
            'conversationId' => $conversationId,
            'senderId' => 'user-1',
            'sender' => [
                'id' => 'user-1',
                'displayName' => 'Alice',
                'avatar' => null,
            ],
            'type' => 'text',
            'content' => 'Hallo!',
            'payload' => null,
            'clientId' => null,
            'editedAt' => null,
            'deleted' => false,
            'createdAt' => '2025-01-15 10:30:00',
        ];
    }
}
