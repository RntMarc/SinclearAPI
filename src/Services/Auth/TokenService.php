<?php

namespace Sinclear\Api\Services\Auth;

use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\JtiBlacklistRepository;
use Sinclear\Api\Repository\RefreshTokenRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final readonly class TokenService
{
    public const string ADMIN_SCOPE = 'admin';

    private const int ADMIN_ACCESS_TTL = 1200;

    public function __construct(
        private Settings $settings,
        private PDO $pdo,
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

    /**
     * Stellt einen kurzlebigen, nicht-refreshbaren Admin-Token aus. Wird nach
     * erfolgreichem Step-up-OTP (erneute Admin-Bestätigung) verwendet und
     * traegt einen eigenen `scope`-Claim, damit er klar vom normalen
     * Access-Token unterscheidbar ist.
     */
    public function createAdminAccessToken(AuthenticatedUser $user): string
    {
        $now = time();
        $payload = [
            'iss' => $this->settings->jwt['issuer'],
            'sub' => $user->id,
            'email' => $user->email,
            'isAdmin' => true,
            'scope' => self::ADMIN_SCOPE,
            'jti' => $user->jti,
            'iat' => $now,
            'exp' => $now + self::ADMIN_ACCESS_TTL,
        ];

        return $this->encodeJwt($payload);
    }

    public function getAdminAccessTtl(): int
    {
        return self::ADMIN_ACCESS_TTL;
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

    /**
     * Rotiert einen Refresh-Token mit Grace-Period und Reuse-Detection.
     *
     * Ablauf (atomar, Transaktion + Row-Lock):
     *  1. Gültiger Token → normal rotieren (alten revoken, neuen anlegen).
     *  2. Bereits rotierter Token innerhalb der Grace-Periode → benigner
     *     Replay: der gültige Nachfolger wird re-rotatiert, die Familie bleibt
     *     bestehen (toleriert verlorene Antworten / parallele Requests).
     *  3. Bekannter, aber abgelaufener Token (oder Replay nach Grace-Periode)
     *     → echte Reuse-Detection: gesamte Familie sperren, null zurückgeben.
     */
    public function rotateRefreshToken(string $oldTokenHash): ?array
    {
        $graceSeconds = (int) ($this->settings->jwt['refresh_reuse_grace'] ?? 60);
        $now = new DateTimeImmutable();

        $this->pdo->beginTransaction();
        try {
            $token = $this->refreshTokenRepo->findValidToken($oldTokenHash, forUpdate: true);
            if ($token !== null) {
                return $this->rotate($token, $now, $graceSeconds);
            }

            $successor = $this->refreshTokenRepo->findGraceEligibleSuccessor($oldTokenHash, forUpdate: true);
            if ($successor !== null) {
                return $this->rotate($successor, $now, $graceSeconds);
            }

            // Echte Reuse-Detection: Token bekannt, aber nicht mehr tolerierbar.
            $reused = $this->refreshTokenRepo->findAnyByHash($oldTokenHash);
            if ($reused !== null) {
                $this->refreshTokenRepo->revokeFamily($reused['familyId'], $now);
            }

            $this->pdo->commit();
            return null;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Führt die eigentliche Rotation für einen (gültigen) Token durch und
     * committet die laufende Transaktion.
     *
     * @param array<string, mixed> $token
     * @return array{refresh_token: string, expires_at: int, user_id: string}
     */
    private function rotate(array $token, DateTimeImmutable $now, int $graceSeconds): array
    {
        $this->refreshTokenRepo->revokeToken($token['id'], $now);

        $newRefreshToken = $this->generateRefreshToken();
        $newTokenHash = $this->hashRefreshToken($newRefreshToken);

        $expiresAt = new DateTimeImmutable('+' . $this->settings->jwt['refresh_ttl'] . ' seconds');
        $graceUntil = $now->modify('+' . $graceSeconds . ' seconds');

        $this->refreshTokenRepo->createToken(
            $token['familyId'],
            $token['userId'],
            $newTokenHash,
            $expiresAt,
            previousTokenHash: $token['tokenHash'],
            graceUntil: $graceUntil,
        );

        $this->pdo->commit();

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

    /**
     * Setzt die JTI eines Access-Tokens auf die Blacklist, sodass der Token
     * bis zu seinem regulären Ablauf ungültig ist (Logout).
     */
    public function blacklistJti(string $jti): void
    {
        $expiresAt = new DateTimeImmutable('+' . $this->settings->jwt['access_ttl'] . ' seconds');
        $this->jtiBlacklistRepo->add($jti, $expiresAt);
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
