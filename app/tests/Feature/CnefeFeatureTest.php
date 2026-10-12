<?php

namespace app\tests\Feature;

use app\database\Connection;
use app\tests\Fake\GeocoderFake;
use app\tests\Fake\RoutingEngineFake;
use app\tests\Fixture\CnefeFixture;

class CnefeFeatureTest extends FeatureTestCase
{
    private const HEADERS = ['Authorization' => 'Bearer test-key'];

    protected function setUp(): void
    {
        GeocoderFake::reset();
        RoutingEngineFake::reset();
        Connection::getConnection()->exec('DELETE FROM geocoding_cache');
        Connection::getConnection()->exec('DELETE FROM rate_limit');
        CnefeFixture::clear();
        CnefeFixture::load();
    }

    protected function tearDown(): void
    {
        CnefeFixture::clear();
    }

    public function testAddressesInTheLocalBaseNeverReachTheRemoteGeocoder(): void
    {
        $response = $this->distance(
            $this->address('Rua Senador Vergueiro', '110'),
            $this->address('Rua Senador Vergueiro', '300')
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame('interpolated', $response['body']['data']['origin']['precision']);
        $this->assertSame('number', $response['body']['data']['destination']['precision']);
        $this->assertSame('cnefe', $response['body']['data']['origin']['source']);
        $this->assertFalse($response['body']['data']['requires_confirmation']);
        $this->assertSame([], GeocoderFake::calls());
    }

    public function testTypoInTheStreetIsCorrected(): void
    {
        $response = $this->distance(
            $this->address('R. Senador Vergeiro', '120'),
            $this->address('Rua Senador Vergueiro', '300')
        );

        $origin = $response['body']['data']['origin'];
        $this->assertSame(200, $response['status']);
        $this->assertSame('Rua Senador Vergueiro', $origin['street_used']);
        $this->assertTrue($origin['street_corrected']);
        $this->assertSame('number', $origin['precision']);
    }

    public function testAmbiguousStreetReturns422WithSuggestions(): void
    {
        $response = $this->distance(
            $this->address('Rua Senador Vergueiro', '120'),
            $this->address('Antonio Conselheiro', '10')
        );

        $this->assertSame(422, $response['status']);
        $this->assertSame('ambiguous_street', $response['body']['data']['reason']);
        $this->assertSame('destination', $response['body']['data']['address']);
        $this->assertEqualsCanonicalizing(
            ['Rua Antonio Conselheiro', 'Avenida Antonio Conselheiro'],
            array_column($response['body']['data']['suggestions'], 'name')
        );
    }

    public function testConfirmedSuggestionIsAccepted(): void
    {
        $response = $this->distance(
            $this->address('Rua Senador Vergueiro', '120'),
            $this->address('Avenida Antonio Conselheiro', '10', ['street_confirmed' => true])
        );

        $this->assertSame(200, $response['status']);
        $this->assertEqualsWithDelta(-22.59, $response['body']['data']['destination']['lat'], 0.0000001);
    }

    public function testStreetMissingFromTheBaseFallsBackToTheRemoteGeocoder(): void
    {
        $response = $this->distance(
            $this->address('Rua Senador Vergueiro', '120'),
            $this->address('Av. Campinas', '1000')
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame('fake', $response['body']['data']['destination']['source']);
        $this->assertFalse($response['body']['data']['destination']['street_corrected']);
    }

    public function testSuggestionsEndpoint(): void
    {
        $response = $this->request('GET', '/api/v1/streets/suggestions', [
            'street' => 'senador verg', 'city' => CnefeFixture::CITY, 'state' => 'SP', 'limit' => '3',
        ], self::HEADERS);

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['data']['available']);
        $this->assertSame('Rua Senador Vergueiro', $response['body']['data']['suggestions'][0]['name']);
    }

    public function testSuggestionsForACityWithoutBase(): void
    {
        $response = $this->request('GET', '/api/v1/streets/suggestions', [
            'street' => 'paulista', 'city' => 'Campinas', 'state' => 'SP',
        ], self::HEADERS);

        $this->assertSame(200, $response['status']);
        $this->assertFalse($response['body']['data']['available']);
        $this->assertSame([], $response['body']['data']['suggestions']);
    }

    public function testSuggestionsValidation(): void
    {
        $response = $this->request('GET', '/api/v1/streets/suggestions', ['street' => 'x', 'limit' => '50'], self::HEADERS);

        $this->assertSame(400, $response['status']);
        $this->assertContains("The field 'city' is required.", $response['body']['data']['errors']);
        $this->assertContains("The field 'limit' must be an integer between 1 and 10.", $response['body']['data']['errors']);
    }

    public function testSuggestionsRequireAnApiKey(): void
    {
        $response = $this->request('GET', '/api/v1/streets/suggestions', ['street' => 'x', 'city' => 'y', 'state' => 'SP']);

        $this->assertSame(401, $response['status']);
    }

    private function distance(array $origin, array $destination): array
    {
        return $this->request('POST', '/api/v1/distance', ['origin' => $origin, 'destination' => $destination], self::HEADERS);
    }

    private function address(string $street, string $number, array $extra = []): array
    {
        return [
            'street' => $street,
            'number' => $number,
            'neighborhood' => 'Centro',
            'city' => CnefeFixture::CITY,
            'state' => CnefeFixture::STATE,
        ] + $extra;
    }
}
