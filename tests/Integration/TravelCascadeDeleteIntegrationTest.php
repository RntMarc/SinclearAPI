<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sinclear\Api\Repository\ChatConversationRepository;
use Sinclear\Api\Repository\ChatParticipantRepository;
use Sinclear\Api\Repository\EventRelationRepository;
use Sinclear\Api\Repository\FeedPostCommentRepository;
use Sinclear\Api\Repository\FeedPostRepository;
use Sinclear\Api\Repository\FeedPostVoteRepository;
use Sinclear\Api\Repository\ForumMemberRepository;
use Sinclear\Api\Repository\ForumRepository;
use Sinclear\Api\Repository\NotificationPreferenceRepository;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Repository\PushSubscriptionRepository;
use Sinclear\Api\Repository\TravelChatRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelPlanMemberRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Services\ForumService;
use Sinclear\Api\Services\ImageService;
use Sinclear\Api\Services\NotificationPreferenceService;
use Sinclear\Api\Services\NotificationService;
use Sinclear\Api\Services\TravelChatService;
use Sinclear\Api\Tests\Unit\FakeCentrifugoClient;

/**
 * Integrationstests der Loeschkaskade (Reise-/Event-Loeschung).
 *
 * Benoetigen eine Datenbank und laufen daher nur auf dem Server (AGENTS.md).
 * Die FK-basierte Kaskade wird direkt ueber die Datenbank geprueft, die
 * anwendungsseitigen Loeschungen (Forum, Chat) ueber die Services.
 */
final class TravelCascadeDeleteIntegrationTest extends TestCase
{
    private PDO $db;
    private ForumService $forumService;
    private TravelChatService $chatService;
    private TravelTripRepository $tripRepo;

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
            'ForumMember', 'FeedPostComment', 'FeedPostVote', 'FeedPosts', 'Forum',
            'TravelChat', 'DirectMessage', 'ChatParticipant', 'ChatConversation',
            'PtParticipant', 'PtLeg', 'PtJourney',
            'TravelAccommodationTrip', 'TravelEventTicket', 'TravelRelation',
            'EventRelation', 'TravelEvent', 'TravelTrip', 'User',
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
            displayName varchar(191) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelTrip (
            id varchar(191) NOT NULL PRIMARY KEY,
            name varchar(255) NOT NULL,
            state enum('planning','active','cancelled') NOT NULL DEFAULT 'active',
            forumId varchar(191) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelEvent (
            ID varchar(191) NOT NULL PRIMARY KEY,
            trip varchar(191) DEFAULT NULL,
            name varchar(255) NOT NULL,
            KEY idx_travel_event_trip (trip),
            CONSTRAINT fk_travel_event_trip FOREIGN KEY (trip) REFERENCES TravelTrip (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE EventRelation (
            id varchar(191) NOT NULL PRIMARY KEY,
            eventId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            KEY idx_eventrelation_event (eventId),
            CONSTRAINT fk_event_relation_event FOREIGN KEY (eventId) REFERENCES TravelEvent (ID) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelRelation (
            ID varchar(191) NOT NULL PRIMARY KEY,
            userid varchar(191) NOT NULL,
            tripid varchar(191) NOT NULL,
            KEY idx_travelrelation_trip (tripid),
            CONSTRAINT fk_travel_relation_trip FOREIGN KEY (tripid) REFERENCES TravelTrip (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelEventTicket (
            ID varchar(191) NOT NULL PRIMARY KEY,
            type enum('event','trip','user') NOT NULL,
            event varchar(191) DEFAULT NULL,
            trip varchar(191) DEFAULT NULL,
            user varchar(191) DEFAULT NULL,
            KEY idx_travel_event_ticket_event (event),
            KEY idx_travel_event_ticket_trip (trip),
            CONSTRAINT fk_travel_event_ticket_event FOREIGN KEY (event) REFERENCES TravelEvent (ID) ON DELETE CASCADE,
            CONSTRAINT fk_travel_event_ticket_trip FOREIGN KEY (trip) REFERENCES TravelTrip (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelAccommodationTrip (
            ID varchar(191) NOT NULL PRIMARY KEY,
            tripid varchar(191) NOT NULL,
            accommodationId varchar(191) NOT NULL,
            KEY idx_travelaccommodationtrip_trip (tripid),
            CONSTRAINT fk_travel_accommodation_trip_trip FOREIGN KEY (tripid) REFERENCES TravelTrip (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PtJourney (
            id varchar(191) NOT NULL PRIMARY KEY,
            tripId varchar(191) DEFAULT NULL,
            creatorId varchar(191) NOT NULL,
            KEY idx_pt_journey_trip (tripId),
            CONSTRAINT fk_pt_journey_trip_cascade FOREIGN KEY (tripId) REFERENCES TravelTrip (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PtLeg (
            id varchar(191) NOT NULL PRIMARY KEY,
            journeyId varchar(191) NOT NULL,
            CONSTRAINT fk_pt_leg_journey FOREIGN KEY (journeyId) REFERENCES PtJourney (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PtParticipant (
            journeyId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            PRIMARY KEY (journeyId, userId),
            CONSTRAINT fk_pt_participant_journey FOREIGN KEY (journeyId) REFERENCES PtJourney (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE ChatConversation (
            id varchar(191) NOT NULL PRIMARY KEY,
            type enum('direct','group') NOT NULL DEFAULT 'direct',
            name varchar(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE ChatParticipant (
            conversationId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            PRIMARY KEY (conversationId, userId),
            CONSTRAINT fk_participant_conversation FOREIGN KEY (conversationId) REFERENCES ChatConversation (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE DirectMessage (
            id varchar(191) NOT NULL PRIMARY KEY,
            conversationId varchar(191) NOT NULL,
            senderId varchar(191) NOT NULL,
            content text NOT NULL,
            CONSTRAINT fk_dm_conversation FOREIGN KEY (conversationId) REFERENCES ChatConversation (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE TravelChat (
            id varchar(191) NOT NULL PRIMARY KEY,
            conversationId varchar(191) NOT NULL,
            tripId varchar(191) DEFAULT NULL,
            eventId varchar(191) DEFAULT NULL,
            CONSTRAINT fk_travelchat_conversation FOREIGN KEY (conversationId) REFERENCES ChatConversation (id) ON DELETE CASCADE,
            CONSTRAINT fk_travelchat_trip FOREIGN KEY (tripId) REFERENCES TravelTrip (id) ON DELETE CASCADE,
            CONSTRAINT fk_travelchat_event FOREIGN KEY (eventId) REFERENCES TravelEvent (ID) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE Forum (
            id varchar(191) NOT NULL PRIMARY KEY,
            name varchar(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE FeedPosts (
            id varchar(191) NOT NULL PRIMARY KEY,
            forumId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            content text NOT NULL,
            isDraft tinyint NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE FeedPostVote (
            id varchar(191) NOT NULL PRIMARY KEY,
            postId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE FeedPostComment (
            id varchar(191) NOT NULL PRIMARY KEY,
            postId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            text text DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE ForumMember (
            id varchar(191) NOT NULL PRIMARY KEY,
            forumId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("INSERT INTO User (id, email, displayName) VALUES ('leader', 'leader@test.com', 'Leader')");
        $this->db->exec("INSERT INTO User (id, email, displayName) VALUES ('member', 'member@test.com', 'Member')");
    }

    private function wireServices(): void
    {
        $this->tripRepo = new TravelTripRepository($this->db);

        $this->forumService = new ForumService(
            forumRepo: new ForumRepository($this->db),
            memberRepo: new ForumMemberRepository($this->db),
            postRepo: new FeedPostRepository($this->db),
            voteRepo: new FeedPostVoteRepository($this->db),
            commentRepo: new FeedPostCommentRepository($this->db),
            imageService: new ImageService(new NullLogger()),
            travelRelationRepo: new TravelRelationRepository($this->db),
            notificationService: new NotificationService(
                notificationRepo: new NotificationRepository($this->db),
                pushSubRepo: new PushSubscriptionRepository($this->db),
                preferenceService: new NotificationPreferenceService(new NotificationPreferenceRepository($this->db)),
            ),
            userRepo: new UserRepository($this->db),
        );

        $this->chatService = new TravelChatService(
            travelChatRepo: new TravelChatRepository($this->db),
            tripRepo: $this->tripRepo,
            eventRepo: new TravelEventRepository($this->db),
            travelRelationRepo: new TravelRelationRepository($this->db),
            planMemberRepo: new TravelPlanMemberRepository($this->db),
            eventRelationRepo: new EventRelationRepository($this->db),
            conversationRepo: new ChatConversationRepository($this->db),
            participantRepo: new ChatParticipantRepository($this->db),
            centrifugoClient: new FakeCentrifugoClient(),
        );
    }

    private function countRows(string $table, string $where = '1'): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM `$table` WHERE $where");
        return (int) $stmt->fetchColumn();
    }

    public function testDeletingTripCascadesToChildren(): void
    {
        $tripId = 'trip-1';
        $this->db->exec("INSERT INTO TravelTrip (id, name, state) VALUES ('$tripId', 'Reise', 'active')");
        $eventId = 'event-1';

        $this->db->exec("INSERT INTO TravelEvent (ID, trip, name) VALUES ('$eventId', '$tripId', 'Event')");
        $this->db->exec("INSERT INTO EventRelation (id, eventId, userId) VALUES ('er-1', '$eventId', 'member')");
        $this->db->exec("INSERT INTO TravelRelation (ID, userid, tripid) VALUES ('tr-1', 'leader', '$tripId')");
        $this->db->exec("INSERT INTO TravelEventTicket (ID, type, event, trip) VALUES ('tk-ev', 'event', '$eventId', NULL)");
        $this->db->exec("INSERT INTO TravelEventTicket (ID, type, trip) VALUES ('tk-trip', 'trip', '$tripId')");
        $this->db->exec("INSERT INTO TravelAccommodationTrip (ID, tripid, accommodationId) VALUES ('lat-1', '$tripId', 'acc-1')");
        $this->db->exec("INSERT INTO PtJourney (id, tripId, creatorId) VALUES ('j-1', '$tripId', 'leader')");
        $this->db->exec("INSERT INTO PtLeg (id, journeyId) VALUES ('leg-1', 'j-1')");
        $this->db->exec("INSERT INTO PtParticipant (journeyId, userId) VALUES ('j-1', 'leader')");

        $this->db->exec("DELETE FROM TravelTrip WHERE id = '$tripId'");

        $this->assertSame(0, $this->countRows('TravelTrip', "id = '$tripId'"));
        $this->assertSame(0, $this->countRows('TravelEvent', "trip = '$tripId'"));
        $this->assertSame(0, $this->countRows('EventRelation', "eventId = '$eventId'"));
        $this->assertSame(0, $this->countRows('TravelRelation', "tripid = '$tripId'"));
        $this->assertSame(0, $this->countRows('TravelEventTicket', "trip = '$tripId' OR event = '$eventId'"));
        $this->assertSame(0, $this->countRows('TravelAccommodationTrip', "tripid = '$tripId'"));
        $this->assertSame(0, $this->countRows('PtJourney', "tripId = '$tripId'"));
        $this->assertSame(0, $this->countRows('PtLeg', "journeyId = 'j-1'"));
        $this->assertSame(0, $this->countRows('PtParticipant', "journeyId = 'j-1'"));
    }

    public function testDeletingEventCascadesToRelationsAndTickets(): void
    {
        $eventId = 'event-2';
        $this->db->exec("INSERT INTO TravelEvent (ID, trip, name) VALUES ('$eventId', NULL, 'Standalone')");
        $this->db->exec("INSERT INTO EventRelation (id, eventId, userId) VALUES ('er-2', '$eventId', 'leader')");
        $this->db->exec("INSERT INTO TravelEventTicket (ID, type, event) VALUES ('tk-ev2', 'event', '$eventId')");
        $this->db->exec("INSERT INTO TravelEventTicket (ID, type, event, user) VALUES ('tk-user2', 'user', '$eventId', 'member')");

        $this->db->exec("DELETE FROM TravelEvent WHERE ID = '$eventId'");

        $this->assertSame(0, $this->countRows('TravelEvent', "ID = '$eventId'"));
        $this->assertSame(0, $this->countRows('EventRelation', "eventId = '$eventId'"));
        $this->assertSame(0, $this->countRows('TravelEventTicket', "event = '$eventId'"));
    }

    public function testDeleteForumRemovesPostsVotesCommentsAndMembers(): void
    {
        $forumId = 'forum-1';
        $this->db->exec("INSERT INTO Forum (id, name) VALUES ('$forumId', 'Reise-Forum')");
        $this->db->exec("INSERT INTO FeedPosts (id, forumId, userId, content, isDraft) VALUES ('p-1', '$forumId', 'leader', '{}', 0)");
        $this->db->exec("INSERT INTO FeedPosts (id, forumId, userId, content, isDraft) VALUES ('p-2', '$forumId', 'leader', '{}', 1)");
        $this->db->exec("INSERT INTO FeedPostVote (id, postId, userId) VALUES ('v-1', 'p-1', 'member')");
        $this->db->exec("INSERT INTO FeedPostComment (id, postId, userId, text) VALUES ('c-1', 'p-1', 'member', 'Hallo')");
        $this->db->exec("INSERT INTO ForumMember (id, forumId, userId) VALUES ('fm-1', '$forumId', 'leader')");

        $this->forumService->deleteForum($forumId);

        $this->assertSame(0, $this->countRows('Forum', "id = '$forumId'"));
        $this->assertSame(0, $this->countRows('FeedPosts', "forumId = '$forumId'"));
        $this->assertSame(0, $this->countRows('FeedPostVote', "postId IN ('p-1','p-2')"));
        $this->assertSame(0, $this->countRows('FeedPostComment', "postId IN ('p-1','p-2')"));
        $this->assertSame(0, $this->countRows('ForumMember', "forumId = '$forumId'"));
    }

    public function testDeleteForTripRemovesFullConversation(): void
    {
        $tripId = 'trip-2';
        $this->db->exec("INSERT INTO TravelTrip (id, name, state) VALUES ('$tripId', 'Reise', 'active')");
        $conversationId = 'conv-1';
        $this->db->exec("INSERT INTO ChatConversation (id, type, name) VALUES ('$conversationId', 'group', 'Reise-Chat')");
        $this->db->exec("INSERT INTO TravelChat (id, conversationId, tripId) VALUES ('tc-1', '$conversationId', '$tripId')");
        $this->db->exec("INSERT INTO ChatParticipant (conversationId, userId) VALUES ('$conversationId', 'leader')");
        $this->db->exec("INSERT INTO DirectMessage (id, conversationId, senderId, content) VALUES ('dm-1', '$conversationId', 'leader', 'Hallo')");

        $this->chatService->deleteForTrip($tripId);

        $this->assertSame(0, $this->countRows('ChatConversation', "id = '$conversationId'"));
        $this->assertSame(0, $this->countRows('TravelChat', "tripId = '$tripId'"));
        $this->assertSame(0, $this->countRows('ChatParticipant', "conversationId = '$conversationId'"));
        $this->assertSame(0, $this->countRows('DirectMessage', "conversationId = '$conversationId'"));
    }
}
