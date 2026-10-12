<?php

namespace app\tests\Unit;

use app\domain\service\RouteFactorStudyService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RouteFactorStudyServiceTest extends TestCase
{
    private RouteFactorStudyService $study;

    protected function setUp(): void
    {
        $this->study = new RouteFactorStudyService();
    }

    public function testRatiosAndEstimateErrors(): void
    {
        $summary = $this->study->summarize([
            ['straight_km' => 1.0, 'route_km' => 1.2],
            ['straight_km' => 2.0, 'route_km' => 2.6],
            ['straight_km' => 1.0, 'route_km' => 1.6],
        ], 1.3);

        $this->assertSame(3, $summary['pairs']);
        $this->assertSame(['min' => 1.2, 'median' => 1.3, 'mean' => 1.367, 'p90' => 1.54, 'max' => 1.6], $summary['ratio']);

        // Estimates: 1.3 (+8.3%), 2.6 (0%), 1.3 (-18.75%).
        $this->assertSame(-18.8, $summary['estimate_error_pct']['max_under']);
        $this->assertSame(8.3, $summary['estimate_error_pct']['max_over']);
        $this->assertSame(8.3, $summary['estimate_error_pct']['median_abs']);
        $this->assertSame(66.7, $summary['within_10_pct']);

        // (1*1.2 + 2*2.6 + 1*1.6) / (1 + 4 + 1) = 8.0 / 6
        $this->assertSame(1.333, $summary['best_factor']);
    }

    public function testIgnoresPairsTooCloseToMeasure(): void
    {
        $summary = $this->study->summarize([
            ['straight_km' => 0.05, 'route_km' => 0.4],
            ['straight_km' => 1.0, 'route_km' => 1.3],
        ], 1.3);

        $this->assertSame(1, $summary['pairs']);
        $this->assertSame(1.3, $summary['ratio']['max']);
    }

    public function testNeedsAtLeastOneUsablePair(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->study->summarize([['straight_km' => 0.1, 'route_km' => 0.2]], 1.3);
    }
}
