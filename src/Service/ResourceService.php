<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use Sinclear\Api\Domain\Pagination;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Repository\AbstractRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Security\Policy\PolicyInterface;

/**
 * Generic CRUD service delegating authorization to policies.
 */
final class ResourceService
{
    /** @var callable|null */
    private $dtoMapper;

    public function __construct(
        private readonly AbstractRepository $repository,
        private readonly PolicyInterface $policy,
        ?callable $dtoMapper = null
    ) {
        $this->dtoMapper = $dtoMapper;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|float>}
     */
    public function list(AuthenticatedUser $user, Pagination $pagination, array $queryFilters = []): array
    {
        if (!$this->policy->canList($user)) {
            throw HttpException::forbidden();
        }

        $filters = array_merge($this->policy->listFilters($user), $queryFilters);
        $total = $this->repository->count($filters);
        $rows = $this->repository->paginate($pagination, $filters);

        return [
            'data' => array_map($this->mapRow(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(AuthenticatedUser $user, string $id): array
    {
        $row = $this->repository->findById($id);
        if ($row === null) {
            throw HttpException::notFound();
        }
        if (!$this->policy->canView($user, $row)) {
            throw HttpException::forbidden();
        }
        return $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(AuthenticatedUser $user, array $data): array
    {
        if (!$this->policy->canCreate($user)) {
            throw HttpException::forbidden();
        }
        $row = $this->repository->create($data);
        return $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(AuthenticatedUser $user, string $id, array $data): array
    {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw HttpException::notFound();
        }
        if (!$this->policy->canUpdate($user, $existing)) {
            throw HttpException::forbidden();
        }
        $row = $this->repository->update($id, $data);
        return $this->mapRow($row ?? $existing);
    }

    public function delete(AuthenticatedUser $user, string $id): void
    {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw HttpException::notFound();
        }
        if (!$this->policy->canDelete($user, $existing)) {
            throw HttpException::forbidden();
        }
        $this->repository->delete($id);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        if ($this->dtoMapper !== null) {
            return ($this->dtoMapper)($row);
        }
        return $row;
    }
}
