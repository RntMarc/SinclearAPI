<?php

declare(strict_types=1);

namespace Sinclear\Api\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final readonly class GameAccountRepository
{
    private const string COLUMNS = 'id, userId, game, accountId, displayName, avatarUrl, profile, lastSyncedAt, createdAt, updatedAt';

    public function __construct(
        private PDO $pdo,
    ) {}

    /** @return array<string, mixed>|null */
    public function findByUserIdAndGame(string $userId, string $game): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM GameAccount WHERE userId = ? AND game = ? LIMIT 1'
        );
        $stmt->execute([$userId, $game]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function listByUserId(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM GameAccount WHERE userId = ? ORDER BY game ASC'
        );
        $stmt->execute([$userId]);

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return list<array<string, mixed>> */
    public function listByGame(string $game): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM GameAccount WHERE game = ? ORDER BY createdAt ASC'
        );
        $stmt->execute([$game]);

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Accounts fuer eine Batch-Abfrage. Es werden nur Nutzer zurueckgegeben,
     * die tatsaechlich noch in der User-Tabelle existieren.
     *
     * @param list<string> $userIds
     * @return list<array<string, mixed>>
     */
    public function listByGameAndUserIds(string $game, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT ga.id, ga.userId, ga.game, ga.accountId, ga.displayName, ga.avatarUrl,
                    ga.profile, ga.lastSyncedAt, ga.createdAt, ga.updatedAt
             FROM GameAccount ga
             INNER JOIN User u ON u.id = ga.userId
             WHERE ga.game = ? AND ga.userId IN (' . $placeholders . ')
             ORDER BY ga.userId ASC'
        );
        $stmt->execute([$game, ...$userIds]);

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Legt einen Account an oder ersetzt die Verknuepfung des Nutzers fuer das
     * jeweilige Spiel (UNIQUE userId + game).
     *
     * @param array<string, mixed>|null $profile
     */
    public function upsert(
        string $userId,
        string $game,
        string $accountId,
        ?string $displayName,
        ?string $avatarUrl,
        ?array $profile,
    ): string {
        $existing = $this->findByUserIdAndGame($userId, $game);
        $profileJson = $profile !== null
            ? json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : null;

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE GameAccount
                 SET accountId = ?, displayName = ?, avatarUrl = ?, profile = ?, lastSyncedAt = NULL, updatedAt = NOW(3)
                 WHERE id = ?'
            );
            $stmt->execute([$accountId, $displayName, $avatarUrl, $profileJson, $existing['id']]);

            return (string) $existing['id'];
        }

        $id = Uuid::uuid7()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO GameAccount
                (id, userId, game, accountId, displayName, avatarUrl, profile, lastSyncedAt, createdAt, updatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NOW(3), NOW(3))'
        );
        $stmt->execute([$id, $userId, $game, $accountId, $displayName, $avatarUrl, $profileJson]);

        return $id;
    }

    public function deleteByUserIdAndGame(string $userId, string $game): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM GameAccount WHERE userId = ? AND game = ?');
        $stmt->execute([$userId, $game]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Markiert die angegebenen Accounts als zuletzt synchronisiert.
     *
     * @param list<string> $accountIds
     */
    public function markSynced(string $game, array $accountIds): void
    {
        if ($accountIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $this->pdo->prepare(
            'UPDATE GameAccount SET lastSyncedAt = NOW(3), updatedAt = NOW(3)
             WHERE game = ? AND accountId IN (' . $placeholders . ')'
        );
        $stmt->execute([$game, ...$accountIds]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        if (isset($row['profile']) && is_string($row['profile']) && $row['profile'] !== '') {
            $decoded = json_decode($row['profile'], true);
            $row['profile'] = is_array($decoded) ? $decoded : null;
        } elseif (array_key_exists('profile', $row)) {
            $row['profile'] = null;
        }

        return $row;
    }
}
