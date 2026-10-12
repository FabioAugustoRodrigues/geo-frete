<?php

namespace app\tests\Feature;

use app\core\controller\Request;
use app\core\controller\Router;
use app\exception\RouteNotFoundException;
use app\tests\Fake\RoutingEngineFake;

class HealthFeatureTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        RoutingEngineFake::reset();
    }

    public function testHealthReportsDatabaseAndRoutingOk(): void
    {
        $response = $this->request('GET', '/api/v1/health');

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']['data']['database']);
        $this->assertSame('ok', $response['body']['data']['routing_engine']);
        $this->assertNotEmpty($response['body']['data']['server_time']);
        $this->assertSame('', $response['body']['message']);
    }

    public function testRoutingEngineDownDegradesButStaysHealthy(): void
    {
        RoutingEngineFake::makeUnavailable();

        $response = $this->request('GET', '/api/v1/health');

        $this->assertSame(200, $response['status']);
        $this->assertSame('unavailable', $response['body']['data']['routing_engine']);
    }

    public function testUnknownRouteIsNotFound(): void
    {
        $this->expectException(RouteNotFoundException::class);

        Router::dispatch(new Request([], 'GET', '/api/v1/does-not-exist', []));
    }
}
