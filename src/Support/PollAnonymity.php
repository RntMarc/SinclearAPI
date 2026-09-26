<?php

declare(strict_types=1);

namespace Sinclear\Api\Support;

/**
 * Anonymisiert Teilnehmer einer Umfrage.
 *
 * Die Stimmtabelle speichert keinen `userId`, sondern ausschließlich
 * `participantHash = HMAC-SHA256("pollId:userId", secret)`. Der Hash ist
 * deterministisch (Doppelwahl-Erkennung) und erlaubt ohne das Secret keine
 * Rückführung auf einen Nutzer.
 */
final class PollAnonymity
{
    public function __construct(
        private readonly string $secret,
    ) {}

    public function participantHash(string $pollId, string $userId): string
    {
        return hash_hmac('sha256', $pollId . ':' . $userId, $this->secret);
    }
}
