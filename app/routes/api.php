<?php

namespace app\routes;

use app\core\controller\Router;
use app\http\controller\HealthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| JSON endpoints, versioned under /api/v1.
|
*/

Router::get("/api/v1/health", HealthController::class, "health");
