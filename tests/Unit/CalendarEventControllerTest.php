<?php

namespace Sinclear\Api\Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Controllers\CalendarEventController;
use Sinclear\Api\Repository\CalendarEventRepository;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Repository\PtJourneyRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\CalendarEventService;
use Sinclear\Api\Services\CalendarFeedService;
use Slim\Psr7\Response;

final class CalendarEventControllerTest extends TestCase
{
    private const array VALID_BODY = [
        'title' => 'Standup',
        'allDay' => false,
        'startDate' => '2026-09-26',
        'endDate' => '2026-09-26',
        'startTime' => '20:00:00',
        'endTime' => '23:59:00',
        'visibility' => 0,
    ];

    private PDO $pdo;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        // Lokales Dev-System ohne ext-pdo: Test laeuft erst auf dem Server.
        if (!class_exists(PDO::class)) {
            $this->markTestSkipped('ext-pdo ist in dieser Umgebung nicht verfuegbar');
        }

        $this->pdo = $this->createMock(PDO::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testCreateMapsForeignKeyViolationToInvalidReference(): void
    {
        $exception = new \PDOException(
            'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row'
        );
        $exception->errorInfo = [
            '23000',
            1452,
            'Cannot add or update a child row: a foreign key constraint fails',
        ];

        $this->pdo->method('prepare')->willThrowException($exception);
        $this->logger->expects($this->once())->method('warning');

        $result = $this->createController()->create(
            $this->createRequest(self::VALID_BODY),
            new Response(),
        );

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('invalid_reference', $body['error'] ?? null);
        $this->assertArrayNotHasKey('message', $body);
    }

    public function testCreateExposesMessageOnlyInDebugMode(): void
    {
        $exception = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1452');
        $exception->errorInfo = ['23000', 1452, 'fk fails'];

        $this->pdo->method('prepare')->willThrowException($exception);

        $result = $this->createController(debug: true)->create(
            $this->createRequest(self::VALID_BODY),
            new Response(),
        );

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('invalid_reference', $body['error'] ?? null);
        $this->assertSame($exception->getMessage(), $body['message'] ?? null);
    }

    public function testCreateMapsDuplicateKeyToConflict(): void
    {
        $exception = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $exception->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'PRIMARY'"];

        $this->pdo->method('prepare')->willThrowException($exception);
        $this->logger->expects($this->once())->method('warning');

        $result = $this->createController()->create(
            $this->createRequest(self::VALID_BODY),
            new Response(),
        );

        $this->assertSame(409, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('conflict', $body['error'] ?? null);
    }

    public function testCreateMapsMalformedValueToInvalidValue(): void
    {
        $exception = new \PDOException('SQLSTATE[HY000]: General error: 1366 Incorrect integer value');
        $exception->errorInfo = ['HY000', 1366, "Incorrect integer value: '' for column 'allDay'"];

        $this->pdo->method('prepare')->willThrowException($exception);
        $this->logger->expects($this->once())->method('warning');

        $result = $this->createController()->create(
            $this->createRequest(self::VALID_BODY),
            new Response(),
        );

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('invalid_value', $body['error'] ?? null);
    }

    public function testCreateFallsBackToInternalErrorAndLogsError(): void
    {
        $this->pdo->method('prepare')->willThrowException(new \RuntimeException('unexpected failure'));
        $this->logger->expects($this->once())->method('error');

        $result = $this->createController()->create(
            $this->createRequest(self::VALID_BODY),
            new Response(),
        );

        $this->assertSame(500, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('internal_error', $body['error'] ?? null);
    }

    public function testDeleteUnknownEventReturns404EventNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->pdo->method('prepare')->willReturn($stmt);
        $this->logger->expects($this->once())->method('warning');

        $result = $this->createController()->delete(
            $this->createRequest([]),
            new Response(),
            ['id' => 'missing-id'],
        );

        $this->assertSame(404, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('event_not_found', $body['error'] ?? null);
    }

    private function createController(bool $debug = false): CalendarEventController
    {
        $eventService = new CalendarEventService(
            eventRepo: new CalendarEventRepository($this->pdo),
            closeFriendRepo: new CloseFriendRepository($this->pdo),
        );
        $feedService = new CalendarFeedService(
            calendarEventService: $eventService,
            travelEventRepo: new TravelEventRepository($this->pdo),
            tripRepo: new TravelTripRepository($this->pdo),
            ptJourneyRepo: new PtJourneyRepository($this->pdo),
            userRepo: new UserRepository($this->pdo),
        );

        return new CalendarEventController(
            calendarService: $eventService,
            calendarFeedService: $feedService,
            logger: $this->logger,
            settings: new Settings(
                app: ['debug' => $debug],
                db: [],
                jwt: [],
                discord: [],
                smtp: [],
                cors: [],
                rate_limit: [],
                pagination: [],
            ),
        );
    }

    private function createRequest(array $body): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(AuthenticatedUser::class)
            ->willReturn(new AuthenticatedUser('user-1', 'user@example.com', false, 'jti-1'));
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }
}
