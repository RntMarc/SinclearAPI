<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use PDO;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final class TravelService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function myTrips(AuthenticatedUser $user): array
    {
        $now = new \DateTime();

        $relStmt = $this->pdo->prepare(
            "SELECT tripid FROM TravelRelation WHERE userid = :userId"
        );
        $relStmt->execute(['userId' => $user->id]);
        $myTripIds = $relStmt->fetchAll(PDO::FETCH_COLUMN);
        $myTripIdSet = array_flip($myTripIds);

        if ($user->isAdmin) {
            $stmt = $this->pdo->query("SELECT * FROM TravelTrip ORDER BY start ASC");
            $trips = $stmt ? $stmt->fetchAll() : [];
        } else {
            if (empty($myTripIds)) return [];

            $placeholders = implode(',', array_fill(0, count($myTripIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT * FROM TravelTrip WHERE id IN ($placeholders) ORDER BY start ASC"
            );
            $stmt->execute($myTripIds);
            $trips = $stmt->fetchAll();
        }

        return array_map(function(array $trip) use ($now, $myTripIdSet): array {
            $enriched = $this->enrichTrip($trip, $now);
            $enriched['isParticipant'] = isset($myTripIdSet[$trip['id']]);
            return $enriched;
        }, $trips);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function myEvents(AuthenticatedUser $user): array
    {
        $now = new \DateTime();

        if ($user->isAdmin) {
            $stmt = $this->pdo->query("SELECT * FROM TravelEvent ORDER BY start ASC");
            return $stmt ? $stmt->fetchAll() : [];
        }

        $erStmt = $this->pdo->prepare(
            "SELECT eventId FROM EventRelation WHERE userId = :userId"
        );
        $erStmt->execute(['userId' => $user->id]);
        $eventIds = $erStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($eventIds)) return [];

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM TravelEvent WHERE ID IN ($placeholders) ORDER BY start ASC"
        );
        $stmt->execute($eventIds);
        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function standaloneEvents(AuthenticatedUser $user): array
    {
        $now = new \DateTime();

        if ($user->isAdmin) {
            $stmt = $this->pdo->query(
                "SELECT * FROM TravelEvent WHERE trip IS NULL ORDER BY start ASC"
            );
            $events = $stmt ? $stmt->fetchAll() : [];
        } else {
            $relStmt = $this->pdo->prepare(
                "SELECT eventId FROM EventRelation WHERE userId = :userId"
            );
            $relStmt->execute(['userId' => $user->id]);
            $eventIds = $relStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($eventIds)) return [];

            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT * FROM TravelEvent WHERE trip IS NULL AND ID IN ($placeholders) ORDER BY start ASC"
            );
            $stmt->execute($eventIds);
            $events = $stmt->fetchAll();
        }

        return array_map(fn(array $event) => $this->enrichEvent($event, $now), $events);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tripDetails(AuthenticatedUser $user, string $tripId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM TravelTrip WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $tripId]);
        $trip = $stmt->fetch();
        if (!$trip) return null;

        $relStmt = $this->pdo->prepare(
            "SELECT COUNT(*) as cnt FROM TravelRelation WHERE tripid = :tripId AND userid = :userId LIMIT 1"
        );
        $relStmt->execute(['tripId' => $tripId, 'userId' => $user->id]);
        $isParticipant = (int) $relStmt->fetchColumn() > 0;

        if (!$user->isAdmin && !$isParticipant) {
            return ['error' => 'Unauthorized'];
        }

        $participants = $this->fetchParticipants($tripId);
        $events = $this->fetchTripEvents($tripId);
        $eventIds = array_column($events, 'ID');
        $eventParticipants = [];
        if (!empty($eventIds)) {
            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
            $epStmt = $this->pdo->prepare(
                "SELECT * FROM EventRelation WHERE eventId IN ($placeholders)"
            );
            $epStmt->execute($eventIds);
            $eventParticipants = $epStmt->fetchAll();
        }

        $now = new \DateTime();
        $mappedEvents = array_map(function(array $event) use ($now, $eventParticipants, $user) {
            $participantIds = [];
            foreach ($eventParticipants as $ep) {
                if ($ep['eventId'] === $event['ID']) {
                    $participantIds[] = $ep['userId'];
                }
            }
            return [
                'id' => $event['ID'],
                'tripId' => $event['trip'],
                'name' => $event['name'],
                'description' => $event['description'],
                'start' => $event['start'],
                'end' => $event['end'],
                'hasTickets' => $event['hastickets'] ?? '0',
                'ticketId' => $event['ticket'] ?? null,
                'ticketUrl' => $event['ticketUrl'] ?? null,
                'url' => $event['url'] ?? null,
                'image' => $event['image'] ?? null,
                'organizer' => $event['organizer'] ?? null,
                'address' => $event['address'] ?? null,
                'latitude' => $event['latitude'] ?? null,
                'longitude' => $event['longitude'] ?? null,
                'osmId' => $event['OSMID'] ?? null,
                'isPast' => new \DateTime($event['end']) < $now,
                'isActive' => new \DateTime($event['start']) <= $now && new \DateTime($event['end']) >= $now,
                'isUpcoming' => new \DateTime($event['start']) > $now,
                'participantIds' => $participantIds,
                'isParticipant' => in_array($user->id, $participantIds, true),
            ];
        }, $events);

        $userAccommodation = null;
        foreach ($participants as $p) {
            if ($p['userId'] === $user->id) {
                $userAccommodation = $p['accommodation'];
                break;
            }
        }

        $result = $this->enrichTrip($trip, $now);
        $result['participants'] = $participants;
        $result['userAccommodation'] = $userAccommodation;
        $result['events'] = $mappedEvents;

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tripParticipants(AuthenticatedUser $user, string $tripId): array
    {
        return $this->fetchParticipants($tripId);
    }

    public function addParticipant(AuthenticatedUser $user, string $tripId, string $userId): bool
    {
        $check = $this->pdo->prepare(
            "SELECT COUNT(*) FROM TravelRelation WHERE tripid = :tripId AND userid = :userId LIMIT 1"
        );
        $check->execute(['tripId' => $tripId, 'userId' => $userId]);
        if ((int) $check->fetchColumn() > 0) return true;

        $stmt = $this->pdo->prepare(
            "INSERT INTO TravelRelation (ID, userid, tripid) VALUES (:id, :userId, :tripId)"
        );
        $stmt->execute([
            'id' => $this->generateUuid(),
            'userId' => $userId,
            'tripId' => $tripId,
        ]);
        return true;
    }

    public function updateParticipantAccommodation(string $tripId, string $userId, ?string $accommodationId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE TravelRelation SET accommodation = :accommodation WHERE tripid = :tripId AND userid = :userId"
        );
        $stmt->execute([
            'accommodation' => $accommodationId,
            'tripId' => $tripId,
            'userId' => $userId,
        ]);
    }

    public function removeParticipant(string $tripId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM TravelRelation WHERE tripid = :tripId AND userid = :userId"
        );
        $stmt->execute(['tripId' => $tripId, 'userId' => $userId]);
    }

    /**
     * @param array<string, mixed> $trip
     * @return array<string, mixed>
     */
    private function enrichTrip(array $trip, \DateTime $now): array
    {
        return [
            'id' => $trip['id'],
            'name' => $trip['name'],
            'description' => $trip['description'] ?? null,
            'start' => $trip['start'],
            'end' => $trip['end'],
            'hasTickets' => $trip['hastickets'] ?? '0',
            'ticketId' => $trip['ticket'] ?? null,
            'ticketUrl' => $trip['ticketUrl'] ?? null,
            'isPast' => new \DateTime($trip['end']) < $now,
            'isActive' => new \DateTime($trip['start']) <= $now && new \DateTime($trip['end']) >= $now,
            'isUpcoming' => new \DateTime($trip['start']) > $now,
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function enrichEvent(array $event, \DateTime $now): array
    {
        return [
            'id' => $event['ID'],
            'tripId' => $event['trip'] ?? null,
            'name' => $event['name'],
            'description' => $event['description'] ?? null,
            'start' => $event['start'],
            'end' => $event['end'],
            'hasTickets' => $event['hastickets'] ?? '0',
            'ticketId' => $event['ticket'] ?? null,
            'ticketUrl' => $event['ticketUrl'] ?? null,
            'url' => $event['url'] ?? null,
            'image' => $event['image'] ?? null,
            'organizer' => $event['organizer'] ?? null,
            'address' => $event['address'] ?? null,
            'latitude' => $event['latitude'] ?? null,
            'longitude' => $event['longitude'] ?? null,
            'osmId' => $event['OSMID'] ?? null,
            'isPast' => new \DateTime($event['end']) < $now,
            'isActive' => new \DateTime($event['start']) <= $now && new \DateTime($event['end']) >= $now,
            'isUpcoming' => new \DateTime($event['start']) > $now,
        ];
    }

    /**
     * @param string $tripId
     * @return list<array<string, mixed>>
     */
    private function fetchParticipants(string $tripId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                u.id,
                u.displayName,
                u.email,
                u.emailVisibility,
                u.image,
                ta.ID AS acc_id,
                ta.name AS acc_name,
                ta.description AS acc_description,
                ta.address AS acc_address,
                ta.OSMID AS acc_osmId,
                ta.latitude AS acc_latitude,
                ta.longitude AS acc_longitude,
                ta.phone AS acc_phone,
                ta.mail AS acc_mail,
                ta.ishotel AS acc_isHotel,
                ci.discordHandle AS ci_discordHandle,
                ci.discordVisibility AS ci_discordVisibility,
                ci.fluxerHandle AS ci_fluxerHandle,
                ci.fluxerVisibility AS ci_fluxerVisibility,
                ci.matrixUser AS ci_matrixUser,
                ci.matrixHomeserver AS ci_matrixHomeserver,
                ci.matrixVisibility AS ci_matrixVisibility,
                ci.signalNumber AS ci_signalNumber,
                ci.signalVisibility AS ci_signalVisibility,
                ci.whatsappNumber AS ci_whatsappNumber,
                ci.whatsappVisibility AS ci_whatsappVisibility,
                si.instagramHandle AS si_instagramHandle,
                si.instagramVisibility AS si_instagramVisibility,
                si.mastodonHandle AS si_mastodonHandle,
                si.mastodonVisibility AS si_mastodonVisibility,
                si.pixelfedHandle AS si_pixelfedHandle,
                si.pixelfedVisibility AS si_pixelfedVisibility,
                si.blueskyHandle AS si_blueskyHandle,
                si.blueskyVisibility AS si_blueskyVisibility,
                si.youtubeHandle AS si_youtubeHandle,
                si.youtubeVisibility AS si_youtubeVisibility,
                si.twitchHandle AS si_twitchHandle,
                si.twitchVisibility AS si_twitchVisibility,
                si.unsplashHandle AS si_unsplashHandle,
                si.unsplashVisibility AS si_unsplashVisibility
            FROM TravelRelation tr
            INNER JOIN User u ON u.id = tr.userid
            LEFT JOIN TravelAccommodation ta ON ta.ID = tr.accommodation
            LEFT JOIN ContactInfo ci ON ci.userId = u.id
            LEFT JOIN SocialInfo si ON si.userId = u.id
            WHERE tr.tripid = :tripId
        ");
        $stmt->execute(['tripId' => $tripId]);
        $rows = $stmt->fetchAll();

        return array_map(function(array $row): array {
            $acc = null;
            if ($row['acc_id'] !== null) {
                $acc = [
                    'id' => $row['acc_id'],
                    'name' => $row['acc_name'],
                    'description' => $row['acc_description'],
                    'address' => $row['acc_address'],
                    'osmId' => $row['acc_osmId'],
                    'latitude' => $row['acc_latitude'] !== null ? (float) $row['acc_latitude'] : null,
                    'longitude' => $row['acc_longitude'] !== null ? (float) $row['acc_longitude'] : null,
                    'phone' => $row['acc_phone'],
                    'mail' => $row['acc_mail'],
                    'isHotel' => (int) ($row['acc_isHotel'] ?? 0),
                ];
            }
            $contact = null;
            if ($row['ci_discordHandle'] !== null) {
                $contact = [
                    'discordHandle' => $row['ci_discordHandle'],
                    'discordVisibility' => $row['ci_discordVisibility'],
                    'fluxerHandle' => $row['ci_fluxerHandle'],
                    'fluxerVisibility' => $row['ci_fluxerVisibility'],
                    'matrixUser' => $row['ci_matrixUser'],
                    'matrixHomeserver' => $row['ci_matrixHomeserver'],
                    'matrixVisibility' => $row['ci_matrixVisibility'],
                    'signalNumber' => $row['ci_signalNumber'],
                    'signalVisibility' => $row['ci_signalVisibility'],
                    'whatsappNumber' => $row['ci_whatsappNumber'],
                    'whatsappVisibility' => $row['ci_whatsappVisibility'],
                ];
            }
            $social = null;
            if ($row['si_instagramHandle'] !== null) {
                $social = [
                    'instagramHandle' => $row['si_instagramHandle'],
                    'instagramVisibility' => $row['si_instagramVisibility'],
                    'mastodonHandle' => $row['si_mastodonHandle'],
                    'mastodonVisibility' => $row['si_mastodonVisibility'],
                    'pixelfedHandle' => $row['si_pixelfedHandle'],
                    'pixelfedVisibility' => $row['si_pixelfedVisibility'],
                    'blueskyHandle' => $row['si_blueskyHandle'],
                    'blueskyVisibility' => $row['si_blueskyVisibility'],
                    'youtubeHandle' => $row['si_youtubeHandle'],
                    'youtubeVisibility' => $row['si_youtubeVisibility'],
                    'twitchHandle' => $row['si_twitchHandle'],
                    'twitchVisibility' => $row['si_twitchVisibility'],
                    'unsplashHandle' => $row['si_unsplashHandle'],
                    'unsplashVisibility' => $row['si_unsplashVisibility'],
                ];
            }
            return [
                'id' => $row['id'],
                'displayName' => $row['displayName'],
                'email' => $row['email'],
                'emailVisibility' => $row['emailVisibility'],
                'image' => $row['image'],
                'accommodation' => $acc,
                'contactInfo' => $contact,
                'socialInfo' => $social,
            ];
        }, $rows);
    }

    /**
     * @param string $tripId
     * @return list<array<string, mixed>>
     */
    private function fetchTripEvents(string $tripId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM TravelEvent WHERE trip = :tripId ORDER BY start ASC"
        );
        $stmt->execute(['tripId' => $tripId]);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $participantIds
     * @return array<string, mixed>
     */
    public function createEvent(array $data, array $participantIds = []): array
    {
        $id = $this->generateUuid();
        $stmt = $this->pdo->prepare("
            INSERT INTO TravelEvent (ID, trip, name, description, start, end, hastickets, ticket, ticketUrl, url, image, organizer, address, latitude, longitude, OSMID)
            VALUES (:id, :trip, :name, :description, :start, :end, :hastickets, :ticket, :ticketUrl, :url, :image, :organizer, :address, :latitude, :longitude, :OSMID)
        ");
        $stmt->execute([
            'id' => $id,
            'trip' => $data['tripId'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start' => $data['start'],
            'end' => $data['end'],
            'hastickets' => $data['hasTickets'] ?? '0',
            'ticket' => $data['ticketId'] ?? null,
            'ticketUrl' => $data['ticketUrl'] ?? null,
            'url' => $data['url'] ?? null,
            'image' => $data['image'] ?? null,
            'organizer' => $data['organizer'] ?? null,
            'address' => $data['address'] ?? null,
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'OSMID' => isset($data['osmId']) ? (int) $data['osmId'] : null,
        ]);

        $this->setEventParticipantIds($id, $participantIds);

        return ['id' => $id];
    }

    /**
     * @param string $id
     * @param array<string, mixed> $data
     * @param list<string>|null $participantIds
     */
    public function updateEvent(string $id, array $data, ?array $participantIds = null): void
    {
        $fields = [];
        $params = ['id' => $id];

        $map = [
            'tripId' => 'trip',
            'name' => 'name',
            'description' => 'description',
            'start' => 'start',
            'end' => 'end',
            'hasTickets' => 'hastickets',
            'ticketId' => 'ticket',
            'ticketUrl' => 'ticketUrl',
            'url' => 'url',
            'image' => 'image',
            'organizer' => 'organizer',
            'address' => 'address',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'osmId' => 'OSMID',
        ];

        foreach ($map as $clientKey => $dbColumn) {
            if (array_key_exists($clientKey, $data)) {
                $value = $data[$clientKey];
                if (in_array($clientKey, ['latitude', 'longitude'], true)) {
                    $value = $value !== null ? (float) $value : null;
                } elseif ($clientKey === 'osmId') {
                    $value = $value !== null ? (int) $value : null;
                } elseif ($clientKey === 'hasTickets') {
                    $value = $value ? '1' : '0';
                }
                $fields[] = "$dbColumn = :$dbColumn";
                $params[$dbColumn] = $value;
            }
        }

        if (!empty($fields)) {
            $stmt = $this->pdo->prepare(
                "UPDATE TravelEvent SET " . implode(', ', $fields) . " WHERE ID = :id"
            );
            $stmt->execute($params);
        }

        if ($participantIds !== null) {
            $this->setEventParticipantIds($id, $participantIds);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEventWithParticipants(string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM TravelEvent WHERE ID = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $event = $stmt->fetch();
        if (!$event) return null;

        $relStmt = $this->pdo->prepare(
            "SELECT userId FROM EventRelation WHERE eventId = :eventId"
        );
        $relStmt->execute(['eventId' => $id]);
        $participantIds = $relStmt->fetchAll(PDO::FETCH_COLUMN);

        $now = new \DateTime();
        $enriched = $this->enrichEvent($event, $now);
        $enriched['participantIds'] = $participantIds;

        return $enriched;
    }

    public function deleteEvent(string $id): void
    {
        $this->pdo->prepare("DELETE FROM EventRelation WHERE eventId = :id")->execute(['id' => $id]);
        $this->pdo->prepare("DELETE FROM TravelEvent WHERE ID = :id")->execute(['id' => $id]);
    }

    /**
     * @param string $eventId
     * @param list<string> $participantIds
     */
    private function setEventParticipantIds(string $eventId, array $participantIds): void
    {
        $this->pdo->prepare("DELETE FROM EventRelation WHERE eventId = :eventId")->execute(['eventId' => $eventId]);

        foreach ($participantIds as $userId) {
            $this->pdo->prepare(
                "INSERT INTO EventRelation (id, eventId, userId, createdAt) VALUES (:id, :eventId, :userId, :createdAt)"
            )->execute([
                'id' => $this->generateUuid(),
                'eventId' => $eventId,
                'userId' => $userId,
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
