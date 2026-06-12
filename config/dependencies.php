<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Http\Middleware\AuthenticationMiddleware;
use Sinclear\Api\Http\Middleware\OptionalAuthenticationMiddleware;
use Sinclear\Api\Http\Middleware\CorsMiddleware;
use Sinclear\Api\Http\Middleware\LoginThrottleMiddleware;
use Sinclear\Api\Http\Middleware\RateLimitMiddleware;
use Sinclear\Api\Http\Middleware\RequestSizeLimitMiddleware;
use Sinclear\Api\Http\Middleware\SecurityHeadersMiddleware;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Repository\ContactInfoRepository;
use Sinclear\Api\Repository\JtiBlacklistRepository;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Repository\PasskeyRepository;
use Sinclear\Api\Repository\RefreshTokenFamilyRepository;
use Sinclear\Api\Repository\RefreshTokenRepository;
use Sinclear\Api\Repository\SocialInfoRepository;
use Sinclear\Api\Repository\SubscriptionRelationRepository;
use Sinclear\Api\Repository\UserPreferencesRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Repository\WebauthnChallengeRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\Auth\DiscordOAuthService;
use Sinclear\Api\Service\Auth\OtpService;
use Sinclear\Api\Service\Auth\PasskeyService;
use Sinclear\Api\Service\Auth\TokenService;
use Sinclear\Api\Http\Controllers\AuthController;
use Sinclear\Api\Service\CalendarService;
use Sinclear\Api\Service\ChatService;
use Sinclear\Api\Service\MailService;
use Sinclear\Api\Service\NotificationService;
use Sinclear\Api\Service\PollService;
use Sinclear\Api\Service\RateLimitService;
use Sinclear\Api\Service\TravelService;
use Sinclear\Api\Service\UserExportService;

$settings = require __DIR__ . '/settings.php';

return [
    Settings::class => static fn (): Settings => new Settings($settings),

    LoggerInterface::class => static function (ContainerInterface $c): Logger {
        $settings = $c->get(Settings::class);
        $logger = new Logger('api');
        $logger->pushHandler(new StreamHandler(
            dirname(__DIR__) . '/var/logs/api.log',
            $settings->isDebug() ? Logger::DEBUG : Logger::WARNING
        ));
        return $logger;
    },

    \PDO::class => static function (ContainerInterface $c): \PDO {
        $settings = $c->get(Settings::class);
        $db = $settings->get('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'],
            $db['port'],
            $db['name']
        );
        $pdo = new \PDO($dsn, $db['user'], $db['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    },

    RateLimitService::class => DI\autowire(),
    MailService::class => DI\autowire(),
    TokenService::class => DI\autowire(),
    OtpService::class => DI\autowire(),
    PasskeyService::class => DI\autowire(),
    DiscordOAuthService::class => DI\autowire(),

    UserRepository::class => DI\autowire(),
    UserPreferencesRepository::class => DI\autowire(),
    ContactInfoRepository::class => DI\autowire(),
    SocialInfoRepository::class => DI\autowire(),
    SubscriptionRelationRepository::class => DI\autowire(),
    CloseFriendRepository::class => DI\autowire(),
    OtpTokenRepository::class => DI\autowire(),
    PasskeyRepository::class => DI\autowire(),
    WebauthnChallengeRepository::class => DI\autowire(),
    RefreshTokenRepository::class => DI\autowire(),
    RefreshTokenFamilyRepository::class => DI\autowire(),
    JtiBlacklistRepository::class => DI\autowire(),

    SecurityHeadersMiddleware::class => DI\autowire(),
    RequestSizeLimitMiddleware::class => DI\autowire(),
    RateLimitMiddleware::class => DI\autowire(),
    LoginThrottleMiddleware::class => DI\autowire(),
    CorsMiddleware::class => DI\autowire(),
    AuthenticationMiddleware::class => DI\autowire(),
    OptionalAuthenticationMiddleware::class => DI\autowire(),

    AuthenticatedUser::class => static function (): ?AuthenticatedUser {
        return null;
    },

    AuthController::class => DI\autowire(),
    UserController::class => DI\autowire(),
    PollService::class => DI\autowire(),
    ChatService::class => DI\autowire(),
    CalendarService::class => DI\autowire(),
    NotificationService::class => DI\autowire(),
    TravelService::class => DI\autowire(),
    UserExportService::class => DI\autowire(),
];
