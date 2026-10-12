<?php

namespace app\domain\geo;

use app\domain\exception\RoutingUnavailableException;
use app\domain\model\Coordinate;
use app\domain\model\Route;

interface RoutingEngineInterface
{
    /**
     * @throws RoutingUnavailableException
     */
    public function route(Coordinate $from, Coordinate $to): Route;

    /**
     * Cheap liveness check for the health endpoint.
     */
    public function isAvailable(): bool;
}
