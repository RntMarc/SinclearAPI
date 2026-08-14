<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Sabre\VObject\Component\VCard;

/**
 * Erzeugt vCard-Daten (vCard 3.0) aus gefilterten Nutzerprofilen.
 *
 * Die uebergebenen Nutzerdaten muessen bereits nach den
 * Sichtbarkeitseinstellungen gefiltert sein (siehe
 * UserService::formatUserBaseFiltered) - die E-Mail-Adresse und der
 * Geburtstag sind nur enthalten, wenn sie fuer den Anfragenden sichtbar
 * sind.
 */
final readonly class VcardFactory
{
    private const string PRODID = '-//Sinclear Beyond//CardDAV Server//DE';
    private const string UID_DOMAIN = '@sinclear.de';

    /** @param array<string, mixed> $user */
    public function userToVcard(array $user): string
    {
        $properties = [
            'VERSION' => '3.0',
            'PRODID' => self::PRODID,
            'UID' => $user['id'] . self::UID_DOMAIN,
            'FN' => (string) $user['displayName'],
            'N' => [(string) $user['displayName'], '', '', '', ''],
        ];

        if (!empty($user['email'])) {
            $properties['EMAIL'] = (string) $user['email'];
        }

        if (!empty($user['birthday'])) {
            $properties['BDAY'] = $this->formatBirthday((string) $user['birthday']);
        }

        if (!empty($user['note'])) {
            $properties['NOTE'] = (string) $user['note'];
        }

        $vcard = new VCard($properties);

        return $vcard->serialize();
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function userToCardObject(array $user): array
    {
        $cardData = $this->userToVcard($user);

        return [
            'uri' => $user['id'] . '.vcf',
            'carddata' => $cardData,
            'lastmodified' => isset($user['createdAt']) ? strtotime((string) $user['createdAt']) : time(),
            'etag' => '"' . sha1($cardData) . '"',
            'size' => strlen($cardData),
        ];
    }

    private function formatBirthday(string $birthday): string
    {
        if (strlen($birthday) === 10) {
            return $birthday;
        }

        return substr($birthday, 0, 10);
    }
}
