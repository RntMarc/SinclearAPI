<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;
use Sinclear\Api\Repository\UserRepository;

/**
 * Principal-Backend fuer den DAV-Endpunkt.
 *
 * Principals haben das Format "principals/{userId}" (zwei Pfad-Komponenten,
 * damit die Calendar-/AddressBook-Home-Erkennung von sabre/dav funktioniert).
 * Zusaetzlich existiert der virtuelle Principal "principals/dav-invalid-token"
 * fuer Clients mit ungueltigen oder abgelaufenen Tokens.
 */
final class DavPrincipalBackend extends AbstractBackend
{
    public function __construct(
        private UserRepository $userRepo,
        private DavAuthBackend $authBackend,
    ) {}

    public static function userIdFromPrincipal(string $principalUri): ?string
    {
        if (!str_starts_with($principalUri, DavAuthBackend::PRINCIPAL_PREFIX)) {
            return null;
        }
        $userId = substr($principalUri, strlen(DavAuthBackend::PRINCIPAL_PREFIX));
        return $userId !== '' ? $userId : null;
    }

    public function getPrincipalsByPrefix($prefixPath)
    {
        if ($prefixPath !== 'principals') {
            return [];
        }

        if ($this->authBackend->isInvalidTokenPrincipal((string) $this->authBackend->getCurrentPrincipal())) {
            return [$this->invalidPrincipalRecord()];
        }

        $users = $this->userRepo->findAll();
        return array_map(fn(array $user) => $this->userToPrincipal($user), $users);
    }

    public function getPrincipalByPath($path)
    {
        if ($this->authBackend->isInvalidTokenPrincipal((string) $path)) {
            return $this->invalidPrincipalRecord();
        }

        $userId = self::userIdFromPrincipal((string) $path);
        if ($userId === null) {
            return null;
        }

        $user = $this->userRepo->findById($userId);
        return $user !== null ? $this->userToPrincipal($user) : null;
    }

    public function updatePrincipal($path, PropPatch $propPatch)
    {
        throw new Forbidden('Principals are read-only');
    }

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {
        return [];
    }

    public function getGroupMemberSet($principal)
    {
        return [];
    }

    public function getGroupMembership($principal)
    {
        return [];
    }

    public function setGroupMemberSet($principal, array $members)
    {
        throw new Forbidden('Principals are read-only');
    }

    /** @param array<string, mixed> $user */
    private function userToPrincipal(array $user): array
    {
        $principal = [
            'uri' => DavAuthBackend::PRINCIPAL_PREFIX . $user['id'],
            '{DAV:}displayname' => $user['displayName'],
        ];

        // Die eigene E-Mail-Adresse nur an den Principal selbst ausliefern,
        // damit E-Mail-Sichtbarkeitseinstellungen nicht umgangen werden.
        if ($this->authBackend->getCurrentPrincipal() === DavAuthBackend::PRINCIPAL_PREFIX . $user['id']) {
            $principal['{http://sabredav.org/ns}email-address'] = $user['email'];
        }

        return $principal;
    }

    private function invalidPrincipalRecord(): array
    {
        return [
            'uri' => DavAuthBackend::INVALID_TOKEN_PRINCIPAL,
            '{DAV:}displayname' => 'Sinclear Beyond',
        ];
    }
}
