<?php

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
|
| app/config/config.php reads .env and defines the configuration constants.
| The test values must be in $_ENV *before* that file is required for the
| first time: the dotenv repository is immutable, so .env cannot override
| them, and constants cannot be redefined afterwards. That is also why
| config.php is not loaded through Composer's "autoload.files".
|
*/

$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_NAME'] = 'geo_frete_test';

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../config/config.php';
