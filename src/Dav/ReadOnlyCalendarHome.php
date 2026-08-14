<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Sabre\CalDAV\CalendarHome;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\MkCol;

final class ReadOnlyCalendarHome extends CalendarHome
{
    public function createFile($name, $data = null)
    {
        throw new Forbidden('Calendar homes are read-only');
    }

    public function createDirectory($filename)
    {
        throw new Forbidden('Calendar homes are read-only');
    }

    public function createExtendedCollection($name, MkCol $mkCol)
    {
        throw new Forbidden('Calendar homes are read-only');
    }

    public function delete()
    {
        throw new Forbidden('Calendar homes are read-only');
    }

    public function setName($name)
    {
        throw new Forbidden('Calendar homes are read-only');
    }
}
