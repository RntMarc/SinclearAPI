<?php

namespace Sinclear\Api\Services;

use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Repository\AdminAuditLogRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Protokolliert mutierende Admin-Aktionen in der Tabelle AdminAuditLog.
 *
 * Lesende Zugriffe werden bewusst nicht protokolliert; nur Aktionen, die
 * Daten veraendern (create/update/delete/approve/reject/close/link/...),
 * erzeugen einen Eintrag mit Admin-ID, Aktion, Entitaetstyp/-ID sowie
 * IP-Adresse und User-Agent.
 */
final readonly class AdminAuditService
{
    public function __construct(
        private AdminAuditLogRepository $auditLogRepo,
    ) {}

    public function record(
        AuthenticatedUser $admin,
        string $action,
        string $entityType,
        ?string $entityId,
        ServerRequestInterface $request,
    ): void {
        $serverParams = $request->getServerParams();

        $ipAddress = null;
        $forwardedFor = $serverParams['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($forwardedFor) && $forwardedFor !== '') {
            $ipAddress = trim(explode(',', $forwardedFor)[0]);
        } else {
            $ipAddress = $serverParams['REMOTE_ADDR'] ?? null;
        }

        $userAgent = $request->getHeaderLine('User-Agent');
        $userAgent = $userAgent !== '' ? $userAgent : null;

        $this->auditLogRepo->create(
            adminId: $admin->id,
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            ipAddress: is_string($ipAddress) ? $ipAddress : null,
            userAgent: $userAgent,
        );
    }
}
