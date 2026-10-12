<?php

namespace app\tests\Unit;

use app\domain\model\Coordinate;
use app\domain\service\DistanceService;
use PHPUnit\Framework\TestCase;

class DistanceServiceTest extends TestCase
{
    public function testOneDegreeOfLatitudeIsAbout111Km(): void
    {
        $service = new DistanceService(1.3);

        $km = $service->straightLineKm(new Coordinate(-22.0, -47.0), new Coordinate(-23.0, -47.0));

        $this->assertEqualsWithDelta(111.19, $km, 0.01);
    }

    public function testSamePointIsZero(): void
    {
        $service = new DistanceService(1.3);
        $point = new Coordinate(-22.5647, -47.4017);

        $this->assertSame(0.0, $service->straightLineKm($point, $point));
    }

    public function testEstimateAppliesTheRouteFactor(): void
    {
        $service = new DistanceService(1.5);
        $from = new Coordinate(-22.0, -47.0);
        $to = new Coordinate(-22.1, -47.1);

        $this->assertEqualsWithDelta(
            $service->straightLineKm($from, $to) * 1.5,
            $service->estimatedRouteKm($from, $to),
            0.000001
        );
    }
}
