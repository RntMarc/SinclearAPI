<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;

final readonly class AppController
{
    public function __construct(
        private string $downloadsBaseUrl,
        private LoggerInterface $logger,
    ) {}

    public function version(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $versionFile = dirname(__DIR__, 2) . '/app_version.json';

        $this->logger->info('App version check', [
            'resolved_path' => $versionFile,
            'file_exists' => file_exists($versionFile),
            'cwd' => getcwd(),
        ]);

        if (!file_exists($versionFile)) {
            $this->logger->warning('app_version.json not found', [
                'path' => $versionFile,
            ]);
            return ResponseFactory::json(
                [
                    'error' => 'version_info_unavailable',
                    'debug' => [
                        'expected_path' => $versionFile,
                        'cwd' => getcwd(),
                    ],
                ],
                503,
                $response,
            );
        }

        $raw = file_get_contents($versionFile);

        if ($raw === false) {
            $this->logger->error('Failed to read app_version.json', [
                'path' => $versionFile,
            ]);
            return ResponseFactory::json(
                ['error' => 'version_file_unreadable'],
                503,
                $response,
            );
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || empty($data['version']) || empty($data['versionCode'])) {
            $this->logger->warning('app_version.json has invalid structure', [
                'path' => $versionFile,
                'content_preview' => substr($raw, 0, 200),
            ]);
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
