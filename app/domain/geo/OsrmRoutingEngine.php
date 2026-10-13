<?php

namespace app\domain\geo;

use app\domain\exception\RoutingUnavailableException;
use app\domain\model\Coordinate;
use app\domain\model\Route;

/**
 * Road routing through an OSRM server (Open Source Routing Machine) loaded
 * with an OpenStreetMap extract. See docker/osrm/ for how the map is built.
 */
class OsrmRoutingEngine implements RoutingEngineInterface
{
    /** OSRM answers in milliseconds; waiting longer only delays the fallback. */
    private const HEALTH_TIMEOUT_SECONDS = 1;

    public function __construct(
        private HttpClient $httpClient,
        private string $baseUrl,
        private int $timeoutSeconds
    ) {
    }

    public function route(Coordinate $from, Coordinate $to, bool $withPath = false): Route
    {
        // OSRM expects "lng,lat" pairs, the reverse of the domain's lat/lng order.
        $coordinates = sprintf('%F,%F;%F,%F', $from->lng, $from->lat, $to->lng, $to->lat);

        // "full" keeps every bend of the streets, so the line follows them at
        // street-level zoom; without a path, skip the geometry entirely.
        $query = $withPath
            ? ['overview' => 'full', 'geometries' => 'geojson']
            : ['overview' => 'false'];

        $response = $this->httpClient->getJson(
            "{$this->baseUrl}/route/v1/driving/{$coordinates}",
            $query,
            $this->timeoutSeconds
        );

        // Null covers both network failures and 4xx answers such as "NoSegment"
        // (a point outside the loaded map): either way, there is no route.
        if ($response === null) {
            throw new RoutingUnavailableException('OSRM gave no usable answer.');
        }

        if (($response['code'] ?? null) !== 'Ok' || !isset($response['routes'][0]['distance'])) {
            throw new RoutingUnavailableException('OSRM found no route: ' . ($response['code'] ?? 'unknown'));
        }

        $route = $response['routes'][0];

        return new Route(
            distanceKm: $route['distance'] / 1000,
            durationMin: isset($route['duration']) ? $route['duration'] / 60 : null,
            method: Route::METHOD_ROUTE,
            originSnapKm: $this->snapKm($response, 0),
            destinationSnapKm: $this->snapKm($response, 1),
            path: $withPath ? $this->path($route) : null
        );
    }

    /**
     * @return Coordinate[]|null
     */
    private function path(array $route): ?array
    {
        $points = $route['geometry']['coordinates'] ?? null;

        if (!is_array($points)) {
            return null;
        }

        // GeoJSON points are [lng, lat]: back to the domain's order.
        return array_map(fn (array $point) => new Coordinate((float) $point[1], (float) $point[0]), $points);
    }

    public function isAvailable(): bool
    {
        return $this->httpClient->isReachable($this->baseUrl, self::HEALTH_TIMEOUT_SECONDS);
    }

    /**
     * Each waypoint's "distance" is how many meters OSRM moved the input point
     * to place it on the nearest road.
     */
    private function snapKm(array $response, int $index): ?float
    {
        $meters = $response['waypoints'][$index]['distance'] ?? null;

        return $meters === null ? null : $meters / 1000;
    }
}
