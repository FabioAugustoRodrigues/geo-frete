<?php

namespace app\tests\Feature;

use app\database\Connection;
use app\tests\Fake\GeocoderFake;
use app\tests\Fake\RoutingEngineFake;

class DistanceFeatureTest extends FeatureTestCase
{
    private const URI = '/api/v1/distance';

    protected function setUp(): void
    {
        GeocoderFake::reset();
        RoutingEngineFake::reset();
        Connection::getConnection()->exec('DELETE FROM geocoding_cache');
        Connection::getConnection()->exec('DELETE FROM rate_limit');
    }

    protected function tearDown(): void
    {
        RoutingEngineFake::reset();
    }

    public function testCalculatesTheRouteDistance(): void
    {
        $response = $this->post($this->validRequest());

        $this->assertSame(200, $response['status']);

        $data = $response['body']['data'];
        $this->assertSame('route', $data['method']);
        $this->assertGreaterThan(0, $data['straight_line_km']);
        $this->assertEqualsWithDelta($data['straight_line_km'] * RoutingEngineFake::ROUTE_FACTOR, $data['distance_km'], 0.02);
        $this->assertEqualsWithDelta($data['distance_km'] / RoutingEngineFake::SPEED_KMH * 60, $data['duration_min'], 0.1);
        $this->assertSame('number', $data['origin']['precision']);
        $this->assertSame('fake', $data['origin']['source']);
        $this->assertSame(0.01, $data['origin']['snap_distance_km']);
        $this->assertSame('Rua Senador Vergueiro, 500, Centro, Limeira, SP', $data['origin']['formatted_address']);
        $this->assertFalse($data['requires_confirmation']);
    }

    public function testFallsBackToTheEstimateWhenRoutingIsDown(): void
    {
        RoutingEngineFake::makeUnavailable();

        $response = $this->post($this->validRequest());

        $this->assertSame(200, $response['status']);

        $data = $response['body']['data'];
        $this->assertSame('estimate', $data['method']);
        $this->assertNull($data['duration_min']);
        $this->assertNull($data['origin']['snap_distance_km']);
        $this->assertEqualsWithDelta($data['straight_line_km'] * ROUTE_FACTOR, $data['distance_km'], 0.02);
    }

    public function testPointFarFromAnyRoadRequiresConfirmation(): void
    {
        RoutingEngineFake::snapPointsBy(SNAP_WARNING_KM + 0.1);

        $response = $this->post($this->validRequest());

        $this->assertSame('number', $response['body']['data']['origin']['precision']);
        $this->assertTrue($response['body']['data']['requires_confirmation']);
    }

    public function testClientCoordinatesAreUsedWithoutGeocoding(): void
    {
        $request = $this->validRequest();
        $request['origin'] += ['lat' => -22.5647, 'lng' => -47.4017];
        $request['destination'] += ['lat' => -22.5800, 'lng' => -47.4100];

        $response = $this->post($request);

        $this->assertSame(200, $response['status']);
        $this->assertSame('provided', $response['body']['data']['origin']['precision']);
        $this->assertSame([], GeocoderFake::calls());
    }

    public function testStreetLevelPrecisionRequiresConfirmation(): void
    {
        $request = $this->validRequest();
        $request['destination']['street'] = GeocoderFake::STREET_APPROXIMATE;

        $response = $this->post($request);

        $this->assertSame('street', $response['body']['data']['destination']['precision']);
        $this->assertTrue($response['body']['data']['requires_confirmation']);
    }

    public function testSecondRequestIsServedFromCache(): void
    {
        $this->post($this->validRequest());
        $this->post($this->validRequest());

        $this->assertCount(2, GeocoderFake::calls(), 'only the first request should geocode origin and destination');
    }

    public function testValidationErrorsReturn400(): void
    {
        $response = $this->post(['origin' => ['street' => 'Rua X']]);

        $this->assertSame(400, $response['status']);
        $this->assertSame('Invalid data.', $response['body']['message']);
        $this->assertContains("The field 'destination' is required.", $response['body']['data']['errors']);
    }

    public function testAddressNotFoundReturns422(): void
    {
        $request = $this->validRequest();
        $request['destination']['street'] = GeocoderFake::STREET_NOT_FOUND;

        $response = $this->post($request);

        $this->assertSame(422, $response['status']);
        $this->assertSame(['reason' => 'not_found', 'address' => 'destination'], $response['body']['data']);
    }

    public function testUnavailableGeocoderReturns503(): void
    {
        $request = $this->validRequest();
        $request['origin']['street'] = GeocoderFake::STREET_UNAVAILABLE;

        $response = $this->post($request);

        $this->assertSame(503, $response['status']);
        $this->assertNull($response['body']['data']);
    }

    public function testMissingApiKeyReturns401(): void
    {
        $response = $this->request('POST', self::URI, $this->validRequest());

        $this->assertSame(401, $response['status']);
        $this->assertSame('Missing API key.', $response['body']['message']);
    }

    public function testInvalidApiKeyReturns401(): void
    {
        $response = $this->post($this->validRequest(), 'wrong-key');

        $this->assertSame(401, $response['status']);
        $this->assertSame('Invalid API key.', $response['body']['message']);
    }

    public function testRateLimitReturns429(): void
    {
        $statement = Connection::getConnection()->prepare(
            'INSERT INTO rate_limit (client_hash, window_minute, total) VALUES (:hash, :window, :total)'
        );
        $statement->execute([
            ':hash' => hash('sha256', 'key:other-key'),
            ':window' => intdiv(time(), 60),
            ':total' => RATE_LIMIT_PER_MINUTE,
        ]);

        $limited = $this->post($this->validRequest(), 'other-key');
        $otherClient = $this->post($this->validRequest(), 'test-key');

        $this->assertSame(429, $limited['status']);
        $this->assertSame(200, $otherClient['status']);
    }

    private function post(array $data, string $key = 'test-key'): array
    {
        return $this->request('POST', self::URI, $data, ['Authorization' => "Bearer {$key}"]);
    }

    private function validRequest(): array
    {
        return [
            'origin' => [
                'street' => 'Rua Senador Vergueiro',
                'number' => '500',
                'neighborhood' => 'Centro',
                'city' => 'Limeira',
                'state' => 'SP',
            ],
            'destination' => [
                'street' => 'Rua Tiradentes',
                'number' => '1200',
                'neighborhood' => 'Vila Cristovam',
                'city' => 'Limeira',
                'state' => 'SP',
            ],
        ];
    }
}
