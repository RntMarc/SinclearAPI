<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Sinclear\Api\Application\Settings;

/**
 * Dünner HTTP-Wrapper für die Matrix-Client-Server-API (Continuwuity).
 *
 * Authentifizierung erfolgt über den Application Service:
 * - `Authorization: Bearer <as_token>`
 * - Profiloperationen zusätzlich mit `?user_id=<mxid>` (AS-Impersonation,
 *   kein User-Access-Token nötig).
 */
final readonly class MatrixClient
{
    public function __construct(
        private ClientInterface $httpClient,
        private Settings $settings,
    ) {}

    /**
     * Legt einen Matrix-Account über den Application Service an.
     *
     * Idempotent: Bei `M_USER_IN_USE` wird der deterministische Matrix-User
     * als Erfolg gewertet (kein doppeltes Anlegen).
     *
     * @return string vollständige Matrix-User-ID (z.B. @sb_...:server)
     * @throws MatrixClientException bei transientem/permanentem Fehler
     */
    public function registerAs(string $localpart, string $password): string
    {
        $baseUrl = $this->settings->matrix['homeserver_url'];
        $asToken = $this->settings->matrix['as_token'];
        $serverName = $this->settings->matrix['server_name'];

        $response = $this->request('POST', $baseUrl . '/_matrix/client/v3/register', [
            'json' => [
                'type' => 'm.login.application_service',
                'username' => $localpart,
                'password' => $password,
            ],
            'headers' => ['Authorization' => 'Bearer ' . $asToken],
        ]);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            return '@' . $localpart . ':' . $serverName;
        }

        $error = $this->decodeError($response);
        if (($error['errcode'] ?? '') === 'M_USER_IN_USE') {
            // Idempotenter Retry: Konto existiert bereits.
            return '@' . $localpart . ':' . $serverName;
        }

        throw $this->classify($response, $error);
    }

    /**
     * Setzt den Anzeigenamen eines Matrix-Accounts (AS-Impersonation).
     *
     * @throws MatrixClientException bei transientem/permanentem Fehler
     */
    public function setDisplayName(string $matrixUserId, string $displayName): void
    {
        $baseUrl = $this->settings->matrix['homeserver_url'];
        $asToken = $this->settings->matrix['as_token'];
        $encoded = rawurlencode($matrixUserId);

        $response = $this->request(
            'PUT',
            $baseUrl . '/_matrix/client/v3/profile/' . $encoded . '/displayname?user_id=' . $encoded,
            [
                'json' => ['displayname' => $displayName],
                'headers' => ['Authorization' => 'Bearer ' . $asToken],
            ]
        );

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            return;
        }

        throw $this->classify($response, $this->decodeError($response));
    }

    private function request(string $method, string $uri, array $options): ResponseInterface
    {
        try {
            return $this->httpClient->request($method, $uri, $options + ['http_errors' => false]);
        } catch (GuzzleException $e) {
            throw MatrixClientException::transient('Matrix request failed: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function decodeError(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $error */
    private function classify(ResponseInterface $response, array $error): MatrixClientException
    {
        $status = $response->getStatusCode();
        $errcode = (string) ($error['errcode'] ?? '');
        $message = (string) ($error['error'] ?? ('HTTP ' . $status));

        if ($status === 429 || $errcode === 'M_LIMIT_EXCEEDED' || $errcode === 'M_UNKNOWN') {
            return MatrixClientException::transient($message, $errcode, $status);
        }

        if ($status >= 500) {
            return MatrixClientException::transient($message, $errcode, $status);
        }

        return MatrixClientException::permanent($message, $errcode, $status);
    }
}
