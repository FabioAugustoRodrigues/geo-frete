<?php

namespace app\tests\Integration;

use app\domain\geo\AddressNormalizer;
use app\domain\geo\CnefeGeocoder;
use app\domain\model\Address;
use app\domain\model\Precision;
use app\domain\repository\CnefeRepository;
use app\tests\Fixture\CnefeFixture;

/**
 * Runs against the real database because the neighbor lookups are SQL.
 */
class CnefeGeocoderTest extends DatabaseTestCase
{
    private CnefeGeocoder $geocoder;

    protected function setUp(): void
    {
        CnefeFixture::clear();
        CnefeFixture::load();
        $this->geocoder = new CnefeGeocoder(new CnefeRepository(), new AddressNormalizer());
    }

    protected function tearDown(): void
    {
        CnefeFixture::clear();
    }

    public function testExactNumber(): void
    {
        $result = $this->geocoder->geocode($this->address('Rua Senador Vergueiro', '120'));

        $this->assertSame(Precision::Number, $result->precision);
        $this->assertSame('cnefe', $result->source);
        $this->assertEqualsWithDelta(-22.5602, $result->coordinate->lat, 0.0000001);
    }

    public function testSuffixesAndAccentsDoNotMatter(): void
    {
        $result = $this->geocoder->geocode($this->address('R. SENADOR VERGUEIRO', '120 fundos', 'TESTOPOLIS'));

        $this->assertSame(Precision::Number, $result->precision);
    }

    public function testInterpolatesBetweenSameSideNeighbors(): void
    {
        // 110 is even: interpolated between 100 and 120, ignoring odd 121 on the other side.
        $result = $this->geocoder->geocode($this->address('Rua Senador Vergueiro', '110'));

        $this->assertSame(Precision::Interpolated, $result->precision);
        $this->assertEqualsWithDelta(-22.5601, $result->coordinate->lat, 0.0000001);
        $this->assertEqualsWithDelta(-47.4001, $result->coordinate->lng, 0.0000001);
    }

    public function testInterpolatesProportionallyToTheNumber(): void
    {
        // 200 lies 80/180 of the way from 120 to 300.
        $result = $this->geocoder->geocode($this->address('Rua Senador Vergueiro', '200'));

        $this->assertSame(Precision::Interpolated, $result->precision);
        $this->assertEqualsWithDelta(-22.5602 + (-0.0018 * 80 / 180), $result->coordinate->lat, 0.0000001);
    }

    public function testUsesACloseNeighborPastTheLastKnownNumber(): void
    {
        $result = $this->geocoder->geocode($this->address('Rua Senador Vergueiro', '310'));

        $this->assertSame(Precision::Interpolated, $result->precision);
        $this->assertEqualsWithDelta(-22.5620, $result->coordinate->lat, 0.0000001);
    }

    public function testFarFromAnyKnownNumberIsOnlyStreetPrecision(): void
    {
        $result = $this->geocoder->geocode($this->address('Rua Senador Vergueiro', '900'));

        $this->assertSame(Precision::Street, $result->precision);
    }

    public function testNoNumberIsStreetPrecision(): void
    {
        $result = $this->geocoder->geocode($this->address('Rua Senador Vergueiro', 'S/N'));

        $this->assertSame(Precision::Street, $result->precision);
    }

    public function testTypedStreetTypeChoosesBetweenSameNamedStreets(): void
    {
        $avenue = $this->geocoder->geocode($this->address('Av. Antonio Conselheiro', '10'));
        $street = $this->geocoder->geocode($this->address('Rua Antonio Conselheiro', '10'));

        $this->assertEqualsWithDelta(-22.5900, $avenue->coordinate->lat, 0.0000001);
        $this->assertEqualsWithDelta(-22.5800, $street->coordinate->lat, 0.0000001);
    }

    public function testUnknownStreetOrCityIsNull(): void
    {
        $this->assertNull($this->geocoder->geocode($this->address('Rua Inexistente', '10')));
        $this->assertNull($this->geocoder->geocode($this->address('Rua Senador Vergueiro', '100', 'Campinas')));
    }

    public function testReimportReplacesThePreviousData(): void
    {
        CnefeFixture::load();

        $result = $this->geocoder->geocode($this->address('Rua Senador Vergueiro', '120'));

        $this->assertSame(Precision::Number, $result->precision);
    }

    private function address(string $street, string $number, string $city = CnefeFixture::CITY): Address
    {
        return new Address($street, $number, 'Centro', $city, CnefeFixture::STATE);
    }
}
