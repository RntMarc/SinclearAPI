<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Sabre\CardDAV\AddressBook;
use Sabre\DAV\Exception\Forbidden;

final class ReadOnlyAddressBook extends AddressBook
{
    public function createDirectory($name)
    {
        throw new Forbidden('Address books are read-only');
    }

    public function createFile($name, $data = null)
    {
        throw new Forbidden('Address books are read-only');
    }

    public function delete()
    {
        throw new Forbidden('Address books are read-only');
    }

    public function setName($newName)
    {
        throw new Forbidden('Address books are read-only');
    }

    public function getChildACL()
    {
        return [
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->getOwner(),
                'protected' => true,
            ],
        ];
    }
}
