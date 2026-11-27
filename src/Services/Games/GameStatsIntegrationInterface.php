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
     * Bei Push-basierten Integrationen (z. B. Minecraft) ist das eine No-op;
     * siehe {@see isPushBased()}.
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
     * Loest die Nutzereingabe spielspezifisch auf und liefert die kanonische
     * Account-ID sowie das oeffentliche Profil (Anzeigename/Avatar).
     *
     * @return array{accountId: string, displayName: string|null, avatarUrl: string|null, profile: array<string, mixed>}
     */
    public function resolveProfile(string $input): array;

    /**
     * Ob die Statistiken per Push angeliefert werden (kein Cron-Pull). Bei
     * `true` ueberspringt {@see GameStatsService::refreshAll()} die Integration
     * und `refreshAll()` ist eine No-op.
     */
    public function isPushBased(): bool;
}
