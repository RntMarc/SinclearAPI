<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games;

/**
 * Vertrag fuer eine Spiele-Integration. Jede Integration kuemmert sich um
 * Cache-Aufbau, Cache-Lesen, Cache-Metadaten und die Profilaufloesung ihres
 * Spiels. Controller, Routen und das GameAccount-Schema bleiben unabhaengig
 * vom konkreten Spiel.
 */
interface GameStatsIntegrationInterface
{
    /**
     * Aktualisiert den Cache fuer alle uebergebenen Accounts.
     *
     * @param list<array<string, mixed>> $accounts GameAccount-Datensaetze
     */
    public function refreshAll(array $accounts): void;

    /**
     * Liest die normalisierten Statistiken eines Accounts aus dem Cache.
     *
     * @return array<string, mixed>|null
     */
    public function getCachedStats(string $accountId): ?array;

    /**
     * Zeitpunkt der letzten und naechsten Cache-Erneuerung.
     *
     * @return array{lastRefreshedAt: string|null, nextRefreshAt: string|null, intervalSeconds: int}
     */
    public function getCacheMeta(): array;

    /**
     * Loest das oeffentliche Profil (Anzeigename/Avatar) eines Accounts auf.
     *
     * @return array{displayName: string|null, avatarUrl: string|null, profile: array<string, mixed>}
     */
    public function resolveProfile(string $accountId): array;
}
