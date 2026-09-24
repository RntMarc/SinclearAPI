<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\CalendarEventRepository;

/**
 * Praezisiert CalendarEventRepository gegen MySQL inkl. Strict-Mode:
 * PDO bindet execute()-Parameter als String, daher duerfen Booleans und
 * leere Zeiten hier nicht roh durchgereicht werden.
 */
final class CalendarEventRepositoryTest extends TestCase
{
    private PDO $db;
    private CalendarEventRepository $repo;

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

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->exec('DROP TABLE IF EXISTS CalendarEventParticipant');
        $this->db->exec('DROP TABLE IF EXISTS CalendarEvent');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->exec("
            CREATE TABLE CalendarEvent (
                id varchar(191) NOT NULL PRIMARY KEY,
                creatorId varchar(191) NOT NULL,
                title varchar(255) NOT NULL,
                description text DEFAULT NULL,
                startDate date NOT NULL,
                endDate date NOT NULL,
                startTime time DEFAULT NULL,
                endTime time DEFAULT NULL,
                allDay tinyint(1) NOT NULL DEFAULT 0,
                visibility tinyint(1) NOT NULL DEFAULT 0,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY idx_calendar_creator (creatorId),
                KEY idx_calendar_time (startDate, endDate)
            )
        ");

        $this->repo = new CalendarEventRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->exec('DROP TABLE IF EXISTS CalendarEvent');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testCreatePersistsTimedEventWithFalseAllDay(): void
    {
        $id = $this->repo->create('user-1', [
            'title' => 'Dinner',
            'description' => 'with team',
            'startDate' => '2026-09-26',
            'endDate' => '2026-09-26',
            'startTime' => '20:00:00',
            'endTime' => '23:59:00',
            'allDay' => false,
            'visibility' => 0,
        ]);

        $row = $this->fetchEvent($id);

        $this->assertEquals(0, $row['allDay']);
        $this->assertSame('2026-09-26', $row['startDate']);
        $this->assertSame('2026-09-26', $row['endDate']);
        $this->assertSame('20:00:00', $row['startTime']);
        $this->assertSame('23:59:00', $row['endTime']);
        $this->assertEquals(0, $row['visibility']);
    }

    public function testCreatePersistsAllDayEventWithoutTimes(): void
    {
        $id = $this->repo->create('user-1', [
            'title' => 'Holiday',
            'description' => null,
            'startDate' => '2026-10-01',
            'endDate' => '2026-10-02',
            'startTime' => null,
            'endTime' => null,
            'allDay' => true,
            'visibility' => 1,
        ]);

        $row = $this->fetchEvent($id);

        $this->assertEquals(1, $row['allDay']);
        $this->assertNull($row['startTime']);
        $this->assertNull($row['endTime']);
        $this->assertEquals(1, $row['visibility']);
        $this->assertSame('2026-10-02', $row['endDate']);
    }

    public function testCreateNormalizesEmptyTimesToNull(): void
    {
        $id = $this->repo->create('user-1', [
            'title' => 'No times',
            'startDate' => '2026-11-01',
            'endDate' => '2026-11-01',
            'startTime' => '',
            'endTime' => '',
            'allDay' => false,
            'visibility' => 0,
        ]);

        $row = $this->fetchEvent($id);

        $this->assertNull($row['startTime']);
        $this->assertNull($row['endTime']);
    }

    public function testUpdateTogglesAllDayFlagAndTimes(): void
    {
        $id = $this->repo->create('user-1', [
            'title' => 'Toggle me',
            'startDate' => '2026-12-24',
            'endDate' => '2026-12-24',
            'startTime' => null,
            'endTime' => null,
            'allDay' => true,
            'visibility' => 0,
        ]);

        $this->repo->update($id, [
            'allDay' => false,
            'startTime' => '18:30:00',
            'endTime' => null,
            'visibility' => 2,
        ]);

        $row = $this->fetchEvent($id);

        $this->assertEquals(0, $row['allDay']);
        $this->assertSame('18:30:00', $row['startTime']);
        $this->assertNull($row['endTime']);
        $this->assertEquals(2, $row['visibility']);
    }

    private function fetchEvent(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM CalendarEvent WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, 'Angelegtes Event in der Datenbank gefunden');

        return $row;
    }
}
