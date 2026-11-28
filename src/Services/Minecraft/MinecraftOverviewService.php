<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Minecraft;

use DateTimeImmutable;
use DateTimeZone;
use Sinclear\Api\Repository\MinecraftOverviewRepository;

/**
 * Baut die aggregierte Quellen-Uebersicht fuer `GET /minecraft/sources`.
 *
 * Die Daten stammen aus `MinecraftStatCurrent`; jeder Spieler mit mindestens
 * einem erfassten Zaehler zaehlt mit, unabhaengig von einer Beyond-Verknuepfung.
 * Summen (`totals`) enthalten immer alle Zaehler, die Rangliste wird nach dem
 * gewaehlten Zaehler sortiert und optional per `limit` getrimmt.
 */
final readonly class MinecraftOverviewService
{
    public function __construct(
        private MinecraftOverviewRepository $repository,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array{sourceCount: int, serverCount: int}}
     */
    public function overview(MinecraftStatsQuery $query): array
    {
        $sources = $this->repository->listSources($query->serverId);
        $totalsBySource = $this->groupTotals($this->repository->getTotalsBySource($query->serverId));
        $playerCounts = $this->repository->getPlayerCountsBySource($query->serverId);
        $serverPlayerCounts = $this->repository->getPlayerCountsByServer($query->serverId);

        $sourceRankings = $this->groupBy(
            $this->repository->getRankingBySource($query->category, $query->stat, $query->serverId),
            'sourceId',
        );
        $serverRankings = $this->groupBy(
            $this->repository->getRankingByServer($query->category, $query->stat, $query->serverId),
            'serverId',
        );

        $sourcePlayerStats = [];
        $serverPlayerStats = [];
        if ($query->includeStats) {
            $sourceIds = [];
            foreach ($sources as $source) {
                $sourceIds[] = $source['sourceId'];
            }

            $sourcePlayerStats = $this->indexSourceStats(
                $this->repository->getSourcePlayerStats(
                    $sourceIds,
                    $this->rankedUuids($sourceRankings, $query->limit),
                ),
            );
            $serverPlayerStats = $this->indexServerStats(
                $this->repository->getServerPlayerStats(
                    $sourceIds,
                    $this->rankedUuids($serverRankings, $query->limit),
                ),
            );
        }

        $sourceData = [];
        foreach ($sources as $source) {
            $sourceId = $source['sourceId'];
            $sourceData[] = [
                'sourceId' => $sourceId,
                'serverId' => $source['serverId'],
                'world' => $source['world'],
                'lastCollectedAt' => self::isoUtc($source['lastCollectedAt']),
                'playerCount' => $playerCounts[$sourceId] ?? 0,
                'totals' => (object) ($totalsBySource[$sourceId] ?? []),
                'ranking' => $this->formatRanking(
                    $sourceRankings[$sourceId] ?? [],
                    $query->limit,
                    $query->includeStats ? ($sourcePlayerStats[$sourceId] ?? []) : null,
                ),
            ];
        }

        $serverData = $this->buildServers(
            $sourceData,
            $totalsBySource,
            $serverPlayerCounts,
            $serverRankings,
            $query,
            $serverPlayerStats,
        );

        return [
            'data' => [
                'scope' => $query->serverId !== null ? 'server' : 'global',
                'serverId' => $query->serverId,
                'rankedBy' => [
                    'category' => $query->category,
                    'stat' => $query->stat,
                ],
                'sources' => $sourceData,
                'servers' => $serverData,
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            'meta' => [
                'sourceCount' => count($sourceData),
                'serverCount' => count($serverData),
            ],
        ];
    }

    /**
     * @param list<array{sourceId: int, category: string, stat: string, total: int}> $rows
     * @return array<int, array<string, array<string, int>>>
     */
    private function groupTotals(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[$row['sourceId']][$row['category']][$row['stat']] = $row['total'];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int|string, list<array<string, mixed>>>
     */
    private function groupBy(array $rows, string $key): array
    {
        $result = [];
        foreach ($rows as $row) {
            /** @var int|string $value */
            $value = $row[$key];
            $result[$value][] = $row;
        }

        return $result;
    }

    /**
     * @param array<int|string, list<array<string, mixed>>> $rankings
     * @return list<string>
     */
    private function rankedUuids(array $rankings, ?int $limit): array
    {
        $uuids = [];
        foreach ($rankings as $rows) {
            $rank = 0;
            foreach ($rows as $row) {
                if ($limit !== null && $rank >= $limit) {
                    break;
                }

                $rank++;
                $uuids[(string) $row['playerUuid']] = true;
            }
        }

        return array_keys($uuids);
    }

    /**
     * @param list<array{sourceId: int, playerUuid: string, category: string, stat: string, value: int}> $rows
     * @return array<int, array<string, array<string, array<string, int>>>>
     */
    private function indexSourceStats(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[$row['sourceId']][$row['playerUuid']][$row['category']][$row['stat']] = $row['value'];
        }

        return $result;
    }

    /**
     * @param list<array{serverId: string, playerUuid: string, category: string, stat: string, value: int}> $rows
     * @return array<string, array<string, array<string, array<string, int>>>>
     */
    private function indexServerStats(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[$row['serverId']][$row['playerUuid']][$row['category']][$row['stat']] = $row['value'];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $sourceData
     * @param array<int, array<string, array<string, int>>> $totalsBySource
     * @param array<string, int> $playerCounts
     * @param array<int|string, list<array<string, mixed>>> $rankings
     * @param array<string, array<string, array<string, array<string, int>>>> $playerStats
     * @return list<array<string, mixed>>
     */
    private function buildServers(
        array $sourceData,
        array $totalsBySource,
        array $playerCounts,
        array $rankings,
        MinecraftStatsQuery $query,
        array $playerStats,
    ): array {
        $servers = [];
        foreach ($sourceData as $source) {
            $serverId = (string) $source['serverId'];

            if (!isset($servers[$serverId])) {
                $servers[$serverId] = [
                    'serverId' => $serverId,
                    'worlds' => [],
                    'lastCollectedAt' => null,
                    'playerCount' => $playerCounts[$serverId] ?? 0,
                    'totals' => [],
                    'ranking' => [],
                ];
            }

            $servers[$serverId]['worlds'][] = $source['world'];

            $last = $source['lastCollectedAt'];
            if ($last !== null
                && ($servers[$serverId]['lastCollectedAt'] === null || $last > $servers[$serverId]['lastCollectedAt'])
            ) {
                $servers[$serverId]['lastCollectedAt'] = $last;
            }

            foreach ($totalsBySource[(int) $source['sourceId']] ?? [] as $category => $stats) {
                foreach ($stats as $stat => $value) {
                    $current = $servers[$serverId]['totals'][$category][$stat] ?? 0;
                    $servers[$serverId]['totals'][$category][$stat] = $current + $value;
                }
            }
        }

        ksort($servers);

        $result = [];
        foreach ($servers as $serverId => $server) {
            $server['totals'] = (object) $server['totals'];
            $server['ranking'] = $this->formatRanking(
                $rankings[$serverId] ?? [],
                $query->limit,
                $query->includeStats ? ($playerStats[$serverId] ?? []) : null,
            );
            $result[] = $server;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, array<string, array<string, int>>>|null $statsByPlayer null = `includeStats` aus
     * @return list<array<string, mixed>>
     */
    private function formatRanking(array $rows, ?int $limit, ?array $statsByPlayer): array
    {
        $result = [];
        $rank = 0;
        foreach ($rows as $row) {
            if ($limit !== null && $rank >= $limit) {
                break;
            }

            $rank++;
            $uuid = (string) $row['playerUuid'];

            $entry = [
                'rank' => $rank,
                'uuid' => $uuid,
                'name' => $row['name'] !== null ? (string) $row['name'] : null,
                'linkedUserId' => $row['linkedUserId'] !== null ? (string) $row['linkedUserId'] : null,
                'avatarUrl' => $row['avatarUrl'] !== null ? (string) $row['avatarUrl'] : null,
                'value' => (int) $row['value'],
            ];

            if ($statsByPlayer !== null) {
                $entry['stats'] = (object) ($statsByPlayer[$uuid] ?? []);
            }

            $result[] = $entry;
        }

        return $result;
    }

    private static function isoUtc(?string $value): ?string
    {
        if ($value === null || $value === '') {
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
