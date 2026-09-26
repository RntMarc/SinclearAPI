<?php

declare(strict_types=1);

namespace Sinclear\Api\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Zentrale Konvertierung und Validierung zeitpunktbezogener Felder.
 *
 * Getaktete Eintraege werden als RFC 3339 mit Offset ausgetauscht und intern
 * als UTC-Instant gespeichert. Die IANA-Zeitzone eines Eintrags bleibt
 * zusaetzlich erhalten, damit die gemeinte Wandzeit (Anzeige, Bearbeitung,
 * Sommer-/Winterzeit) korrekt bleibt. Ganztägige Eintraege sind zivile Tage
 * (`Y-m-d`) und werden nicht umgerechnet.
 */
final class DateTimeValue
{
    public const string DEFAULT_TIMEZONE = 'Europe/Berlin';

    /**
     * Prueft einen IANA-Zeitzonennamen und liefert die Zone zurueck.
     *
     * @throws InvalidArgumentException bei unbekannter Zeitzone
     */
    public static function assertTimeZone(string $timezone): DateTimeZone
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            throw new InvalidArgumentException('Invalid timezone');
        }
        if (strcasecmp($timezone, 'UTC') === 0) {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid timezone');
        }
    }

    /**
     * Liefert den kanonischen IANA-Namen; leer/null faellt auf den Standard
     * zurueck.
     */
    public static function normalizeTimeZone(?string $timezone): string
    {
        if ($timezone === null || trim($timezone) === '') {
            return self::DEFAULT_TIMEZONE;
        }

        return self::assertTimeZone($timezone)->getName();
    }

    /**
     * Parst einen RFC-3339-Zeitpunkt (Offset `Z` oder `+HH:MM` erforderlich)
     * und liefert ihn als UTC-Instant.
     *
     * @throws InvalidArgumentException bei fehlendem Offset oder ungueltigem Datum
     */
    public static function parseInstant(string $value): DateTimeImmutable
    {
        $value = trim($value);
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})'
            . '(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/i';

        if (!preg_match($pattern, $value, $matches)) {
            throw new InvalidArgumentException('Invalid datetime');
        }

        try {
            $instant = new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid datetime');
        }

        // Kalenderueberlaeufe abweisen (z. B. 2026-02-30 -> 2026-03-02).
        $normalized = sprintf(
            '%s-%s-%sT%s:%s:%s',
            $matches[1],
            $matches[2],
            $matches[3],
            $matches[4],
            $matches[5],
            $matches[6],
        );
        if ($instant->format('Y-m-d\TH:i:s') !== $normalized) {
            throw new InvalidArgumentException('Invalid datetime');
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Formatiert einen UTC-Instant als RFC 3339 in der Zielzone.
     * Volle Stunden UTC werden als `Z` ausgegeben (Branchenkonvention).
     */
    public static function formatInstant(DateTimeImmutable $instant, DateTimeZone $timezone): string
    {
        $formatted = $instant->setTimezone($timezone)->format('Y-m-d\TH:i:sP');
        if (str_ends_with($formatted, '+00:00')) {
            return substr($formatted, 0, -6) . 'Z';
        }

        return $formatted;
    }

    /**
     * Parst einen zivilen Tag `Y-m-d`.
     *
     * @throws InvalidArgumentException bei ungueltigem Datum
     */
    public static function parseDate(string $value): DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid date');
        }

        return $date;
    }

    public static function formatDate(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * Beginn eines zivilen Tages (`Y-m-d`) in der angegebenen Zone, als
     * UTC-DATETIME. Dient als untere Grenze fuer Zeitraumfilter.
     */
    public static function civilDayStartUtc(string $date, string $timezone): string
    {
        $start = new DateTimeImmutable(
            self::parseDate($date)->format('Y-m-d') . ' 00:00:00',
            self::assertTimeZone($timezone),
        );

        return $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Exklusive obere Grenze eines zivilen Tages (`Y-m-d`) in der angegebenen
     * Zone, als UTC-DATETIME (Folgetag 00:00). `+1 day` rechnet auf der
     * Wandzeit und ist damit sommer-/winterzeitfest.
     */
    public static function civilDayEndUtcExclusive(string $date, string $timezone): string
    {
        $end = (new DateTimeImmutable(
            self::parseDate($date)->format('Y-m-d') . ' 00:00:00',
            self::assertTimeZone($timezone),
        ))->modify('+1 day');

        return $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Serialisiert einen Instant fuer DATETIME-Spalten (UTC, Sekundengenau). */
    public static function toDatabase(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Liest einen DATETIME-Wert als UTC-Instant; null/leer bleibt null. */
    public static function fromDatabase(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normalisiert die Zeitfelder eines Datensatzes fuer die API-Ausgabe:
     * ganztägig -> `startDate`/`endDate`, getaktet -> `startAt`/`endAt`
     * (RFC 3339 in der Eintragszeitzone), jeweils inkl. `allDay` und
     * `timezone`. Die jeweils ungenutzte Feldgruppe wird entfernt.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeTimingForOutput(array $row): array
    {
        $allDay = (int) ($row['allDay'] ?? 0) === 1;
        $timezoneName = self::normalizeTimeZone(
            isset($row['timezone']) ? (string) $row['timezone'] : null,
        );
        $timezone = self::assertTimeZone($timezoneName);

        $row['allDay'] = $allDay;
        $row['timezone'] = $timezoneName;

        if ($allDay) {
            unset($row['startAt'], $row['endAt']);
            $row['startDate'] = self::formatStoredDate($row['startDate'] ?? null);
            $row['endDate'] = self::formatStoredDate($row['endDate'] ?? null);
        } else {
            unset($row['startDate'], $row['endDate']);
            $row['startAt'] = self::formatStoredInstant($row['startAt'] ?? null, $timezone);
            $row['endAt'] = self::formatStoredInstant($row['endAt'] ?? null, $timezone);
        }

        return $row;
    }

    private static function formatStoredDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return self::formatDate(self::parseDate($value));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function formatStoredInstant(mixed $value, DateTimeZone $timezone): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $instant = self::fromDatabase($value);
        if ($instant === null) {
            return null;
        }

        return self::formatInstant($instant, $timezone);
    }
}
