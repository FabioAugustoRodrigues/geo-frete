<?php

namespace app\tests\Unit;

use app\domain\exception\GeocoderUnavailableException;
use app\domain\geo\AddressNormalizer;
use app\domain\geo\HttpClient;
use app\domain\geo\NominatimGeocoder;
use app\domain\model\Address;
use app\domain\model\Coordinate;
use app\domain\model\Precision;
use app\domain\repository\ThrottleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Responses below mirror real Nominatim answers for Limeira/SP (trimmed).
 */
class NominatimGeocoderTest extends TestCase
{
    private const HOUSE = [
        'lat' => '-22.5662', 'lon' => '-47.4049', 'place_rank' => 30,
        'address' => ['house_number' => '500', 'road' => 'Rua Senador Vergueiro', 'city' => 'Limeira'],
    ];

    private const ROAD = [
        'lat' => '-22.5670678', 'lon' => '-47.4058422', 'place_rank' => 26,
        'address' => ['road' => 'Rua Senador Vergueiro', 'city' => 'Limeira'],
    ];

    private const NEIGHBORHOOD = [
        'lat' => '-22.5641', 'lon' => '-47.4012', 'place_rank' => 20,
        'address' => ['suburb' => 'Centro', 'city' => 'Limeira'],
    ];

    private HttpClient $http;
    private NominatimGeocoder $geocoder;

    protected function setUp(): void
    {
        $this->http = new class (1, 'test', '') extends HttpClient {
            /** @var array<int, array|null> null simulates a network failure */
            public array $responses = [];
            public array $queries = [];

            public function getJson(string $url, array $query = [], ?int $timeoutSeconds = null): ?array
            {
                $this->queries[] = $query;

                return array_shift($this->responses);
            }
        };

        $noThrottle = new class extends ThrottleRepository {
            public function waitForTurn(string $provider): void
            {
            }
        };

        $this->geocoder = new NominatimGeocoder($this->http, $noThrottle, new AddressNormalizer(), 'https://nominatim.test');
    }

    public function testHouseNumberInTheAnswerMeansNumberPrecision(): void
    {
        $this->http->responses = [[self::HOUSE]];

        $result = $this->geocoder->geocode($this->address());

        $this->assertSame(Precision::Number, $result->precision);
        $this->assertSame(-22.5662, $result->coordinate->lat);
        $this->assertSame('nominatim', $result->source);
        $this->assertCount(1, $this->http->queries);
    }

    public function testBareStreetAnswerToANumberSearchIsOnlyStreetPrecision(): void
    {
        $this->http->responses = [[self::ROAD]];

        $result = $this->geocoder->geocode($this->address());

        $this->assertSame(Precision::Street, $result->precision);
    }

    public function testFallsBackToTheNeighborhood(): void
    {
        $this->http->responses = [[], [self::NEIGHBORHOOD]];

        $result = $this->geocoder->geocode($this->address());

        $this->assertSame(Precision::Neighborhood, $result->precision);
        $this->assertSame('Centro, Limeira, São Paulo', $this->http->queries[1]['q']);
    }

    public function testStreetFoundInAnotherCityIsIgnored(): void
    {
        $otherCity = self::ROAD;
        $otherCity['address']['city'] = 'Americana';
        $this->http->responses = [[$otherCity], [self::NEIGHBORHOOD]];

        $result = $this->geocoder->geocode($this->address());

        $this->assertSame(Precision::Neighborhood, $result->precision);
    }

    public function testCityLevelAnswerIsNotANeighborhood(): void
    {
        $city = ['lat' => '-22.56', 'lon' => '-47.40', 'place_rank' => 16, 'address' => ['city' => 'Limeira']];
        $this->http->responses = [[], [$city]];

        $this->assertNull($this->geocoder->geocode($this->address()));
    }

    public function testNotFoundWhenNominatimAnswersEmpty(): void
    {
        $this->http->responses = [[], []];

        $this->assertNull($this->geocoder->geocode($this->address()));
    }

    public function testUnavailableWhenEveryRequestFails(): void
    {
        $this->http->responses = [null, null];

        $this->expectException(GeocoderUnavailableException::class);

        $this->geocoder->geocode($this->address());
    }

    public function testOneFailedRequestIsNotUnavailabilityIfTheOtherAnswered(): void
    {
        $this->http->responses = [null, []];

        $this->assertNull($this->geocoder->geocode($this->address()));
    }

    public function testSendsStructuredQueryWithStateNameAndNearbyPreference(): void
    {
        $this->http->responses = [[self::HOUSE]];

        $this->geocoder->geocode($this->address(), new Coordinate(-22.5, -47.4));

        $query = $this->http->queries[0];
        $this->assertSame('500 Rua Senador Vergueiro', $query['street']);
        $this->assertSame('São Paulo', $query['state']);
        $this->assertSame('13480001', $query['postalcode']);
        $this->assertArrayHasKey('viewbox', $query);
        $this->assertArrayNotHasKey('bounded', $query);
    }

    private function address(): Address
    {
        return new Address('Rua Senador Vergueiro', '500', 'Centro', 'Limeira', 'SP', '13480001');
    }
}
