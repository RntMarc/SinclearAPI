<?php

declare(strict_types=1);

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Middleware\MinecraftIngestMiddleware;
use Sinclear\Api\Security\Policy\MinecraftIngestPolicy;
use Sinclear\Api\Services\Minecraft\MinecraftIngestService;
use Sinclear\Api\Services\Minecraft\MinecraftSnapshotValidator;
use Sinclear\Api\Services\Minecraft\MinecraftValidationException;

final readonly class MinecraftIngestController
{
    public function __construct(
        private MinecraftSnapshotValidator $validator,
        private MinecraftIngestService $service,
        private MinecraftIngestPolicy $policy,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function ingest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        if (!$this->policy->canIngest($request->getAttribute(MinecraftIngestMiddleware::ATTRIBUTE) === true)) {
            return ResponseFactory::json(['error' => 'unauthorized'], 401, $response);
        }

        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload)) {
            return ResponseFactory::json(['error' => 'invalid_json'], 400, $response);
        }

        try {
            $normalized = $this->validator->validate($payload);
        } catch (MinecraftValidationException $e) {
            $body = ['error' => $e->errorCode];
            if ($e->details !== []) {
                $body['details'] = $e->details;
            }
            return ResponseFactory::json($body, 400, $response);
        }

        try {
            $result = $this->service->ingest($normalized);
        } catch (\Throwable $e) {
            $this->logger->error('[MinecraftIngest] processing failed', ['exception' => $e]);
            return ResponseFactory::json(['error' => 'internal_error'], 500, $response);
        }

        return ResponseFactory::json($result, 200, $response);
    }
}
