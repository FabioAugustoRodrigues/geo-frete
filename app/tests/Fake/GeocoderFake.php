<?php

namespace app\tests\Fake;

use app\domain\exception\GeocoderUnavailableException;
use app\domain\geo\GeocoderInterface;
use app\domain\model\Address;
use app\domain\model\Coordinate;
use app\domain\model\GeocodeResult;
use app\domain\model\Precision;

/**
 * Deterministic geocoder for tests: every address maps to a stable point
 * around downtown Limeira, and a few magic street names trigger the other
 * outcomes. State is static because the container builds a new instance
 * per request.
 */
class GeocoderFake implements GeocoderInterface
{
    public const SOURCE = 'fake';

    public const STREET_NOT_FOUND = 'Rua Inexistente';
    public const STREET_UNAVAILABLE = 'Rua Fora do Ar';
    public const STREET_APPROXIMATE = 'Rua Aproximada';

    private const CENTER_LAT = -22.5647;
    private const CENTER_LNG = -47.4017;

    /** @var Address[] */
    private static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    /**
     * @return Address[] every address geocoded since the last reset
     */
    public static function calls(): array
    {
        return self::$calls;
    }

    public function geocode(Address $address, ?Coordinate $near = null): ?GeocodeResult
    {
        self::$calls[] = $address;

        return match ($address->street) {
            self::STREET_NOT_FOUND => null,
            self::STREET_UNAVAILABLE => throw new GeocoderUnavailableException(self::SOURCE),
            default => new GeocodeResult(
                $this->pointFor($address),
                $address->street === self::STREET_APPROXIMATE ? Precision::Street : Precision::Number,
                self::SOURCE
            ),
        };
    }

    /**
     * Spreads addresses up to ~2 km from the center, stable per street/number.
     */
    private function pointFor(Address $address): Coordinate
    {
        $hash = crc32($address->street . '|' . $address->number);

        $latOffset = (($hash % 2000) - 1000) / 50000;
        $lngOffset = ((intdiv($hash, 2000) % 2000) - 1000) / 50000;

        return new Coordinate(self::CENTER_LAT + $latOffset, self::CENTER_LNG + $lngOffset);
    }
}
