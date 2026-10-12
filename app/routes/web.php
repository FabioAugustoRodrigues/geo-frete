<?php

namespace app\routes;

use app\core\controller\Router;
use app\http\controller\WebController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| HTML pages for humans (no API key), unlike the JSON routes in api.php.
|
*/

Router::get("/", WebController::class, "documentation");
