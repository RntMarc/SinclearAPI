<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Sabre\CardDAV\AddressBookHome;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\MkCol;

final class ReadOnlyAddressBookHome extends AddressBookHome
{
    public function createFile($name, $data = null)
    {
        throw new Forbidden('Address book homes are read-only');
    }

    public function createDirectory($filename)
    {
        throw new Forbidden('Address book homes are read-only');
    }

    public function createExtendedCollection($name, MkCol $mkCol)
    {
        throw new Forbidden('Address book homes are read-only');
    }

    public function delete()
    {
        throw new Forbidden('Address book homes are read-only');
    }

    public function setName($name)
    {
        throw new Forbidden('Address book homes are read-only');
    }

    public function getChildren()
    {
        $addressBooks = $this->carddavBackend->getAddressBooksForUser($this->principalUri);

        $objs = [];
        foreach ($addressBooks as $addressBook) {
            $objs[] = new ReadOnlyAddressBook($this->carddavBackend, $addressBook);
        }

        return $objs;
    }
}
