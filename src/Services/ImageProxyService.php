<?php

namespace Sinclear\Api\Services;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\ClientInterface;

final readonly class ImageProxyService
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private const PRIVATE_IPV6_PREFIXES = [
        'fc00:',
        'fd00:',
        'fe80:',
        '::1',
        '::0',
    ];

    private const MAX_REDIRECTS = 5;

    private const TYPE_CONFIG = [
        'favicon' => [
            'timeout' => 3,
            'maxSize' => 16 * 1024,
        ],
        'preview' => [
            'timeout' => 8,
            'maxSize' => 2 * 1024 * 1024,
        ],
    ];

    public function __construct(
        private ClientInterface $httpClient,
    ) {}

    /**
     * @return array{body: string, contentType: string}
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function proxy(string $url, string $type = 'favicon'): array
    {
        $config = self::TYPE_CONFIG[$type] ?? self::TYPE_CONFIG['favicon'];

        $this->validateUrl($url);

        $currentUrl = $url;
        $redirectCount = 0;

        while (true) {
            $this->validateUrl($currentUrl);

            try {
                $response = $this->httpClient->request('GET', $currentUrl, [
                    'timeout' => $config['timeout'],
                    'connect_timeout' => $config['timeout'],
                    'allow_redirects' => false,
                    'headers' => [
                        'User-Agent' => 'SinclearAPI/2.0 ImageProxy',
                        'Accept' => 'image/*',
                    ],
                ]);
            } catch (ConnectException) {
                throw new \RuntimeException('external_server_error', 502);
            } catch (RequestException $e) {
                $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
                if ($statusCode === 404) {
                    throw new \RuntimeException('not_found', 404);
                }
                throw new \RuntimeException('external_server_error', 502);
            } catch (GuzzleException) {
                throw new \RuntimeException('external_server_error', 502);
            }

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 300 && $statusCode < 400) {
                $redirectCount++;
                if ($redirectCount > self::MAX_REDIRECTS) {
                    throw new \RuntimeException('too_many_redirects', 502);
                }

                $location = $response->getHeaderLine('Location');
                if ($location === '') {
                    throw new \RuntimeException('external_server_error', 502);
                }

                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                continue;
            }

            break;
        }

        if ($statusCode === 404) {
            throw new \RuntimeException('not_found', 404);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('external_server_error', 502);
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $contentType = explode(';', $contentType)[0];
        $contentType = strtolower(trim($contentType));

        if (!str_starts_with($contentType, 'image/')) {
            throw new \RuntimeException('not_an_image', 400);
        }

        $body = $response->getBody();
        $data = '';
        $maxSize = $config['maxSize'];

        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === null || $chunk === '') {
                break;
            }
            $data .= $chunk;
            if (strlen($data) > $maxSize) {
                throw new \RuntimeException('response_too_large', 502);
            }
        }

        return [
            'body' => $data,
            'contentType' => $contentType,
        ];
    }

    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException('invalid_url');
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new \InvalidArgumentException('invalid_scheme');
        }

        $this->ensureNotPrivateIp($parsed['host']);
    }

    private function ensureNotPrivateIp(string $host): void
    {
        $ip = @gethostbyname($host);
        if ($ip === $host) {
            return;
        }

        if ($this->isPrivateIp($ip)) {
            throw new \InvalidArgumentException('private_ip_not_allowed');
        }
    }

    private function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        if ($ip === '::1' || $ip === '::0') {
            return true;
        }

        foreach (self::PRIVATE_IPV6_PREFIXES as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('/^https?:\/\//i', $location)) {
            return $location;
        }

        $baseParsed = parse_url($baseUrl);
        if ($baseParsed === false) {
            throw new \RuntimeException('external_server_error', 502);
        }

        $scheme = $baseParsed['scheme'];
        $host = $baseParsed['host'];
        $port = isset($baseParsed['port']) ? ':' . $baseParsed['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $basePath = $baseParsed['path'] ?? '/';
        $baseDir = substr($basePath, 0, strrpos($basePath, '/') + 1);

        return $scheme . '://' . $host . $port . $baseDir . ltrim($location, '/');
    }
}
