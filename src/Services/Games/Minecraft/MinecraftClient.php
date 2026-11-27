<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games\Minecraft;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\Settings;

/**
 * Schlanker HTTP-Client fuer die Mojang-API.
 *
 * Wird nur beim Verknuepfen eines Minecraft-Accounts verwendet, um eine
 * UUID oder einen Usernamen aufzuloesen. Der Avatar stammt aus einem
 * konfigurierbaren Avatar-Dienst (mc-heads.net).
 */
final readonly class MinecraftClient
{
    private const string USER_AGENT = 'SinclearBeyondAPI/2.0 (https://sinclear.app)';
    private const int HTTP_TIMEOUT = 10;

    private Client $httpClient;
    private string $profileApiBaseUrl;
    private string $sessionApiBaseUrl;
    private string $avatarBaseUrl;

    public function __construct(
        Settings $settings,
        private LoggerInterface $logger,
        ?Client $httpClient = null,
    ) {
        $minecraft = $settings->minecraft;
        $this->profileApiBaseUrl = rtrim((string) ($minecraft['profile_api_base_url'] ?? 'https://api.mojang.com'), '/');
        $this->sessionApiBaseUrl = rtrim((string) ($minecraft['session_api_base_url'] ?? 'https://sessionserver.mojang.com'), '/');
        $this->avatarBaseUrl = rtrim((string) ($minecraft['avatar_base_url'] ?? 'https://mc-heads.net/avatar'), '/');
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Loest einen Usernamen ueber die Mojang-Profile-API auf.
     *
     * @return array{id: string, name: string}|null null, wenn der Name nicht existiert
     */
    public function findByName(string $name): ?array
    {
        $data = $this->getJson($this->profileApiBaseUrl . '/users/profiles/minecraft/' . rawurlencode($name));
        if ($data === null) {
            return null;
        }

        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            return null;
        }

        return [
            'id' => $id,
            'name' => (string) ($data['name'] ?? $name),
        ];
    }

    /**
     * Loest eine UUID ueber die Mojang-Session-API auf.
     *
     * @return array{id: string, name: string}|null null, wenn der Account nicht existiert
     */
    public function findByUuid(string $uuid): ?array
    {
        $data = $this->getJson($this->sessionApiBaseUrl . '/session/minecraft/profile/' . $uuid);
        if ($data === null) {
            return null;
        }

        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            return null;
        }

        return [
            'id' => $id,
            'name' => (string) ($data['name'] ?? ''),
        ];
    }

    /**
     * Avatar-URL fuer eine UUID aus dem konfigurierten Avatar-Dienst.
     */
    public function avatarUrl(string $uuid): string
    {
        return $this->avatarBaseUrl . '/' . $uuid . '/64';
    }

    /**
     * @return array<string, mixed>|null null bei 204/404 (nicht gefunden)
     */
    private function getJson(string $url): ?array
    {
        try {
            $response = $this->httpClient->get($url);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();

            // 404 (bzw. 204 als leerer Erfolg) bedeutet "nicht gefunden".
            if ($status === 404) {
                return null;
            }

            $retryAfter = $response->getHeaderLine('Retry-After');
            $this->logger->warning('Mojang API request failed', [
                'url' => $url,
                'status' => $status,
                'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
            ]);

            throw new MinecraftApiException(
                status: $status,
                retryAfter: $retryAfter !== '' ? (int) $retryAfter : null,
                message: 'mojang_api_error',
            );
        } catch (RequestException $e) {
            $this->logger->warning('Mojang API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new MinecraftApiException(status: 0, retryAfter: null, message: 'mojang_api_unreachable');
        } catch (GuzzleException $e) {
            $this->logger->warning('Mojang API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new MinecraftApiException(status: 0, retryAfter: null, message: 'mojang_api_unreachable');
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
