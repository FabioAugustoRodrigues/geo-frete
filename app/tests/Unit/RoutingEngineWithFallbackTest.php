<?php

namespace app\tests\Unit;

use app\domain\geo\EstimateRoutingEngine;
use app\domain\geo\RoutingEngineWithFallback;
use app\domain\model\Coordinate;
use app\domain\model\Route;
use app\domain\service\DistanceService;
use app\tests\Fake\RoutingEngineFake;
use PHPUnit\Framework\TestCase;

class RoutingEngineWithFallbackTest extends TestCase
{
    private RoutingEngineWithFallback $engine;

    protected function setUp(): void
    {
        RoutingEngineFake::reset();

        $distanceService = new DistanceService(1.3);
        $this->engine = new RoutingEngineWithFallback(
            new RoutingEngineFake($distanceService),
            new EstimateRoutingEngine($distanceService)
        );
    }

    protected function tearDown(): void
    {
        RoutingEngineFake::reset();
    }

    public function testUsesThePrimaryEngineWhenItWorks(): void
    {
        $route = $this->engine->route(...$this->points());

        $this->assertSame(Route::METHOD_ROUTE, $route->method);
        $this->assertNotNull($route->durationMin);
        $this->assertTrue($this->engine->isAvailable());
    }

    public function testFallsBackToTheEstimateWhenThePrimaryFails(): void
    {
        RoutingEngineFake::makeUnavailable();

        $route = $this->engine->route(...$this->points());

        $this->assertSame(Route::METHOD_ESTIMATE, $route->method);
        $this->assertNull($route->durationMin);
        $this->assertNull($route->originSnapKm);
        $this->assertFalse($this->engine->isAvailable(), 'health must reflect the primary engine');
    }

    /**
     * @return Coordinate[]
     */
    private function points(): array
    {
        return [new Coordinate(-22.5647, -47.4017), new Coordinate(-22.5800, -47.4100)];
    }
}
