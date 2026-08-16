<?php

namespace Sinclear\Api\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\NotificationPreferenceRepository;
use Sinclear\Api\Services\NotificationPreferenceService;

class NotificationPreferenceServiceTest extends TestCase
{
    private PDO $db;
    private NotificationPreferenceService $service;

    protected function setUp(): void
    {
        $this->db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
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

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->exec('DROP TABLE IF EXISTS NotificationPreference');
        $this->db->exec('DROP TABLE IF EXISTS User');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->exec("
            CREATE TABLE User (
                id varchar(191) NOT NULL PRIMARY KEY,
                email varchar(191) NOT NULL,
                displayName varchar(191) NOT NULL
            )
        ");
        $this->db->exec("
            CREATE TABLE NotificationPreference (
                id varchar(191) NOT NULL PRIMARY KEY,
                userId varchar(191) NOT NULL,
                type varchar(64) NOT NULL,
                state varchar(16) NOT NULL DEFAULT 'enabled',
                data json DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY idx_notifpref_user_type (userId, type)
            )
        ");

        $this->db->exec("INSERT INTO User (id, email, displayName) VALUES ('user-1', 'a@test.com', 'Alice')");

        $this->service = new NotificationPreferenceService(new NotificationPreferenceRepository($this->db));
    }

    protected function tearDown(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->exec('DROP TABLE IF EXISTS NotificationPreference');
        $this->db->exec('DROP TABLE IF EXISTS User');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testGetAllReturnsEnabledDefaults(): void
    {
        $all = $this->service->getAll('user-1');

        $this->assertIsArray($all);
        $this->assertArrayHasKey('forum_comment', $all);
        $this->assertSame('enabled', $all['forum_comment']['state']);
        $this->assertSame(null, $all['forum_comment']['customData']);
        $this->assertTrue($all['forum_comment']['customAllowed']);
        $this->assertFalse($all['trip_user_added']['customAllowed']);
    }

    public function testUpdateDisablesType(): void
    {
        $result = $this->service->update('user-1', [
            ['type' => 'story_post', 'state' => 'disabled'],
        ]);

        $this->assertSame('disabled', $result['story_post']['state']);
    }

    public function testUpdateReEnablesType(): void
    {
        $this->service->update('user-1', [['type' => 'story_post', 'state' => 'disabled']]);
        $result = $this->service->update('user-1', [['type' => 'story_post', 'state' => 'enabled']]);

        $this->assertSame('enabled', $result['story_post']['state']);
    }

    public function testUpdateCustomForForumStoresData(): void
    {
        $result = $this->service->update('user-1', [
            ['type' => 'forum_comment', 'state' => 'custom', 'customData' => ['forumIds' => ['f1', 'f2']]],
        ]);

        $this->assertSame('custom', $result['forum_comment']['state']);
        $this->assertSame(['forumIds' => ['f1', 'f2']], $result['forum_comment']['customData']);
    }

    public function testUpdateCustomForNonCustomizableTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->update('user-1', [
            ['type' => 'trip_user_added', 'state' => 'custom', 'customData' => ['tripIds' => ['t1']]],
        ]);
    }

    public function testUpdateInvalidTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->update('user-1', [
            ['type' => 'does_not_exist', 'state' => 'disabled'],
        ]);
    }

    public function testUpdateInvalidStateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->update('user-1', [
            ['type' => 'story_post', 'state' => 'maybe'],
        ]);
    }

    public function testUpdateCustomWithoutDataThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->update('user-1', [
            ['type' => 'forum_comment', 'state' => 'custom'],
        ]);
    }

    public function testUpdateCustomWithWrongDataKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->update('user-1', [
            ['type' => 'forum_comment', 'state' => 'custom', 'customData' => ['userIds' => ['u1']]],
        ]);
    }

    public function testShouldSendDefaultIsTrue(): void
    {
        $this->assertTrue($this->service->shouldSend('user-1', 'forum_comment', null));
    }

    public function testShouldSendDisabledIsFalse(): void
    {
        $this->service->update('user-1', [['type' => 'forum_comment', 'state' => 'disabled']]);

        $this->assertFalse($this->service->shouldSend('user-1', 'forum_comment', null));
    }

    public function testShouldSendCustomMatchesForum(): void
    {
        $this->service->update('user-1', [
            ['type' => 'forum_comment', 'state' => 'custom', 'customData' => ['forumIds' => ['f1', 'f2']]],
        ]);

        $data = [
            ['relation' => 'parent_forum', 'object' => 'Forum', 'identifier' => 'f1'],
        ];

        $this->assertTrue($this->service->shouldSend('user-1', 'forum_comment', $data));
    }

    public function testShouldSendCustomDoesNotMatchForum(): void
    {
        $this->service->update('user-1', [
            ['type' => 'forum_comment', 'state' => 'custom', 'customData' => ['forumIds' => ['f1', 'f2']]],
        ]);

        $data = [
            ['relation' => 'parent_forum', 'object' => 'Forum', 'identifier' => 'f9'],
        ];

        $this->assertFalse($this->service->shouldSend('user-1', 'forum_comment', $data));
    }

    public function testShouldSendCustomStoryMatchesAuthor(): void
    {
        $this->service->update('user-1', [
            ['type' => 'story_post', 'state' => 'custom', 'customData' => ['userIds' => ['author-1']]],
        ]);

        $data = [
            ['relation' => 'story_author', 'object' => 'User', 'identifier' => 'author-1'],
        ];

        $this->assertTrue($this->service->shouldSend('user-1', 'story_post', $data));
    }
}
