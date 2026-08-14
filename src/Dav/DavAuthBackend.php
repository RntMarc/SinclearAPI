<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Psr\Log\LoggerInterface;
use Sabre\DAV\Auth\Backend\BackendInterface;
use Sabre\HTTP\Auth\Basic;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sinclear\Api\Services\DavTokenService;

/**
 * Basic-Auth-Backend fuer den DAV-Endpunkt.
 *
 * Da Beyond keine Passwoerter kennt, authentifizieren sich DAV-Clients mit
 * E-Mail + DAV-Token. Bei gueltigen Credentials wird der Principal
 * "principals/{userId}" ausgestellt.
 *
 * Besonderheit: Ungueltige, abgelaufene oder fehlende Credentials fuehren
 * NICHT zu einer 401-Challenge. Stattdessen wird ein virtueller Principal
 * ausgestellt, fuer den die Backends einen Hinweis-Kalender bzw. ein
 * Hinweis-Adressbuch mit dem Eintrag "Abgelaufener oder ungueltiger Token"
 * ausliefern. So sieht der Nutzer im Client direkt, dass sein Token erneuert
 * werden muss.
 */
final class DavAuthBackend implements BackendInterface
{
    public const string INVALID_TOKEN_PRINCIPAL = 'principals/dav-invalid-token';
    public const string PRINCIPAL_PREFIX = 'principals/';

    private string $realm = 'Sinclear Beyond';
    private ?string $currentPrincipal = null;

    public function __construct(
        private DavTokenService $tokenService,
        private ?LoggerInterface $logger = null,
    ) {}

    public function setRealm(string $realm): void
    {
        $this->realm = $realm;
    }

    public function check(RequestInterface $request, ResponseInterface $response): array
    {
        $auth = new Basic($this->realm, $request, $response);
        $credentials = $auth->getCredentials();

        if ($credentials !== false) {
            $userId = $this->tokenService->validateToken($credentials[0], $credentials[1] ?? '');
            if ($userId !== null) {
                $this->currentPrincipal = self::PRINCIPAL_PREFIX . $userId;
                return [true, $this->currentPrincipal];
            }

            $this->logger?->info('DAV: invalid or expired token presented', [
                'email' => $credentials[0],
            ]);
        }

        $this->currentPrincipal = self::INVALID_TOKEN_PRINCIPAL;
        return [true, self::INVALID_TOKEN_PRINCIPAL];
    }

    public function challenge(RequestInterface $request, ResponseInterface $response): void
    {
        $auth = new Basic($this->realm, $request, $response);
        $auth->requireLogin();
    }

    public function getCurrentPrincipal(): ?string
    {
        return $this->currentPrincipal;
    }

    public function isInvalidTokenPrincipal(string $principalUri): bool
    {
        return $principalUri === self::INVALID_TOKEN_PRINCIPAL;
    }
}
