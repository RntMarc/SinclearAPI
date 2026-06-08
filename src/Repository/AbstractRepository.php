<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Domain\Pagination;

/**
 * Base repository with common CRUD operations using prepared statements.
 */
abstract class AbstractRepository
{
    public function __construct(
        protected readonly PDO $pdo
    ) {
    }

    abstract protected function table(): string;

    /**
     * @return list<string>
     */
    abstract protected function columns(): array;

    protected function primaryKey(): string
    {
        return 'id';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $pk = $this->primaryKey();
        $stmt = $this->pdo->prepare(
            sprintf('SELECT * FROM `%s` WHERE `%s` = :id LIMIT 1', $this->table(), $pk)
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(Pagination $pagination, array $filters = []): array
    {
        $where = '';
        $params = [];
        if ($filters !== []) {
            $clauses = [];
            foreach ($filters as $column => $value) {
                $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
                if ($safeColumn === '') {
                    continue;
                }
                $clauses[] = sprintf('`%s` = :f_%s', $safeColumn, $safeColumn);
                $params['f_' . $safeColumn] = $value;
            }
            if ($clauses !== []) {
                $where = ' WHERE ' . implode(' AND ', $clauses);
            }
        }

        $sort = preg_replace('/[^a-zA-Z0-9_]/', '', $pagination->sort) ?: $this->primaryKey();
        $sql = sprintf(
            'SELECT * FROM `%s`%s ORDER BY `%s` %s LIMIT :limit OFFSET :offset',
            $this->table(),
            $where,
            $sort,
            $pagination->order
        );
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $pagination->limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination->offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        $where = '';
        $params = [];
        if ($filters !== []) {
            $clauses = [];
            foreach ($filters as $column => $value) {
                $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
                if ($safeColumn === '') {
                    continue;
                }
                $clauses[] = sprintf('`%s` = :f_%s', $safeColumn, $safeColumn);
                $params['f_' . $safeColumn] = $value;
            }
            if ($clauses !== []) {
                $where = ' WHERE ' . implode(' AND ', $clauses);
            }
        }

        $stmt = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM `%s`%s', $this->table(), $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        if (!isset($data[$this->primaryKey()])) {
            $data[$this->primaryKey()] = Uuid::uuid4()->toString();
        }

        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $this->table(),
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        return $this->findById((string) $data[$this->primaryKey()]) ?? $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data): ?array
    {
        if ($data === []) {
            return $this->findById($id);
        }

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = sprintf('`%s` = :%s', $column, $column);
        }

        $pk = $this->primaryKey();
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `%s` = :pk',
            $this->table(),
            implode(', ', $sets),
            $pk
        );
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':pk', $id);
        $stmt->execute();

        return $this->findById($id);
    }

    public function delete(string $id): bool
    {
        $pk = $this->primaryKey();
        $stmt = $this->pdo->prepare(
            sprintf('DELETE FROM `%s` WHERE `%s` = :id', $this->table(), $pk)
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
