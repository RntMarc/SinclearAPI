<?php

namespace Sinclear\Api\Support;

/**
 * Zentrale Zuordnung der von TravelService geworfenen Fehlercodes zu
 * HTTP-Status und Client-Fehlercode.
 */
final class TravelError
{
    /** @var array<string, array{0: string, 1: int}> */
    private const MAP = [
        'Not a participant' => ['forbidden', 403],
        'Not a leader' => ['forbidden', 403],
        'Conversion not allowed' => ['conversion_not_allowed', 403],
        'Not a member' => ['forbidden', 403],
        'Trip not found' => ['trip_not_found', 404],
        'Event not found' => ['event_not_found', 404],
        'Accommodation not found' => ['accommodation_not_found', 404],
        'Forum not found' => ['forum_not_found', 404],
        'User not found' => ['user_not_found', 404],
        'Ticket not found' => ['ticket_not_found', 404],
        'Ticket creation failed' => ['ticket_creation_failed', 500],
        'Already a participant' => ['already_participant', 409],
        'Last leader remains' => ['last_leader', 409],
        'Name required' => ['name_required', 400],
        'Trip required' => ['trip_required', 400],
        'Invalid role' => ['invalid_role', 400],
        'Invalid conversion' => ['invalid_conversion', 400],
        'No fields to update' => ['no_fields_to_update', 400],
        'invalid_timezone' => ['invalid_timezone', 400],
        'date_required' => ['date_required', 400],
        'invalid_date' => ['invalid_date', 400],
        'invalid_time_range' => ['invalid_time_range', 400],
        'time_required' => ['time_required', 400],
        'invalid_datetime' => ['invalid_datetime', 400],
        'invalid_image' => ['invalid_image', 400],
        'invalid_image_encoding' => ['invalid_image', 400],
        'image_too_large' => ['invalid_image', 400],
        'invalid_image_format' => ['invalid_image', 400],
        'unsupported_image_format' => ['invalid_image', 400],
        'image_dimensions_too_large' => ['invalid_image', 400],
        'invalid_image_aspect_ratio' => ['invalid_image', 400],
    ];

    /**
     * @return array{0: string, 1: int}
     */
    public static function resolve(string $message): array
    {
        return self::MAP[$message] ?? ['internal_error', 500];
    }
}
