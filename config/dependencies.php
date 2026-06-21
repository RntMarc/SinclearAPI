<?php

namespace Sinclear\Api\Application;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Controllers\AuthController;
use Sinclear\Api\Controllers\ExploreController;
use Sinclear\Api\Controllers\NewsController;
use Sinclear\Api\Controllers\TravelController;
use Sinclear\Api\Middleware\AuthenticationMiddleware;
use Sinclear\Api\Middleware\CorsMiddleware;
use Sinclear\Api\Middleware\LoginThrottleMiddleware;
use Sinclear\Api\Middleware\RateLimitMiddleware;
use Sinclear\Api\Middleware\RequireHttpsMiddleware;
use Sinclear\Api\Middleware\SecurityHeadersMiddleware;
use Sinclear\Api\Middleware\UserRateLimitMiddleware;
use Sinclear\Api\Repository\JtiBlacklistRepository;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Repository\RefreshTokenRepository;
use Sinclear\Api\Repository\DiscoverBookmarkRepository;
use Sinclear\Api\Repository\DiscoverGastronomyRepository;
use Sinclear\Api\Repository\DiscoverPlaceRepository;
use Sinclear\Api\Repository\DiscoverReviewRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Repository\NewsArticleRepository;
use Sinclear\Api\Repository\NewsUpvoteRepository;
use Sinclear\Api\Repository\RssSourceRepository;
use Sinclear\Api\Repository\TravelAccommodationRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\TravelService;
use Sinclear\Api\Security\Policy\ExplorePolicy;
use Sinclear\Api\Services\Auth\DiscordOAuthService;
use Sinclear\Api\Services\Auth\OtpService;
use Sinclear\Api\Services\Auth\TokenService;
use Sinclear\Api\Services\ExploreService;
use Sinclear\Api\Services\ImageProxyService;
use Sinclear\Api\Services\NominatimCache;
use Sinclear\Api\Services\NominatimRateLimiter;
use Sinclear\Api\Services\NewsService;
use Sinclear\Api\Services\RateLimiter;
use Sinclear\Api\Services\RssFeedService;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use function DI\autowire;
use function DI\create;
use function DI\get;

return [
    Settings::class => function (): Settings {
        $settingsArray = require __DIR__ . '/settings.php';
        return new Settings(...$settingsArray);
    },

    LoggerInterface::class => function (ContainerInterface $c): LoggerInterface {
        $settings = $c->get(Settings::class);
        $logger = new Logger('sinclear-api');
        $logger->pushProcessor(new UidProcessor());

        $logLevel = $settings->app['debug'] ? Logger::DEBUG : Logger::INFO;
        $logger->pushHandler(new StreamHandler(
            __DIR__ . '/../var/log/app.log',
            $logLevel,
        ));

        return $logger;
    },

    PDO::class => function (ContainerInterface $c): PDO {
        $settings = $c->get(Settings::class);
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $settings->db['host'],
            $settings->db['port'],
            $settings->db['name'],
        );

        $pdo = new PDO($dsn, $settings->db['user'], $settings->db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    },

    MailerInterface::class => function (ContainerInterface $c): MailerInterface {
        $settings = $c->get(Settings::class);

        $transport = new EsmtpTransport(
            $settings->smtp['host'],
            $settings->smtp['port'],
        );

        if (!empty($settings->smtp['user'])) {
            $transport->setUsername($settings->smtp['user']);
            $transport->setPassword($settings->smtp['password']);
        }

        return new Mailer($transport);
    },

    RateLimiter::class => autowire(),

    UserRepository::class => autowire(),
    OtpTokenRepository::class => autowire(),
    RefreshTokenRepository::class => autowire(),
    JtiBlacklistRepository::class => autowire(),

    OtpService::class => function (ContainerInterface $c): OtpService {
        $settings = $c->get(Settings::class);
        return new OtpService(
            otpTokenRepo: $c->get(OtpTokenRepository::class),
            mailer: $c->get(MailerInterface::class),
            fromAddress: $settings->smtp['from'],
        );
    },

    TokenService::class => autowire(),
    DiscordOAuthService::class => autowire(),

    AuthController::class => autowire(),
    ExploreController::class => autowire(),

    DiscoverPlaceRepository::class => autowire(),
    DiscoverBookmarkRepository::class => autowire(),
    DiscoverGastronomyRepository::class => autowire(),
    DiscoverReviewRepository::class => autowire(),

    ExploreService::class => autowire(),
    ExplorePolicy::class => autowire(),
    NominatimRateLimiter::class => autowire(),
    NominatimCache::class => autowire(),

    ClientInterface::class => fn(): ClientInterface => new Client(['timeout' => 15]),

    NewsArticleRepository::class => autowire(),
    NewsUpvoteRepository::class => autowire(),
    RssSourceRepository::class => autowire(),

    NewsService::class => autowire(),
    RssFeedService::class => autowire(),
    NewsController::class => autowire(),

    ImageProxyService::class => autowire(),

    TravelTripRepository::class => autowire(),
    TravelEventRepository::class => autowire(),
    TravelAccommodationRepository::class => autowire(),
    TravelRelationRepository::class => autowire(),

    TravelService::class => autowire(),
    TravelController::class => autowire(),

    RequireHttpsMiddleware::class => create(),
    SecurityHeadersMiddleware::class => create(),

    CorsMiddleware::class => function (ContainerInterface $c): CorsMiddleware {
        $settings = $c->get(Settings::class);
        return new CorsMiddleware(
            allowedOrigins: $settings->cors['allowed_origins'],
            logger: $c->get(LoggerInterface::class),
        );
    },

    RateLimitMiddleware::class => function (ContainerInterface $c): RateLimitMiddleware {
        $settings = $c->get(Settings::class);
        return new RateLimitMiddleware(
            rateLimiter: $c->get(RateLimiter::class),
            maxRequests: $settings->rate_limit['requests'],
            windowSeconds: $settings->rate_limit['window'],
        );
    },

    LoginThrottleMiddleware::class => function (ContainerInterface $c): LoginThrottleMiddleware {
        $settings = $c->get(Settings::class);
        return new LoginThrottleMiddleware(
            rateLimiter: $c->get(RateLimiter::class),
            maxRequests: $settings->rate_limit['auth_requests'],
            windowSeconds: $settings->rate_limit['auth_window'],
        );
    },

    UserRateLimitMiddleware::class => function (ContainerInterface $c): UserRateLimitMiddleware {
        return new UserRateLimitMiddleware(
            rateLimiter: $c->get(RateLimiter::class),
            maxRequests: 60,
            windowSeconds: 60,
        );
    },

    AuthenticationMiddleware::class => function (ContainerInterface $c): AuthenticationMiddleware {
        $settings = $c->get(Settings::class);
        return new AuthenticationMiddleware(
            tokenService: $c->get(TokenService::class),
            required: true,
        );
    },

    'auth.optional' => function (ContainerInterface $c): AuthenticationMiddleware {
        return new AuthenticationMiddleware(
            tokenService: $c->get(TokenService::class),
            required: false,
        );
    },

    AuthenticatedUser::class => null,
];
