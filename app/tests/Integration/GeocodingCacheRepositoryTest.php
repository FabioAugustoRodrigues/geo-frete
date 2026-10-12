<?php

namespace app\tests\Integration;

use app\database\Connection;
use app\domain\model\Coordinate;
use app\domain\model\GeocodeResult;
use app\domain\model\Precision;
use app\domain\repository\GeocodingCacheRepository;

class GeocodingCacheRepositoryTest extends DatabaseTestCase
{
    private GeocodingCacheRepository $repository;

    protected function setUp(): void
    {
        Connection::getConnection()->exec('DELETE FROM geocoding_cache');
        $this->repository = new GeocodingCacheRepository();
    }

    public function testSavedResultIsFound(): void
    {
        $this->repository->save('SP|limeira|senador vergueiro|500', $this->result(-22.5662, Precision::Number));

        $found = $this->repository->find('SP|limeira|senador vergueiro|500', 180);

        $this->assertSame(-22.5662, $found->coordinate->lat);
        $this->assertSame(-47.4049, $found->coordinate->lng);
        $this->assertSame(Precision::Number, $found->precision);
        $this->assertSame('nominatim', $found->source);
    }

    public function testUnknownKeyIsNull(): void
    {
        $this->assertNull($this->repository->find('SP|limeira|unknown|1', 180));
    }

    public function testSavingTheSameKeyOverwrites(): void
    {
        $this->repository->save('key', $this->result(-22.1, Precision::Street));
        $this->repository->save('key', $this->result(-22.2, Precision::Number));

        $found = $this->repository->find('key', 180);

        $this->assertSame(-22.2, $found->coordinate->lat);
        $this->assertSame(Precision::Number, $found->precision);
    }

    public function testExpiredEntryIsIgnored(): void
    {
        $this->repository->save('key', $this->result(-22.1, Precision::Number));
        Connection::getConnection()->exec('UPDATE geocoding_cache SET created_at = DATE_SUB(NOW(), INTERVAL 10 DAY)');

        $this->assertNull($this->repository->find('key', 5));
        $this->assertNotNull($this->repository->find('key', 30));
    }

    private function result(float $lat, Precision $precision): GeocodeResult
    {
        return new GeocodeResult(new Coordinate($lat, -47.4049), $precision, 'nominatim');
    }
}
