<?php

namespace Sinclear\Api\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\VObject\Reader;
use Sinclear\Api\Dav\DavDummyFactory;
use Sinclear\Api\Dav\DavServerFactory;
use Sinclear\Api\Dav\IcsFactory;
use Sinclear\Api\Dav\VcardFactory;
use Sinclear\Api\Repository\CalendarEventRepository;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Repository\ContactInfoRepository;
use Sinclear\Api\Repository\DavTokenRepository;
use Sinclear\Api\Repository\PtJourneyRepository;
use Sinclear\Api\Repository\SocialInfoRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserPreferenceRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Policy\UserPolicy;
use Sinclear\Api\Services\CalendarEventService;
use Sinclear\Api\Services\CalendarFeedService;
use Sinclear\Api\Services\DavTokenService;
use Sinclear\Api\Services\UserPreferenceService;
use Sinclear\Api\Services\UserService;

class DavIntegrationTest extends TestCase
{
    private PDO $db;
    private DavServerFactory $serverFactory;
    private DavTokenService $tokenService;
    private string $aliceId = 'user-alice';
    private string $bobId = 'user-bob';

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
            'DavToken', 'CalendarEventParticipant', 'CalendarEvent',
            'EventRelation', 'TravelRelation', 'TravelEvent', 'TravelTrip',
            'PtParticipant', 'PtLeg', 'PtJourney',
            'CloseFriend', 'UserPreferences', 'User',
        ] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS `$table`");
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
                userId varchar(191) NOT NULL UNIQUE,
                language varchar(16) NOT NULL DEFAULT 'de',
                theme varchar(16) NOT NULL DEFAULT 'light',
                primaryColor varchar(16) NOT NULL DEFAULT '#6366f1',
                timezone varchar(64) NOT NULL DEFAULT 'Europe/Berlin',
                emailVisibility tinyint NOT NULL DEFAULT 1,
                birthdayVisibility tinyint NOT NULL DEFAULT 1,
                syncAvatarFromDiscord tinyint NOT NULL DEFAULT 1,
                onboardingCompleted tinyint NOT NULL DEFAULT 0,
                discordVisibility tinyint NOT NULL DEFAULT 1,
                fluxerVisibility tinyint NOT NULL DEFAULT 1,
                matrixVisibility tinyint NOT NULL DEFAULT 1,
                signalVisibility tinyint NOT NULL DEFAULT 1,
                whatsappVisibility tinyint NOT NULL DEFAULT 1,
                unsplashVisibility tinyint NOT NULL DEFAULT 1,
                instagramVisibility tinyint NOT NULL DEFAULT 1,
                mastodonVisibility tinyint NOT NULL DEFAULT 1,
                pixelfedVisibility tinyint NOT NULL DEFAULT 1,
                blueskyVisibility tinyint NOT NULL DEFAULT 1,
                youtubeVisibility tinyint NOT NULL DEFAULT 1,
                twitchVisibility tinyint NOT NULL DEFAULT 1,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            )
        ");

        $this->db->exec("
            CREATE TABLE CloseFriend (
                userId varchar(191) NOT NULL,
                friendId varchar(191) NOT NULL,
                PRIMARY KEY (userId, friendId)
            )
        ");

        $this->db->exec("
            CREATE TABLE CalendarEvent (
                id varchar(191) NOT NULL PRIMARY KEY,
                creatorId varchar(191) NOT NULL,
                title varchar(255) NOT NULL,
                description text DEFAULT NULL,
                startTime datetime NOT NULL,
                endTime datetime NOT NULL,
                visibility tinyint(1) NOT NULL DEFAULT 0,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY idx_calendar_creator (creatorId),
                KEY idx_calendar_time (startTime, endTime)
            )
        ");

        $this->db->exec("
            CREATE TABLE CalendarEventParticipant (
                eventId varchar(191) NOT NULL,
                userId varchar(191) NOT NULL,
                addedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (eventId, userId)
            )
        ");

        $this->db->exec("
            CREATE TABLE DavToken (
                id varchar(191) NOT NULL PRIMARY KEY,
                userId varchar(191) NOT NULL,
                label varchar(255) NOT NULL,
                keyHash varchar(64) NOT NULL,
                expiresAt datetime NOT NULL,
                lastUsedAt datetime NULL DEFAULT NULL,
                createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY idx_dav_token_hash (keyHash)
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

        $stmt = $this->db->prepare(
            'INSERT INTO User (id, email, passwordHash, displayName, birthday, createdAt) VALUES (?, ?, ?, ?, ?, NOW(3))'
        );
        $stmt->execute([$this->aliceId, 'alice@example.com', '', 'Alice', null]);
        $stmt->execute([$this->bobId, 'bob@example.com', '', 'Bob', '1990-05-04 00:00:00']);

        $this->db->prepare('INSERT INTO UserPreferences (id, userId, emailVisibility, birthdayVisibility) VALUES (?, ?, ?, ?)')
            ->execute(['pref-alice', $this->aliceId, 1, 1]);
        $this->db->prepare('INSERT INTO UserPreferences (id, userId, emailVisibility, birthdayVisibility) VALUES (?, ?, ?, ?)')
            ->execute(['pref-bob', $this->bobId, 0, 1]);

        $events = [
            ['event-alice-private', $this->aliceId, 'Alice privat', 'start', '2026-07-01 08:00:00', '2026-07-01 09:00:00', 0],
            ['event-alice-public', $this->aliceId, 'Alice public', 'treffen', '2026-07-01 10:00:00', '2026-07-01 11:00:00', 1],
            ['event-bob-public', $this->bobId, 'Bob public', 'party', '2026-07-01 12:00:00', '2026-07-01 13:00:00', 1],
            ['event-bob-private', $this->bobId, 'Bob privat', 'geheim', '2026-07-01 14:00:00', '2026-07-01 15:00:00', 0],
            ['event-bob-shared', $this->bobId, 'Bob shared', 'mit alice', '2026-07-01 16:00:00', '2026-07-01 17:00:00', 0],
        ];
        $insertEvent = $this->db->prepare(
            'INSERT INTO CalendarEvent (id, creatorId, title, description, startTime, endTime, visibility, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))'
        );
        foreach ($events as $event) {
            $insertEvent->execute($event);
        }
        $this->db->prepare('INSERT INTO CalendarEventParticipant (eventId, userId, addedAt) VALUES (?, ?, NOW(3))')
            ->execute(['event-bob-shared', $this->aliceId]);

        $userRepo = new UserRepository($this->db);
        $davTokenRepo = new DavTokenRepository($this->db);
        $this->tokenService = new DavTokenService($davTokenRepo, $userRepo);

        $preferenceService = new UserPreferenceService(new UserPreferenceRepository($this->db));
        $userService = new UserService(
            $userRepo,
            new ContactInfoRepository($this->db),
            new SocialInfoRepository($this->db),
            $preferenceService,
            new UserPolicy(new CloseFriendRepository($this->db)),
        );

        $icsFactory = new IcsFactory();
        $vcardFactory = new VcardFactory();
        $dummyFactory = new DavDummyFactory($icsFactory, $vcardFactory);

        $calendarRepo = new CalendarEventRepository($this->db);
        $closeFriendRepo = new CloseFriendRepository($this->db);
        $calendarEventService = new CalendarEventService(eventRepo: $calendarRepo, closeFriendRepo: $closeFriendRepo);

        $feedService = new CalendarFeedService(
            calendarEventService: $calendarEventService,
            travelEventRepo: new TravelEventRepository($this->db),
            tripRepo: new TravelTripRepository($this->db),
            ptJourneyRepo: new PtJourneyRepository($this->db),
            userRepo: $userRepo,
        );

        $this->serverFactory = new DavServerFactory(
            $this->tokenService,
            $userRepo,
            $userService,
            $feedService,
            $icsFactory,
            $vcardFactory,
            $dummyFactory,
        );
    }

    public function testOptionsListsDavHeaders(): void
    {
        $response = $this->invoke('OPTIONS', '/api/dav/');
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('calendar-access', $response->getHeader('DAV'));
    }

    public function testCalendarPropfindWithValidToken(): void
    {
        $token = $this->createToken($this->aliceId);
        $response = $this->invoke(
            'PROPFIND',
            "/api/dav/calendars/{$this->aliceId}/calendar/",
            $this->propfindBody(),
            $token,
            ['Depth' => '1'],
        );

        $body = $response->getBodyAsString();
        self::assertSame(207, $response->getStatus(), $body);
        self::assertStringContainsString('event-alice-public.ics', $body);
        self::assertStringContainsString('event-alice-private.ics', $body);
        self::assertStringContainsString('event-bob-public.ics', $body);
        self::assertStringContainsString('event-bob-shared.ics', $body);
        self::assertStringNotContainsString('event-bob-private.ics', $body);
    }

    public function testCalendarObjectGetWithValidToken(): void
    {
        $token = $this->createToken($this->aliceId);
        $response = $this->invoke(
            'GET',
            "/api/dav/calendars/{$this->aliceId}/calendar/event-alice-public.ics",
            null,
            $token,
        );

        $body = $response->getBodyAsString();
        self::assertSame(200, $response->getStatus(), $body);

        $vcal = Reader::read($body);
        self::assertSame('Alice public', (string) $vcal->VEVENT->SUMMARY);
        self::assertSame('20260701T100000Z', (string) $vcal->VEVENT->DTSTART);
        self::assertSame('20260701T110000Z', (string) $vcal->VEVENT->DTEND);
        self::assertSame('PUBLIC', (string) $vcal->VEVENT->CLASS);
    }

    public function testCalendarObjectOfOtherUserIsHidden(): void
    {
        $token = $this->createToken($this->aliceId);

        $response = $this->invoke(
            'GET',
            "/api/dav/calendars/{$this->aliceId}/calendar/event-bob-private.ics",
            null,
            $token,
        );

        self::assertSame(404, $response->getStatus(), $response->getBodyAsString());
    }

    public function testForeignCalendarHomeIsDenied(): void
    {
        $token = $this->createToken($this->aliceId);
        $response = $this->invoke(
            'PROPFIND',
            "/api/dav/calendars/{$this->bobId}/calendar/",
            $this->propfindBody(),
            $token,
        );

        $body = $response->getBodyAsString();
        self::assertSame(207, $response->getStatus(), $body);
        self::assertStringContainsString('403 Forbidden', $body);
        self::assertStringNotContainsString('HTTP/1.1 200 OK', $body, 'Keine Properties duerfen lesbar sein');
    }

    public function testCalendarQueryReport(): void
    {
        $token = $this->createToken($this->aliceId);
        $response = $this->invoke(
            'REPORT',
            "/api/dav/calendars/{$this->aliceId}/calendar/",
            '<?xml version="1.0"?>
             <cal:calendar-query xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
               <d:prop><d:getetag/></d:prop>
               <cal:filter>
                 <cal:comp-filter name="VCALENDAR">
                   <cal:comp-filter name="VEVENT">
                     <cal:time-range start="20260701T000000Z" end="20260702T000000Z"/>
                   </cal:comp-filter>
                 </cal:comp-filter>
               </cal:filter>
            </cal:calendar-query>',
            $token,
            ['Depth' => '1'],
        );

        $body = $response->getBodyAsString();
        self::assertSame(207, $response->getStatus(), $body);

        self::assertStringContainsString('event-alice-public.ics', $body);
        self::assertStringContainsString('event-bob-public.ics', $body);
        self::assertStringContainsString('event-bob-shared.ics', $body);
        self::assertStringNotContainsString('event-bob-private.ics', $body);
    }

    public function testPutIsForbidden(): void
    {
        $token = $this->createToken($this->aliceId);
        $response = $this->invoke(
            'PUT',
            "/api/dav/calendars/{$this->aliceId}/calendar/new-event.ics",
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//\r\nBEGIN:VEVENT\r\nUID:test@sinclear.de\r\nDTSTAMP:20260701T000000Z\r\nDTSTART:20260701T000000Z\r\nDTEND:20260701T010000Z\r\nSUMMARY:Test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            $token,
        );

        self::assertSame(403, $response->getStatus(), $response->getBodyAsString());
    }

    public function testInvalidTokenGetsDummyCalendarWithHintEvent(): void
    {
        $response = $this->invoke(
            'PROPFIND',
            '/api/dav/calendars/dav-invalid-token/calendar/',
            $this->propfindBody(),
            'definitely-not-a-valid-token',
            ['Depth' => '1'],
        );

        $body = $response->getBodyAsString();
        self::assertSame(207, $response->getStatus(), $body);
        self::assertStringContainsString('sinclear-dav-invalid-token.ics', $body);
        self::assertSame(1, substr_count($body, '.ics</d:href>'), $body);
    }

    public function testInvalidTokenHintEventIsTodayNoonUtc(): void
    {
        $response = $this->invoke(
            'GET',
            '/api/dav/calendars/dav-invalid-token/calendar/sinclear-dav-invalid-token.ics',
            null,
            'invalid-token',
        );

        $body = $response->getBodyAsString();
        self::assertSame(200, $response->getStatus(), $body);

        $vcal = Reader::read($body);
        self::assertSame('Abgelaufener oder ungültiger Token', (string) $vcal->VEVENT->SUMMARY);
        self::assertStringContainsString('Token ist entweder abgelaufen oder ungültig', (string) $vcal->VEVENT->DESCRIPTION);

        $expectedStart = gmdate('Ymd\T120000\Z');
        self::assertSame($expectedStart, (string) $vcal->VEVENT->DTSTART);
        self::assertSame(gmdate('Ymd\T130000\Z'), (string) $vcal->VEVENT->DTEND);
    }

    public function testExpiredTokenGetsDummyCalendar(): void
    {
        $token = $this->createToken($this->aliceId);
        $this->db->prepare('UPDATE DavToken SET expiresAt = ? WHERE keyHash = ?')
            ->execute(['2020-01-01 00:00:00', hash('sha256', $token)]);

        $response = $this->invoke(
            'GET',
            '/api/dav/calendars/dav-invalid-token/calendar/sinclear-dav-invalid-token.ics',
            null,
            $token,
        );

        $body = $response->getBodyAsString();
        self::assertSame(200, $response->getStatus(), $body);
        self::assertStringContainsString('Abgelaufener oder ungültiger Token', $body);
    }

    public function testCardDavPropfindWithValidToken(): void
    {
        $token = $this->createToken($this->aliceId);
        $response = $this->invoke(
            'PROPFIND',
            "/api/dav/addressbooks/{$this->aliceId}/contacts/",
            $this->propfindBody(),
            $token,
            ['Depth' => '1'],
        );

        $body = $response->getBodyAsString();
        self::assertSame(207, $response->getStatus(), $body);
        self::assertStringContainsString($this->bobId . '.vcf', $body);
        self::assertStringContainsString($this->aliceId . '.vcf', $body);
    }

    public function testCardDavRespectsEmailVisibility(): void
    {
        $token = $this->createToken($this->aliceId);
        $response = $this->invoke(
            'GET',
            "/api/dav/addressbooks/{$this->aliceId}/contacts/{$this->bobId}.vcf",
            null,
            $token,
        );

        $body = $response->getBodyAsString();
        self::assertSame(200, $response->getStatus(), $body);

        $vcard = Reader::read($body);
        self::assertSame('Bob', (string) $vcard->FN);
        self::assertSame('user-bob@sinclear.de', (string) $vcard->UID);
        self::assertNull($vcard->EMAIL, 'E-Mail mit emailVisibility=0 darf nicht ausgeliefert werden');
        self::assertSame('1990-05-04', (string) $vcard->BDAY);
    }

    public function testCardDavOwnCardContainsEmail(): void
    {
        $token = $this->createToken($this->aliceId);
        $response = $this->invoke(
            'GET',
            "/api/dav/addressbooks/{$this->aliceId}/contacts/{$this->aliceId}.vcf",
            null,
            $token,
        );

        $body = $response->getBodyAsString();
        self::assertSame(200, $response->getStatus(), $body);

        $vcard = Reader::read($body);
        self::assertSame('alice@example.com', (string) $vcard->EMAIL);
    }

    public function testCardDavInvalidTokenGetsDummyCard(): void
    {
        $response = $this->invoke(
            'GET',
            '/api/dav/addressbooks/dav-invalid-token/contacts/sinclear-dav-invalid-token.vcf',
            null,
            'invalid-token',
        );

        $body = $response->getBodyAsString();
        self::assertSame(200, $response->getStatus(), $body);

        $vcard = Reader::read($body);
        self::assertSame('Abgelaufener oder ungültiger Token', (string) $vcard->FN);
        self::assertStringContainsString('Token ist entweder abgelaufen oder ungültig', (string) $vcard->NOTE);
    }

    public function testCardDavCardPutIsForbidden(): void
    {
        $token = $this->createToken($this->aliceId);
        $response = $this->invoke(
            'PUT',
            "/api/dav/addressbooks/{$this->aliceId}/contacts/new.vcf",
            "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Test\r\nEND:VCARD\r\n",
            $token,
        );

        self::assertSame(403, $response->getStatus(), $response->getBodyAsString());
    }

    public function testDavTokenServiceLifecycle(): void
    {
        $tokenData = $this->tokenService->createToken($this->aliceId, 'Test-Token');
        self::assertNotEmpty($tokenData['token']);
        self::assertSame('Test-Token', $tokenData['label']);

        $expiresAt = new \DateTimeImmutable($tokenData['expiresAt'], new \DateTimeZone('UTC'));
        $inOneYear = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+365 days');
        $dayDiff = (int) $expiresAt->diff($inOneYear)->format('%a');
        self::assertLessThanOrEqual(1, $dayDiff);

        self::assertSame($this->aliceId, $this->tokenService->validateToken('alice@example.com', $tokenData['token']));
        self::assertNull($this->tokenService->validateToken('alice@example.com', 'falsches-token'));
        self::assertNull($this->tokenService->validateToken('unbekannt@example.com', $tokenData['token']));

        self::assertTrue($this->tokenService->revokeToken($tokenData['id'], $this->aliceId));
        self::assertNull($this->tokenService->validateToken('alice@example.com', $tokenData['token']));
    }

    private function createToken(string $userId): string
    {
        return $this->tokenService->createToken($userId, 'Integrationstest')['token'];
    }

    private function propfindBody(): string
    {
        return '<?xml version="1.0"?>
            <d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:prop>
                <d:displayname/>
                <d:resourcetype/>
                <d:getetag/>
                <cal:supported-calendar-component-set/>
              </d:prop>
            </d:propfind>';
    }

    private function invoke(string $method, string $url, ?string $body = null, ?string $token = null, array $extraHeaders = []): Response
    {
        $headers = [];
        if ($token !== null) {
            $headers['Authorization'] = 'Basic ' . base64_encode('alice@example.com:' . $token);
        }
        $headers = array_merge($headers, $extraHeaders);

        $request = new Request($method, $url, $headers, $body);
        $request->setBaseUrl('/api/dav/');

        $response = new Response();
        $server = $this->serverFactory->createServer();

        $server->httpRequest = $request;
        $server->httpResponse = $response;

        ob_start();
        try {
            $server->invokeMethod($request, $response, false);
        } catch (\Sabre\DAV\Exception $e) {
            $response->setStatus($e->getHTTPCode());
            $response->setHeader('Content-Type', 'application/xml; charset=utf-8');
            $response->setBody($e->getMessage());
        } finally {
            ob_end_clean();
        }

        return $response;
    }
}
