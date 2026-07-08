<?php

namespace Sinclear\Api\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Repository\UserDeviceRepository;

final class PushService
{
    private Client $httpClient;

    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const string FCM_SEND_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const string FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const int TOKEN_LIFETIME = 3600;

    private ?string $cachedAccessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private UserDeviceRepository $deviceRepo,
        private LoggerInterface $logger,
        private string $projectId,
        private string $clientEmail,
        private string $privateKey,
    ) {
        $this->httpClient = new Client(['timeout' => 10]);
    }

    public function sendNotificationToUser(string $userId, string $notificationId): void
    {
        if ($this->projectId === '' || $this->clientEmail === '' || $this->privateKey === '') {
            $this->logger->warning('FCM not configured, skipping push notification');
            return;
        }

        $devices = $this->deviceRepo->findPushEnabledDevices($userId);

        if (empty($devices)) {
            return;
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            $this->logger->error('Failed to obtain FCM access token');
            return;
        }

        foreach ($devices as $device) {
            $this->sendToDevice($device['pushToken'], $notificationId, $accessToken);
        }
    }

    public function sendToDevice(string $fcmToken, string $notificationId, ?string $accessToken = null): bool
    {
        if ($this->projectId === '' || $this->clientEmail === '' || $this->privateKey === '') {
            return false;
        }

        $accessToken ??= $this->getAccessToken();
        if ($accessToken === null) {
            return false;
        }

        $url = sprintf(self::FCM_SEND_URL, $this->projectId);

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'data' => [
                    'notificationId' => $notificationId,
                ],
            ],
        ];

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
                return true;
            }

            $body = (string) $response->getBody();
            $this->logger->warning('FCM response unexpected status', [
                'status' => $statusCode,
                'body' => $body,
            ]);
            return false;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response !== null ? (string) $response->getBody() : '';

            $errorData = json_decode($body, true);
            $errorStatus = $errorData['error']['status'] ?? '';
            $errorMessage = $errorData['error']['message'] ?? $e->getMessage();

            if (
                $errorStatus === 'UNREGISTERED'
                || $errorStatus === 'NOT_FOUND'
                || str_contains($errorMessage, 'Device unregistered')
                || str_contains($errorMessage, 'Requested entity was not found')
            ) {
                $this->deviceRepo->deleteByPushToken($fcmToken);
                $this->logger->info('FCM token removed (unregistered)', [
                    'errorStatus' => $errorStatus,
                    'message' => $errorMessage,
                ]);
            } else {
                $this->logger->warning('FCM response error', [
                    'errorStatus' => $errorStatus,
                    'body' => $body,
                ]);
            }

            return false;
        } catch (GuzzleException $e) {
            $this->logger->error('FCM send failed (network error)', ['error' => $e->getMessage()]);
            return false;
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
        } catch (\RuntimeException $e) {
            $this->logger->error('FCM JWT creation failed', ['error' => $e->getMessage()]);
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

            if (!is_array($data) || empty($data['access_token'])) {
                $this->logger->error('FCM token exchange failed', ['response' => $data]);
                return null;
            }

            $this->cachedAccessToken = $data['access_token'];
            $this->tokenExpiresAt = $now + ($data['expires_in'] ?? self::TOKEN_LIFETIME) - 60;

            return $this->cachedAccessToken;
        } catch (GuzzleException $e) {
            $this->logger->error('FCM token exchange error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function createJwt(int $now): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claimSet = $this->base64UrlEncode(json_encode([
            'iss' => $this->clientEmail,
            'scope' => self::FCM_SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + self::TOKEN_LIFETIME,
        ]));

        $data = $header . '.' . $claimSet;

        $privateKey = openssl_pkey_get_private($this->privateKey);
        if ($privateKey === false) {
            $this->logger->error('Failed to load FCM private key');
            throw new \RuntimeException('Invalid FCM private key');
        }

        $signature = '';
        openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        openssl_pkey_free($privateKey);

        return $data . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
