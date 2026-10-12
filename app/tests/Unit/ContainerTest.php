<?php

namespace app\tests\Unit;

use app\domain\geo\NominatimGeocoder;
use app\http\controller\DistanceController;
use app\http\middleware\RateLimitMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Feature tests run with fake providers, so they never build the real ones:
 * this catches wiring mistakes (e.g. a missing constructor value) that would
 * only surface in production.
 */
class ContainerTest extends TestCase
{
    public function testRealProvidersAndEntryPointsCanBeBuilt(): void
    {
        $container = require __DIR__ . '/../../config/container.php';

        $this->assertInstanceOf(NominatimGeocoder::class, $container->get(NominatimGeocoder::class));
        $this->assertInstanceOf(DistanceController::class, $container->get(DistanceController::class));
        $this->assertInstanceOf(RateLimitMiddleware::class, $container->get(RateLimitMiddleware::class));
    }
}
