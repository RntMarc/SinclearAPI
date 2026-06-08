#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scaffolds a new CRUD resource entry for ResourceRegistry.php.
 *
 * Usage: php bin/scaffold-resource.php <route-name> <TableName> [PolicyClass]
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/scaffold-resource.php <route> <Table> [Policy]\n");
    exit(1);
}

$route = $argv[1];
$table = $argv[2];
$policy = $argv[3] ?? 'OwnerPolicy';

$entry = sprintf(
    "    ['route' => '%s', 'table' => '%s', 'policy' => %s::class],\n",
    $route,
    $table,
    $policy
);

echo "Add to config/ResourceRegistry.php:\n\n";
echo $entry;
