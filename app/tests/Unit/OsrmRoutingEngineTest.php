<?php

namespace app\tests\Unit;

use app\domain\exception\RoutingUnavailableException;
use app\domain\geo\HttpClient;
use app\domain\geo\OsrmRoutingEngine;
use app\domain\model\Coordinate;
use app\domain\model\Route;
use PHPUnit\Framework\TestCase;

class OsrmRoutingEngineTest extends TestCase
{
    /** Trimmed real OSRM answer (overview=false). */
    private const OK_RESPONSE = [
        'code' => 'Ok',
        'routes' => [['distance' => 2834.6, 'duration' => 402.3, 'weight' => 402.3, 'legs' => []]],
        'waypoints' => [
            ['name' => 'Rua Senador Vergueiro', 'location' => [-47.405842, -22.567068], 'distance' => 12.5],
            ['name' => 'Avenida Campinas', 'location' => [-47.391889, -22.576532], 'distance' => 450.0],
        ],
    ];

    private HttpClient $http;
    private OsrmRoutingEngine $engine;

    protected function setUp(): void
    {
        $this->http = new class (1, 'test', '') extends HttpClient {
            public ?array $response = null;
            public array $calls = [];

            public function getJson(string $url, array $query = [], ?int $timeoutSeconds = null): ?array
            {
                $this->calls[] = ['url' => $url, 'query' => $query, 'timeout' => $timeoutSeconds];

                return $this->response;
            }
        };

        $this->engine = new OsrmRoutingEngine($this->http, 'http://osrm.test', 3);
    }

    public function testConvertsMetersAndSecondsToKmAndMinutes(): void
    {
        $this->http->response = self::OK_RESPONSE;

        $route = $this->engine->route($this->origin(), $this->destination());

        $this->assertEqualsWithDelta(2.8346, $route->distanceKm, 0.00001);
        $this->assertEqualsWithDelta(6.705, $route->durationMin, 0.00001);
        $this->assertSame(Route::METHOD_ROUTE, $route->method);
        $this->assertEqualsWithDelta(0.0125, $route->originSnapKm, 0.00001);
        $this->assertEqualsWithDelta(0.45, $route->destinationSnapKm, 0.00001);
    }

    public function testSendsCoordinatesInLngLatOrder(): void
    {
        $this->http->response = self::OK_RESPONSE;

        $this->engine->route($this->origin(), $this->destination());

        $this->assertSame(
            'http://osrm.test/route/v1/driving/-47.405842,-22.567068;-47.391889,-22.576532',
            $this->http->calls[0]['url']
        );
        $this->assertSame(['overview' => 'false'], $this->http->calls[0]['query']);
        $this->assertSame(3, $this->http->calls[0]['timeout']);
    }

    public function testNoPathUnlessRequested(): void
    {
        $this->http->response = self::OK_RESPONSE;

        $route = $this->engine->route($this->origin(), $this->destination());

        $this->assertNull($route->path);
    }

    public function testRequestsAndConvertsTheGeoJsonPath(): void
    {
        $response = self::OK_RESPONSE;
        $response['routes'][0]['geometry'] = [
            'type' => 'LineString',
            'coordinates' => [[-47.405842, -22.567068], [-47.4, -22.57], [-47.391889, -22.576532]],
        ];
        $this->http->response = $response;

        $route = $this->engine->route($this->origin(), $this->destination(), true);

        $this->assertSame(['overview' => 'full', 'geometries' => 'geojson'], $this->http->calls[0]['query']);
        $this->assertCount(3, $route->path);
        // GeoJSON [lng, lat] back to the domain's lat/lng.
        $this->assertSame(-22.567068, $route->path[0]->lat);
        $this->assertSame(-47.405842, $route->path[0]->lng);
        $this->assertSame(-22.576532, $route->path[2]->lat);
    }

    public function testMissingGeometryInTheAnswerGivesNoPath(): void
    {
        $this->http->response = self::OK_RESPONSE;

        $route = $this->engine->route($this->origin(), $this->destination(), true);

        $this->assertNull($route->path);
        $this->assertSame(Route::METHOD_ROUTE, $route->method);
    }

    public function testNoAnswerIsUnavailable(): void
    {
        $this->http->response = null;

        $this->expectException(RoutingUnavailableException::class);

        $this->engine->route($this->origin(), $this->destination());
    }

    public function testAnswerWithoutRouteIsUnavailable(): void
    {
        $this->http->response = ['code' => 'NoRoute', 'message' => 'Impossible route between points'];

        $this->expectException(RoutingUnavailableException::class);

        $this->engine->route($this->origin(), $this->destination());
    }

    private function origin(): Coordinate
    {
        return new Coordinate(-22.567068, -47.405842);
    }

    private function destination(): Coordinate
    {
        return new Coordinate(-22.576532, -47.391889);
    }
}
