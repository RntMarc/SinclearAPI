<?php

declare(strict_types=1);

namespace Sinclear\Api\Service\Auth;

use Ramsey\Uuid\Uuid;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Dto\UserDto;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Repository\JtiBlacklistRepository;
use Sinclear\Api\Repository\RefreshTokenFamilyRepository;
use Sinclear\Api\Repository\RefreshTokenRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Jwt\JwtEncoder;

/**
 * Issues and validates JWT access tokens and refresh tokens with rotation.
 */
final class TokenService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly UserRepository $userRepository,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly RefreshTokenFamilyRepository $familyRepository,
        private readonly JtiBlacklistRepository $jtiBlacklistRepository
    ) {
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresIn: int, user: array<string, mixed>}
     */
    public function issueTokenPair(string $userId): array
    {
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw HttpException::notFound('user_not_found');
        }

        $family = $this->familyRepository->createForUser($userId);
        $refreshToken = $this->generateRefreshToken();
        $refreshTtl = (int) $this->settings->get('jwt.refresh_ttl', 7776000);

        $this->refreshTokenRepository->store(
            $family['id'],
            $userId,
            $this->hashToken($refreshToken),
            date('Y-m-d H:i:s', time() + $refreshTtl)
        );

        $access = $this->createAccessToken($user);

        return [
            'accessToken' => $access['token'],
            'refreshToken' => $refreshToken,
            'expiresIn' => (int) $this->settings->get('jwt.access_ttl', 900),
            'user' => UserDto::fromRow($user),
        ];
    }

    public function validateAccessToken(string $token): AuthenticatedUser
    {
        try {
            $payload = JwtEncoder::decode($token, $this->getVerificationKey(), $this->getAlgorithm());
            $jti = (string) ($payload['jti'] ?? '');

            if ($jti !== '' && $this->jtiBlacklistRepository->isBlacklisted($jti)) {
                throw HttpException::unauthorized('token_revoked');
            }

            return new AuthenticatedUser(
                (string) $payload['sub'],
                (string) ($payload['email'] ?? ''),
                (bool) ($payload['isAdmin'] ?? false),
                $jti
            );
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            throw HttpException::unauthorized('invalid_token');
        }
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresIn: int}
     */
    public function refresh(string $refreshToken): array
    {
        $hash = $this->hashToken($refreshToken);
        $stored = $this->refreshTokenRepository->findByHash($hash);

        if ($stored === null) {
            throw HttpException::unauthorized('invalid_refresh_token');
        }

        if ($stored['revoked_at'] !== null) {
            $this->familyRepository->revoke((string) $stored['family_id']);
            $this->refreshTokenRepository->revokeAllForFamily((string) $stored['family_id']);
            throw HttpException::unauthorized('refresh_token_reuse_detected');
        }

        if (strtotime((string) $stored['expires_at']) < time()) {
            throw HttpException::unauthorized('refresh_token_expired');
        }

        $this->refreshTokenRepository->revoke((string) $stored['id']);

        $userId = (string) $stored['user_id'];
        $familyId = (string) $stored['family_id'];
        $newRefresh = $this->generateRefreshToken();
        $refreshTtl = (int) $this->settings->get('jwt.refresh_ttl', 7776000);

        $this->refreshTokenRepository->store(
            $familyId,
            $userId,
            $this->hashToken($newRefresh),
            date('Y-m-d H:i:s', time() + $refreshTtl)
        );

        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $access = $this->createAccessToken($user);

        return [
            'accessToken' => $access['token'],
            'refreshToken' => $newRefresh,
            'expiresIn' => (int) $this->settings->get('jwt.access_ttl', 900),
        ];
    }

    public function logout(string $refreshToken, ?AuthenticatedUser $user = null): void
    {
        $hash = $this->hashToken($refreshToken);
        $stored = $this->refreshTokenRepository->findByHash($hash);

        if ($stored !== null) {
            $this->refreshTokenRepository->revoke((string) $stored['id']);
            $this->familyRepository->revoke((string) $stored['family_id']);
        }

        if ($user !== null && $user->jti !== '') {
            $accessTtl = (int) $this->settings->get('jwt.access_ttl', 900);
            $this->jtiBlacklistRepository->add(
                $user->jti,
                date('Y-m-d H:i:s', time() + $accessTtl)
            );
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array{token: string, jti: string}
     */
    private function createAccessToken(array $user): array
    {
        $jti = Uuid::uuid4()->toString();
        $ttl = (int) $this->settings->get('jwt.access_ttl', 900);
        $now = time();

        $payload = [
            'iss' => $this->settings->get('jwt.issuer', 'sinclear-beyond'),
            'sub' => $user['id'],
            'email' => $user['email'],
            'isAdmin' => (bool) $user['isAdmin'],
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        return [
            'token' => JwtEncoder::encode($payload, $this->getSigningKey(), $this->getAlgorithm()),
            'jti' => $jti,
        ];
    }

    private function getAlgorithm(): string
    {
        $publicKey = (string) $this->settings->get('jwt.public_key', '');
        return $publicKey !== '' ? 'RS256' : 'HS256';
    }

    private function getSigningKey(): string
    {
        $privateKey = (string) $this->settings->get('jwt.private_key', '');
        if ($privateKey !== '') {
            return str_replace('\\n', "\n", $privateKey);
        }
        return (string) ($_ENV['JWT_SECRET'] ?? 'dev-secret-change-in-production');
    }

    private function getVerificationKey(): string
    {
        $publicKey = (string) $this->settings->get('jwt.public_key', '');
        if ($publicKey !== '') {
            return str_replace('\\n', "\n", $publicKey);
        }
        return (string) ($_ENV['JWT_SECRET'] ?? 'dev-secret-change-in-production');
    }

    private function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
