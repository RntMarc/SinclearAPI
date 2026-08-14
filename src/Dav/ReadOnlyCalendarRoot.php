<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Sabre\CalDAV\CalendarRoot;

final class ReadOnlyCalendarRoot extends CalendarRoot
{
    public function getChildForPrincipal(array $principal)
    {
        return new ReadOnlyCalendarHome($this->caldavBackend, $principal);
    }
}
