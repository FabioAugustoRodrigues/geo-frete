<?php

use app\core\Application;

require_once './vendor/autoload.php';
require_once './app/config/config.php';

require_once './app/routes/api.php';
require_once './app/routes/web.php';

header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Origin: *");

date_default_timezone_set('America/Sao_Paulo');

$application = new Application();
$application->run();
