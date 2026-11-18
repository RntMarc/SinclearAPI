<?php

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Repository\PushSubscriptionRepository;

/**
 * Sendet Google-FCM-Benachrichtigungen (HTTP v1 API).
 *
 * STRIKTE DATENSCHUTZREGEL:
 * Über FCM dürfen NIEMALS personalisierte Inhalte (Namen, Titel, Texte,
 * Chat-Nachrichten oder Relationsdaten) übertragen werden. Gesendet wird
 * ausschließlich ein anonymes Data-Only Wake-up-Signal (`wakeup = "1"`,
 * `notificationId`), das die Android-App weckt, damit sie den eigentlichen
 * Inhalt direkt und verschlüsselt von der Sinclear-API abruft.
 */
final class FcmPushService
{
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const string FCM_SEND_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const string FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const int TOKEN_LIFETIME = 3600;

    private Client $httpClient;
    private ?string $cachedAccessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly PushSubscriptionRepository $pushSubRepo,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $projectId = '',
        private readonly string $clientEmail = '',
        private readonly string $privateKey = '',
        ?Client $httpClient = null,
        private readonly string $cacheFile = __DIR__ . '/../../var/cache/fcm-token.json',
    ) {
        $this->httpClient = $httpClient ?? new Client(['timeout' => 10]);
    }

    public function isConfigured(): bool
    {
        return $this->projectId !== '' && $this->clientEmail !== '' && $this->privateKey !== '';
    }

    /**
     * Erzeugt den strikt anonymisierten FCM-Payload für ein Wake-up-Signal.
     *
     * Enthält keinerlei `notification`-Block und keinerlei persönliche Daten
     * (keine Namen, keine Texte, keine Relations-IDs).
     *
     * @return array{message: array{token: string, data: array{wakeup: string, notificationId: string}, android: array{priority: string}}}
     */
    public static function buildWakeupPayload(string $fcmToken, string $notificationId): array
    {
        return [
            'message' => [
                'token' => $fcmToken,
                'data' => [
                    'wakeup' => '1',
                    'notificationId' => $notificationId,
                ],
                'android' => [
                    'priority' => 'high',
                ],
            ],
        ];
    }

    /**
     * Sendet ein anonymes Wake-up-Signal an alle hinterlegten FCM-Subscriptions
     * des Nutzers.
     */
    public function sendWakeupToUser(string $userId, string $notificationId): void
    {
        if (!$this->isConfigured()) {
            $this->logger?->warning('FCM wakeup skipped: FCM is not configured');
            return;
        }

        $subscriptions = $this->pushSubRepo->findByUserId($userId, 'fcm');
        if ($subscriptions === []) {
            return;
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            foreach ($subscriptions as $sub) {
                $this->pushSubRepo->markFailure($sub['id'], 'FCM OAuth token exchange failed');
            }
            return;
        }

        foreach ($subscriptions as $sub) {
            $this->sendWakeupToSubscription($sub, $notificationId, $accessToken);
        }
    }

    /**
     * @param array<string, mixed> $sub
     */
    private function sendWakeupToSubscription(array $sub, string $notificationId, string $accessToken): void
    {
        $subId = (string) $sub['id'];
        $fcmToken = (string) $sub['endpoint'];
        $url = sprintf(self::FCM_SEND_URL, $this->projectId);
        $payload = self::buildWakeupPayload($fcmToken, $notificationId);

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->pushSubRepo->markSuccess($subId);
                return;
            }

            $this->pushSubRepo->markFailure($subId, 'HTTP ' . $statusCode);
            $this->logger?->warning('FCM unexpected status', [
                'status' => $statusCode,
                'subId' => $subId,
            ]);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            $errorData = json_decode($body, true);
            $errorStatus = is_array($errorData) ? (string) ($errorData['error']['status'] ?? '') : '';
            $errorMessage = is_array($errorData)
                ? (string) ($errorData['error']['message'] ?? $e->getMessage())
                : $e->getMessage();

            if (
                $statusCode === 404
                || $statusCode === 410
                || $errorStatus === 'UNREGISTERED'
                || $errorStatus === 'NOT_FOUND'
                || str_contains($errorMessage, 'Device unregistered')
                || str_contains($errorMessage, 'Requested entity was not found')
            ) {
                $this->pushSubRepo->deleteById($subId);
                return;
            }

            $this->pushSubRepo->markFailure($subId, 'HTTP ' . $statusCode . ': ' . $errorMessage);
            $this->logger?->warning('FCM delivery failed', [
                'subId' => $subId,
                'status' => $statusCode,
                'errorStatus' => $errorStatus,
                'error' => $errorMessage,
            ]);
        } catch (GuzzleException|\Throwable $e) {
            $this->pushSubRepo->markFailure($subId, $e->getMessage());
            $this->logger?->warning('FCM delivery failed', [
                'subId' => $subId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getAccessToken(): ?string
    {
        if ($this->cachedAccessToken !== null && time() < $this->tokenExpiresAt) {
            return $this->cachedAccessToken;
        }

        $cached = self::readCachedToken($this->cacheFile, $this->clientEmail);
        if ($cached !== null) {
            $this->cachedAccessToken = $cached['token'];
            $this->tokenExpiresAt = $cached['expiresAt'];
            return $this->cachedAccessToken;
        }

        $now = time();

        try {
            $jwt = $this->createJwt($now);
        } catch (\Throwable $e) {
            $this->logger?->error('FCM JWT creation failed', ['error' => $e->getMessage()]);
            return null;
        }

        try {
            $response = $this->httpClient->post(self::TOKEN_URL, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data) || empty($data['access_token']) || !is_string($data['access_token'])) {
                $this->logger?->error('FCM token exchange failed');
                return null;
            }

            $this->cachedAccessToken = $data['access_token'];
            $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : self::TOKEN_LIFETIME;
            $this->tokenExpiresAt = $now + $expiresIn - 60;
            self::writeCachedToken(
                $this->cacheFile,
                $this->cachedAccessToken,
                $this->tokenExpiresAt,
                $this->clientEmail,
            );

            return $this->cachedAccessToken;
        } catch (\Throwable $e) {
            $this->logger?->error('FCM token exchange error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Liest ein zwischen Requests geteiltes OAuth-Token aus dem Dateisystem.
     *
     * PHP-FPM erzeugt pro Request eine neue Service-Instanz; ohne Dateicache
     * würde jede Benachrichtigung einen neuen JWT signieren und einen
     * blockierenden Token-Request zu Google auslösen.
     *
     * @return array{token: string, expiresAt: int}|null
     */
    private static function readCachedToken(string $cacheFile, string $clientEmail): ?array
    {
        if (!is_file($cacheFile)) {
            return null;
        }

        try {
            $raw = file_get_contents($cacheFile);
            if ($raw === false) {
                return null;
            }
            $data = json_decode($raw, true);
            $cachedToken = is_array($data) ? ($data['token'] ?? null) : null;
            $expiresAt = is_array($data) ? ($data['expiresAt'] ?? null) : null;
            $cachedClientEmail = is_array($data) ? ($data['clientEmail'] ?? null) : null;
            if (
                !is_string($cachedToken)
                || !is_int($expiresAt)
                || !is_string($cachedClientEmail)
                || $cachedClientEmail !== $clientEmail
                || time() >= $expiresAt
            ) {
                return null;
            }
            return ['token' => $cachedToken, 'expiresAt' => $expiresAt];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function writeCachedToken(
        string $cacheFile,
        string $token,
        int $expiresAt,
        string $clientEmail,
    ): void {
        $payload = json_encode([
            'token' => $token,
            'expiresAt' => $expiresAt,
            'clientEmail' => $clientEmail,
        ]);
        if ($payload === false) {
            return;
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $tmp = $cacheFile . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload) === false) {
            return;
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $cacheFile)) {
            @unlink($tmp);
        }
    }

    private function createJwt(int $now): string
    {
        $header = self::base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claimSet = self::base64UrlEncode((string) json_encode([
            'iss' => $this->clientEmail,
            'scope' => self::FCM_SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + self::TOKEN_LIFETIME,
        ]));

        $signingInput = $header . '.' . $claimSet;
        $normalizedKey = str_replace('\n', "\n", $this->privateKey);
        $privateKey = openssl_pkey_get_private($normalizedKey);
        if ($privateKey === false) {
            throw new \RuntimeException('Invalid FCM private key');
        }

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new \RuntimeException('Failed to sign FCM JWT');
        }

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
