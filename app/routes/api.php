<?php

namespace app\routes;

use app\core\controller\Router;
use app\http\controller\DistanceController;
use app\http\controller\HealthController;
use app\http\middleware\ApiKeyMiddleware;
use app\http\middleware\RateLimitMiddleware;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| JSON endpoints, versioned under /api/v1. Middleware order matters: the
| rate limit trusts the key only after ApiKeyMiddleware validated it.
|
*/

Router::get("/api/v1/health", HealthController::class, "health");

Router::group(['middleware' => [ApiKeyMiddleware::class, RateLimitMiddleware::class]], function () {
    Router::post("/api/v1/distance", DistanceController::class, "calculate");
});
