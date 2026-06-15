<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\Auth\DiscordOAuthService;
use Sinclear\Api\Services\Auth\OtpService;
use Sinclear\Api\Services\Auth\TokenService;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Repository\UserRepository;

final readonly class AuthController
{
    public function __construct(
        private OtpService $otpService,
        private TokenService $tokenService,
        private DiscordOAuthService $discordService,
        private OtpTokenRepository $otpTokenRepo,
        private UserRepository $userRepo,
    ) {}

    public function loginOtpRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $email = trim($body['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ResponseFactory::json(['error' => 'invalid_email'], 400, $response);
        }

        $user = $this->userRepo->findByEmail($email);
        if ($user === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        if (!$this->otpService->canRequestCode($email)) {
            return ResponseFactory::json(['error' => 'too_many_requests'], 429, $response);
        }

        $code = $this->otpService->generateCode();
        $this->otpService->sendOtpEmail($email, $code);
        $this->otpService->storeCode($email, $code);

        return ResponseFactory::json(['message' => 'otp_sent'], 200, $response);
    }

    public function loginOtpVerify(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $code = trim($body['code'] ?? '');

        if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
            return ResponseFactory::json(['error' => 'invalid_code'], 400, $response);
        }

        $otpToken = null;

        if (!empty($email)) {
            $otpToken = $this->otpTokenRepo->findValid($email, $code);
        } else {
            $otpToken = $this->otpTokenRepo->findValidByCode($code);
        }

        if ($otpToken === null) {
            return ResponseFactory::json(['error' => 'invalid_or_expired_code'], 400, $response);
        }

        $userEmail = $otpToken['email'];

        $user = $this->userRepo->findByEmail($userEmail);
        if ($user === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        $this->otpTokenRepo->markUsed($otpToken['id']);

        $session = $this->tokenService->createRefreshSession($user['id']);

        return ResponseFactory::json([
            'refresh_token' => $session['refresh_token'],
            'expires_at' => $session['expires_at'],
        ], 200, $response);
    }

    public function loginDiscordStart(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $result = $this->discordService->generateOAuthUrl();

        return ResponseFactory::json([
            'url' => $result['url'],
        ], 200, $response);
    }

    public function loginDiscordCallback(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $code = $params['code'] ?? '';
        $state = $params['state'] ?? '';

        if (empty($code) || empty($state)) {
            $response->getBody()->write(
                '<html><body><h1>Fehler</h1><p>Ungültige Anfrage.</p></body></html>'
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        try {
            $result = $this->discordService->processCallback($code, $state);

            $html = strtr(
                file_get_contents(__DIR__ . '/../../templates/discord-callback.php') ?: '',
                ['{{code}}' => htmlspecialchars($result['pairing_code'])],
            );

            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\RuntimeException $e) {
            $response->getBody()->write(
                '<html><body><h1>Fehler</h1><p>' . htmlspecialchars($e->getMessage()) . '</p></body></html>'
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
    }

    public function refresh(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $refreshToken = trim($body['refresh_token'] ?? '');

        if (empty($refreshToken)) {
            return ResponseFactory::json(['error' => 'missing_refresh_token'], 400, $response);
        }

        $tokenHash = $this->tokenService->hashRefreshToken($refreshToken);
        $result = $this->tokenService->rotateRefreshToken($tokenHash);

        if ($result === null) {
            return ResponseFactory::json(['error' => 'invalid_refresh_token'], 401, $response);
        }

        $user = $this->userRepo->findById($result['user_id']);
        if ($user === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        $authUser = new AuthenticatedUser(
            id: $user['id'],
            email: $user['email'],
            isAdmin: (bool) $user['isAdmin'],
            jti: Uuid::uuid7()->toString(),
        );

        $accessToken = $this->tokenService->createAccessToken($authUser);

        return ResponseFactory::json([
            'access_token' => $accessToken,
            'expires_in' => $this->tokenService->getAccessTtl(),
            'refresh_token' => $result['refresh_token'],
            'token_type' => 'Bearer',
        ], 200, $response);
    }
}
