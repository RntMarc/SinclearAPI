<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Autorisierungsregeln fuer Reisen, Reise-Events und Standalone-Events.
 *
 * Rollen je Relation: 'leader' (Reiseleiter/Veranstalter) oder
 * 'participant'. Reise-Events erben die Bearbeitungsrechte von der
 * zugehoerigen Reise; Standalone-Events besitzen eigene Rollen in der
 * EventRelation-Tabelle.
 */
final readonly class TravelPolicy
{
    public const ROLE_LEADER = 'leader';
    public const ROLE_PARTICIPANT = 'participant';

    /**
     * Lesen darf jeder Teilnehmer (bzw. Admin).
     */
    public function canViewTrip(AuthenticatedUser $user, bool $isParticipant): bool
    {
        return $user->isAdmin || $isParticipant;
    }

    /**
     * Bearbeiten/Loeschen einer Reise nur fuer Reiseleiter (oder Admin).
     */
    public function canManageTrip(AuthenticatedUser $user, ?string $tripRole): bool
    {
        return $user->isAdmin || $tripRole === self::ROLE_LEADER;
    }

    /**
     * Bearbeiten/Loeschen eines Events.
     *
     * Reise-Events erben die Rechte von der Reise ($tripRole), Standalone-
     * Events nutzen die eigene Eventrolle ($eventRole).
     */
    public function canManageEvent(
        AuthenticatedUser $user,
        bool $isStandalone,
        ?string $tripRole,
        ?string $eventRole,
    ): bool {
        if ($user->isAdmin) {
            return true;
        }

        return $isStandalone
            ? $eventRole === self::ROLE_LEADER
            : $tripRole === self::ROLE_LEADER;
    }

    /**
     * Rollenvergabe in einer Reise nur fuer Reiseleiter (oder Admin).
     */
    public function canAssignTripRole(AuthenticatedUser $user, ?string $tripRole): bool
    {
        return $this->canManageTrip($user, $tripRole);
    }

    /**
     * Rollenvergabe bei einem Standalone-Event nur fuer dessen Veranstalter
     * (oder Admin).
     */
    public function canAssignEventRole(AuthenticatedUser $user, ?string $eventRole): bool
    {
        return $user->isAdmin || $eventRole === self::ROLE_LEADER;
    }

    /**
     * Umhaengen eines Events zwischen Reise und Standalone erfordert
     * Leader-Rechte an Quelle UND Ziel (Admin immer).
     */
    public function canConvert(AuthenticatedUser $user, ?string $sourceRole, ?string $destRole): bool
    {
        return $user->isAdmin
            || ($sourceRole === self::ROLE_LEADER && $destRole === self::ROLE_LEADER);
    }

    /**
     * Es muss immer mindestens ein Leader bestehen bleiben. Das Entfernen
     * oder Degradieren des letzten Leaders wird fuer alle (auch Admin)
     * blockiert, um die Invariante zu wahren.
     */
    public function canRemoveLeader(bool $isLastLeader): bool
    {
        return !$isLastLeader;
    }

    /**
     * Unterkuenfte anlegen/verknuepfen duerfen alle Teilnehmer der Reise
     * (Reiseleiter und Mitreisende), damit sich Mitreisende selbst versorgen
     * koennen. Admins immer.
     */
    public function canCreateAccommodation(AuthenticatedUser $user, bool $isParticipant): bool
    {
        return $user->isAdmin || $isParticipant;
    }

    /**
     * Einem anderen Teilnehmer eine Unterkunft zuweisen duerfen nur
     * Reiseleiter (oder Admin).
     */
    public function canAssignAccommodationToOther(AuthenticatedUser $user, ?string $tripRole): bool
    {
        return $this->canManageTrip($user, $tripRole);
    }

    /**
     * Sich selbst eine Unterkunft zuweisen darf jeder Teilnehmer.
     */
    public function canAssignOwnAccommodation(AuthenticatedUser $user, bool $isParticipant): bool
    {
        return $user->isAdmin || $isParticipant;
    }

    /**
     * Endgueltiges Loeschen einer Katalog-Unterkunft nur fuer den Ersteller
     * (oder Admin); das Loesen von einer Reise bleibt Reiseleitern
     * vorbehalten.
     */
    public function canDeleteAccommodationGlobally(AuthenticatedUser $user, ?string $createdBy): bool
    {
        return $user->isAdmin || ($createdBy !== null && $createdBy === $user->id);
    }

    /**
     * Details einer Unterkunft bearbeiten darf der Reiseleiter der
     * verknuepften Reise, der Ersteller der Katalog-Unterkunft (oder Admin).
     */
    public function canEditAccommodation(
        AuthenticatedUser $user,
        ?string $tripRole,
        ?string $createdBy,
    ): bool {
        return $this->canManageTrip($user, $tripRole)
            || $this->canDeleteAccommodationGlobally($user, $createdBy);
    }
}
