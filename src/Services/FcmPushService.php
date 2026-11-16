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

            return $this->cachedAccessToken;
        } catch (\Throwable $e) {
            $this->logger?->error('FCM token exchange error', ['error' => $e->getMessage()]);
            return null;
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
