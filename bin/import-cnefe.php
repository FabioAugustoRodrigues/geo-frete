#!/usr/bin/env php
<?php

/*
| Imports one municipality's CNEFE (IBGE 2022 Census addresses) into the
| local address base. Re-running replaces the previous import.
|
|   php bin/import-cnefe.php SP Limeira
|   docker compose exec api php bin/import-cnefe.php SP Limeira
*/

use app\domain\service\CnefeImportService;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/config/config.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/import-cnefe.php <UF> <municipality>\n");
    exit(1);
}

[, $state, $city] = $argv;

$container = require __DIR__ . '/../app/config/container.php';

try {
    $start = microtime(true);
    $result = $container->get(CnefeImportService::class)->import($state, $city);

    printf(
        "Imported %s (%d): %d streets, %d house numbers in %.1fs.\n",
        $result['name'],
        $result['code'],
        $result['streets'],
        $result['addresses'],
        microtime(true) - $start
    );
} catch (Throwable $e) {
    error_log('[CNEFE IMPORT] ' . $e->getMessage());
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
