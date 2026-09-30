<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\ChatConversationRepository;
use Sinclear\Api\Repository\ChatParticipantRepository;
use Sinclear\Api\Repository\EventRelationRepository;
use Sinclear\Api\Repository\NotificationPreferenceRepository;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Repository\TravelAccommodationRepository;
use Sinclear\Api\Repository\TravelChatRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelPlanAccommodationOptionRepository;
use Sinclear\Api\Repository\TravelPlanDateOptionRepository;
use Sinclear\Api\Repository\TravelPlanDateResponseRepository;
use Sinclear\Api\Repository\TravelPlanEventInterestRepository;
use Sinclear\Api\Repository\TravelPlanEventRepository;
use Sinclear\Api\Repository\TravelPlanMemberRepository;
use Sinclear\Api\Repository\TravelPlanTopicRepository;
use Sinclear\Api\Repository\TravelPlanTransportRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\TravelPlanningPolicy;
use Sinclear\Api\Services\NotificationPreferenceService;
use Sinclear\Api\Services\NotificationService;
use Sinclear\Api\Services\TravelChatService;
use Sinclear\Api\Services\TravelPlanningNotificationService;
use Sinclear\Api\Services\TravelPlanningService;
use Sinclear\Api\Tests\Unit\FakeCentrifugoClient;

/**
 * Integrationstests des Planungs-Flows (TravelTrip.state = 'planning').
 *
 * Benoetigen eine Datenbank und laufen daher nur auf dem Server (AGENTS.md).
 * Schwerpunkt: Zugriffsschutz und Idempotenz der Aktivierung sowie die
 * rennsicheren Schreibpfade der Planungs-Repositories.
 */
final class TravelPlanningIntegrationTest extends TestCase
{
    private PDO $db;
    private TravelPlanningService $service;
    private TravelTripRepository $tripRepo;
    private TravelPlanMemberRepository $memberRepo;
    private TravelPlanTopicRepository $topicRepo;
    private TravelPlanDateOptionRepository $dateOptionRepo;
    private TravelPlanDateResponseRepository $dateResponseRepo;
    private TravelPlanTransportRepository $transportRepo;
    private TravelPlanAccommodationOptionRepository $accommodationOptionRepo;
    private TravelPlanEventRepository $planEventRepo;
    private TravelPlanEventInterestRepository $eventInterestRepo;

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

        $this->createSchema();
        $this->wireServices();
    }

    protected function tearDown(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->tables() as $table) {
            $this->db->exec("DROP TABLE IF EXISTS `$table`");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> */
    private function tables(): array
    {
        return [
            'TravelPlanEventInterest', 'TravelPlanEvent',
            'TravelPlanAccommodationOption', 'TravelPlanTransport',
            'TravelPlanDateResponse', 'TravelPlanDateOption', 'TravelPlanTopic',
            'TravelPlanMember', 'TravelAccommodationTrip', 'TravelAccommodation',
            'TravelRelation', 'TravelEvent', 'TravelChat', 'ChatParticipant',
            'ChatConversation', 'Notification', 'NotificationPreference',
            'TravelTrip', 'User',
        ];
    }

    private function createSchema(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->tables() as $table) {
            $this->db->exec("DROP TABLE IF EXISTS `$table`");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->exec("CREATE TABLE User (
            id varchar(191) NOT NULL PRIMARY KEY,
            email varchar(191) NOT NULL,
            passwordHash varchar(191) NOT NULL DEFAULT '',
            displayName varchar(191) NOT NULL,
            discordId varchar(191) DEFAULT NULL,
            isAdmin tinyint NOT NULL DEFAULT 0,
            image text DEFAULT NULL,
            discordAvatarHash varchar(191) DEFAULT NULL,
            birthday date DEFAULT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelTrip (
            id varchar(191) NOT NULL PRIMARY KEY,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            startDate date DEFAULT NULL,
            endDate date DEFAULT NULL,
            allDay tinyint(1) NOT NULL DEFAULT 1,
            timezone varchar(64) NOT NULL DEFAULT 'Europe/Berlin',
            startAt datetime(3) DEFAULT NULL,
            endAt datetime(3) DEFAULT NULL,
            hastickets enum('1','0') DEFAULT '0',
            ticket varchar(191) DEFAULT NULL,
            ticketUrl text DEFAULT NULL,
            forumId varchar(191) DEFAULT NULL,
            state enum('planning','active','cancelled') NOT NULL DEFAULT 'active'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelPlanMember (
            id varchar(191) NOT NULL PRIMARY KEY,
            tripId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            status enum('invited','accepted','declined','inactive') NOT NULL DEFAULT 'invited',
            role enum('leader','member') NOT NULL DEFAULT 'member',
            origin varchar(32) DEFAULT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            deactivatedAt datetime(3) DEFAULT NULL,
            UNIQUE KEY uk_planmember_trip_user (tripId,userId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelPlanTopic (
            id varchar(191) NOT NULL PRIMARY KEY,
            tripId varchar(191) NOT NULL,
            topic enum('participants','travel','program') NOT NULL,
            status enum('pending','in_progress','completed','skipped') NOT NULL DEFAULT 'pending',
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            UNIQUE KEY uk_plantopic_trip_topic (tripId,topic)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelPlanDateOption (
            id varchar(191) NOT NULL PRIMARY KEY,
            tripId varchar(191) NOT NULL,
            label varchar(255) DEFAULT NULL,
            allDay tinyint NOT NULL DEFAULT 1,
            timezone varchar(64) NOT NULL DEFAULT 'Europe/Berlin',
            startAt datetime(3) DEFAULT NULL,
            endAt datetime(3) DEFAULT NULL,
            startDate date DEFAULT NULL,
            endDate date DEFAULT NULL,
            isFinal tinyint NOT NULL DEFAULT 0,
            proposedBy varchar(191) DEFAULT NULL,
            position smallint NOT NULL DEFAULT 0,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelPlanDateResponse (
            id varchar(191) NOT NULL PRIMARY KEY,
            dateOptionId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            availability enum('yes','maybe','no') NOT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            UNIQUE KEY uk_plandateresponse_option_user (dateOptionId,userId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelPlanTransport (
            id varchar(191) NOT NULL PRIMARY KEY,
            tripId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            direction enum('outbound','return') NOT NULL DEFAULT 'outbound',
            mode varchar(32) DEFAULT NULL,
            offersRide tinyint NOT NULL DEFAULT 0,
            availableSeats smallint DEFAULT NULL,
            notes text DEFAULT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            UNIQUE KEY uk_plantransport_trip_user_dir (tripId,userId,direction)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelAccommodation (
            ID varchar(191) NOT NULL PRIMARY KEY,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            address text DEFAULT NULL,
            OSMID bigint unsigned DEFAULT NULL,
            latitude double DEFAULT NULL,
            longitude double DEFAULT NULL,
            phone varchar(64) DEFAULT NULL,
            mail varchar(191) DEFAULT NULL,
            ishotel tinyint NOT NULL DEFAULT 0,
            citySlug varchar(191) DEFAULT NULL,
            tripId varchar(191) DEFAULT NULL,
            createdBy varchar(191) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelPlanAccommodationOption (
            id varchar(191) NOT NULL PRIMARY KEY,
            tripId varchar(191) NOT NULL,
            accommodationId varchar(191) DEFAULT NULL,
            name varchar(255) DEFAULT NULL,
            description text DEFAULT NULL,
            address text DEFAULT NULL,
            OSMID bigint unsigned DEFAULT NULL,
            latitude double DEFAULT NULL,
            longitude double DEFAULT NULL,
            citySlug varchar(191) DEFAULT NULL,
            pricePerPersonPerNight decimal(10,2) DEFAULT NULL,
            currency char(3) DEFAULT NULL,
            isSelected tinyint NOT NULL DEFAULT 0,
            proposedBy varchar(191) DEFAULT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelAccommodationTrip (
            ID varchar(191) NOT NULL PRIMARY KEY,
            tripid varchar(191) NOT NULL,
            accommodationId varchar(191) NOT NULL,
            pricePerPersonPerNight decimal(10,2) DEFAULT NULL,
            currency char(3) DEFAULT NULL,
            UNIQUE KEY uniq_travelaccommodationtrip (tripid,accommodationId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelPlanEvent (
            id varchar(191) NOT NULL PRIMARY KEY,
            tripId varchar(191) NOT NULL,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            dayIndex smallint NOT NULL DEFAULT 0,
            allDay tinyint NOT NULL DEFAULT 0,
            timezone varchar(64) NOT NULL DEFAULT 'Europe/Berlin',
            startAt datetime(3) DEFAULT NULL,
            endAt datetime(3) DEFAULT NULL,
            startDate date DEFAULT NULL,
            endDate date DEFAULT NULL,
            address text DEFAULT NULL,
            latitude double DEFAULT NULL,
            longitude double DEFAULT NULL,
            OSMID bigint DEFAULT NULL,
            citySlug varchar(191) DEFAULT NULL,
            isConfirmed tinyint NOT NULL DEFAULT 0,
            confirmedEventId varchar(191) DEFAULT NULL,
            proposedBy varchar(191) DEFAULT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelPlanEventInterest (
            id varchar(191) NOT NULL PRIMARY KEY,
            eventSuggestionId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            interest enum('yes','maybe','no') NOT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            UNIQUE KEY uk_planeventinterest_sugg_user (eventSuggestionId,userId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelRelation (
            ID varchar(191) NOT NULL PRIMARY KEY,
            userid varchar(191) NOT NULL,
            tripid varchar(191) NOT NULL,
            accommodation varchar(191) DEFAULT NULL,
            role varchar(16) NOT NULL DEFAULT 'participant'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelEvent (
            ID varchar(191) NOT NULL PRIMARY KEY,
            trip varchar(191) DEFAULT NULL,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            allDay tinyint NOT NULL DEFAULT 0,
            timezone varchar(64) NOT NULL DEFAULT 'Europe/Berlin',
            startAt datetime(3) DEFAULT NULL,
            endAt datetime(3) DEFAULT NULL,
            startDate date DEFAULT NULL,
            endDate date DEFAULT NULL,
            hastickets enum('1','0') DEFAULT '0',
            ticket varchar(191) DEFAULT NULL,
            ticketUrl text DEFAULT NULL,
            url text DEFAULT NULL,
            image text DEFAULT NULL,
            organizer varchar(191) DEFAULT NULL,
            address text DEFAULT NULL,
            latitude double DEFAULT NULL,
            longitude double DEFAULT NULL,
            OSMID bigint DEFAULT NULL,
            citySlug varchar(191) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE ChatConversation (
            id varchar(191) NOT NULL PRIMARY KEY,
            type varchar(16) NOT NULL DEFAULT 'direct',
            name varchar(255) DEFAULT NULL,
            image text DEFAULT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelChat (
            id varchar(191) NOT NULL PRIMARY KEY,
            conversationId varchar(191) NOT NULL,
            tripId varchar(191) DEFAULT NULL,
            eventId varchar(191) DEFAULT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE ChatParticipant (
            conversationId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            joinedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            lastReadSeq int NOT NULL DEFAULT 0,
            PRIMARY KEY (conversationId,userId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE Notification (
            id varchar(191) NOT NULL PRIMARY KEY,
            userId varchar(191) NOT NULL,
            type varchar(64) NOT NULL,
            dedupeKey varchar(191) DEFAULT NULL,
            title varchar(255) NOT NULL,
            body text NOT NULL,
            data json DEFAULT NULL,
            isRead tinyint(1) NOT NULL DEFAULT 0,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE NotificationPreference (
            id varchar(191) NOT NULL PRIMARY KEY,
            userId varchar(191) NOT NULL,
            type varchar(64) NOT NULL,
            state varchar(16) NOT NULL DEFAULT 'enabled',
            data json DEFAULT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            UNIQUE KEY idx_notifpref_user_type (userId,type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("INSERT INTO User (id, email, displayName) VALUES ('leader', 'leader@test.com', 'Leader')");
        $this->db->exec("INSERT INTO User (id, email, displayName) VALUES ('member', 'member@test.com', 'Member')");
        $this->db->exec("INSERT INTO User (id, email, displayName) VALUES ('declined', 'declined@test.com', 'Declined')");
        $this->db->exec("INSERT INTO User (id, email, displayName) VALUES ('stranger', 'stranger@test.com', 'Stranger')");
    }

    private function wireServices(): void
    {
        $this->tripRepo = new TravelTripRepository($this->db);
        $relationRepo = new TravelRelationRepository($this->db);
        $eventRepo = new TravelEventRepository($this->db);
        $accommodationRepo = new TravelAccommodationRepository($this->db);
        $this->memberRepo = new TravelPlanMemberRepository($this->db);
        $this->topicRepo = new TravelPlanTopicRepository($this->db);
        $this->dateOptionRepo = new TravelPlanDateOptionRepository($this->db);
        $this->dateResponseRepo = new TravelPlanDateResponseRepository($this->db);
        $this->transportRepo = new TravelPlanTransportRepository($this->db);
        $this->accommodationOptionRepo = new TravelPlanAccommodationOptionRepository($this->db);
        $this->planEventRepo = new TravelPlanEventRepository($this->db);
        $this->eventInterestRepo = new TravelPlanEventInterestRepository($this->db);
        $travelChatRepo = new TravelChatRepository($this->db);
        $userRepo = new UserRepository($this->db);

        $chatService = new TravelChatService(
            travelChatRepo: $travelChatRepo,
            tripRepo: $this->tripRepo,
            eventRepo: $eventRepo,
            travelRelationRepo: $relationRepo,
            planMemberRepo: $this->memberRepo,
            eventRelationRepo: new EventRelationRepository($this->db),
            conversationRepo: new ChatConversationRepository($this->db),
            participantRepo: new ChatParticipantRepository($this->db),
            centrifugoClient: new FakeCentrifugoClient(),
        );

        $notificationService = new NotificationService(
            notificationRepo: new NotificationRepository($this->db),
            pushSubRepo: new \Sinclear\Api\Repository\PushSubscriptionRepository($this->db),
            preferenceService: new NotificationPreferenceService(new NotificationPreferenceRepository($this->db)),
        );

        $this->service = new TravelPlanningService(
            tripRepo: $this->tripRepo,
            relationRepo: $relationRepo,
            eventRepo: $eventRepo,
            accommodationRepo: $accommodationRepo,
            memberRepo: $this->memberRepo,
            topicRepo: $this->topicRepo,
            dateOptionRepo: $this->dateOptionRepo,
            dateResponseRepo: $this->dateResponseRepo,
            transportRepo: $this->transportRepo,
            accommodationOptionRepo: $this->accommodationOptionRepo,
            planEventRepo: $this->planEventRepo,
            eventInterestRepo: $this->eventInterestRepo,
            travelChatRepo: $travelChatRepo,
            userRepo: $userRepo,
            chatService: $chatService,
            policy: new TravelPlanningPolicy(),
            notificationService: new TravelPlanningNotificationService(
                notificationService: $notificationService,
                tripRepo: $this->tripRepo,
                memberRepo: $this->memberRepo,
                userRepo: $userRepo,
            ),
            pdo: $this->db,
        );
    }

    private function auth(string $id, bool $isAdmin = false): AuthenticatedUser
    {
        return new AuthenticatedUser($id, $id . '@test.com', $isAdmin, 'jti-' . $id);
    }

    private function countRows(string $table, string $where = '1'): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM `$table` WHERE $where");
        return (int) $stmt->fetchColumn();
    }

    // ──────────────────────────── Anlage ────────────────────────────

    public function testCreatePlanningTripSetsUpLeaderTopicsAndChat(): void
    {
        $detail = $this->service->createPlanningTrip($this->auth('leader'), [
            'name' => 'Sommerurlaub',
            'skippedTopics' => ['program'],
        ]);

        $this->assertSame('planning', $detail['state']);
        $this->assertTrue($detail['canManage']);
        $this->assertSame('leader', $detail['role']);
        $this->assertCount(3, $detail['topics']);
        $this->assertSame('skipped', $detail['topicStatus']['program']);
        $this->assertSame('pending', $detail['topicStatus']['participants']);
        $this->assertNotNull($detail['conversationId']);

        $this->assertSame(1, $this->countRows('TravelPlanMember', "tripId = '{$detail['id']}'"));
        $member = $this->memberRepo->findByTripAndUser($detail['id'], 'leader');
        $this->assertSame('leader', $member['role']);
        $this->assertSame('accepted', $member['status']);
        $this->assertSame(1, $this->countRows('ChatParticipant'));
    }

    // ──────────────────────────── Rennsichere Schreibpfade ────────────────────────────

    public function testInviteIsIdempotentAndPreservesRole(): void
    {
        $tripId = $this->tripRepo->create(['name' => 'T', 'state' => 'planning']);
        $this->memberRepo->create($tripId, 'leader', 'accepted', 'leader', 'creator');

        $this->service->inviteMember($this->auth('leader'), $tripId, 'member');
        $this->service->inviteMember($this->auth('leader'), $tripId, 'member');

        $this->assertSame(1, $this->countRows('TravelPlanMember', "tripId = '$tripId' AND userId = 'member'"));
        $member = $this->memberRepo->findByTripAndUser($tripId, 'member');
        $this->assertSame('invited', $member['status']);
        $this->assertSame('member', $member['role']);

        // Re-Invite einer deaktivierten Leitung degradiert die Rolle nicht.
        $this->memberRepo->updateStatus($tripId, 'declined', 'inactive');
        $this->memberRepo->create($tripId, 'declined', 'inactive', 'leader', 'invite');
        $this->memberRepo->updateStatus($tripId, 'declined', 'inactive');
        $this->service->inviteMember($this->auth('leader'), $tripId, 'declined');
        $reinvited = $this->memberRepo->findByTripAndUser($tripId, 'declined');
        $this->assertSame('invited', $reinvited['status']);
        $this->assertSame('leader', $reinvited['role']);
    }

    public function testSetFinalExclusiveKeepsSingleFinal(): void
    {
        $tripId = $this->tripRepo->create(['name' => 'T', 'state' => 'planning']);
        $a = $this->dateOptionRepo->create(['tripId' => $tripId, 'label' => 'A']);
        $b = $this->dateOptionRepo->create(['tripId' => $tripId, 'label' => 'B']);

        $this->dateOptionRepo->setFinalExclusive($tripId, $a);
        $this->dateOptionRepo->setFinalExclusive($tripId, $b);

        $this->assertSame(1, $this->countRows('TravelPlanDateOption', "tripId = '$tripId' AND isFinal = 1"));
        $final = $this->dateOptionRepo->findFinalByTrip($tripId);
        $this->assertSame($b, $final['id']);
    }

    public function testSetSelectedExclusiveKeepsSingleSelected(): void
    {
        $tripId = $this->tripRepo->create(['name' => 'T', 'state' => 'planning']);
        $a = $this->accommodationOptionRepo->create(['tripId' => $tripId, 'name' => 'A']);
        $b = $this->accommodationOptionRepo->create(['tripId' => $tripId, 'name' => 'B']);

        $this->accommodationOptionRepo->setSelectedExclusive($tripId, $a);
        $this->accommodationOptionRepo->setSelectedExclusive($tripId, $b);

        $this->assertSame(1, $this->countRows('TravelPlanAccommodationOption', "tripId = '$tripId' AND isSelected = 1"));
        $selected = $this->accommodationOptionRepo->findSelectedByTrip($tripId);
        $this->assertSame($b, $selected['id']);
    }

    public function testDateResponseUpsertIsIdempotent(): void
    {
        $tripId = $this->tripRepo->create(['name' => 'T', 'state' => 'planning']);
        $optionId = $this->dateOptionRepo->create(['tripId' => $tripId]);

        $this->dateResponseRepo->upsert($optionId, 'member', 'yes');
        $this->dateResponseRepo->upsert($optionId, 'member', 'maybe');

        $this->assertSame(1, $this->countRows('TravelPlanDateResponse', "dateOptionId = '$optionId'"));
        $rows = $this->dateResponseRepo->findByOption($optionId);
        $this->assertSame('maybe', $rows[0]['availability']);
    }

    public function testEventInterestUpsertIsIdempotent(): void
    {
        $tripId = $this->tripRepo->create(['name' => 'T', 'state' => 'planning']);
        $suggestionId = $this->planEventRepo->create(['tripId' => $tripId, 'name' => 'Museum']);

        $this->eventInterestRepo->upsert($suggestionId, 'member', 'maybe');
        $this->eventInterestRepo->upsert($suggestionId, 'member', 'yes');

        $this->assertSame(1, $this->countRows('TravelPlanEventInterest', "eventSuggestionId = '$suggestionId'"));
        $rows = $this->eventInterestRepo->findBySuggestion($suggestionId);
        $this->assertSame('yes', $rows[0]['interest']);
    }

    public function testTransportUpsertIsIdempotentAndKeepsUnsetFields(): void
    {
        $tripId = $this->tripRepo->create(['name' => 'T', 'state' => 'planning']);

        $this->transportRepo->upsert($tripId, 'member', 'outbound', [
            'mode' => 'Zug',
            'offersRide' => 0,
            'notes' => 'Fensterplatz',
        ]);
        // Nur seats aendern: mode/notes bleiben erhalten.
        $this->transportRepo->upsert($tripId, 'member', 'outbound', [
            'availableSeats' => 3,
        ]);

        $this->assertSame(1, $this->countRows('TravelPlanTransport', "tripId = '$tripId' AND userId = 'member'"));
        $row = $this->transportRepo->findByTripAndUserAndDirection($tripId, 'member', 'outbound');
        $this->assertSame('Zug', $row['mode']);
        $this->assertSame('Fensterplatz', $row['notes']);
        $this->assertSame(3, (int) $row['availableSeats']);
    }

    public function testTopicUpsertIsIdempotent(): void
    {
        $tripId = $this->tripRepo->create(['name' => 'T', 'state' => 'planning']);

        $this->topicRepo->upsert($tripId, 'travel', 'pending');
        $this->topicRepo->upsert($tripId, 'travel', 'completed');

        $this->assertSame(1, $this->countRows('TravelPlanTopic', "tripId = '$tripId' AND topic = 'travel'"));
        $topics = $this->topicRepo->findByTrip($tripId);
        $this->assertSame('completed', $topics[0]['status']);
    }

    // ──────────────────────────── Aktivierung ────────────────────────────

    public function testNonLeaderCannotActivateActiveTrip(): void
    {
        $detail = $this->service->createPlanningTrip($this->auth('leader'), ['name' => 'T']);
        $tripId = $detail['id'];
        $this->service->activatePlanningTrip($this->auth('leader'), $tripId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not a planning leader');
        // Regression: die Rechtepruefung darf durch den idempotenten
        // Early-Return (state = 'active') nicht umgangen werden.
        $this->service->activatePlanningTrip($this->auth('stranger'), $tripId);
    }

    public function testActivationRequiresLeaderEvenBeforeActivation(): void
    {
        $detail = $this->service->createPlanningTrip($this->auth('leader'), ['name' => 'T']);
        $this->service->inviteMember($this->auth('leader'), $detail['id'], 'member');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not a planning leader');
        $this->service->activatePlanningTrip($this->auth('member'), $detail['id']);
    }

    public function testActivationIsIdempotentAndTransfersData(): void
    {
        $user = $this->auth('leader');
        $detail = $this->service->createPlanningTrip($user, ['name' => 'Sommer']);
        $tripId = $detail['id'];

        $this->service->inviteMember($user, $tripId, 'member');
        $this->service->respondToInvitation($this->auth('member'), $tripId, 'accepted');
        $this->service->inviteMember($user, $tripId, 'declined');
        $this->service->respondToInvitation($this->auth('declined'), $tripId, 'declined');

        $dateId = $this->service->createDateOption($user, $tripId, [
            'allDay' => true,
            'timezone' => 'Europe/Berlin',
            'startDate' => '2026-07-01',
            'endDate' => '2026-07-07',
        ])['id'];
        $this->service->finalizeDateOption($user, $tripId, $dateId);

        $accId = $this->service->createAccommodationOption($user, $tripId, [
            'name' => 'Hotel Alpen',
            'pricePerPersonPerNight' => 42.5,
            'currency' => 'EUR',
        ])['id'];
        $this->service->selectAccommodationOption($user, $tripId, $accId);

        $suggestionId = $this->service->createEventSuggestion($user, $tripId, [
            'name' => 'Wanderung',
            'allDay' => true,
            'timezone' => 'Europe/Berlin',
            'startDate' => '2026-07-02',
            'endDate' => '2026-07-02',
        ])['id'];
        $this->service->confirmEventSuggestion($user, $tripId, $suggestionId, true);

        $activated = $this->service->activatePlanningTrip($user, $tripId);
        $this->assertSame('active', $activated['state']);
        $this->assertSame('2026-07-01', $activated['startDate']);
        $this->assertSame('2026-07-07', $activated['endDate']);

        // Leader + accepted uebernommen, declined nicht.
        $this->assertSame(1, $this->countRows('TravelRelation', "tripid = '$tripId' AND userid = 'leader'"));
        $this->assertSame(1, $this->countRows('TravelRelation', "tripid = '$tripId' AND userid = 'member'"));
        $this->assertSame(0, $this->countRows('TravelRelation', "tripid = '$tripId' AND userid = 'declined'"));
        $this->assertSame('leader', $this->db->query(
            "SELECT role FROM TravelRelation WHERE tripid = '$tripId' AND userid = 'leader'"
        )->fetchColumn());

        // Unterkunft inkl. Preis uebernommen.
        $this->assertSame(1, $this->countRows('TravelAccommodationTrip', "tripid = '$tripId'"));
        $accLink = $this->db->query(
            "SELECT pricePerPersonPerNight, currency FROM TravelAccommodationTrip WHERE tripid = '$tripId'"
        )->fetch();
        $this->assertSame(42.5, (float) $accLink['pricePerPersonPerNight']);
        $this->assertSame('EUR', $accLink['currency']);

        // Bestaetigter Vorschlag -> TravelEvent.
        $this->assertSame(1, $this->countRows('TravelEvent', "trip = '$tripId'"));
        $suggestion = $this->planEventRepo->findByIdAndTrip($suggestionId, $tripId);
        $this->assertNotNull($suggestion['confirmedEventId']);

        // Chat aus TravelRelation synchronisiert: declined entfernt.
        $conversationId = $detail['conversationId'];
        $this->assertSame(1, $this->countRows('ChatParticipant', "conversationId = '$conversationId' AND userId = 'member'"));
        $this->assertSame(0, $this->countRows('ChatParticipant', "conversationId = '$conversationId' AND userId = 'declined'"));

        // Idempotent: erneute Aktivierung erzeugt keine Duplikate.
        $again = $this->service->activatePlanningTrip($user, $tripId);
        $this->assertSame('active', $again['state']);
        $this->assertSame(1, $this->countRows('TravelRelation', "tripid = '$tripId'"));
        $this->assertSame(1, $this->countRows('TravelEvent', "trip = '$tripId'"));
        $this->assertSame(1, $this->countRows('TravelAccommodationTrip', "tripid = '$tripId'"));
    }
}
