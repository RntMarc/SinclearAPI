<?php

namespace Sinclear\Api\Services\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PDO;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Repository\UserRepository;

final readonly class DiscordOAuthService
{
    private const int STATE_TTL = 600;
    private const int PAIRING_CODE_TTL = 120;

    private Client $httpClient;

    public function __construct(
        private Settings $settings,
        private PDO $pdo,
        private OtpTokenRepository $otpTokenRepo,
    ) {
        $this->httpClient = new Client();
    }

    public function generateOAuthUrl(?string $userId = null): array
    {
        $state = Uuid::uuid7()->toString();
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $this->storeState($state, $codeVerifier, $userId);

        $redirectUri = $userId !== null
            ? ($this->settings->discord['relink_redirect_uri'] ?? $this->settings->discord['redirect_uri'])
            : $this->settings->discord['redirect_uri'];

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->settings->discord['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => 'identify email guilds',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return [
            'url' => 'https://discord.com/api/oauth2/authorize?' . $params,
            'state' => $state,
        ];
    }

    public function processCallback(string $code, string $state): array
    {
        $stored = $this->retrieveState($state);
        if ($stored === null) {
            throw new \RuntimeException('Invalid or expired state');
        }

        $codeVerifier = $stored['code_verifier'];

        $this->deleteState($state);

        $tokenData = $this->exchangeCode($code, $codeVerifier);
        $discordUser = $this->getUserInfo($tokenData['access_token']);

        $this->checkGuildMembership($tokenData['access_token'], $discordUser['id']);

        $user = $this->findOrFailUser($discordUser);
        if ($user === null) {
            throw new \RuntimeException('No account linked to this Discord account');
        }

        $pairingCode = $this->generatePairingCode();
        $expiresAt = new \DateTimeImmutable('+' . self::PAIRING_CODE_TTL . ' seconds');
        $this->otpTokenRepo->create($user['email'], $pairingCode, $expiresAt);

        return [
            'pairing_code' => $pairingCode,
            'user_id' => $user['id'],
        ];
    }

    private function generateCodeVerifier(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $verifier = '';
        for ($i = 0; $i < 128; $i++) {
            $verifier .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $verifier;
    }

    private function generateCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function storeState(string $state, string $codeVerifier, ?string $userId = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO WebauthnChallenge (id, challenge, userId, expiresAt, createdAt)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $expiresAt = new \DateTimeImmutable('+' . self::STATE_TTL . ' seconds');
        $stmt->execute([$state, $codeVerifier, $userId, $expiresAt->format('Y-m-d H:i:s.v')]);
    }

    private function retrieveState(string $state): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, challenge, userId, expiresAt FROM WebauthnChallenge
             WHERE id = ? AND expiresAt > NOW()'
        );
        $stmt->execute([$state]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result === false) {
            return null;
        }
        return [
            'code_verifier' => $result['challenge'],
            'userId' => $result['userId'],
        ];
    }

    private function deleteState(string $state): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM WebauthnChallenge WHERE id = ?');
        $stmt->execute([$state]);
    }

    public function processRelinkCallback(string $code, string $state): array
    {
        $stored = $this->retrieveState($state);
        if ($stored === null) {
            throw new \RuntimeException('Invalid or expired state');
        }

        $codeVerifier = $stored['code_verifier'];
        $userId = $stored['userId'];

        $this->deleteState($state);

        $redirectUri = $this->settings->discord['relink_redirect_uri']
            ?? $this->settings->discord['redirect_uri'];

        try {
            $response = $this->httpClient->post('https://discord.com/api/oauth2/token', [
                'form_params' => [
                    'client_id' => $this->settings->discord['client_id'],
                    'client_secret' => $this->settings->discord['client_secret'],
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $codeVerifier,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Discord token exchange failed: ' . $e->getMessage());
        }

        $tokenData = json_decode((string) $response->getBody(), true);
        if (!isset($tokenData['access_token'])) {
            throw new \RuntimeException('Failed to exchange Discord code');
        }

        $discordUser = $this->getUserInfo($tokenData['access_token']);
        $this->checkGuildMembership($tokenData['access_token'], $discordUser['id']);

        $pairingCode = $this->generatePairingCode();
        $expiresAt = new \DateTimeImmutable('+' . self::PAIRING_CODE_TTL . ' seconds');

        $metadata = json_encode([
            'type' => 'discord_relink',
            'userId' => $userId,
            'newDiscordId' => $discordUser['id'],
        ]);
        $this->otpTokenRepo->create($metadata, $pairingCode, $expiresAt);

        return [
            'pairing_code' => $pairingCode,
        ];
    }

    public function generateRegistrationOAuthUrl(): array
    {
        $state = Uuid::uuid7()->toString();
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $this->storeState($state, $codeVerifier, '__register__');

        $redirectUri = $this->settings->discord['register_redirect_uri']
            ?? $this->settings->discord['redirect_uri'];

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->settings->discord['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => 'identify email guilds',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return [
            'url' => 'https://discord.com/api/oauth2/authorize?' . $params,
            'state' => $state,
        ];
    }

    public function processRegistrationCallback(string $code, string $state): array
    {
        $stored = $this->retrieveState($state);
        if ($stored === null) {
            throw new \RuntimeException('Invalid or expired state');
        }

        if ($stored['userId'] !== '__register__') {
            throw new \RuntimeException('Invalid registration state');
        }

        $codeVerifier = $stored['code_verifier'];

        $this->deleteState($state);

        $redirectUri = $this->settings->discord['register_redirect_uri']
            ?? $this->settings->discord['redirect_uri'];

        try {
            $response = $this->httpClient->post('https://discord.com/api/oauth2/token', [
                'form_params' => [
                    'client_id' => $this->settings->discord['client_id'],
                    'client_secret' => $this->settings->discord['client_secret'],
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $codeVerifier,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Discord token exchange failed: ' . $e->getMessage());
        }

        $tokenData = json_decode((string) $response->getBody(), true);
        if (!isset($tokenData['access_token'])) {
            throw new \RuntimeException('Failed to exchange Discord code');
        }

        $discordUser = $this->getUserInfo($tokenData['access_token']);
        $this->checkGuildMembership($tokenData['access_token'], $discordUser['id']);

        $repo = new UserRepository($this->pdo);
        $existingByDiscord = $repo->findByDiscordId($discordUser['id']);
        if ($existingByDiscord !== null) {
            throw new \RuntimeException('Dieser Discord-Account ist bereits mit einem Konto verknüpft. Bitte melde dich an.');
        }

        $email = $discordUser['email'] ?? '';
        if (empty($email)) {
            throw new \RuntimeException('Keine E-Mail-Adresse von Discord erhalten. Bitte erteile die Berechtigung für deine E-Mail-Adresse.');
        }

        $existingByEmail = $repo->findByEmail($email);
        if ($existingByEmail !== null) {
            throw new \RuntimeException('Diese E-Mail ist bereits registriert. Bitte melde dich an.');
        }

        $displayName = $discordUser['username'] ?? 'User';
        $user = $repo->create($email, $displayName, $discordUser['id']);

        $pairingCode = $this->generatePairingCode();
        $expiresAt = new \DateTimeImmutable('+' . self::PAIRING_CODE_TTL . ' seconds');
        $this->otpTokenRepo->create($user['email'], $pairingCode, $expiresAt);

        return [
            'pairing_code' => $pairingCode,
            'user_id' => $user['id'],
        ];
    }

    private function exchangeCode(string $code, string $codeVerifier): array
    {
        try {
            $response = $this->httpClient->post('https://discord.com/api/oauth2/token', [
                'form_params' => [
                    'client_id' => $this->settings->discord['client_id'],
                    'client_secret' => $this->settings->discord['client_secret'],
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->settings->discord['redirect_uri'],
                    'code_verifier' => $codeVerifier,
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!isset($data['access_token'])) {
                throw new \RuntimeException('Failed to exchange Discord code');
            }

            return $data;
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Discord token exchange failed: ' . $e->getMessage());
        }
    }

    private function getUserInfo(string $accessToken): array
    {
        try {
            $response = $this->httpClient->get('https://discord.com/api/users/@me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!isset($data['id'])) {
                throw new \RuntimeException('Failed to get Discord user info');
            }

            return $data;
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Discord user info failed: ' . $e->getMessage());
        }
    }

    private function checkGuildMembership(string $accessToken, string $discordId): void
    {
        $guildId = $this->settings->discord['guild_id'];
        if (empty($guildId)) {
            return;
        }

        try {
            $response = $this->httpClient->get(
                "https://discord.com/api/users/@me/guilds",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                ]
            );

            $guilds = json_decode((string) $response->getBody(), true);
            if (!is_array($guilds)) {
                throw new \RuntimeException('Failed to check guild membership');
            }

            $isMember = false;
            foreach ($guilds as $guild) {
                if (isset($guild['id']) && $guild['id'] === $guildId) {
                    $isMember = true;
                    break;
                }
            }

            if (!$isMember) {
                throw new \RuntimeException('User is not a member of the required Discord guild');
            }
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Guild membership check failed: ' . $e->getMessage());
        }
    }

    private function findOrFailUser(array $discordUser): ?array
    {
        $repo = new UserRepository($this->pdo);
        $user = $repo->findByDiscordId($discordUser['id']);

        if ($user === null) {
            return null;
        }

        return $user;
    }

    private function generatePairingCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
