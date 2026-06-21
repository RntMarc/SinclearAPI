#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Sinclear\Api\Cli;

use Sinclear\Api\Services\NominatimCache;

require_once __DIR__ . '/../vendor/autoload.php';

$cacheDir = __DIR__ . '/../var/cache/nominatim';

if (!is_dir($cacheDir)) {
    echo "Nominatim cache directory does not exist: $cacheDir\n";
    exit(0);
}

$cache = new NominatimCache($cacheDir);

$deleted = $cache->clean();

if ($deleted > 0) {
    echo "Cleaned $deleted expired file(s) from Nominatim cache.\n";
} else {
    echo "No expired files found in Nominatim cache.\n";
}

exit(0);
