<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\LaMetricTokenRepository;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Services\LaMetricSummaryService;
use Sinclear\Api\Services\LaMetricTokenService;

class LaMetricIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $_ENV['DB_HOST'] ?? '127.0.0.1',
                $_ENV['DB_PORT'] ?? '3306',
                $_ENV['DB_NAME'] ?? 'sinclear_test',
            ),
            $_ENV['DB_USER'] ?? 'root',
            $_ENV['DB_PASSWORD'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        $this->db->exec("SET time_zone = '+00:00'");

        $this->db->exec('DROP TABLE IF EXISTS LaMetricToken');
        $this->db->exec('DROP TABLE IF EXISTS Notification');

        $this->db->exec("
            CREATE TABLE LaMetricToken (
                id varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                label varchar(100) NOT NULL DEFAULT 'LaMetric Time',
                token varchar(64) NOT NULL,
                expiresAt datetime NOT NULL,
                lastUsedAt datetime(3) DEFAULT NULL,
                createdAt datetime(3) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY idx_lametric_token_value (token),
                UNIQUE KEY idx_lametric_token_user (userId)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE Notification (
                id varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                type varchar(191) NOT NULL,
                dedupeKey varchar(191) DEFAULT NULL,
                title varchar(255) NOT NULL,
                body text NOT NULL,
                data json DEFAULT NULL,
                isRead tinyint NOT NULL DEFAULT 0,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (id),
                KEY idx_notification_user_unread (userId, isRead)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function tokenService(): LaMetricTokenService
    {
        return new LaMetricTokenService(new LaMetricTokenRepository($this->db));
    }

    public function testSaveTokenReplacesExistingToken(): void
    {
        $service = $this->tokenService();

        $first = $service->saveToken('user-1', 'Wohnzimmer');
        self::assertSame('Wohnzimmer', $first['label']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['token']);

        $second = $service->saveToken('user-1', 'Küche');
        self::assertNotSame($first['token'], $second['token']);

        $count = (int) $this->db->query('SELECT COUNT(*) FROM LaMetricToken')->fetchColumn();
        self::assertSame(1, $count);

        $stored = $service->getToken('user-1');
        self::assertNotNull($stored);
        self::assertSame($second['token'], $stored['token']);
        self::assertSame('Küche', $stored['label']);
    }

    public function testSaveTokenUsesDefaultLabel(): void
    {
        $token = $this->tokenService()->saveToken('user-2', '   ');
        self::assertSame('LaMetric Time', $token['label']);
    }

    public function testValidateTokenReturnsUserId(): void
    {
        $service = $this->tokenService();
        $saved = $service->saveToken('user-3', 'Test');

        self::assertSame('user-3', $service->validateToken($saved['token']));
    }

    public function testValidateTokenRejectsUnknownAndMalformedTokens(): void
    {
        $service = $this->tokenService();

        self::assertNull($service->validateToken(str_repeat('a', 64)));
        self::assertNull($service->validateToken('too-short'));
        self::assertNull($service->validateToken(''));
    }

    public function testValidateTokenRejectsExpiredToken(): void
    {
        $token = str_repeat('b', 64);
        $stmt = $this->db->prepare(
            'INSERT INTO LaMetricToken (id, userId, label, token, expiresAt, lastUsedAt, createdAt)
             VALUES (?, ?, ?, ?, ?, NULL, NOW(3))'
        );
        $stmt->execute(['expired-id', 'user-4', 'Expired', $token, '2000-01-01 00:00:00']);

        self::assertNull($this->tokenService()->validateToken($token));
    }

    public function testDeleteToken(): void
    {
        $service = $this->tokenService();
        $service->saveToken('user-5', 'Test');

        self::assertTrue($service->deleteToken('user-5'));
        self::assertNull($service->getToken('user-5'));
        self::assertFalse($service->deleteToken('user-5'));
    }

    public function testSummaryBuildsSingleFrame(): void
    {
        $insert = $this->db->prepare(
            'INSERT INTO Notification (id, userId, type, title, body, isRead, createdAt)
             VALUES (?, ?, ?, ?, ?, 0, NOW(3))'
        );
        for ($i = 0; $i < 10; $i++) {
            $insert->execute(['c' . $i, 'user-6', 'direct_message', 'Neue Nachricht', '']);
        }
        $insert->execute(['r1', 'user-6', 'trip_user_added', 'Du wurdest zu einer Reise hinzugefügt', '']);
        $insert->execute(['read1', 'user-6', 'forum_post', 'Neuer Beitrag im Forum', '']);
        $this->db->exec("UPDATE Notification SET isRead = 1 WHERE id = 'read1'");

        $service = new LaMetricSummaryService(new NotificationRepository($this->db));
        $payload = $service->buildSummary('user-6');

        self::assertSame('10 neue Chats und 1 neue Reise', $payload['frames'][0]['text']);
    }

    public function testSummaryWithoutUnreadReturnsAllesGelesen(): void
    {
        $service = new LaMetricSummaryService(new NotificationRepository($this->db));
        $payload = $service->buildSummary('user-without-notifications');

        self::assertSame('Alles gelesen', $payload['frames'][0]['text']);
    }
}
