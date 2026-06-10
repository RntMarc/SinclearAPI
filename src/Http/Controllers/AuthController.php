<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Respect\Validation\Validator as v;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Dto\UserDto;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Repository\UserPreferencesRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\Auth\DiscordOAuthService;
use Sinclear\Api\Service\Auth\OtpService;
use Sinclear\Api\Service\Auth\PasskeyService;
use Sinclear\Api\Service\Auth\TokenService;

/**
 * Authentication endpoints (public, no JWT required).
 */
final class AuthController
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly PasskeyService $passkeyService,
        private readonly DiscordOAuthService $discordOAuthService,
        private readonly TokenService $tokenService,
        private readonly UserRepository $userRepository,
        private readonly UserPreferencesRepository $preferencesRepository
    ) {
    }

    public function otpRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $email = (string) ($body['email'] ?? '');
        if (!v::email()->validate($email)) {
            throw HttpException::badRequest('invalid_email');
        }

        // If authenticated, we might be requesting OTP for email change
        $user = $request->getAttribute(AuthenticatedUser::class);
        if ($user instanceof AuthenticatedUser) {
            // For email change, we don't check if user exists with new email
            // (or rather, we should check it doesn't exist)
            if ($this->userRepository->findByEmail($email) !== null) {
                throw HttpException::badRequest('email_already_taken');
            }
            $this->otpService->requestForEmailChange($email);
        } else {
            $this->otpService->request($email);
        }

        return ResponseFactory::json(['success' => true], 200, $response);
    }

    public function otpVerify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        if (!v::email()->validate($body['email'] ?? '') || !v::length(6, 6)->validate($body['code'] ?? '')) {
            throw HttpException::badRequest('invalid_credentials');
        }

        $user = $request->getAttribute(AuthenticatedUser::class);
        $tokens = $this->otpService->verify(
            (string) $body['email'],
            (string) $body['code'],
            $user instanceof AuthenticatedUser ? $user : null
        );
        return ResponseFactory::json($tokens, 200, $response);
    }

    public function passkeyRegisterBegin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $options = $this->passkeyService->registerBegin($user);
        return ResponseFactory::json($options, 200, $response);
    }

    public function passkeyRegisterFinish(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->passkeyService->registerFinish($user, $body, $body['name'] ?? null);
        return ResponseFactory::json($result, 200, $response);
    }

    public function passkeyLoginBegin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $options = $this->passkeyService->loginBegin();
        return ResponseFactory::json($options, 200, $response);
    }

    public function passkeyLoginFinish(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $tokens = $this->passkeyService->loginFinish($body);
        return ResponseFactory::json($tokens, 200, $response);
    }

    public function passkeyDelete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $this->passkeyService->deletePasskey($user, $args['id']);
        return ResponseFactory::noContent($response);
    }

    public function discordStart(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $redirect = $request->getQueryParams()['redirect'] ?? null;
        $result = $this->discordOAuthService->start(is_string($redirect) ? $redirect : null);
        return $response->withHeader('Location', $result['url'])->withStatus(302);
    }

    public function discordCallback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $code = (string) ($params['code'] ?? '');
        $state = (string) ($params['state'] ?? '');
        if ($code === '' || $state === '') {
            throw HttpException::badRequest('invalid_callback');
        }
        $tokens = $this->discordOAuthService->callback($code, $state);
        if (isset($tokens['redirect'])) {
            $url = (string) $tokens['redirect'];
            unset($tokens['redirect']);
            $query = http_build_query($tokens);
            return $response->withHeader('Location', $url . (str_contains($url, '?') ? '&' : '?') . $query)->withStatus(302);
        }
        return ResponseFactory::json($tokens, 200, $response);
    }

    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $refreshToken = (string) ($body['refreshToken'] ?? '');
        if ($refreshToken === '') {
            throw HttpException::badRequest('missing_refresh_token');
        }
        $tokens = $this->tokenService->refresh($refreshToken);
        return ResponseFactory::json($tokens, 200, $response);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if (!v::email()->validate($email) || $password === '') {
            throw HttpException::badRequest('invalid_credentials');
        }

        $user = $this->userRepository->findByEmail($email);
        if ($user === null || !password_verify($password, (string) ($user['passwordHash'] ?? ''))) {
            throw HttpException::badRequest('invalid_credentials');
        }

        $tokens = $this->tokenService->issueTokenPair((string) $user['id']);
        return ResponseFactory::json($tokens, 200, $response);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $refreshToken = (string) ($body['refreshToken'] ?? '');
        $user = $request->getAttribute(AuthenticatedUser::class);
        $this->tokenService->logout($refreshToken, $user instanceof AuthenticatedUser ? $user : null);
        return ResponseFactory::json(['success' => true], 200, $response);
    }

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $dbUser = $this->userRepository->findById($user->id);
        if ($dbUser === null) {
            throw HttpException::notFound();
        }

        $userData = UserDto::fromRow($dbUser);

        $prefs = $this->preferencesRepository->findByUserId($user->id);
        if ($prefs !== null) {
            $userData['preferences'] = [
                'theme' => $prefs['theme'],
                'language' => $prefs['language'],
                'primaryColor' => $prefs['primaryColor'],
                'timezone' => $prefs['timezone'],
            ];
        } else {
            $userData['preferences'] = null;
        }

        return ResponseFactory::json(['data' => $userData], 200, $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return $user;
    }
}
