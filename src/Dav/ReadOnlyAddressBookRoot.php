<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Sabre\CardDAV\AddressBookRoot;

final class ReadOnlyAddressBookRoot extends AddressBookRoot
{
    public function getChildForPrincipal(array $principal)
    {
        return new ReadOnlyAddressBookHome($this->carddavBackend, $principal['uri']);
    }
}
