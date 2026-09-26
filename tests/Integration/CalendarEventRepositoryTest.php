<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\CalendarEventRepository;

/**
 * Praezisiert CalendarEventRepository gegen MySQL inkl. Strict-Mode:
 * Getaktete Events werden als UTC-Instants gespeichert, ganztägige als
 * zivile Tage; die IANA-Zeitzone bleibt erhalten.
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
                allDay tinyint(1) NOT NULL DEFAULT 0,
                timezone varchar(64) NOT NULL DEFAULT 'Europe/Berlin',
                startAt datetime(3) DEFAULT NULL,
                endAt datetime(3) DEFAULT NULL,
                startDate date DEFAULT NULL,
                endDate date DEFAULT NULL,
                visibility tinyint(1) NOT NULL DEFAULT 0,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY idx_calendar_creator (creatorId),
                KEY idx_calendar_instant (startAt, endAt),
                KEY idx_calendar_day (startDate, endDate)
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

    public function testCreatePersistsTimedEventAsUtcInstant(): void
    {
        $id = $this->repo->create('user-1', [
            'title' => 'Dinner',
            'description' => 'with team',
            'allDay' => false,
            'timezone' => 'Europe/Berlin',
            'startAt' => '2026-09-26T20:00:00+02:00',
            'endAt' => '2026-09-26T23:59:00+02:00',
            'visibility' => 0,
        ]);

        $row = $this->fetchEvent($id);

        $this->assertEquals(0, $row['allDay']);
        $this->assertSame('Europe/Berlin', $row['timezone']);
        $this->assertSame('2026-09-26 18:00:00', $row['startAt']);
        $this->assertSame('2026-09-26 21:59:00', $row['endAt']);
        $this->assertNull($row['startDate']);
        $this->assertNull($row['endDate']);
        $this->assertEquals(0, $row['visibility']);
    }

    public function testCreatePersistsAllDayEventWithCivilDates(): void
    {
        $id = $this->repo->create('user-1', [
            'title' => 'Holiday',
            'description' => null,
            'allDay' => true,
            'timezone' => 'Europe/Berlin',
            'startDate' => '2026-10-01',
            'endDate' => '2026-10-02',
            'visibility' => 1,
        ]);

        $row = $this->fetchEvent($id);

        $this->assertEquals(1, $row['allDay']);
        $this->assertNull($row['startAt']);
        $this->assertNull($row['endAt']);
        $this->assertSame('2026-10-01', $row['startDate']);
        $this->assertSame('2026-10-02', $row['endDate']);
        $this->assertEquals(1, $row['visibility']);
    }

    public function testCreateNormalizesEmptyValuesToNull(): void
    {
        $id = $this->repo->create('user-1', [
            'title' => 'No times',
            'allDay' => true,
            'timezone' => '',
            'startDate' => '2026-11-01',
            'endDate' => '2026-11-01',
            'startAt' => '',
            'endAt' => null,
            'visibility' => 0,
        ]);

        $row = $this->fetchEvent($id);

        $this->assertNull($row['startAt']);
        $this->assertNull($row['endAt']);
        $this->assertSame('Europe/Berlin', $row['timezone']);
    }

    public function testUpdateTogglesAllDayFlagAndClearsOtherFields(): void
    {
        $id = $this->repo->create('user-1', [
            'title' => 'Toggle me',
            'allDay' => true,
            'timezone' => 'Europe/Berlin',
            'startDate' => '2026-12-24',
            'endDate' => '2026-12-24',
            'visibility' => 0,
        ]);

        $this->repo->update($id, [
            'allDay' => 0,
            'startAt' => '2026-12-24T18:30:00+01:00',
            'endAt' => '2026-12-24T20:00:00+01:00',
            'startDate' => null,
            'endDate' => null,
            'visibility' => 2,
        ]);

        $row = $this->fetchEvent($id);

        $this->assertEquals(0, $row['allDay']);
        $this->assertSame('2026-12-24 17:30:00', $row['startAt']);
        $this->assertSame('2026-12-24 19:00:00', $row['endAt']);
        $this->assertNull($row['startDate']);
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
