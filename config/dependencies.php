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
use Sinclear\Api\Controllers\AdminController;
use Sinclear\Api\Controllers\AppController;
use Sinclear\Api\Controllers\AuthController;
use Sinclear\Api\Controllers\CalendarEventController;
use Sinclear\Api\Controllers\LocationSharingController;
use Sinclear\Api\Controllers\LocationSharingIngressController;
use Sinclear\Api\Controllers\ExploreController;
use Sinclear\Api\Controllers\FeedbackController;
use Sinclear\Api\Controllers\ForumController;
use Sinclear\Api\Controllers\NotificationController;
use Sinclear\Api\Controllers\ProfileController;
use Sinclear\Api\Controllers\RecipeController;
use Sinclear\Api\Controllers\ReviewController;
use Sinclear\Api\Controllers\TravelController;
use Sinclear\Api\Controllers\UserController;
use Sinclear\Api\Middleware\AdminMiddleware;
use Sinclear\Api\Repository\CalendarEventRepository;
use Sinclear\Api\Repository\LocationSharingSessionRepository;
use Sinclear\Api\Repository\LocationSharingRecipientRepository;
use Sinclear\Api\Repository\LocationSharingLocationRepository;
use Sinclear\Api\Repository\RecipeRepository;
use Sinclear\Api\Repository\RecipeBookmarkRepository;
use Sinclear\Api\Repository\RecipeIngredientRepository;
use Sinclear\Api\Repository\RecipeReviewRepository;
use Sinclear\Api\Repository\RecipeStepRepository;
use Sinclear\Api\Repository\ContactInfoRepository;
use Sinclear\Api\Repository\SocialInfoRepository;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Security\Policy\UserPolicy;
use Sinclear\Api\Services\UserService;
use Sinclear\Api\Middleware\AuthenticationMiddleware;
use Sinclear\Api\Middleware\CorsMiddleware;
use Sinclear\Api\Middleware\LoginThrottleMiddleware;
use Sinclear\Api\Middleware\RateLimitMiddleware;
use Sinclear\Api\Middleware\RequireHttpsMiddleware;
use Sinclear\Api\Middleware\SecurityHeadersMiddleware;
use Sinclear\Api\Repository\JtiBlacklistRepository;
use Sinclear\Api\Repository\NotificationRepository;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Repository\RefreshTokenRepository;
use Sinclear\Api\Repository\DiscoverBookmarkRepository;
use Sinclear\Api\Repository\DiscoverGastronomyRepository;
use Sinclear\Api\Repository\DiscoverPlaceRepository;
use Sinclear\Api\Repository\DiscoverReviewRepository;
use Sinclear\Api\Repository\FeedbackSuggestionRepository;
use Sinclear\Api\Repository\FeedbackVoteRepository;
use Sinclear\Api\Repository\FeedbackCommentRepository;
use Sinclear\Api\Repository\ForumRepository;
use Sinclear\Api\Repository\ForumMemberRepository;
use Sinclear\Api\Repository\FeedPostRepository;
use Sinclear\Api\Repository\FeedPostVoteRepository;
use Sinclear\Api\Repository\FeedPostCommentRepository;
use Sinclear\Api\Repository\UserDeviceRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Repository\TravelAccommodationRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\CalendarEventService;
use Sinclear\Api\Services\LocationSharingService;
use Sinclear\Api\Services\TravelService;
use Sinclear\Api\Security\Policy\CalendarEventPolicy;
use Sinclear\Api\Security\Policy\LocationSharingPolicy;
use Sinclear\Api\Security\Policy\ExplorePolicy;
use Sinclear\Api\Security\Policy\FeedbackPolicy;
use Sinclear\Api\Security\Policy\RecipePolicy;
use Sinclear\Api\Security\Policy\ForumPolicy;
use Sinclear\Api\Security\Policy\NotificationPolicy;
use Sinclear\Api\Services\Auth\DiscordOAuthService;
use Sinclear\Api\Services\Auth\OtpService;
use Sinclear\Api\Services\Auth\TokenService;
use Sinclear\Api\Services\ExploreService;
use Sinclear\Api\Services\FeedbackService;
use Sinclear\Api\Services\ForumService;
use Sinclear\Api\Services\NominatimCache;
use Sinclear\Api\Services\NominatimRateLimiter;
use Sinclear\Api\Services\NotificationService;
use Sinclear\Api\Services\RecipeService;
use Sinclear\Api\Services\PushService;
use Sinclear\Api\Services\RateLimiter;
use Sinclear\Api\Services\ImageService;
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
    ImageService::class => autowire(),

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
    ReviewPolicy::class => autowire(),
    ReviewService::class => autowire(),
    ReviewController::class => autowire(),

    RecipeRepository::class => autowire(),
    RecipeBookmarkRepository::class => autowire(),
    RecipeIngredientRepository::class => autowire(),
    RecipeReviewRepository::class => autowire(),
    RecipeStepRepository::class => autowire(),
    RecipePolicy::class => autowire(),
    RecipeService::class => autowire(),
    RecipeController::class => autowire(),

    FeedbackSuggestionRepository::class => autowire(),
    FeedbackVoteRepository::class => autowire(),
    FeedbackCommentRepository::class => autowire(),
    FeedbackPolicy::class => autowire(),
    FeedbackService::class => autowire(),
    FeedbackController::class => autowire(),

    ForumRepository::class => autowire(),
    ForumMemberRepository::class => autowire(),
    FeedPostRepository::class => autowire(),
    FeedPostVoteRepository::class => autowire(),
    FeedPostCommentRepository::class => autowire(),
    ForumPolicy::class => autowire(),
    ForumService::class => autowire(),
    ForumController::class => autowire(),

    NominatimRateLimiter::class => autowire(),
    NominatimCache::class => autowire(),

    ClientInterface::class => fn(): ClientInterface => new Client(['timeout' => 15]),

    TravelTripRepository::class => autowire(),
    TravelEventRepository::class => autowire(),
    TravelAccommodationRepository::class => autowire(),
    TravelRelationRepository::class => autowire(),

    TravelService::class => autowire(),
    TravelController::class => autowire(),

    CalendarEventRepository::class => autowire(),
    CalendarEventService::class => autowire(),
    CalendarEventController::class => autowire(),
    CalendarEventPolicy::class => autowire(),

    LocationSharingSessionRepository::class => autowire(),
    LocationSharingRecipientRepository::class => autowire(),
    LocationSharingLocationRepository::class => autowire(),
    LocationSharingPolicy::class => autowire(),
    LocationSharingService::class => autowire(),
    LocationSharingController::class => autowire(),
    LocationSharingIngressController::class => autowire(),

    ContactInfoRepository::class => autowire(),
    ContactInfoUpdateRepository::class => autowire(),
    SocialInfoRepository::class => autowire(),
    SocialInfoUpdateRepository::class => autowire(),
    CloseFriendRepository::class => autowire(),

    UserUpdateRepository::class => autowire(),

    UserPolicy::class => autowire(),
    UserService::class => autowire(),
    UserController::class => autowire(),

    ProfileService::class => autowire(),
    ProfileController::class => autowire(),

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

    NotificationRepository::class => autowire(),
    UserDeviceRepository::class => autowire(),

    NotificationPolicy::class => autowire(),

    PushService::class => function (ContainerInterface $c): PushService {
        $settings = $c->get(Settings::class);
        return new PushService(
            deviceRepo: $c->get(UserDeviceRepository::class),
            logger: $c->get(LoggerInterface::class),
            projectId: $settings->fcm['project_id'],
            clientEmail: $settings->fcm['client_email'],
            privateKey: $settings->fcm['private_key'],
        );
    },

    NotificationService::class => autowire(),
    NotificationController::class => autowire(),

    AdminMiddleware::class => create(),

    AdminController::class => autowire(),

    AppController::class => function (ContainerInterface $c): AppController {
        $settings = $c->get(Settings::class);
        return new AppController(
            downloadsBaseUrl: $settings->downloads['base_url'],
            logger: $c->get(LoggerInterface::class),
        );
    },
];
