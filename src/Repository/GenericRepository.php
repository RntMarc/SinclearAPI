<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

/**
 * Configurable repository for any database table.
 */
final class GenericRepository extends AbstractRepository
{
    /**
     * @param list<string> $columnList
     */
    public function __construct(
        \PDO $pdo,
        private readonly string $tableName,
        private readonly array $columnList,
        private readonly string $pk = 'id'
    ) {
        parent::__construct($pdo);
    }

    protected function table(): string
    {
        return $this->tableName;
    }

    protected function columns(): array
    {
        return $this->columnList;
    }

    protected function primaryKey(): string
    {
        return $this->pk;
    }
}
