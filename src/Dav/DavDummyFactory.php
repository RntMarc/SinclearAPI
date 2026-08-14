<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Erzeugt den Hinweis-Kalendereintrag bzw. den Hinweis-Kontakt fuer Clients,
 * die sich mit einem ungueltigen oder abgelaufenen DAV-Token melden.
 *
 * Das Kalender-Event liegt immer am Tag des Abrufs von 12:00 bis 13:00 UTC.
 * Es ist rein virtuell (wird nie gespeichert) und wird bei jedem Abruf neu
 * fuer den aktuellen Tag berechnet - es entsteht also nie mehr als ein
 * Hinweis-Event.
 */
final readonly class DavDummyFactory
{
    public const string TITLE = 'Abgelaufener oder ungültiger Token';
    public const string DESCRIPTION = 'Der von dir für den Login verwendete DAV-Token ist entweder abgelaufen oder ungültig. Bitte fordere einen neuen Token in den Einstellungen von Sinclear Beyond an oder wende dich bei Problemen an einen Administrator.';

    public function __construct(
        private IcsFactory $icsFactory,
        private VcardFactory $vcardFactory,
    ) {}

    /** @return array<string, mixed> */
    public function calendarObject(): array
    {
        $start = $this->todayNoon();
        $end = $start->modify('+1 hour');

        $event = [
            'id' => 'sinclear-dav-invalid-token',
            'title' => self::TITLE,
            'description' => self::DESCRIPTION,
            'startTime' => $start->format('Y-m-d H:i:s'),
            'endTime' => $end->format('Y-m-d H:i:s'),
            'visibility' => 1,
            'creatorId' => 'sinclear-dav',
            'updatedAt' => $start->format('Y-m-d H:i:s'),
            'createdAt' => $start->format('Y-m-d H:i:s'),
        ];

        return $this->icsFactory->eventToCalendarObject($event);
    }

    /** @return array<string, mixed> */
    public function cardObject(): array
    {
        return $this->vcardFactory->userToCardObject([
            'id' => 'sinclear-dav-invalid-token',
            'displayName' => self::TITLE,
            'note' => self::DESCRIPTION,
            'createdAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    private function todayNoon(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(12, 0, 0);
    }
}
