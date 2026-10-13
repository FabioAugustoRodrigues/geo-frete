<?php

use app\core\Application;
use app\http\Cors;

require_once './vendor/autoload.php';
require_once './app/config/config.php';

require_once './app/routes/api.php';
require_once './app/routes/web.php';

foreach (Cors::headers(CORS_ALLOWED_ORIGINS, $_SERVER['HTTP_ORIGIN'] ?? null) as $header) {
    header($header);
}

date_default_timezone_set('America/Sao_Paulo');

$application = new Application();
$application->run();
