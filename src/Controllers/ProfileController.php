<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\ProfileService;

final readonly class ProfileController
{
    public function __construct(
        private ProfileService $profileService,
    ) {}

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body) || $body === []) {
            return ResponseFactory::json(['error' => 'invalid_request'], 400, $response);
        }

        try {
            $profile = $this->profileService->updateProfile($user, $body);
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }

        return ResponseFactory::json(['data' => $profile], 200, $response);
    }

    public function requestEmailChange(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();
        $newEmail = trim((string) ($body['newEmail'] ?? ''));

        try {
            $this->profileService->requestEmailChange($user, $newEmail);
        } catch (\InvalidArgumentException $e) {
            $status = match ($e->getMessage()) {
                'too_many_requests' => 429,
                default => 400,
            };
            return ResponseFactory::json(['error' => $e->getMessage()], $status, $response);
        }

        return ResponseFactory::json(['message' => 'otp_sent'], 200, $response);
    }

    public function verifyEmailChange(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();
        $code = trim((string) ($body['code'] ?? ''));
        $newEmail = trim((string) ($body['newEmail'] ?? ''));

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return ResponseFactory::json(['error' => 'invalid_email'], 400, $response);
        }

        try {
            $this->profileService->verifyEmailChange($user, $code, $newEmail);
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }

        return ResponseFactory::json(['message' => 'email_updated'], 200, $response);
    }

    public function startDiscordRelink(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $result = $this->profileService->startDiscordRelink($user);

        return ResponseFactory::json(['url' => $result['url']], 200, $response);
    }

    public function discordCallback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $code = $params['code'] ?? '';
        $state = $params['state'] ?? '';

        if ($code === '' || $state === '') {
            return $this->htmlError($response, 'Ungültige Anfrage.');
        }

        try {
            $result = $this->profileService->processDiscordCallback($code, $state);

            $html = strtr(
                file_get_contents(__DIR__ . '/../../templates/discord-callback.php') ?: '',
                ['{{code}}' => htmlspecialchars($result['pairing_code'])],
            );

            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\RuntimeException $e) {
            return $this->htmlError($response, htmlspecialchars($e->getMessage()));
        }
    }

    public function verifyDiscordRelink(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();
        $code = trim((string) ($body['code'] ?? ''));

        try {
            $this->profileService->verifyDiscordRelink($user, $code);
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }

        return ResponseFactory::json(['message' => 'discord_updated'], 200, $response);
    }

    public function updateVisibility(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $request->getParsedBody();

        if (!is_array($body) || $body === []) {
            return ResponseFactory::json(['error' => 'invalid_request'], 400, $response);
        }

        try {
            $this->profileService->updateVisibility($user, $body);
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::json(['error' => $e->getMessage()], 400, $response);
        }

        return ResponseFactory::json(['message' => 'visibility_updated'], 200, $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }

    private function htmlError(ResponseInterface $response, string $message): ResponseInterface
    {
        $response->getBody()->write(
            '<html><body><h1>Fehler</h1><p>' . $message . '</p></body></html>'
        );
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
