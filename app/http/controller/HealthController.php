<?php

namespace app\http\controller;

use app\core\controller\ControllerAbstract;
use app\core\controller\Request;
use app\database\Connection;
use app\domain\geo\RoutingEngineInterface;
use Throwable;

class HealthController extends ControllerAbstract
{
    public function __construct(private RoutingEngineInterface $routingEngine)
    {
    }

    public function health(Request $request)
    {
        $databaseOk = true;

        try {
            Connection::getConnection()->query('SELECT 1');
        } catch (Throwable $e) {
            $databaseOk = false;
            error_log("[HEALTH CHECK ERROR] " . $e->getMessage());
        }

        return $this->respondJson(
            [
                "data" => [
                    "database" => $databaseOk ? "ok" : "unavailable",
                    // A down routing engine degrades answers to estimates but
                    // does not stop the API, so it does not turn health into 503.
                    "routing_engine" => $this->routingEngine->isAvailable() ? "ok" : "unavailable",
                    "server_time" => date('c'),
                ],
                "message" => ""
            ],
            $databaseOk ? 200 : 503
        );
    }
}
