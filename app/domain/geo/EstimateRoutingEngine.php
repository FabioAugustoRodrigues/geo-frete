<?php

namespace app\domain\geo;

use app\domain\model\Coordinate;
use app\domain\model\Route;
use app\domain\service\DistanceService;

/**
 * Never fails: straight line x route factor. The last resort when no real
 * routing engine can answer.
 */
class EstimateRoutingEngine implements RoutingEngineInterface
{
    public function __construct(private DistanceService $distanceService)
    {
    }

    public function route(Coordinate $from, Coordinate $to): Route
    {
        return new Route(
            distanceKm: $this->distanceService->estimatedRouteKm($from, $to),
            durationMin: null,
            method: Route::METHOD_ESTIMATE
        );
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
