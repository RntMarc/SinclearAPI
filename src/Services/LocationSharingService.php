<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\LocationSharingSessionRepository;
use Sinclear\Api\Repository\LocationSharingRecipientRepository;
use Sinclear\Api\Repository\LocationSharingLocationRepository;
use Sinclear\Api\Repository\UserRepository;

final readonly class LocationSharingService
{
    public function __construct(
        private LocationSharingSessionRepository $sessionRepo,
        private LocationSharingRecipientRepository $recipientRepo,
        private LocationSharingLocationRepository $locationRepo,
        private NotificationService $notificationService,
        private UserRepository $userRepo,
        private Settings $settings,
    ) {}

    public function createSession(array $data, string $ownerId): array
    {
        $id = $this->sessionRepo->create([
            'ownerId' => $ownerId,
            'sharingMode' => $data['sharing_mode'] ?? 'location',
            'durationSeconds' => $data['duration_seconds'],
            'frequencySeconds' => $data['frequency_seconds'] ?? 600,
        ]);

        $this->recipientRepo->addRecipients($id, $data['recipient_ids']);

        $owner = $this->userRepo->findById($ownerId);
        $ownerDisplayName = $owner['displayName'] ?? 'Unbekannt';

        $recipients = $this->recipientRepo->getRecipients($id);
        foreach ($recipients as $recipient) {
            try {
                $this->notificationService->createNotification(
                    userId: $recipient['userId'],
                    code: 'location_sharing.started',
                    payload: [
                        'locationSharingSessionId' => $id,
                        'ownerDisplayName' => $ownerDisplayName,
                    ],
                );
            } catch (\Throwable $e) {
                error_log('Location sharing notification failed: ' . $e->getMessage());
            }
        }

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

    public function addLocationByToken(string $token, float $lat, float $lon, ?float $accuracy, ?string $recordedAt): string
    {
        $session = $this->sessionRepo->findByToken($token);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }

        if ($session['isActive'] != 1) {
            throw new \RuntimeException('session_inactive');
        }

        $utc = new \DateTimeZone('UTC');
        $now = new \DateTime('now', $utc);
        $expiresAt = \DateTime::createFromFormat('Y-m-d H:i:s', $session['expiresAt'], $utc);
        if ($expiresAt !== false && $expiresAt < $now) {
            throw new \RuntimeException('session_expired');
        }

        $recordedAtFormatted = $recordedAt;
        if ($recordedAtFormatted === null) {
            $recordedAtFormatted = $now->format('Y-m-d H:i:s');
        } else {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $recordedAtFormatted, $utc);
            if ($dt !== false) {
                $recordedAtFormatted = $dt->format('Y-m-d H:i:s');
            } else {
                $recordedAtFormatted = $now->format('Y-m-d H:i:s');
            }
        }

        return $this->locationRepo->create([
            'sessionId' => $session['id'],
            'latitude' => $lat,
            'longitude' => $lon,
            'accuracy' => $accuracy,
            'recordedAt' => $recordedAtFormatted,
        ]);
    }

    public function listLocations(string $sessionId, ?string $since): array
    {
        $rows = $this->locationRepo->listBySession($sessionId, $since);
        return array_map(fn(array $row) => $this->formatLocation($row), $rows);
    }

    public function isRecipient(string $sessionId, string $userId): bool
    {
        return $this->recipientRepo->isRecipient($sessionId, $userId);
    }

    public function deleteSession(string $id): void
    {
        $this->sessionRepo->delete($id);
    }

    public function generateIntegrationUrls(string $token): array
    {
        $baseUrl = $this->settings->app['url'];
        $base = $baseUrl . '/api/v2/location-sharing/log';

        return [
            'osmand' => $base . '/osmand/' . $token . '/yourname?lat={0}&lon={1}&alt={4}&acc={3}&timestamp={2}&speed={5}&bearing={6}',
            'gpslogger' => $base . '/gpslogger/' . $token . '/yourname?lat=%LAT&lon=%LON&sat=%SAT&alt=%ALT&acc=%ACC&speed=%SPD&bearing=%DIR&timestamp=%TIMESTAMP&bat=%BATT',
            'owntracks' => $base . '/owntracks/' . $token . '/yourname',
            'ulogger' => $base . '/ulogger/' . $token . '/yourname',
            'traccar' => $base . '/traccar/' . $token . '/yourname',
            'opengts' => $base . '/opengts/' . $token . '/yourname',
            'overland' => $base . '/overland/' . $token . '/yourname',
            'locusmap' => $base . '/locusmap/' . $token . '/yourname?lat=LAT&lon=LON&time=TIME&alt=ALT&speed=SPEED&bearing=BEARING',
            'httpGet' => $base . '/get/' . $token . '/yourname?lat=LAT&lon=LON&alt=ALT&acc=ACC&bat=BAT&sat=SAT&speed=SPD&bearing=DIR&timestamp=TIME',
        ];
    }

    private function formatSession(array $session): array
    {
        return [
            'id' => $session['id'],
            'token' => $session['token'],
            'ownerId' => $session['ownerId'],
            'sharingMode' => $session['sharingMode'],
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
        $data['locationCount'] = $this->sessionRepo->countLocations($id);
        $data['integrationUrls'] = $this->generateIntegrationUrls($session['token']);
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
