<?php

namespace app\tests\Integration;

use app\database\Connection;
use app\domain\repository\ThrottleRepository;

class ThrottleRepositoryTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        Connection::getConnection()->exec('DELETE FROM provider_throttle');
    }

    public function testFirstCallDoesNotWait(): void
    {
        $start = microtime(true);

        (new ThrottleRepository())->waitForTurn('test-provider');

        $this->assertLessThan(0.5, microtime(true) - $start);
    }

    public function testSecondCallWaitsForTheMinimumInterval(): void
    {
        $repository = new ThrottleRepository();
        $repository->waitForTurn('test-provider');

        $start = microtime(true);
        $repository->waitForTurn('test-provider');

        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $start);
    }

    public function testProvidersAreThrottledIndependently(): void
    {
        $repository = new ThrottleRepository();
        $repository->waitForTurn('provider-a');

        $start = microtime(true);
        $repository->waitForTurn('provider-b');

        $this->assertLessThan(0.5, microtime(true) - $start);
    }
}
