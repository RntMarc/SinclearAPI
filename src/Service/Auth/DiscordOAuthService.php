<?php

declare(strict_types=1);

namespace Sinclear\Api\Service\Auth;

use GuzzleHttp\Client;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Repository\UserRepository;

/**
 * Discord OAuth2 flow with PKCE.
 */
final class DiscordOAuthService
{
    private const string STATE_DIR = 'discord-oauth';

    public function __construct(
        private readonly Settings $settings,
        private readonly UserRepository $userRepository,
        private readonly TokenService $tokenService,
        private readonly Client $httpClient = new Client(['timeout' => 20])
    ) {
    }

    /**
     * @return array{url: string}
     */
    public function start(?string $redirectAfter = null): array
    {
        $discord = $this->settings->get('discord', []);
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(16));

        $this->storeState($state, [
            'code_verifier' => $codeVerifier,
            'redirect_after' => $redirectAfter,
        ]);

        $params = http_build_query([
            'client_id' => $discord['client_id'],
            'redirect_uri' => $discord['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'identify email guilds',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => 'https://discord.com/api/oauth2/authorize?' . $params];
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresIn: int, user: array<string, mixed>, redirect?: string}
     */
    public function callback(string $code, string $state): array
    {
        $stored = $this->loadState($state);
        if ($stored === null) {
            throw HttpException::badRequest('invalid_state');
        }

        $this->deleteState($state);
        $discord = $this->settings->get('discord', []);

        $tokenResponse = $this->httpClient->post('https://discord.com/api/oauth2/token', [
            'form_params' => [
                'client_id' => $discord['client_id'],
                'client_secret' => $discord['client_secret'],
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $discord['redirect_uri'],
                'code_verifier' => $stored['code_verifier'],
            ],
        ]);

        $tokens = json_decode((string) $tokenResponse->getBody(), true);
        $accessToken = (string) ($tokens['access_token'] ?? '');

        $userResponse = $this->httpClient->get('https://discord.com/api/users/@me', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);
        $discordUser = json_decode((string) $userResponse->getBody(), true);
        $discordId = (string) ($discordUser['id'] ?? '');

        if ($discordId === '') {
            throw HttpException::badRequest('discord_auth_failed');
        }

        $user = $this->userRepository->findByDiscordId($discordId);
        if ($user === null) {
            $email = (string) ($discordUser['email'] ?? $discordId . '@discord.local');
            $existing = $this->userRepository->findByEmail($email);
            if ($existing !== null) {
                $user = $this->userRepository->update((string) $existing['id'], [
                    'discordId' => $discordId,
                ]);
            } else {
                $user = $this->userRepository->create([
                    'id' => Uuid::uuid4()->toString(),
                    'email' => $email,
                    'passwordHash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),
                    'displayName' => (string) ($discordUser['username'] ?? 'Discord User'),
                    'discordId' => $discordId,
                    'isAdmin' => 0,
                    'onboardingCompleted' => 0,
                    'birthdayVisibility' => 1,
                    'emailVisibility' => 1,
                    'createdAt' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $result = $this->tokenService->issueTokenPair((string) $user['id']);
        if (!empty($stored['redirect_after'])) {
            $result['redirect'] = (string) $stored['redirect_after'];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function storeState(string $state, array $data): void
    {
        $dir = $this->getStateDir();
        file_put_contents($dir . '/' . hash('sha256', $state) . '.json', (string) json_encode($data), LOCK_EX);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadState(string $state): ?array
    {
        $file = $this->getStateDir() . '/' . hash('sha256', $state) . '.json';
        if (!is_file($file)) {
            return null;
        }
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    private function deleteState(string $state): void
    {
        $file = $this->getStateDir() . '/' . hash('sha256', $state) . '.json';
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function getStateDir(): string
    {
        $dir = dirname(__DIR__, 3) . '/var/' . self::STATE_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }
}
