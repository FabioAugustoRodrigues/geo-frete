<?php

namespace app\domain\service;

use app\domain\model\Coordinate;

class DistanceService extends ServiceAbstract
{
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * @param float $routeFactor how much longer a road route typically is than
     *                           the straight line (e.g. 1.3 = 30% longer)
     */
    public function __construct(private float $routeFactor)
    {
    }

    /**
     * Great-circle distance (Haversine formula).
     */
    public function straightLineKm(Coordinate $from, Coordinate $to): float
    {
        $fromLat = deg2rad($from->lat);
        $toLat = deg2rad($to->lat);
        $deltaLat = deg2rad($to->lat - $from->lat);
        $deltaLng = deg2rad($to->lng - $from->lng);

        $a = sin($deltaLat / 2) ** 2 + cos($fromLat) * cos($toLat) * sin($deltaLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Rough road distance: the straight line stretched by the route factor.
     */
    public function estimatedRouteKm(Coordinate $from, Coordinate $to): float
    {
        return $this->straightLineKm($from, $to) * $this->routeFactor;
    }
}
