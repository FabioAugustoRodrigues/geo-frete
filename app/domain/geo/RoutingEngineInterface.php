<?php

namespace app\domain\geo;

use app\domain\exception\RoutingUnavailableException;
use app\domain\model\Coordinate;
use app\domain\model\Route;

interface RoutingEngineInterface
{
    /**
     * @param bool $withPath also return the points the route goes through (larger
     *                       answer: only when the caller will draw the route)
     *
     * @throws RoutingUnavailableException
     */
    public function route(Coordinate $from, Coordinate $to, bool $withPath = false): Route;

    /**
     * Cheap liveness check for the health endpoint.
     */
    public function isAvailable(): bool;
}
