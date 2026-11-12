<?php

namespace Sinclear\Api\Controllers\AdminApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\Auth\OtpService;
use Sinclear\Api\Services\Auth\TokenService;

/**
 * Step-up-Authentifizierung fuer mutierende Admin-Aktionen.
 *
 * Lese-Zugriffe funktionieren mit dem normalen Access-Token (isAdmin-Claim);
 * fuer Schreibzugriffe muss der Admin sich per E-Mail-OTP erneut bestaetigen
 * und erhaelt dafuer einen kurzlebigen Admin-Token (scope='admin', 20 Min.,
 * nicht-refreshbar).
 */
final readonly class AdminApiAuthController
{
    use AdminApiConcern;

    public function __construct(
        private OtpService $otpService,
        private OtpTokenRepository $otpTokenRepo,
        private UserRepository $userRepo,
        private TokenService $tokenService,
    ) {}

    public function otpRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $authUser = $this->requireUser($request);

        $user = $this->userRepo->findById($authUser->id);
        if ($user === null || !($user['isAdmin'] ?? false)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $email = (string) $user['email'];

        if (!$this->otpService->canRequestCode($email)) {
            return ResponseFactory::json(['error' => 'too_many_requests'], 429, $response);
        }

        $code = $this->otpService->generateCode();
        $this->otpService->sendAdminOtpEmail($email, $code);
        $this->otpService->storeCode($email, $code, OtpTokenRepository::TYPE_ADMIN_STEPUP);

        return ResponseFactory::json(['message' => 'otp_sent'], 200, $response);
    }

    public function otpVerify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $authUser = $this->requireUser($request);
        $body = $this->parsedBody($request);
        $code = trim((string) ($body['code'] ?? ''));

        if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
            return ResponseFactory::json(['error' => 'invalid_code'], 400, $response);
        }

        $user = $this->userRepo->findById($authUser->id);
        if ($user === null || !($user['isAdmin'] ?? false)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $email = (string) $user['email'];
        $otpToken = $this->otpTokenRepo->findValidAdminStepup($email, $code);
        if ($otpToken === null) {
            $this->otpTokenRepo->incrementAttemptsForEmail($email, OtpTokenRepository::TYPE_ADMIN_STEPUP);
            return ResponseFactory::json(['error' => 'invalid_or_expired_code'], 400, $response);
        }

        $this->otpTokenRepo->markUsed($otpToken['id']);

        $adminUser = new AuthenticatedUser(
            id: $user['id'],
            email: $email,
            isAdmin: true,
            jti: Uuid::uuid7()->toString(),
        );

        $accessToken = $this->tokenService->createAdminAccessToken($adminUser);

        return ResponseFactory::json([
            'access_token' => $accessToken,
            'expires_in' => $this->tokenService->getAdminAccessTtl(),
            'token_type' => 'Bearer',
        ], 200, $response);
    }
}
