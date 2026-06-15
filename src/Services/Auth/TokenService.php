<?php

namespace Sinclear\Api\Services\Auth;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\JtiBlacklistRepository;
use Sinclear\Api\Repository\RefreshTokenRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class TokenService
{
    public function __construct(
        private Settings $settings,
        private RefreshTokenRepository $refreshTokenRepo,
        private JtiBlacklistRepository $jtiBlacklistRepo,
    ) {}

    public function createAccessToken(AuthenticatedUser $user): string
    {
        $now = time();
        $payload = [
            'iss' => $this->settings->jwt['issuer'],
            'sub' => $user->id,
            'email' => $user->email,
            'isAdmin' => $user->isAdmin,
            'jti' => $user->jti,
            'iat' => $now,
            'exp' => $now + $this->settings->jwt['access_ttl'],
        ];

        return $this->encodeJwt($payload);
    }

    public function validateAccessToken(string $token): ?object
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = $this->decodeJwt($token);
        if ($payload === null) {
            return null;
        }

        if (!isset($payload->jti) || $this->jtiBlacklistRepo->isBlacklisted($payload->jti)) {
            return null;
        }

        if (!isset($payload->exp) || $payload->exp < time()) {
            return null;
        }

        return $payload;
    }

    public function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(64));
    }

    public function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function createRefreshSession(string $userId): array
    {
        $familyId = $this->refreshTokenRepo->createFamily($userId);

        $refreshToken = $this->generateRefreshToken();
        $tokenHash = $this->hashRefreshToken($refreshToken);

        $expiresAt = new DateTimeImmutable('+' . $this->settings->jwt['refresh_ttl'] . ' seconds');
        $this->refreshTokenRepo->createToken($familyId, $userId, $tokenHash, $expiresAt);

        return [
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt->getTimestamp(),
        ];
    }

    public function rotateRefreshToken(string $oldTokenHash): ?array
    {
        $token = $this->refreshTokenRepo->findValidToken($oldTokenHash);
        if ($token === null) {
            return null;
        }

        $now = new DateTimeImmutable();

        $this->refreshTokenRepo->revokeToken($token['id'], $now);

        $newRefreshToken = $this->generateRefreshToken();
        $newTokenHash = $this->hashRefreshToken($newRefreshToken);

        $expiresAt = new DateTimeImmutable('+' . $this->settings->jwt['refresh_ttl'] . ' seconds');
        $this->refreshTokenRepo->createToken(
            $token['familyId'],
            $token['userId'],
            $newTokenHash,
            $expiresAt,
        );

        return [
            'refresh_token' => $newRefreshToken,
            'expires_at' => $expiresAt->getTimestamp(),
            'user_id' => $token['userId'],
        ];
    }

    public function getAccessTtl(): int
    {
        return $this->settings->jwt['access_ttl'];
    }

    public function revokeRefreshToken(string $tokenHash): void
    {
        $token = $this->refreshTokenRepo->findValidToken($tokenHash);
        if ($token !== null) {
            $this->refreshTokenRepo->revokeToken($token['id'], new DateTimeImmutable());
        }
    }

    private function encodeJwt(array $payload): string
    {
        $header = $this->base64urlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payloadEncoded = $this->base64urlEncode(json_encode($payload));

        $signature = '';
        openssl_sign(
            "$header.$payloadEncoded",
            $signature,
            $this->settings->jwt['private_key'],
            OPENSSL_ALGO_SHA256,
        );

        return "$header.$payloadEncoded." . $this->base64urlEncode($signature);
    }

    private function decodeJwt(string $token): ?object
    {
        $parts = explode('.', $token);

        $signature = $this->base64urlDecode($parts[2]);
        $valid = openssl_verify(
            "$parts[0].$parts[1]",
            $signature,
            $this->settings->jwt['public_key'],
            OPENSSL_ALGO_SHA256,
        );

        if ($valid !== 1) {
            return null;
        }

        $payload = json_decode($this->base64urlDecode($parts[1]));
        if (!$payload instanceof \stdClass) {
            return null;
        }

        return $payload;
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
