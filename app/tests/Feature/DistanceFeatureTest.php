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

    public function testNoGeometryUnlessRequested(): void
    {
        $response = $this->post($this->validRequest());

        $this->assertArrayNotHasKey('geometry', $response['body']['data']);
    }

    public function testGeometryIsAGeoJsonLineFromOriginToDestination(): void
    {
        $response = $this->post($this->validRequest() + ['include_geometry' => true]);

        $this->assertSame(200, $response['status']);

        $data = $response['body']['data'];
        $this->assertSame('LineString', $data['geometry']['type']);
        $this->assertCount(3, $data['geometry']['coordinates']);

        // GeoJSON order is [lng, lat]: the first point is the origin.
        [$lng, $lat] = $data['geometry']['coordinates'][0];
        $this->assertEqualsWithDelta($data['origin']['lng'], $lng, 0.000001);
        $this->assertEqualsWithDelta($data['origin']['lat'], $lat, 0.000001);

        [$lng, $lat] = $data['geometry']['coordinates'][2];
        $this->assertEqualsWithDelta($data['destination']['lng'], $lng, 0.000001);
    }

    public function testEstimateHasNullGeometry(): void
    {
        RoutingEngineFake::makeUnavailable();

        $response = $this->post($this->validRequest() + ['include_geometry' => true]);

        $this->assertSame('estimate', $response['body']['data']['method']);
        $this->assertArrayHasKey('geometry', $response['body']['data']);
        $this->assertNull($response['body']['data']['geometry']);
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
        $this->exhaustQuota('key:other-key|ip:203.0.113.1');

        $limited = $this->postFrom('203.0.113.1', 'other-key');
        $otherKey = $this->postFrom('203.0.113.1', 'test-key');

        $this->assertSame(429, $limited['status']);
        $this->assertSame(200, $otherKey['status']);
    }

    /**
     * A key published in a web page is shared by all its visitors: one of
     * them must not exhaust the quota of the others.
     */
    public function testSameKeyFromAnotherIpHasItsOwnQuota(): void
    {
        $this->exhaustQuota('key:other-key|ip:203.0.113.1');

        $otherVisitor = $this->postFrom('203.0.113.2', 'other-key');

        $this->assertSame(200, $otherVisitor['status']);
    }

    private function exhaustQuota(string $clientId): void
    {
        $statement = Connection::getConnection()->prepare(
            'INSERT INTO rate_limit (client_hash, window_minute, total) VALUES (:hash, :window, :total)'
        );
        $statement->execute([
            ':hash' => hash('sha256', $clientId),
            ':window' => intdiv(time(), 60),
            ':total' => RATE_LIMIT_PER_MINUTE,
        ]);
    }

    private function postFrom(string $ip, string $key): array
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $ip;

        try {
            return $this->post($this->validRequest(), $key);
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
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
