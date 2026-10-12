<?php

namespace app\tests\Integration;

use app\database\Connection;
use app\domain\repository\RateLimitRepository;

class RateLimitRepositoryTest extends DatabaseTestCase
{
    private RateLimitRepository $repository;

    protected function setUp(): void
    {
        Connection::getConnection()->exec('DELETE FROM rate_limit');
        $this->repository = new RateLimitRepository();
    }

    public function testCountsPerClientAndWindow(): void
    {
        $this->assertSame(1, $this->repository->increment('key:a', 100));
        $this->assertSame(2, $this->repository->increment('key:a', 100));
        $this->assertSame(1, $this->repository->increment('key:b', 100));
        $this->assertSame(1, $this->repository->increment('key:a', 101));
    }

    public function testStoresOnlyTheHashOfTheClientId(): void
    {
        $this->repository->increment('key:secret-api-key', 100);

        $stored = Connection::getConnection()->query('SELECT client_hash FROM rate_limit')->fetchColumn();

        $this->assertSame(hash('sha256', 'key:secret-api-key'), $stored);
    }

    public function testDeletesOnlyOlderWindows(): void
    {
        $this->repository->increment('key:a', 98);
        $this->repository->increment('key:a', 99);
        $this->repository->increment('key:a', 100);

        $this->assertSame(2, $this->repository->deleteWindowsBefore(100));
        $this->assertSame(2, $this->repository->increment('key:a', 100));
    }
}
