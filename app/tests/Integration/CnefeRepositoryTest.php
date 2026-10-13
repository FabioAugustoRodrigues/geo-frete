<?php

namespace app\tests\Integration;

use app\domain\repository\CnefeRepository;
use app\tests\Fixture\CnefeFixture;

class CnefeRepositoryTest extends DatabaseTestCase
{
    private CnefeRepository $repository;

    protected function setUp(): void
    {
        CnefeFixture::clear();
        $this->repository = new CnefeRepository();
    }

    protected function tearDown(): void
    {
        CnefeFixture::clear();
    }

    public function testListsImportedCitiesWithTheirBounds(): void
    {
        CnefeFixture::load();

        $cities = $this->repository->listCities();

        $this->assertCount(1, $cities);
        $city = $cities[0];
        $this->assertSame(CnefeFixture::CITY_CODE, $city['city_code']);
        $this->assertSame('SP', $city['state']);
        $this->assertSame(CnefeFixture::CITY, $city['name']);
        $this->assertSame(4, $city['street_count']);
        $this->assertSame(7, $city['address_count']);

        // The fixture's points span -22.59..-22.55 and -47.43..-47.39.
        $this->assertEqualsWithDelta(
            ['south' => -22.59, 'west' => -47.43, 'north' => -22.55, 'east' => -47.39],
            $city['bounds'],
            0.0000001
        );
    }

    public function testReimportReplacesTheBoundsInsteadOfDuplicatingThem(): void
    {
        CnefeFixture::load();
        CnefeFixture::load();

        $this->assertCount(1, $this->repository->listCities());
    }

    public function testNoCities(): void
    {
        $this->assertSame([], $this->repository->listCities());
    }
}
