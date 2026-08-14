<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Sabre\CardDAV\Backend\AbstractBackend;
use Sabre\DAV\Exception\Forbidden;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\UserService;

/**
 * Read-only CardDAV-Backend.
 *
 * Stellt ein einzelnes Adressbuch "Beyond Kontakte" je Principal bereit,
 * das alle Nutzer enthaelt, die der Anfragende sehen darf (gefiltert nach
 * den Sichtbarkeitseinstellungen 0/1/2 inkl. enger Freunde).
 *
 * Fuer den virtuellen Invalid-Token-Principal wird ein Hinweis-Adressbuch
 * mit genau einem Kontakt ("Abgelaufener oder ungueltiger Token")
 * ausgeliefert. Schreibzugriffe werden grundsaetzlich verweigert.
 */
final class CardDavBackend extends AbstractBackend
{
    public const string INVALID_TOKEN_ADDRESSBOOK_ID = 'addressbook:dav-invalid-token';
    public const string ADDRESSBOOK_URI = 'contacts';

    public function __construct(
        private UserRepository $userRepo,
        private UserService $userService,
        private VcardFactory $vcardFactory,
        private DavDummyFactory $dummyFactory,
    ) {}

    public function getAddressBooksForUser($principalUri)
    {
        if ($principalUri === DavAuthBackend::INVALID_TOKEN_PRINCIPAL) {
            return [$this->addressBookInfo(self::INVALID_TOKEN_ADDRESSBOOK_ID, (string) $principalUri, 'Sinclear Beyond')];
        }

        $userId = DavPrincipalBackend::userIdFromPrincipal((string) $principalUri);
        if ($userId === null) {
            return [];
        }

        return [$this->addressBookInfo($this->addressBookId($userId), (string) $principalUri, 'Beyond Kontakte')];
    }

    public function getCards($addressBookId)
    {
        $addressBookId = (string) $addressBookId;

        if ($addressBookId === self::INVALID_TOKEN_ADDRESSBOOK_ID) {
            return [$this->dummyFactory->cardObject()];
        }

        $userId = $this->userIdFromAddressBookId($addressBookId);
        if ($userId === null) {
            return [];
        }

        $requester = $this->buildRequester($userId);
        $users = $this->userRepo->findAll();

        $cards = [];
        foreach ($users as $user) {
            $filtered = $this->userService->formatUserBaseFiltered($user, $requester);
            $cards[] = $this->vcardFactory->userToCardObject($filtered);
        }

        return $cards;
    }

    public function getCard($addressBookId, $cardUri)
    {
        $addressBookId = (string) $addressBookId;

        if ($addressBookId === self::INVALID_TOKEN_ADDRESSBOOK_ID) {
            $card = $this->dummyFactory->cardObject();
            return $card['uri'] === $cardUri ? $card : null;
        }

        $userId = $this->userIdFromAddressBookId($addressBookId);
        if ($userId === null) {
            return null;
        }

        $targetUserId = str_ends_with($cardUri, '.vcf') ? substr($cardUri, 0, -4) : $cardUri;
        $user = $this->userRepo->findById($targetUserId);
        if ($user === null) {
            return null;
        }

        $filtered = $this->userService->formatUserBaseFiltered($user, $this->buildRequester($userId));
        return $this->vcardFactory->userToCardObject($filtered);
    }

    public function updateAddressBook($addressBookId, \Sabre\DAV\PropPatch $propPatch)
    {
        throw new Forbidden('Address books are read-only');
    }

    public function createAddressBook($principalUri, $url, array $properties)
    {
        throw new Forbidden('Address books are read-only');
    }

    public function deleteAddressBook($addressBookId)
    {
        throw new Forbidden('Address books are read-only');
    }

    public function createCard($addressBookId, $cardUri, $cardData)
    {
        throw new Forbidden('This address book is read-only');
    }

    public function updateCard($addressBookId, $cardUri, $cardData)
    {
        throw new Forbidden('This address book is read-only');
    }

    public function deleteCard($addressBookId, $cardUri)
    {
        throw new Forbidden('This address book is read-only');
    }

    /** @return array<string, mixed> */
    private function addressBookInfo(string $id, string $principalUri, string $displayName): array
    {
        return [
            'id' => $id,
            'uri' => self::ADDRESSBOOK_URI,
            'principaluri' => $principalUri,
            '{DAV:}displayname' => $displayName,
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => $displayName,
            '{http://sabredav.org/ns}read-only' => 1,
        ];
    }

    private function addressBookId(string $userId): string
    {
        return 'addressbook:' . $userId;
    }

    private function userIdFromAddressBookId(string $addressBookId): ?string
    {
        $prefix = 'addressbook:';
        if (!str_starts_with($addressBookId, $prefix)) {
            return null;
        }
        $userId = substr($addressBookId, strlen($prefix));
        return $userId !== '' ? $userId : null;
    }

    private function buildRequester(string $userId): AuthenticatedUser
    {
        $user = $this->userRepo->findById($userId);
        return new AuthenticatedUser(
            id: $userId,
            email: $user['email'] ?? '',
            isAdmin: (bool) ($user['isAdmin'] ?? false),
            jti: '',
        );
    }
}
