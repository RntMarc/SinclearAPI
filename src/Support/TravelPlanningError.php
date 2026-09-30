<?php

namespace Sinclear\Api\Support;

/**
 * Zentrale Zuordnung der von TravelPlanningService geworfenen Fehlercodes zu
 * HTTP-Status und Client-Fehlercode.
 *
 * Bewusst getrennt von TravelError, damit die Planungslogik unabhaengig von
 * der operativen Travel-Logik bleibt. Wiederkehrende Codes (z. B. Namens-
 * pflicht, Zeitvalidierung) teilen sich dieselbe Client-Fehlercode-Konvention.
 */
final class TravelPlanningError
{
    /** @var array<string, array{0: string, 1: int}> */
    private const MAP = [
        // Zugriff
        'Not a planning member' => ['forbidden', 403],
        'Not a planning leader' => ['forbidden', 403],
        'Not a planning trip' => ['invalid_planning_trip', 409],
        'Inconsistent planning data' => ['inconsistent_planning_data', 409],
        'Not a member' => ['forbidden', 403],
        'Not allowed' => ['forbidden', 403],
        'Last leader remains' => ['last_leader', 409],

        // Nicht gefunden
        'Planning trip not found' => ['planning_trip_not_found', 404],
        'Member not found' => ['member_not_found', 404],
        'Date option not found' => ['date_option_not_found', 404],
        'Accommodation option not found' => ['accommodation_option_not_found', 404],
        'Event suggestion not found' => ['event_suggestion_not_found', 404],
        'Accommodation not found' => ['accommodation_not_found', 404],
        'User not found' => ['user_not_found', 404],

        // Konflikte
        'Already a member' => ['already_member', 409],
        'Trip already active' => ['trip_already_active', 409],

        // Eingabe
        'Name required' => ['name_required', 400],
        'No fields to update' => ['no_fields_to_update', 400],
        'Invalid role' => ['invalid_role', 400],
        'Invalid topic' => ['invalid_topic', 400],
        'Invalid topic status' => ['invalid_topic_status', 400],
        'Invalid member status' => ['invalid_member_status', 400],
        'Invalid response' => ['invalid_response', 400],
        'Invalid availability' => ['invalid_availability', 400],
        'Invalid interest' => ['invalid_interest', 400],
        'Invalid direction' => ['invalid_direction', 400],
        'Accommodation required' => ['accommodation_required', 400],
        'UserId required' => ['user_id_required', 400],
        'Invalid price' => ['invalid_price', 400],
        'invalid_timezone' => ['invalid_timezone', 400],
        'date_required' => ['date_required', 400],
        'invalid_date' => ['invalid_date', 400],
        'invalid_time_range' => ['invalid_time_range', 400],
        'time_required' => ['time_required', 400],
        'invalid_datetime' => ['invalid_datetime', 400],
        'invalid_image' => ['invalid_image', 400],
    ];

    /**
     * @return array{0: string, 1: int}
     */
    public static function resolve(string $message): array
    {
        return self::MAP[$message] ?? ['internal_error', 500];
    }
}
