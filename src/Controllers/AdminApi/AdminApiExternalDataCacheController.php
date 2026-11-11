<?php

namespace Sinclear\Api\Controllers\AdminApi;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\ExternalDataCacheRepository;
use Sinclear\Api\Services\AdminAuditService;

final readonly class AdminApiExternalDataCacheController
{
    use AdminApiConcern;

    public function __construct(
        private ExternalDataCacheRepository $externalDataCacheRepo,
        private AdminAuditService $auditService,
        private PDO $pdo,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $params = $request->getQueryParams();
        $dataType = !empty($params['data_type']) ? $params['data_type'] : null;

        return ResponseFactory::json(
            ['entries' => $this->externalDataCacheRepo->findAll($dataType)],
            200,
            $response,
        );
    }

    public function deleteEntry(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $id = $args['id'] ?? '';
        if ($id === '') {
            return ResponseFactory::json(['error' => 'id_required'], 400, $response);
        }

        $stmt = $this->pdo->prepare('DELETE FROM ExternalDataCache WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            return ResponseFactory::json(['error' => 'not_found'], 404, $response);
        }

        $this->auditService->record($user, 'delete', 'external_data_cache', $id, $request);

        return ResponseFactory::json(['message' => 'deleted'], 200, $response);
    }

    public function clear(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $params = $request->getQueryParams();
        $dataType = !empty($params['data_type']) ? $params['data_type'] : null;

        if ($dataType !== null) {
            $count = $this->externalDataCacheRepo->deleteByType($dataType);
            $this->auditService->record($user, 'clear', 'external_data_cache', $dataType, $request);
        } else {
            $count = $this->externalDataCacheRepo->deleteAll();
            $this->auditService->record($user, 'clear', 'external_data_cache', null, $request);
        }

        return ResponseFactory::json(['message' => 'cleared', 'deleted' => $count], 200, $response);
    }
}
