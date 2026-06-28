<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;

final readonly class AppController
{
    public function __construct(
        private string $downloadsBaseUrl,
    ) {}

    public function version(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $versionFile = __DIR__ . '/../../../app_version.json';

        if (!file_exists($versionFile)) {
            return ResponseFactory::json(
                ['error' => 'version_info_unavailable'],
                503,
                $response,
            );
        }

        $data = json_decode(file_get_contents($versionFile), true);

        if (!is_array($data) || empty($data['version']) || empty($data['versionCode'])) {
            return ResponseFactory::json(
                ['error' => 'version_info_invalid'],
                503,
                $response,
            );
        }

        $result = [
            'version' => $data['version'],
            'versionCode' => (int) $data['versionCode'],
            'downloadUrl' => $this->downloadsBaseUrl . '/' . ($data['apkFile'] ?? 'sinclear-latest.apk'),
            'changelog' => $data['changelog'] ?? [],
        ];

        return ResponseFactory::json($result, 200, $response);
    }
}
