<?php

namespace app\tests\Fake;

use app\domain\exception\RoutingUnavailableException;
use app\domain\geo\RoutingEngineInterface;
use app\domain\model\Coordinate;
use app\domain\model\Route;
use app\domain\service\DistanceService;

/**
 * Deterministic routing engine for tests: the route is 25% longer than the
 * straight line, driven at 30 km/h. State is static because the container
 * builds a new instance per request.
 */
class RoutingEngineFake implements RoutingEngineInterface
{
    public const ROUTE_FACTOR = 1.25;
    public const SPEED_KMH = 30.0;

    private static bool $unavailable = false;
    private static float $snapKm = 0.01;

    public function __construct(private DistanceService $distanceService)
    {
    }

    public static function reset(): void
    {
        self::$unavailable = false;
        self::$snapKm = 0.01;
    }

    public static function makeUnavailable(): void
    {
        self::$unavailable = true;
    }

    public static function snapPointsBy(float $km): void
    {
        self::$snapKm = $km;
    }

    public function route(Coordinate $from, Coordinate $to): Route
    {
        if (self::$unavailable) {
            throw new RoutingUnavailableException('Fake routing engine is down.');
        }

        $distanceKm = $this->distanceService->straightLineKm($from, $to) * self::ROUTE_FACTOR;

        return new Route(
            distanceKm: $distanceKm,
            durationMin: $distanceKm / self::SPEED_KMH * 60,
            method: Route::METHOD_ROUTE,
            originSnapKm: self::$snapKm,
            destinationSnapKm: self::$snapKm
        );
    }

    public function isAvailable(): bool
    {
        return !self::$unavailable;
    }
}
