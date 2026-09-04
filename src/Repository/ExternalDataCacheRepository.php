<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;

final readonly class ExternalDataCacheRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function find(string $dataType, string $locationKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ExternalDataCache WHERE data_type = ? AND location_key = ? AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$dataType, $locationKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['payload'] = json_decode($row['payload'], true);
        return $row;
    }

    public function save(string $dataType, string $locationKey, string $source, array $payload, int $ttlSeconds): void
    {
        $now = date('Y-m-d H:i:s.') . substr((string) microtime(true), strpos((string) microtime(true), '.') + 1, 3);
        $expiresAt = date('Y-m-d H:i:s.', (int) microtime(true) + $ttlSeconds) . substr((string) microtime(true), strpos((string) microtime(true), '.') + 1, 3);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ExternalDataCache (id, data_type, location_key, source, payload, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                source = VALUES(source),
                payload = VALUES(payload),
                expires_at = VALUES(expires_at),
                updated_at = VALUES(updated_at)'
        );

        $id = $this->generateId();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt->execute([$id, $dataType, $locationKey, $source, $payloadJson, $expiresAt, $now, $now]);
    }

    public function delete(string $dataType, string $locationKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ExternalDataCache WHERE data_type = ? AND location_key = ?');
        $stmt->execute([$dataType, $locationKey]);
    }

    public function deleteByType(string $dataType): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM ExternalDataCache WHERE data_type = ?');
        $stmt->execute([$dataType]);
        return $stmt->rowCount();
    }

    public function deleteAll(): int
    {
        $stmt = $this->pdo->query('DELETE FROM ExternalDataCache');
        return $stmt->rowCount();
    }

    public function findAll(?string $dataType = null): array
    {
        if ($dataType !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, data_type, location_key, source, expires_at, created_at, updated_at,
                        LENGTH(payload) as payload_size
                 FROM ExternalDataCache WHERE data_type = ? ORDER BY expires_at DESC'
            );
            $stmt->execute([$dataType]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT id, data_type, location_key, source, expires_at, created_at, updated_at,
                        LENGTH(payload) as payload_size
                 FROM ExternalDataCache ORDER BY data_type, expires_at DESC'
            );
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats(): array
    {
        $stmt = $this->pdo->query(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN expires_at < NOW() THEN 1 ELSE 0 END) as expired,
                data_type,
                COUNT(*) as count
             FROM ExternalDataCache
             GROUP BY data_type'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0;
        $expired = 0;
        $byType = [];

        foreach ($rows as $row) {
            $total += (int) $row['count'];
            $expired += (int) $row['expired'];
            $byType[$row['data_type']] = [
                'count' => (int) $row['count'],
                'expired' => (int) $row['expired'],
            ];
        }

        return [
            'total' => $total,
            'expired' => $expired,
            'by_type' => $byType,
        ];
    }

    public function cleanupExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM ExternalDataCache WHERE expires_at < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function generateId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
