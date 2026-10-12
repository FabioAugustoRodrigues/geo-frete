<?php

namespace app\http\controller;

use app\core\controller\ControllerAbstract;
use app\core\controller\Request;
use app\database\Connection;
use Throwable;

class HealthController extends ControllerAbstract
{
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
                    "server_time" => date('c'),
                ],
                "message" => ""
            ],
            $databaseOk ? 200 : 503
        );
    }
}
