<?php

namespace app\tests\Unit;

use app\domain\exception\AddressNotFoundException;
use app\domain\exception\AmbiguousStreetException;
use app\domain\exception\GeocoderUnavailableException;
use app\domain\geo\AddressNormalizer;
use app\domain\geo\GeocoderInterface;
use app\domain\model\Address;
use app\domain\model\Coordinate;
use app\domain\model\GeocodeResult;
use app\domain\model\Precision;
use app\domain\model\StreetMatch;
use app\domain\repository\CnefeRepository;
use app\domain\repository\GeocodingCacheRepository;
use app\domain\service\AddressResolverService;
use app\domain\service\StreetMatcherService;
use app\tests\Fake\GeocoderFake;
use PHPUnit\Framework\TestCase;

class AddressResolverServiceTest extends TestCase
{
    private GeocodingCacheRepository $cache;
    private GeocoderInterface $local;
    private StreetMatcherService $matcher;

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

        // Local base answering with a configurable precision (null = not found).
        $this->local = new class implements GeocoderInterface {
            public ?Precision $precision = null;
            public array $calls = [];

            public function geocode(Address $address, ?Coordinate $near = null): ?GeocodeResult
            {
                $this->calls[] = $address;

                return $this->precision === null
                    ? null
                    : new GeocodeResult(new Coordinate(-22.1, -47.1), $this->precision, 'local');
            }
        };

        $this->matcher = $this->matcherReturning(StreetMatch::noBase());
    }

    public function testClientCoordinatesSkipEverything(): void
    {
        $resolved = $this->resolver()->resolve($this->address(coordinate: new Coordinate(-22.5, -47.4)), 'origin');

        $this->assertSame(Precision::Provided, $resolved->precision);
        $this->assertSame('client', $resolved->source);
        $this->assertSame([], $this->local->calls);
        $this->assertSame([], GeocoderFake::calls());
    }

    public function testPreciseLocalAnswerSkipsCacheAndRemote(): void
    {
        $this->local->precision = Precision::Interpolated;

        $resolved = $this->resolver()->resolve($this->address(), 'origin');

        $this->assertSame('local', $resolved->source);
        $this->assertSame([], GeocoderFake::calls());
        $this->assertSame([], $this->cache->entries, 'local answers are not cached: the base itself is local');
    }

    public function testRoughLocalAnswerLosesToAMorePreciseRemoteOne(): void
    {
        $this->local->precision = Precision::Street;

        $resolved = $this->resolver()->resolve($this->address(), 'origin');

        $this->assertSame(Precision::Number, $resolved->precision);
        $this->assertSame('fake', $resolved->source);
    }

    public function testRoughLocalAnswerWinsTiesAndRemoteOutages(): void
    {
        $this->local->precision = Precision::Street;

        $tie = $this->resolver()->resolve($this->address(street: GeocoderFake::STREET_APPROXIMATE), 'origin');
        $outage = $this->resolver()->resolve($this->address(street: GeocoderFake::STREET_UNAVAILABLE), 'origin');

        $this->assertSame('local', $tie->source);
        $this->assertSame('local', $outage->source);
    }

    public function testRemoteOutageWithoutLocalAnswerPropagates(): void
    {
        $this->expectException(GeocoderUnavailableException::class);

        $this->resolver()->resolve($this->address(street: GeocoderFake::STREET_UNAVAILABLE), 'origin');
    }

    public function testRemoteResultIsCachedAndReused(): void
    {
        $first = $this->resolver()->resolve($this->address(), 'origin');
        $second = $this->resolver()->resolve($this->address(street: 'R. Senador Vergueiro'), 'origin');

        $this->assertCount(1, GeocoderFake::calls(), 'the abbreviated street must hit the cache');
        $this->assertEquals($first->coordinate, $second->coordinate);
    }

    public function testCorrectedStreetIsGeocodedAndReported(): void
    {
        $this->matcher = $this->matcherReturning(StreetMatch::corrected('Rua Senador Vergueiro', 0.94));

        $resolved = $this->resolver()->resolve($this->address(street: 'Senador Vergeiro'), 'origin');

        $this->assertSame('Rua Senador Vergueiro', GeocoderFake::calls()[0]->street);
        $this->assertSame('Rua Senador Vergueiro', $resolved->streetUsed);
        $this->assertTrue($resolved->streetCorrected);
        $this->assertStringStartsWith('Rua Senador Vergueiro, 500', $resolved->formattedAddress);
    }

    public function testAmbiguousStreetAsksTheUser(): void
    {
        $suggestions = [['name' => 'Rua X', 'score' => 1.0], ['name' => 'Avenida X', 'score' => 1.0]];
        $this->matcher = $this->matcherReturning(StreetMatch::ambiguous($suggestions));

        try {
            $this->resolver()->resolve($this->address(street: 'X'), 'destination');
            $this->fail('Expected AmbiguousStreetException.');
        } catch (AmbiguousStreetException $e) {
            $this->assertSame('destination', $e->getLabel());
            $this->assertSame($suggestions, $e->getSuggestions());
        }
    }

    public function testConfirmedStreetSkipsMatching(): void
    {
        $this->matcher = $this->matcherReturning(StreetMatch::ambiguous([]));

        $resolved = $this->resolver()->resolve($this->address(street: 'X', confirmed: true), 'origin');

        $this->assertSame('X', $resolved->streetUsed);
        $this->assertFalse($resolved->streetCorrected);
    }

    public function testNotFoundAnywhere(): void
    {
        $this->expectException(AddressNotFoundException::class);

        $this->resolver()->resolve($this->address(street: GeocoderFake::STREET_NOT_FOUND), 'origin');
    }

    private function resolver(): AddressResolverService
    {
        return new AddressResolverService($this->local, new GeocoderFake(), $this->matcher, $this->cache, new AddressNormalizer(), 180);
    }

    private function matcherReturning(StreetMatch $match): StreetMatcherService
    {
        return new class ($match) extends StreetMatcherService {
            public function __construct(private StreetMatch $match)
            {
                parent::__construct(new CnefeRepository(), new AddressNormalizer());
            }

            public function match(string $street, string $city, string $state): StreetMatch
            {
                return $this->match;
            }
        };
    }

    private function address(
        string $street = 'Rua Senador Vergueiro',
        ?Coordinate $coordinate = null,
        bool $confirmed = false
    ): Address {
        return new Address($street, '500', 'Centro', 'Limeira', 'SP', coordinate: $coordinate, streetConfirmed: $confirmed);
    }
}
