<?php

use app\cache\CacheFacade;

require_once __DIR__ . '/../../vendor/autoload.php';

// safeLoad: inside Docker the variables come from the container environment,
// so a missing .env file must not be fatal. The repository is immutable, which
// also lets the test bootstrap preset $_ENV values that .env cannot override.
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

// $_ENV is empty for real environment variables when php.ini's variables_order
// lacks "E" (XAMPP's default), so getenv() is the fallback.
$env = static function (string $name, string $default = ''): string {
    if (isset($_ENV[$name])) {
        return (string) $_ENV[$name];
    }

    $value = getenv($name);

    return $value === false ? $default : $value;
};

# ERROR REPORTING
// Never print errors or stack traces into the HTTP response, even if the
// server's php.ini enables display_errors: only RequestHandler decides what
// the client sees. Everything still goes to the log.
ini_set("display_errors", "0");
ini_set("display_startup_errors", "0");
ini_set("log_errors", "1");
ini_set("error_log", __DIR__ . '/../../storage/logs/error_logs.log');

# ENVIRONMENT
define("APP_ENV", $env('APP_ENV', 'production'));

# DATABASE
define("DB_DRIVER", $env('DB_DRIVER', 'mysql'));
define("DB_HOST", $env('DB_HOST', '127.0.0.1'));
define("DB_PORT", (int) $env('DB_PORT', '3306'));
define("DB_USER", $env('DB_USER', 'root'));
define("DB_PASS", $env('DB_PASS'));
define("DB_NAME", $env('DB_NAME', 'geo_frete'));

# CACHE
CacheFacade::setCacheType($env('CACHE_TYPE', 'array'));

# AUTHENTICATION AND RATE LIMIT
define("API_KEYS", $env('API_KEYS'));
define("RATE_LIMIT_PER_MINUTE", (int) $env('RATE_LIMIT_PER_MINUTE', '120'));

# EXTERNAL PROVIDERS
define("APP_USER_AGENT", $env('APP_USER_AGENT', 'geo-frete/0.1'));
define("APP_CONTACT", $env('APP_CONTACT'));
define("NOMINATIM_URL", rtrim($env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'), '/'));
define("HTTP_TIMEOUT", (int) $env('HTTP_TIMEOUT', '8'));

# ROUTING
define("OSRM_URL", rtrim($env('OSRM_URL', 'http://127.0.0.1:5000'), '/'));
define("OSRM_TIMEOUT", (int) $env('OSRM_TIMEOUT', '3'));
define("SNAP_WARNING_KM", (float) $env('SNAP_WARNING_KM', '0.3'));

# DISTANCE
define("GEOCODING_CACHE_DAYS", (int) $env('GEOCODING_CACHE_DAYS', '180'));
define("ROUTE_FACTOR", (float) $env('ROUTE_FACTOR', '1.3'));

unset($env);
