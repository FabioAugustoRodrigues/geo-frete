<?php

namespace app\tests\Feature;

use app\core\controller\Request;
use app\core\controller\Router;
use app\exception\RouteNotFoundException;

class HealthFeatureTest extends FeatureTestCase
{
    public function testHealthReportsDatabaseOk(): void
    {
        $response = $this->request('GET', '/api/v1/health');

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']['data']['database']);
        $this->assertNotEmpty($response['body']['data']['server_time']);
        $this->assertSame('', $response['body']['message']);
    }

    public function testUnknownRouteIsNotFound(): void
    {
        $this->expectException(RouteNotFoundException::class);

        Router::dispatch(new Request([], 'GET', '/api/v1/does-not-exist', []));
    }
}
