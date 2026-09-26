<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sinclear\Api\Repository\NotificationPreferenceRepository;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Repository\PollAnswerRepository;
use Sinclear\Api\Repository\PollAvailabilityVoteRepository;
use Sinclear\Api\Repository\PollInviteRepository;
use Sinclear\Api\Repository\PollOptionRepository;
use Sinclear\Api\Repository\PollQuestionRepository;
use Sinclear\Api\Repository\PollRepository;
use Sinclear\Api\Repository\PollResponseRepository;
use Sinclear\Api\Repository\PollVoteRepository;
use Sinclear\Api\Repository\PushSubscriptionRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\PollPolicy;
use Sinclear\Api\Services\NotificationPreferenceService;
use Sinclear\Api\Services\NotificationService;
use Sinclear\Api\Services\Poll\PollAnswerValidator;
use Sinclear\Api\Services\PollAppointmentService;
use Sinclear\Api\Services\PollFormService;
use Sinclear\Api\Services\PollNotificationService;
use Sinclear\Api\Services\PollService;
use Sinclear\Api\Services\PollVoteService;
use Sinclear\Api\Support\PollAnonymity;

/**
 * Integrationstests für den Poll-Flow. Benötigen eine Datenbank und laufen
 * daher nur auf dem Server (siehe AGENTS.md).
 */
class PollIntegrationTest extends TestCase
{
    private PDO $db;
    private PollService $pollService;
    private PollFormService $formService;
    private PollAppointmentService $appointmentService;
    private PollVoteService $voteService;

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
        $this->createSchema();
        $this->wireServices();
    }

    protected function tearDown(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'PollVote', 'PollAvailabilityVote', 'PollAnswer', 'PollResponse',
            'PollInvite', 'PollOption', 'PollQuestion', 'Poll',
            'Notification', 'NotificationPreference', 'PushSubscription', 'User',
        ] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS $table");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function createSchema(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'PollVote', 'PollAvailabilityVote', 'PollAnswer', 'PollResponse',
            'PollInvite', 'PollOption', 'PollQuestion', 'Poll',
            'Notification', 'NotificationPreference', 'PushSubscription', 'User',
        ] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS $table");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->exec("CREATE TABLE User (
            id varchar(191) NOT NULL PRIMARY KEY,
            email varchar(191) NOT NULL,
            passwordHash varchar(191) NOT NULL DEFAULT '',
            displayName varchar(191) NOT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            isAdmin tinyint NOT NULL DEFAULT 0,
            image text DEFAULT NULL
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
            UNIQUE KEY idx_notifpref_user_type (userId, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PushSubscription (
            id varchar(191) NOT NULL PRIMARY KEY,
            userId varchar(191) NOT NULL,
            type varchar(20) NOT NULL,
            endpoint text NOT NULL,
            p256dh text DEFAULT NULL,
            auth text DEFAULT NULL,
            userAgent varchar(255) DEFAULT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            lastSeenAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            lastSuccessAt datetime(3) DEFAULT NULL,
            lastErrorAt datetime(3) DEFAULT NULL,
            lastError varchar(255) DEFAULT NULL,
            consecutiveFailures int NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE Poll (
            id varchar(191) NOT NULL,
            type enum('form','appointment','vote') NOT NULL,
            creatorId varchar(191) NOT NULL,
            title varchar(255) NOT NULL,
            description text NULL,
            status enum('open','closed') NOT NULL DEFAULT 'open',
            closesAt datetime(3) NULL,
            accessMode enum('invited','all_users') NOT NULL DEFAULT 'invited',
            submissionMode enum('single','multiple') NOT NULL DEFAULT 'single',
            resultsVisibility enum('creator','participants') NOT NULL DEFAULT 'creator',
            allowCounterProposals tinyint NOT NULL DEFAULT 0,
            finalizedOptionId varchar(191) NULL,
            reminderSentAt datetime(3) NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            CONSTRAINT fk_poll_creator FOREIGN KEY (creatorId) REFERENCES User(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PollQuestion (
            id varchar(191) NOT NULL,
            pollId varchar(191) NOT NULL,
            type varchar(32) NOT NULL,
            title text NOT NULL,
            description text NULL,
            isRequired tinyint NOT NULL DEFAULT 0,
            position smallint NOT NULL DEFAULT 0,
            config json NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            CONSTRAINT fk_question_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PollOption (
            id varchar(191) NOT NULL,
            pollId varchar(191) NOT NULL,
            questionId varchar(191) NULL,
            label text NULL,
            allDay tinyint NOT NULL DEFAULT 0,
            timezone varchar(64) NULL,
            startAt datetime(3) NULL,
            endAt datetime(3) NULL,
            startDate date NULL,
            endDate date NULL,
            isCounterProposal tinyint NOT NULL DEFAULT 0,
            proposedBy varchar(191) NULL,
            position smallint NOT NULL DEFAULT 0,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            CONSTRAINT fk_option_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
            CONSTRAINT fk_option_question FOREIGN KEY (questionId) REFERENCES PollQuestion(id) ON DELETE CASCADE,
            CONSTRAINT fk_option_proposer FOREIGN KEY (proposedBy) REFERENCES User(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PollInvite (
            id varchar(191) NOT NULL,
            pollId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            isIndispensable tinyint NOT NULL DEFAULT 0,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            UNIQUE KEY uk_invite_poll_user (pollId,userId),
            CONSTRAINT fk_invite_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
            CONSTRAINT fk_invite_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PollResponse (
            id varchar(191) NOT NULL,
            pollId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            CONSTRAINT fk_response_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
            CONSTRAINT fk_response_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PollAnswer (
            id varchar(191) NOT NULL,
            responseId varchar(191) NOT NULL,
            questionId varchar(191) NOT NULL,
            value mediumtext NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            CONSTRAINT fk_answer_response FOREIGN KEY (responseId) REFERENCES PollResponse(id) ON DELETE CASCADE,
            CONSTRAINT fk_answer_question FOREIGN KEY (questionId) REFERENCES PollQuestion(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PollAvailabilityVote (
            id varchar(191) NOT NULL,
            pollId varchar(191) NOT NULL,
            optionId varchar(191) NOT NULL,
            userId varchar(191) NOT NULL,
            availability enum('yes','maybe','no') NOT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            UNIQUE KEY uk_avail_poll_option_user (pollId,optionId,userId),
            CONSTRAINT fk_avail_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
            CONSTRAINT fk_avail_option FOREIGN KEY (optionId) REFERENCES PollOption(id) ON DELETE CASCADE,
            CONSTRAINT fk_avail_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE PollVote (
            id varchar(191) NOT NULL,
            pollId varchar(191) NOT NULL,
            optionId varchar(191) NOT NULL,
            participantHash char(64) NOT NULL,
            createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            UNIQUE KEY uk_vote_poll_hash_option (pollId,participantHash,optionId),
            CONSTRAINT fk_pollvote_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
            CONSTRAINT fk_pollvote_option FOREIGN KEY (optionId) REFERENCES PollOption(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("INSERT INTO User (id, email, displayName) VALUES ('creator', 'creator@test.com', 'Creator')");
        $this->db->exec("INSERT INTO User (id, email, displayName) VALUES ('user-2', 'u2@test.com', 'User Two')");
        $this->db->exec("INSERT INTO User (id, email, displayName) VALUES ('user-3', 'u3@test.com', 'User Three')");
    }

    private function wireServices(): void
    {
        $pollRepo = new PollRepository($this->db);
        $questionRepo = new PollQuestionRepository($this->db);
        $optionRepo = new PollOptionRepository($this->db);
        $inviteRepo = new PollInviteRepository($this->db);
        $responseRepo = new PollResponseRepository($this->db);
        $answerRepo = new PollAnswerRepository($this->db);
        $availabilityRepo = new PollAvailabilityVoteRepository($this->db);
        $voteRepo = new PollVoteRepository($this->db);
        $userRepo = new UserRepository($this->db);

        $policy = new PollPolicy();
        $validator = new PollAnswerValidator();
        $anonymity = new PollAnonymity('test-secret');

        $notificationService = new NotificationService(
            notificationRepo: new NotificationRepository($this->db),
            pushSubRepo: new PushSubscriptionRepository($this->db),
            preferenceService: new NotificationPreferenceService(new NotificationPreferenceRepository($this->db)),
        );
        $pollNotification = new PollNotificationService(
            notificationService: $notificationService,
            inviteRepo: $inviteRepo,
            responseRepo: $responseRepo,
            availabilityRepo: $availabilityRepo,
            voteRepo: $voteRepo,
            anonymity: $anonymity,
        );

        $this->pollService = new PollService(
            pdo: $this->db,
            pollRepo: $pollRepo,
            questionRepo: $questionRepo,
            optionRepo: $optionRepo,
            inviteRepo: $inviteRepo,
            responseRepo: $responseRepo,
            availabilityRepo: $availabilityRepo,
            userRepo: $userRepo,
            policy: $policy,
            answerValidator: $validator,
            notificationService: $pollNotification,
        );
        $this->formService = new PollFormService(
            pollService: $this->pollService,
            responseRepo: $responseRepo,
            answerRepo: $answerRepo,
            questionRepo: $questionRepo,
            optionRepo: $optionRepo,
            policy: $policy,
            answerValidator: $validator,
        );
        $this->appointmentService = new PollAppointmentService(
            pollService: $this->pollService,
            pollRepo: $pollRepo,
            optionRepo: $optionRepo,
            availabilityRepo: $availabilityRepo,
            notificationService: $pollNotification,
            policy: $policy,
        );
        $this->voteService = new PollVoteService(
            pollService: $this->pollService,
            optionRepo: $optionRepo,
            voteRepo: $voteRepo,
            policy: $policy,
            anonymity: $anonymity,
        );
    }

    private function user(string $id, bool $isAdmin = false): AuthenticatedUser
    {
        return new AuthenticatedUser($id, $id . '@test.com', $isAdmin, 'jti');
    }

    // ── Zugriffsmodi ─────────────────────────────────────

    public function testInvitedPollIsHiddenFromStrangers(): void
    {
        $poll = $this->pollService->create($this->user('creator'), [
            'type' => 'vote',
            'title' => 'Invited poll',
            'accessMode' => 'invited',
            'inviteUserIds' => ['user-2'],
            'options' => [['label' => 'A'], ['label' => 'B']],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $this->pollService->get($this->user('user-3'), (string) $poll['id']);
    }

    public function testAllUsersPollIsVisibleToEveryone(): void
    {
        $poll = $this->pollService->create($this->user('creator'), [
            'type' => 'vote',
            'title' => 'Public poll',
            'accessMode' => 'all_users',
            'options' => [['label' => 'A'], ['label' => 'B']],
        ]);

        $detail = $this->pollService->get($this->user('user-3'), (string) $poll['id']);
        $this->assertSame('all_users', $detail['accessMode']);
    }

    // ── Formular ─────────────────────────────────────────

    public function testFormSingleSubmissionAndEdit(): void
    {
        $poll = $this->pollService->create($this->user('creator'), [
            'type' => 'form',
            'title' => 'Form',
            'submissionMode' => 'single',
            'inviteUserIds' => ['user-2'],
            'questions' => [
                ['type' => 'text', 'title' => 'Name', 'isRequired' => true],
            ],
        ]);
        $pollId = (string) $poll['id'];
        $questionId = (string) $poll['questions'][0]['id'];

        $response = $this->formService->submit($this->user('user-2'), $pollId, [
            ['questionId' => $questionId, 'value' => 'Alice'],
        ]);
        $this->assertSame('Alice', $response['answers'][$questionId]);

        // Zweites Absenden im single-Modus verboten
        try {
            $this->formService->submit($this->user('user-2'), $pollId, [
                ['questionId' => $questionId, 'value' => 'Bob'],
            ]);
            $this->fail('already_responded expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('already_responded', $e->getMessage());
        }

        // Änderung erlaubt
        $updated = $this->formService->updateResponse(
            $this->user('user-2'),
            $pollId,
            (string) $response['id'],
            [['questionId' => $questionId, 'value' => 'Carol']],
        );
        $this->assertSame('Carol', $updated['answers'][$questionId]);
    }

    public function testFormResultsVisibility(): void
    {
        $poll = $this->pollService->create($this->user('creator'), [
            'type' => 'form',
            'title' => 'Form results',
            'resultsVisibility' => 'creator',
            'questions' => [['type' => 'text', 'title' => 'Q']],
        ]);
        $pollId = (string) $poll['id'];

        try {
            $this->formService->listResponses($this->user('user-2'), $pollId);
            $this->fail('results_hidden expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('results_hidden', $e->getMessage());
        }

        // Ersteller sieht Ergebnisse
        $result = $this->formService->listResponses($this->user('creator'), $pollId);
        $this->assertArrayHasKey('data', $result);
    }

    // ── Terminfindung ────────────────────────────────────

    public function testAppointmentAvailabilityAndFinalize(): void
    {
        $poll = $this->pollService->create($this->user('creator'), [
            'type' => 'appointment',
            'title' => 'Doodle',
            'allowCounterProposals' => true,
            'inviteUserIds' => ['user-2'],
            'options' => [
                ['label' => 'Slot 1', 'allDay' => false, 'timezone' => 'Europe/Berlin', 'startAt' => '2026-10-01T10:00:00+02:00', 'endAt' => '2026-10-01T11:00:00+02:00'],
                ['label' => 'Slot 2', 'allDay' => false, 'timezone' => 'Europe/Berlin', 'startAt' => '2026-10-02T10:00:00+02:00', 'endAt' => '2026-10-02T11:00:00+02:00'],
            ],
        ]);
        $pollId = (string) $poll['id'];
        $option1 = (string) $poll['options'][0]['id'];
        $option2 = (string) $poll['options'][1]['id'];

        $this->appointmentService->setAvailability($this->user('user-2'), $pollId, [
            ['optionId' => $option1, 'availability' => 'yes'],
            ['optionId' => $option2, 'availability' => 'no'],
        ]);
        // Upsert ersetzt
        $this->appointmentService->setAvailability($this->user('user-2'), $pollId, [
            ['optionId' => $option2, 'availability' => 'maybe'],
        ]);
        $mine = $this->appointmentService->listAvailability($this->user('user-2'), $pollId);
        $this->assertCount(1, $mine['data']);
        $this->assertSame('maybe', $mine['data'][0]['availability']);

        // Gegenvorschlag
        $counter = $this->appointmentService->addCounterProposal($this->user('user-2'), $pollId, [
            'label' => 'Counter', 'allDay' => false, 'timezone' => 'Europe/Berlin',
            'startAt' => '2026-10-03T10:00:00+02:00', 'endAt' => '2026-10-03T11:00:00+02:00',
        ]);
        $this->assertTrue($counter['isCounterProposal']);

        // Finalisieren schließt die Umfrage
        $finalized = $this->appointmentService->finalize($this->user('creator'), $pollId, $option1);
        $this->assertSame('closed', $finalized['status']);
        $this->assertSame($option1, $finalized['finalizedOptionId']);
    }

    // ── Anonyme Abstimmung ───────────────────────────────

    public function testVoteOnceAnonymouslyAndResultsAfterClose(): void
    {
        $poll = $this->pollService->create($this->user('creator'), [
            'type' => 'vote',
            'title' => 'Vote',
            'inviteUserIds' => ['user-2'],
            'options' => [['label' => 'A'], ['label' => 'B']],
        ]);
        $pollId = (string) $poll['id'];
        $optionA = (string) $poll['options'][0]['id'];

        $this->voteService->vote($this->user('user-2'), $pollId, [$optionA]);

        $status = $this->voteService->voteStatus($this->user('user-2'), $pollId);
        $this->assertTrue($status['hasVoted']);

        // Doppelwahl verboten
        try {
            $this->voteService->vote($this->user('user-2'), $pollId, [$optionA]);
            $this->fail('already_voted expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('already_voted', $e->getMessage());
        }

        // Ergebnisse vor dem Schließen verborgen
        try {
            $this->voteService->results($this->user('creator'), $pollId);
            $this->fail('results_hidden expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('results_hidden', $e->getMessage());
        }

        $this->pollService->close($this->user('creator'), $pollId);

        $results = $this->voteService->results($this->user('creator'), $pollId);
        $this->assertSame(1, $results['meta']['totalParticipants']);
        $this->assertSame(1, $results['data'][0]['votes']);
        $this->assertSame(100.0, $results['data'][0]['percentage']);
    }

    public function testVoteStoresNoUserIdAndUsesHash(): void
    {
        $poll = $this->pollService->create($this->user('creator'), [
            'type' => 'vote',
            'title' => 'Anonymous',
            'accessMode' => 'all_users',
            'options' => [['label' => 'A']],
        ]);
        $pollId = (string) $poll['id'];
        $optionA = (string) $poll['options'][0]['id'];

        $this->voteService->vote($this->user('user-2'), $pollId, [$optionA]);

        $columns = $this->db->query('SHOW COLUMNS FROM PollVote')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotContains('userId', $columns);
        $this->assertContains('participantHash', $columns);

        $hash = $this->db->query("SELECT participantHash FROM PollVote LIMIT 1")->fetchColumn();
        $this->assertNotSame('user-2', $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $hash);
    }
}
