<?php

namespace Sinclear\Api\Controllers\AdminApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Support\DateTimeValue;
use Sinclear\Api\Support\TravelError;

/**
 * Gemeinsame Helfer fuer die JSON-Admin-Controller. Die Business-Logik wird
 * in den vorhandenen Services/Repositories gebuendelt; dieser Trait stellt
 * lediglich wiederkehrende Request-/Response-Konventionen bereit.
 */
trait AdminApiConcern
{
    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }

    /** @return array<string, mixed> */
    private function parsedBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function travelErrorResponse(\RuntimeException $e, ResponseInterface $response): ResponseInterface
    {
        [$code, $status] = TravelError::resolve($e->getMessage());
        return ResponseFactory::json(['error' => $code], $status, $response);
    }

    /**
     * Operative Admin-Schreibpfade (Chat, Forum, Abo) duerfen nur aktive
     * Reisen betreffen; Planungsreisen werden ausschliesslich ueber
     * /trips/planning verwaltet.
     *
     * @param array<string, mixed> $trip
     */
    private function tripIsOperational(array $trip): bool
    {
        return ($trip['state'] ?? 'active') === 'active';
    }

    private function tripNotActiveResponse(ResponseInterface $response): ResponseInterface
    {
        return ResponseFactory::json(['error' => 'trip_not_active'], 409, $response);
    }

    /**
     * Zeitfelder eines Reise-/Event-Datensatzes fuer die Admin-API. Liefert
     * `allDay`, die IANA-Zeitzone sowie `startDate`/`endDate` (ganztägig)
     * bzw. `startAtLocal`/`endAtLocal` (Wandzeit fuer datetime-local).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function timingFields(array $row): array
    {
        $timezone = DateTimeValue::normalizeTimeZone(
            isset($row['timezone']) ? (string) $row['timezone'] : null,
        );

        return [
            'allDay' => !empty($row['allDay']),
            'timezone' => $timezone,
            'startDate' => $row['startDate'] ?? '',
            'endDate' => $row['endDate'] ?? '',
            'startAtLocal' => self::storedInstantForInput($row['startAt'] ?? null, $timezone),
            'endAtLocal' => self::storedInstantForInput($row['endAt'] ?? null, $timezone),
        ];
    }

    private static function storedInstantForInput(mixed $value, string $timezone): string
    {
        $instant = DateTimeValue::fromDatabase(is_string($value) ? $value : null);
        if ($instant === null) {
            return '';
        }

        return $instant
            ->setTimezone(DateTimeValue::assertTimeZone($timezone))
            ->format('Y-m-d\TH:i');
    }
}
