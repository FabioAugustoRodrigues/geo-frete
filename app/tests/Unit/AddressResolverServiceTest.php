<?php

namespace app\tests\Unit;

use app\domain\exception\AddressNotFoundException;
use app\domain\geo\AddressNormalizer;
use app\domain\model\Address;
use app\domain\model\Coordinate;
use app\domain\model\GeocodeResult;
use app\domain\model\Precision;
use app\domain\repository\GeocodingCacheRepository;
use app\domain\service\AddressResolverService;
use app\tests\Fake\GeocoderFake;
use PHPUnit\Framework\TestCase;

class AddressResolverServiceTest extends TestCase
{
    private GeocodingCacheRepository $cache;
    private AddressResolverService $resolver;

    protected function setUp(): void
    {
        GeocoderFake::reset();

        $this->cache = new class extends GeocodingCacheRepository {
            /** @var array<string, GeocodeResult> */
            public array $entries = [];

            public function find(string $key, int $maxAgeDays): ?GeocodeResult
            {
                return $this->entries[$key] ?? null;
            }

            public function save(string $key, GeocodeResult $result): void
            {
                $this->entries[$key] = $result;
            }
        };

        $this->resolver = new AddressResolverService(new GeocoderFake(), $this->cache, new AddressNormalizer(), 180);
    }

    public function testClientCoordinatesSkipGeocodingAndCache(): void
    {
        $address = $this->address(coordinate: new Coordinate(-22.5, -47.4));

        $resolved = $this->resolver->resolve($address, 'origin');

        $this->assertSame(Precision::Provided, $resolved->precision);
        $this->assertSame('client', $resolved->source);
        $this->assertSame([], GeocoderFake::calls());
        $this->assertSame([], $this->cache->entries);
    }

    public function testGeocodedResultIsCachedAndReused(): void
    {
        $first = $this->resolver->resolve($this->address(), 'origin');
        $second = $this->resolver->resolve($this->address(street: 'R. Senador Vergueiro'), 'origin');

        $this->assertCount(1, GeocoderFake::calls(), 'the abbreviated street must hit the cache');
        $this->assertEquals($first->coordinate, $second->coordinate);
        $this->assertCount(1, $this->cache->entries);
    }

    public function testNotFoundIsReportedWithTheLabelAndNotCached(): void
    {
        try {
            $this->resolver->resolve($this->address(street: GeocoderFake::STREET_NOT_FOUND), 'destination');
            $this->fail('Expected AddressNotFoundException.');
        } catch (AddressNotFoundException $e) {
            $this->assertSame('destination', $e->getLabel());
        }

        $this->assertSame([], $this->cache->entries);
    }

    private function address(string $street = 'Rua Senador Vergueiro', ?Coordinate $coordinate = null): Address
    {
        return new Address($street, '500', 'Centro', 'Limeira', 'SP', coordinate: $coordinate);
    }
}
