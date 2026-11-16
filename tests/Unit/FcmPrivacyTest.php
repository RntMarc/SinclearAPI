<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\FcmPushService;
use Sinclear\Api\Services\TravelNotificationService;

/**
 * DB-freier Unit-Test zur Absicherung der strikten FCM-Datenschutzregel
 * (keine personalisierten Inhalte über Google-Server) sowie der dynamischen
 * Text-Generierung.
 */
class FcmPrivacyTest extends TestCase
{
    public function testWakeupPayloadContainsOnlyTokenWakeupAndId(): void
    {
        $payload = FcmPushService::buildWakeupPayload('fcm-token-123', 'notif-uuid-456');

        $this->assertSame([
            'message' => [
                'token' => 'fcm-token-123',
                'data' => [
                    'wakeup' => '1',
                    'notificationId' => 'notif-uuid-456',
                ],
                'android' => [
                    'priority' => 'high',
                ],
            ],
        ], $payload);

        // Strikte Regel: Niemals darf ein `notification`-Objekt, `title`, `body`,
        // `text`, `type` oder `relations` im FCM-Payload enthalten sein.
        $this->assertArrayNotHasKey('notification', $payload['message']);
        $this->assertArrayNotHasKey('title', $payload['message']['data']);
        $this->assertArrayNotHasKey('body', $payload['message']['data']);
        $this->assertArrayNotHasKey('text', $payload['message']['data']);
        $this->assertArrayNotHasKey('type', $payload['message']['data']);
        $this->assertArrayNotHasKey('relations', $payload['message']['data']);
        $this->assertCount(2, $payload['message']['data']);
    }

    public function testTravelChangedFieldsTextGeneration(): void
    {
        $this->assertSame(
            'Der Name wurde geändert.',
            TravelNotificationService::buildChangedFieldsText(['Name']),
        );
        $this->assertSame(
            'Die Beschreibung wurde geändert.',
            TravelNotificationService::buildChangedFieldsText(['Beschreibung']),
        );
        $this->assertSame(
            'Der Zeitraum wurde geändert.',
            TravelNotificationService::buildChangedFieldsText(['Startdatum', 'Enddatum']),
        );
        $this->assertSame(
            'Die Ticket-Informationen wurden geändert.',
            TravelNotificationService::buildChangedFieldsText(['Ticket-Informationen', 'Ticket-URL']),
        );
        $this->assertSame(
            'Die Felder Name, Beschreibung wurden geändert.',
            TravelNotificationService::buildChangedFieldsText(['Name', 'Beschreibung']),
        );
    }
}
