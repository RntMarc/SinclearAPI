<?php

namespace Sinclear\Api\Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Controllers\AuthController;
use Sinclear\Api\Repository\JtiBlacklistRepository;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Repository\RefreshTokenRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Repository\UserPreferenceRepository;
use Sinclear\Api\Services\Auth\DiscordOAuthService;
use Sinclear\Api\Services\Auth\OtpService;
use Sinclear\Api\Services\Auth\TokenService;
use Sinclear\Api\Services\ImageService;
use Slim\Psr7\Response;
use Symfony\Component\Mailer\MailerInterface;

final class AuthControllerTest extends TestCase
{
    private PDO $pdo;
    private OtpTokenRepository $otpTokenRepo;
    private UserRepository $userRepo;
    private TokenService $tokenService;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->otpTokenRepo = new OtpTokenRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);

        $settings = new Settings(
            app: [],
            db: [],
            jwt: [
                'issuer' => 'test',
                'access_ttl' => 3600,
                'refresh_ttl' => 86400,
                'private_key' => 'test',
                'public_key' => 'test',
            ],
            discord: [
                'redirect_uri' => 'http://localhost',
                'client_id' => '123',
                'client_secret' => 'secret',
                'guild_id' => '',
            ],
            smtp: [],
            cors: [],
            rate_limit: [],
            pagination: [],
        );

        $refreshTokenRepo = new RefreshTokenRepository($this->pdo);
        $jtiRepo = new JtiBlacklistRepository($this->pdo);
        $this->tokenService = new TokenService($settings, $refreshTokenRepo, $jtiRepo);

        $mailer = $this->createMock(MailerInterface::class);
        $otpService = new OtpService($this->otpTokenRepo, $mailer, 'noreply@example.com');

        $userPrefRepo = new UserPreferenceRepository($this->pdo);
        $logger = new NullLogger();
        $imageService = new ImageService($logger);

        $discordService = new DiscordOAuthService(
            $settings,
            $this->pdo,
            $this->otpTokenRepo,
            $userPrefRepo,
            $imageService,
            $logger,
        );

        $this->controller = new AuthController(
            $otpService,
            $this->tokenService,
            $discordService,
            $this->otpTokenRepo,
            $this->userRepo,
        );
    }

    public function testLoginOtpVerifyWithInvalidEmailFormatReturns400(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'invalid-email-format',
            'code' => '123456',
        ]);

        $response = new Response();
        $result = $this->controller->loginOtpVerify($request, $response);

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('invalid_email', $body['error'] ?? null);
    }

    public function testLoginOtpVerifyRejectsEmailOtpWhenEmailOmitted(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'code' => '123456',
        ]);

        // The no-email path MUST only search for Discord pairing tokens, never
        // for email OTP tokens. The repository scopes the query by type, so a
        // matching email OTP row can never be redeemed without the email.
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([OtpTokenRepository::TYPE_DISCORD_PAIRING, '123456']);

        $this->pdo->method('prepare')->willReturn($stmt);

        $response = new Response();
        $result = $this->controller->loginOtpVerify($request, $response);

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('invalid_or_expired_code', $body['error'] ?? null);
    }

    public function testLoginOtpVerifyAllowsDiscordPairingCodeWhenEmailOmitted(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'code' => '123456',
        ]);

        $stmt1 = $this->createMock(PDOStatement::class);
        $stmt1->method('fetch')->willReturn([
            'id' => 'token-1',
            'email' => 'user@example.com',
            'code' => '123456',
            'type' => OtpTokenRepository::TYPE_DISCORD_PAIRING,
            'createdAt' => '2026-03-29 12:00:00',
            'expiresAt' => '2026-03-29 12:02:00',
            'usedAt' => null,
        ]);
        $stmt1->expects($this->once())
            ->method('execute')
            ->with([OtpTokenRepository::TYPE_DISCORD_PAIRING, '123456']);

        $stmt2 = $this->createMock(PDOStatement::class);
        $stmt2->method('fetch')->willReturn([
            'id' => 'user-123',
            'email' => 'user@example.com',
            'isAdmin' => 0,
        ]);

        $stmt3 = $this->createMock(PDOStatement::class); // markUsed
        $stmt4 = $this->createMock(PDOStatement::class); // createFamily
        $stmt5 = $this->createMock(PDOStatement::class); // createToken

        $this->pdo->method('prepare')
            ->willReturnOnConsecutiveCalls($stmt1, $stmt2, $stmt3, $stmt4, $stmt5);

        $response = new Response();
        $result = $this->controller->loginOtpVerify($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertNotEmpty($body['refresh_token'] ?? null);
    }

    public function testLoginOtpVerifyWithValidEmailAndCodeSucceeds(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'email' => 'user@example.com',
            'code' => '123456',
        ]);

        $stmt1 = $this->createMock(PDOStatement::class);
        $stmt1->method('fetch')->willReturn([
            'id' => 'token-1',
            'email' => 'user@example.com',
            'code' => '123456',
            'type' => OtpTokenRepository::TYPE_EMAIL_OTP,
            'createdAt' => '2026-03-29 12:00:00',
            'expiresAt' => '2026-03-29 12:10:00',
            'usedAt' => null,
        ]);
        $stmt1->expects($this->once())
            ->method('execute')
            ->with([OtpTokenRepository::TYPE_EMAIL_OTP, '123456', 'user@example.com']);

        $stmt2 = $this->createMock(PDOStatement::class);
        $stmt2->method('fetch')->willReturn([
            'id' => 'user-123',
            'email' => 'user@example.com',
            'isAdmin' => 0,
        ]);

        $stmt3 = $this->createMock(PDOStatement::class);
        $stmt4 = $this->createMock(PDOStatement::class);
        $stmt5 = $this->createMock(PDOStatement::class);

        $this->pdo->method('prepare')
            ->willReturnOnConsecutiveCalls($stmt1, $stmt2, $stmt3, $stmt4, $stmt5);

        $response = new Response();
        $result = $this->controller->loginOtpVerify($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertNotEmpty($body['refresh_token'] ?? null);
    }
}