<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Sinclear\Api\Repository\ArcadeScoreRepository;
use Sinclear\Api\Repository\UserRepository;

final readonly class ArcadeScoreService
{
    public function __construct(
        private ArcadeScoreRepository $repo,
        private ArcadeGameService $gameService,
        private UserRepository $userRepo,
    ) {}

    /**
     * @return array{score: array<string, mixed>, bestScore: int, isPersonalBest: bool}
     */
    public function submit(string $userId, string $gameId, mixed $score, mixed $meta): array
    {
        $game = $this->gameService->findById($gameId);
        if ($game === null) {
            throw new InvalidArgumentException('unknown_game');
        }

        $scoreValue = self::validateScore($score);
        $metaValue = self::normalizeMeta($meta);

        $id = $this->repo->create($userId, $gameId, $scoreValue, $metaValue);
        $created = $this->repo->findById($id);
        if ($created === null) {
            throw new \RuntimeException('arcade_score_persist_failed');
        }

        $best = $this->repo->findBestForUser(
            $gameId,
            $userId,
            (string) $game['scoreDirection'],
        );

        return [
            'score' => $this->formatScore($created),
            'bestScore' => $best !== null ? (int) $best['score'] : $scoreValue,
            'isPersonalBest' => $best !== null && (string) $best['id'] === $id,
        ];
    }

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{page: int, limit: int, total: int, totalPages: int, scoreDirection: string},
     *     me: array<string, mixed>|null
     * }
     */
    public function leaderboard(
        string $slug,
        int $page,
        int $limit,
        ?string $currentUserId,
    ): array {
        $game = $this->gameService->findActiveBySlug($slug);
        if ($game === null) {
            throw new InvalidArgumentException('unknown_game');
        }

        $gameId = (string) $game['id'];
        $direction = (string) $game['scoreDirection'];
        $offset = ($page - 1) * $limit;

        $total = $this->repo->countLeaderboard($gameId);
        $rows = $this->repo->listLeaderboard($gameId, $direction, $limit, $offset);

        $data = [];
        foreach ($rows as $index => $row) {
            $data[] = $this->formatLeaderboardEntry($row, $offset + $index + 1);
        }

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
                'scoreDirection' => $direction,
            ],
            'me' => $this->ownLeaderboardEntry($gameId, $direction, $currentUserId),
        ];
    }

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{page: int, limit: int, total: int, totalPages: int},
     *     bestScore: int,
     *     scoreDirection: string,
     *     userId: string
     * }
     */
    public function history(string $slug, string $userId, int $page, int $limit): array
    {
        $game = $this->gameService->findActiveBySlug($slug);
        if ($game === null) {
            throw new InvalidArgumentException('unknown_game');
        }

        if ($this->userRepo->findById($userId) === null) {
            throw new InvalidArgumentException('user_not_found');
        }

        $gameId = (string) $game['id'];
        $direction = (string) $game['scoreDirection'];
        $offset = ($page - 1) * $limit;

        $result = $this->repo->listByGameAndUser($gameId, $userId, $limit, $offset);
        if ($result['total'] === 0) {
            throw new InvalidArgumentException('score_not_found');
        }

        $best = $this->repo->findBestForUser($gameId, $userId, $direction);

        return [
            'data' => array_map($this->formatScore(...), $result['data']),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $result['total'],
                'totalPages' => (int) ceil($result['total'] / $limit),
            ],
            'bestScore' => $best !== null ? (int) $best['score'] : 0,
            'scoreDirection' => $direction,
            'userId' => $userId,
        ];
    }

    /** @return array<string, mixed>|null */
    private function ownLeaderboardEntry(string $gameId, string $direction, ?string $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $best = $this->repo->findBestForUser($gameId, $userId, $direction);
        if ($best === null) {
            return null;
        }

        $user = $this->userRepo->findById($userId);
        if ($user === null) {
            return null;
        }

        $rank = $this->repo->countAhead(
            $gameId,
            $direction,
            (int) $best['score'],
            (string) $best['createdAt'],
            $userId,
        ) + 1;

        $best['displayName'] = $user['displayName'] ?? null;
        $best['image'] = $user['image'] ?? null;

        return $this->formatLeaderboardEntry($best, $rank);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatLeaderboardEntry(array $row, int $rank): array
    {
        return [
            'rank' => $rank,
            'userId' => $row['userId'],
            'displayName' => $row['displayName'] ?? null,
            'image' => $row['image'] ?? null,
            'score' => (int) $row['score'],
            'meta' => $row['meta'] ?? null,
            'achievedAt' => self::formatTimestamp($row['createdAt'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $score
     * @return array<string, mixed>
     */
    private function formatScore(array $score): array
    {
        return [
            'id' => $score['id'],
            'userId' => $score['userId'],
            'gameId' => $score['gameId'],
            'score' => (int) $score['score'],
            'meta' => $score['meta'] ?? null,
            'createdAt' => self::formatTimestamp($score['createdAt'] ?? null),
        ];
    }

    public static function validateScore(mixed $score): int
    {
        if (!is_int($score)) {
            throw new InvalidArgumentException('invalid_score');
        }

        return $score;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function normalizeMeta(mixed $meta): ?array
    {
        if ($meta === null) {
            return null;
        }

        if (!is_array($meta)) {
            throw new InvalidArgumentException('invalid_meta');
        }

        if ($meta === []) {
            return null;
        }

        if (array_is_list($meta)) {
            throw new InvalidArgumentException('invalid_meta');
        }

        try {
            json_encode($meta, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('invalid_meta');
        }

        return $meta;
    }

    private static function formatTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
