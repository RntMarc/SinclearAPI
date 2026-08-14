<?php

namespace Sinclear\Api\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\CalendarEventRepository;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Repository\PtJourneyRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Services\CalendarEventService;
use Sinclear\Api\Services\CalendarFeedService;

class CalendarFeedServiceTest extends TestCase
{
    private PDO $db;
    private CalendarFeedService $service;

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

        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ([
            'CalendarEventParticipant', 'CalendarEvent', 'CloseFriend', 'UserPreferences',
            'EventRelation', 'TravelRelation', 'TravelEvent', 'TravelTrip',
            'PtParticipant', 'PtLeg', 'PtJourney', 'User',
        ] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->exec("
            CREATE TABLE User (
                id varchar(191) NOT NULL PRIMARY KEY,
                email varchar(191) NOT NULL UNIQUE,
                passwordHash varchar(191) NOT NULL,
                displayName varchar(191) NOT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                birthday datetime(3) DEFAULT NULL,
                isAdmin tinyint NOT NULL DEFAULT 0,
                discordId varchar(191) DEFAULT NULL,
                image text DEFAULT NULL,
                discordAvatarHash varchar(255) DEFAULT NULL
            )
        ");
        $this->db->exec("
            CREATE TABLE UserPreferences (
                id varchar(191) NOT NULL PRIMARY KEY,
                userId varchar(191) NOT NULL,
                birthdayVisibility tinyint NOT NULL DEFAULT 1,
                UNIQUE KEY idx_prefs_user (userId)
            )
        ");
        $this->db->exec("
            CREATE TABLE CloseFriend (
                userId varchar(191) NOT NULL,
                friendId varchar(191) NOT NULL,
                createdAt datetime(3) DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (userId, friendId)
            )
        ");
        $this->db->exec("
            CREATE TABLE CalendarEvent (
                id varchar(191) NOT NULL PRIMARY KEY,
                creatorId varchar(191) NOT NULL,
                title varchar(255) NOT NULL,
                description text DEFAULT NULL,
                startTime datetime(3) NOT NULL,
                endTime datetime(3) NOT NULL,
                visibility tinyint NOT NULL DEFAULT 0,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            )
        ");
        $this->db->exec("
            CREATE TABLE CalendarEventParticipant (
                eventId varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                addedAt datetime(3) DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (eventId, userId)
            )
        ");
        $this->db->exec("
            CREATE TABLE TravelTrip (
                id varchar(191) NOT NULL PRIMARY KEY,
                name varchar(255) DEFAULT NULL,
                description text DEFAULT NULL,
                start datetime(3) DEFAULT NULL,
                end datetime(3) DEFAULT NULL,
                hastickets varchar(255) DEFAULT NULL,
                ticket text DEFAULT NULL,
                ticketUrl text DEFAULT NULL,
                forumId varchar(191) DEFAULT NULL
            )
        ");
        $this->db->exec("
            CREATE TABLE TravelRelation (
                ID varchar(191) NOT NULL PRIMARY KEY,
                userid varchar(191) NOT NULL,
                tripid varchar(191) NOT NULL,
                accommodation varchar(191) DEFAULT NULL
            )
        ");
        $this->db->exec("
            CREATE TABLE TravelEvent (
                ID varchar(191) NOT NULL PRIMARY KEY,
                trip varchar(191) DEFAULT NULL,
                name varchar(255) DEFAULT NULL,
                description text DEFAULT NULL,
                start datetime(3) DEFAULT NULL,
                end datetime(3) DEFAULT NULL,
                hastickets varchar(255) DEFAULT NULL,
                ticket text DEFAULT NULL,
                ticketUrl text DEFAULT NULL,
                url text DEFAULT NULL,
                image text DEFAULT NULL,
                organizer varchar(255) DEFAULT NULL,
                address varchar(255) DEFAULT NULL,
                latitude varchar(64) DEFAULT NULL,
                longitude varchar(64) DEFAULT NULL,
                OSMID varchar(64) DEFAULT NULL
            )
        ");
        $this->db->exec("
            CREATE TABLE EventRelation (
                id varchar(191) NOT NULL PRIMARY KEY,
                eventId varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                createdAt datetime(3) DEFAULT CURRENT_TIMESTAMP(3)
            )
        ");
        $this->db->exec("
            CREATE TABLE PtJourney (
                id varchar(191) NOT NULL PRIMARY KEY,
                tripId varchar(191) DEFAULT NULL,
                creatorId varchar(191) NOT NULL,
                fromStationId varchar(191) DEFAULT NULL,
                fromStationName varchar(255) DEFAULT NULL,
                toStationId varchar(191) DEFAULT NULL,
                toStationName varchar(255) DEFAULT NULL,
                departureTime datetime(3) DEFAULT NULL,
                arrivalTime datetime(3) DEFAULT NULL,
                duration int DEFAULT NULL,
                transfers int DEFAULT NULL,
                chosenAt datetime(3) DEFAULT NULL,
                createdAt datetime(3) DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) DEFAULT CURRENT_TIMESTAMP(3)
            )
        ");
        $this->db->exec("
            CREATE TABLE PtParticipant (
                journeyId varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                addedAt datetime(3) DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (journeyId, userId)
            )
        ");
        $this->db->exec("
            CREATE TABLE PtLeg (
                id varchar(191) NOT NULL PRIMARY KEY,
                journeyId varchar(191) NOT NULL,
                legIndex int NOT NULL DEFAULT 0,
                mode varchar(64) DEFAULT NULL,
                lineName varchar(255) DEFAULT NULL,
                lineProduct varchar(64) DEFAULT NULL,
                fromStationId varchar(191) DEFAULT NULL,
                fromStationName varchar(255) DEFAULT NULL,
                toStationId varchar(191) DEFAULT NULL,
                toStationName varchar(255) DEFAULT NULL,
                tripId varchar(191) DEFAULT NULL,
                plannedDeparture datetime(3) DEFAULT NULL,
                plannedArrival datetime(3) DEFAULT NULL,
                actualDeparture datetime(3) DEFAULT NULL,
                actualArrival datetime(3) DEFAULT NULL,
                departureDelay int DEFAULT NULL,
                arrivalDelay int DEFAULT NULL,
                departurePlatform varchar(64) DEFAULT NULL,
                arrivalPlatform varchar(64) DEFAULT NULL,
                cancelled tinyint(1) NOT NULL DEFAULT 0,
                realTimeState varchar(64) DEFAULT NULL,
                rawResponse text DEFAULT NULL,
                createdAt datetime(3) DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) DEFAULT CURRENT_TIMESTAMP(3)
            )
        ");

        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('user-1', 'a@test.com', 'hash', 'Alice')");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, birthday) VALUES ('user-2', 'b@test.com', 'hash', 'Bob', '1990-05-12')");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, birthday) VALUES ('user-3', 'c@test.com', 'hash', 'Carol', '1991-06-15')");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName, birthday) VALUES ('user-4', 'd@test.com', 'hash', 'Dave', '1992-02-29')");
        $this->db->exec("INSERT INTO User (id, email, passwordHash, displayName) VALUES ('user-5', 'e@test.com', 'hash', 'Eve')");

        $this->db->exec("INSERT INTO UserPreferences (id, userId, birthdayVisibility) VALUES ('pref-2', 'user-2', 1)");
        $this->db->exec("INSERT INTO UserPreferences (id, userId, birthdayVisibility) VALUES ('pref-3', 'user-3', 0)");
        $this->db->exec("INSERT INTO UserPreferences (id, userId, birthdayVisibility) VALUES ('pref-4', 'user-4', 2)");

        $this->db->exec("INSERT INTO CloseFriend (userId, friendId) VALUES ('user-4', 'user-1')");

        $repo = new CalendarEventRepository($this->db);
        $closeFriendRepo = new CloseFriendRepository($this->db);
        $calendarEventService = new CalendarEventService(eventRepo: $repo, closeFriendRepo: $closeFriendRepo);

        $this->service = new CalendarFeedService(
            calendarEventService: $calendarEventService,
            travelEventRepo: new TravelEventRepository($this->db),
            tripRepo: new TravelTripRepository($this->db),
            ptJourneyRepo: new PtJourneyRepository($this->db),
            userRepo: new UserRepository($this->db),
        );
    }

    protected function tearDown(): void
    {
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ([
            'CalendarEventParticipant', 'CalendarEvent', 'CloseFriend', 'UserPreferences',
            'EventRelation', 'TravelRelation', 'TravelEvent', 'TravelTrip',
            'PtParticipant', 'PtLeg', 'PtJourney', 'User',
        ] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    private function insertCalendarEvent(string $id, string $creatorId, string $start, string $end, int $visibility): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO CalendarEvent (id, creatorId, title, startTime, endTime, visibility) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $creatorId, 'Event ' . $id, $start, $end, $visibility]);
    }

    private function itemIds(array $items, string $type): array
    {
        return array_values(array_map(
            fn(array $item) => $item['id'],
            array_filter($items, fn(array $item) => $item['type'] === $type),
        ));
    }

    public function testCalendarEventsRespectVisibility(): void
    {
        $this->insertCalendarEvent('ev-1', 'user-1', '2026-03-10 10:00:00', '2026-03-10 12:00:00', 0);
        $this->insertCalendarEvent('ev-2', 'user-2', '2026-03-11 10:00:00', '2026-03-11 12:00:00', 1);
        $this->insertCalendarEvent('ev-3', 'user-2', '2026-03-12 10:00:00', '2026-03-12 12:00:00', 0);
        $this->insertCalendarEvent('ev-4', 'user-4', '2026-03-13 10:00:00', '2026-03-13 12:00:00', 2);
        $this->insertCalendarEvent('ev-5', 'user-3', '2026-03-14 10:00:00', '2026-03-14 12:00:00', 2);
        $this->insertCalendarEvent('ev-6', 'user-2', '2025-03-11 10:00:00', '2025-03-11 12:00:00', 1);

        $feed = $this->service->buildFeed('user-1', '2026-01-01 00:00:00', '2026-12-31 23:59:59', CalendarFeedService::SUPPORTED_TYPES);

        $ids = $this->itemIds($feed['data'], 'calendar_event');
        $this->assertContains('ev-1', $ids);
        $this->assertContains('ev-2', $ids);
        $this->assertContains('ev-4', $ids);
        $this->assertNotContains('ev-3', $ids);
        $this->assertNotContains('ev-5', $ids);
        $this->assertNotContains('ev-6', $ids);
    }

    public function testTripsAndTravelEventsRespectParticipation(): void
    {
        $this->db->exec("INSERT INTO TravelTrip (id, name, start, end) VALUES ('trip-1', 'Berlin Trip', '2026-07-01 00:00:00', '2026-07-05 23:59:59')");
        $this->db->exec("INSERT INTO TravelTrip (id, name, start, end) VALUES ('trip-2', 'Köln Trip', '2026-07-10 00:00:00', '2026-07-12 23:59:59')");
        $this->db->exec("INSERT INTO TravelRelation (ID, userid, tripid) VALUES ('rel-1', 'user-1', 'trip-1')");
        $this->db->exec("INSERT INTO TravelRelation (ID, userid, tripid) VALUES ('rel-2', 'user-2', 'trip-2')");

        $this->db->exec("INSERT INTO TravelEvent (ID, trip, name, start, end) VALUES ('event-1', 'trip-1', 'Museum', '2026-07-02 09:00:00', '2026-07-02 11:00:00')");
        $this->db->exec("INSERT INTO TravelEvent (ID, trip, name, start, end) VALUES ('event-2', NULL, 'Konzert', '2026-08-01 20:00:00', '2026-08-01 23:00:00')");
        $this->db->exec("INSERT INTO TravelEvent (ID, trip, name, start, end) VALUES ('event-3', NULL, 'Theater', '2026-08-02 20:00:00', '2026-08-02 23:00:00')");
        $this->db->exec("INSERT INTO TravelEvent (ID, trip, name, start, end) VALUES ('event-4', 'trip-2', 'Dom', '2026-07-11 10:00:00', '2026-07-11 12:00:00')");
        $this->db->exec("INSERT INTO EventRelation (id, eventId, userId) VALUES ('er-1', 'event-2', 'user-1')");
        $this->db->exec("INSERT INTO EventRelation (id, eventId, userId) VALUES ('er-2', 'event-3', 'user-2')");

        $feed = $this->service->buildFeed('user-1', '2026-01-01 00:00:00', '2026-12-31 23:59:59', CalendarFeedService::SUPPORTED_TYPES);

        $tripIds = $this->itemIds($feed['data'], 'trip');
        $this->assertSame(['trip-1'], $tripIds);

        $tripItem = array_values(array_filter($feed['data'], fn(array $i) => $i['id'] === 'trip-1'))[0];
        $this->assertTrue($tripItem['allDay']);
        $this->assertSame('Berlin Trip', $tripItem['title']);

        $eventIds = $this->itemIds($feed['data'], 'travel_event');
        $this->assertContains('event-1', $eventIds);
        $this->assertContains('event-2', $eventIds);
        $this->assertNotContains('event-3', $eventIds);
        $this->assertNotContains('event-4', $eventIds);
    }

    public function testBirthdaysRespectVisibilityAndRecurrence(): void
    {
        $feed = $this->service->buildFeed('user-1', '2026-01-01 00:00:00', '2026-12-31 23:59:59', CalendarFeedService::SUPPORTED_TYPES);

        $birthdays = array_values(array_filter($feed['data'], fn(array $i) => $i['type'] === 'birthday'));
        $titles = array_column($birthdays, 'title');

        $this->assertContains('Geburtstag: Bob', $titles);
        $this->assertNotContains('Geburtstag: Carol', $titles);
        $this->assertNotContains('Geburtstag: Dave', $titles);

        $bob = array_values(array_filter($birthdays, fn(array $i) => $i['title'] === 'Geburtstag: Bob'))[0];
        $this->assertSame('2026-05-12 00:00:00', $bob['startTime']);
        $this->assertSame('2026-05-12 23:59:59', $bob['endTime']);
        $this->assertTrue($bob['allDay']);
    }

    public function testBirthdayLeapYearOccurrence(): void
    {
        $feed = $this->service->buildFeed('user-1', '2024-01-01 00:00:00', '2024-12-31 23:59:59', CalendarFeedService::SUPPORTED_TYPES);

        $birthdays = array_values(array_filter($feed['data'], fn(array $i) => $i['type'] === 'birthday'));
        $titles = array_column($birthdays, 'title');

        $this->assertContains('Geburtstag: Dave', $titles);
    }

    public function testPtJourneysRespectParticipationAndIncludeLegs(): void
    {
        $this->db->exec("INSERT INTO PtJourney (id, creatorId, fromStationName, toStationName, departureTime, arrivalTime, duration, transfers, chosenAt) VALUES ('journey-1', 'user-1', 'Hbf', 'Flughafen', '2026-09-01 08:00:00', '2026-09-01 09:30:00', 90, 1, '2026-09-01 07:50:00')");
        $this->db->exec("INSERT INTO PtJourney (id, creatorId, fromStationName, toStationName, departureTime, arrivalTime, duration, transfers, chosenAt) VALUES ('journey-2', 'user-2', 'Hbf', 'Zoo', '2026-09-02 08:00:00', '2026-09-02 08:30:00', 30, 0, '2026-09-02 07:50:00')");
        $this->db->exec("INSERT INTO PtParticipant (journeyId, userId) VALUES ('journey-1', 'user-1')");
        $this->db->exec("INSERT INTO PtParticipant (journeyId, userId) VALUES ('journey-2', 'user-2')");
        $this->db->exec("INSERT INTO PtLeg (id, journeyId, legIndex, mode, lineName, plannedDeparture, plannedArrival, cancelled) VALUES ('leg-1', 'journey-1', 0, 'bus', 'LINIE 10', '2026-09-01 08:00:00', '2026-09-01 08:30:00', 0)");
        $this->db->exec("INSERT INTO PtLeg (id, journeyId, legIndex, mode, lineName, plannedDeparture, plannedArrival, cancelled) VALUES ('leg-2', 'journey-1', 1, 'train', 'S1', '2026-09-01 08:40:00', '2026-09-01 09:30:00', 0)");

        $feed = $this->service->buildFeed('user-1', '2026-01-01 00:00:00', '2026-12-31 23:59:59', CalendarFeedService::SUPPORTED_TYPES);

        $journeyIds = $this->itemIds($feed['data'], 'pt_journey');
        $this->assertSame(['journey-1'], $journeyIds);

        $journey = array_values(array_filter($feed['data'], fn(array $i) => $i['id'] === 'journey-1'))[0];
        $this->assertSame('Hbf → Flughafen', $journey['title']);
        $this->assertCount(2, $journey['detail']['legs']);
    }

    public function testTypeFilter(): void
    {
        $this->insertCalendarEvent('ev-1', 'user-1', '2026-03-10 10:00:00', '2026-03-10 12:00:00', 0);
        $this->db->exec("INSERT INTO TravelTrip (id, name, start, end) VALUES ('trip-1', 'Berlin Trip', '2026-07-01 00:00:00', '2026-07-05 23:59:59')");
        $this->db->exec("INSERT INTO TravelRelation (ID, userid, tripid) VALUES ('rel-1', 'user-1', 'trip-1')");

        $feed = $this->service->buildFeed('user-1', '2026-01-01 00:00:00', '2026-12-31 23:59:59', ['trip']);

        $this->assertCount(1, $feed['data']);
        $this->assertSame('trip', $feed['data'][0]['type']);
        $this->assertSame(['trip'], $feed['meta']['types']);
    }

    public function testItemsAreSortedByStartTime(): void
    {
        $this->insertCalendarEvent('ev-late', 'user-1', '2026-06-01 10:00:00', '2026-06-01 12:00:00', 0);
        $this->insertCalendarEvent('ev-early', 'user-1', '2026-02-01 10:00:00', '2026-02-01 12:00:00', 0);
        $this->db->exec("INSERT INTO TravelTrip (id, name, start, end) VALUES ('trip-mid', 'Berlin Trip', '2026-04-01 00:00:00', '2026-04-05 23:59:59')");
        $this->db->exec("INSERT INTO TravelRelation (ID, userid, tripid) VALUES ('rel-1', 'user-1', 'trip-mid')");

        $feed = $this->service->buildFeed('user-1', '2026-01-01 00:00:00', '2026-12-31 23:59:59', CalendarFeedService::SUPPORTED_TYPES);

        $startTimes = array_column($feed['data'], 'startTime');
        $sorted = $startTimes;
        sort($sorted);
        $this->assertSame($sorted, $startTimes);

        $insertedIds = array_values(array_map(
            fn(array $item) => $item['id'],
            array_filter(
                $feed['data'],
                fn(array $item) => in_array($item['id'], ['ev-early', 'trip-mid', 'ev-late'], true),
            ),
        ));
        $this->assertSame(['ev-early', 'trip-mid', 'ev-late'], $insertedIds);
    }

    public function testTruncationIsReported(): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO CalendarEvent (id, creatorId, title, startTime, endTime, visibility) VALUES (?, ?, ?, ?, ?, ?)'
        );
        for ($i = 1; $i <= 501; $i++) {
            $stmt->execute([
                'ev-' . $i,
                'user-1',
                'Event ' . $i,
                '2026-03-10 10:00:00',
                '2026-03-10 12:00:00',
                0,
            ]);
        }

        $feed = $this->service->buildFeed('user-1', '2026-01-01 00:00:00', '2026-12-31 23:59:59', ['calendar_event']);

        $this->assertTrue($feed['meta']['truncated']);
        $this->assertCount(500, $feed['data']);
    }

    public function testInvalidDatetimeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid datetime');

        $this->service->buildFeed('user-1', '2026-13-01 00:00:00', '2026-12-31 23:59:59', CalendarFeedService::SUPPORTED_TYPES);
    }
}
