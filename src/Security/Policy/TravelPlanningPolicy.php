<?php

namespace Sinclear\Api\Security\Policy;

use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Autorisierungsregeln fuer Planungsreisen (TravelTrip.state = 'planning').
 *
 * Die Leitung der Planung wird ausschliesslich in TravelPlanMember gefuehrt
 * (Rolle 'leader'/'member') und ist bewusst von TravelRelation getrennt:
 * Wer nur mitplant, darf keinen Zugriff auf operative Reise-Objekte erhalten.
 *
 * Ein "aktives Planungsmitglied" ist jedes Mitglied, dessen Status nicht
 * 'inactive' ist (invited/accepted/declined behalten den Zugriff).
 */
final readonly class TravelPlanningPolicy
{
    public const ROLE_LEADER = 'leader';
    public const ROLE_MEMBER = 'member';

    public const STATUS_INVITED = 'invited';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_INACTIVE = 'inactive';

    /** Alle gueltigen Themen-Keys der drei festen Kernphasen. */
    public const TOPICS = ['participants', 'travel', 'program'];

    /** Alle gueltigen Statuse eines Planungsthemas. */
    public const TOPIC_STATUSES = ['pending', 'in_progress', 'completed', 'skipped'];

    /**
     * Planungsdaten lesen darf jedes aktive Planungsmitglied (oder Admin).
     * Ein nicht eingeladenes/inaktives Konto erhaelt keinen Zugriff.
     */
    public function canViewPlan(AuthenticatedUser $user, ?string $memberStatus): bool
    {
        return $user->isAdmin || $this->isActiveStatus($memberStatus);
    }

    /**
     * Leitungsaktionen (Einladungen, Themen, Festlegen, Aktivieren) nur fuer
     * die Leitung (oder Admin).
     */
    public function canManagePlan(AuthenticatedUser $user, ?string $memberRole): bool
    {
        return $user->isAdmin || $memberRole === self::ROLE_LEADER;
    }

    /**
     * Eigene Rueckmeldungen/Praeferenzen/Interessen bearbeiten darf jedes
     * aktive Planungsmitglied.
     */
    public function canManageOwn(AuthenticatedUser $user, ?string $memberStatus): bool
    {
        return $user->isAdmin || $this->isActiveStatus($memberStatus);
    }

    /**
     * Einen Vorschlag (Terminoption, Unterkunftsoption, Eventvorschlag)
     * bearbeiten/loeschen darf die Leitung oder der Ersteller des Vorschlags.
     */
    public function canManageSuggestion(
        AuthenticatedUser $user,
        ?string $memberRole,
        ?string $proposedBy,
    ): bool {
        if ($this->canManagePlan($user, $memberRole)) {
            return true;
        }

        return $proposedBy !== null && $proposedBy === $user->id;
    }

    /**
     * Es muss immer mindestens eine Leitung bestehen bleiben. Das Entfernen
     * oder Degradieren der letzten Leitung wird fuer alle (auch Admin)
     * blockiert, um die Invariante zu wahren.
     */
    public function canRemoveLeader(bool $isLastLeader): bool
    {
        return !$isLastLeader;
    }

    private function isActiveStatus(?string $status): bool
    {
        return $status !== null && $status !== self::STATUS_INACTIVE;
    }
}
