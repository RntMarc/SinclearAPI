<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\LocationSharingSessionRepository;
use Sinclear\Api\Repository\LocationSharingRecipientRepository;
use Sinclear\Api\Repository\LocationSharingLocationRepository;

final readonly class LocationSharingService
{
    public function __construct(
        private LocationSharingSessionRepository $sessionRepo,
        private LocationSharingRecipientRepository $recipientRepo,
        private LocationSharingLocationRepository $locationRepo,
    ) {}

    public function createSession(array $data, string $ownerId): array
    {
        $id = $this->sessionRepo->create([
            'ownerId' => $ownerId,
            'durationSeconds' => $data['duration_seconds'],
            'frequencySeconds' => $data['frequency_seconds'] ?? 600,
        ]);

        $this->recipientRepo->addRecipients($id, $data['recipient_ids']);

        return $this->formatSessionDetail($id, $ownerId);
    }

    public function getSession(string $id): ?array
    {
        $session = $this->sessionRepo->findById($id);
        return $session !== null ? $this->formatSession($session) : null;
    }

    public function getSessionDetail(string $id): ?array
    {
        $session = $this->sessionRepo->findById($id);
        if ($session === null) {
            return null;
        }
        return $this->formatSessionDetail($id, $session['ownerId']);
    }

    public function listMySessions(string $ownerId): array
    {
        $sessions = $this->sessionRepo->listByOwner($ownerId);
        return array_map(fn(array $s) => $this->formatSession($s), $sessions);
    }

    public function listContactSessions(string $userId): array
    {
        $sessions = $this->sessionRepo->listActiveAsRecipient($userId);
        return array_map(function (array $row) {
            return [
                'session' => $this->formatSession($row),
                'owner' => [
                    'id' => $row['ownerId'],
                    'displayName' => $row['displayName'],
                    'image' => $row['image'],
                ],
                'lastLocation' => $this->formatLocation(
                    $this->locationRepo->getLastLocation($row['id'])
                ),
            ];
        }, $sessions);
    }

    public function updateSession(string $id, array $data): array
    {
        $existing = $this->sessionRepo->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('session_not_found');
        }

        $update = [];
        if (array_key_exists('duration_seconds', $data)) {
            $update['durationSeconds'] = $data['duration_seconds'];
        }
        if (array_key_exists('is_active', $data)) {
            $update['isActive'] = $data['is_active'];
        }

        $this->sessionRepo->update($id, $update);
        return $this->formatSessionDetail($id, $existing['ownerId']);
    }

    public function addLocation(string $sessionId, array $data): string
    {
        $session = $this->sessionRepo->findById($sessionId);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }

        return $this->locationRepo->create([
            'sessionId' => $sessionId,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'accuracy' => $data['accuracy'] ?? null,
            'recordedAt' => $data['recordedAt'],
        ]);
    }

    public function listLocations(string $sessionId, ?string $since): array
    {
        return $this->locationRepo->listBySession($sessionId, $since);
    }

    public function isRecipient(string $sessionId, string $userId): bool
    {
        return $this->recipientRepo->isRecipient($sessionId, $userId);
    }

    private function formatSession(array $session): array
    {
        return [
            'id' => $session['id'],
            'ownerId' => $session['ownerId'],
            'durationSeconds' => (int) $session['durationSeconds'],
            'frequencySeconds' => (int) $session['frequencySeconds'],
            'isActive' => (bool) $session['isActive'],
            'startedAt' => $session['startedAt'],
            'expiresAt' => $session['expiresAt'],
            'createdAt' => $session['createdAt'],
            'updatedAt' => $session['updatedAt'],
        ];
    }

    private function formatSessionDetail(string $id, string $ownerId): array
    {
        $session = $this->sessionRepo->findById($id);
        $data = $this->formatSession($session);
        $data['recipients'] = $this->recipientRepo->getRecipients($id);
        $data['lastLocation'] = $this->formatLocation(
            $this->locationRepo->getLastLocation($id)
        );
        return $data;
    }

    private function formatLocation(?array $location): ?array
    {
        if ($location === null) {
            return null;
        }
        return [
            'id' => $location['id'],
            'latitude' => (float) $location['latitude'],
            'longitude' => (float) $location['longitude'],
            'accuracy' => $location['accuracy'] !== null ? (float) $location['accuracy'] : null,
            'recordedAt' => $location['recordedAt'],
            'createdAt' => $location['createdAt'],
        ];
    }
}
