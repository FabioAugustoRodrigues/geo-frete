<?php

namespace app\tests\Fixture;

use app\database\Connection;
use app\domain\repository\CnefeRepository;

/**
 * A tiny fictional municipality loaded into the CNEFE tables. Coordinates
 * are chosen so interpolation results are easy to check by hand.
 */
final class CnefeFixture
{
    public const CITY_CODE = 3599999;
    public const CITY = 'Testópolis';
    public const STATE = 'SP';

    public static function load(): void
    {
        (new CnefeRepository())->replaceCity(
            ['city_code' => self::CITY_CODE, 'state' => self::STATE, 'name' => self::CITY, 'name_normalized' => 'testopolis'],
            [
                self::street('rua', 'Rua Senador Vergueiro', 'senador vergueiro', [
                    100 => [-22.5600, -47.4000],
                    120 => [-22.5602, -47.4002],
                    121 => [-22.5700, -47.4100],
                    300 => [-22.5620, -47.4020],
                ]),
                self::street('rua', 'Rua Antonio Conselheiro', 'antonio conselheiro', [10 => [-22.5800, -47.4200]]),
                self::street('avenida', 'Avenida Antonio Conselheiro', 'antonio conselheiro', [10 => [-22.5900, -47.4300]]),
                self::street('rua', 'Rua Barao de Campinas', 'barao campinas', [50 => [-22.5500, -47.3900]]),
            ]
        );
    }

    public static function clear(): void
    {
        $pdo = Connection::getConnection();
        $pdo->exec('DELETE FROM cnefe_addresses');
        $pdo->exec('DELETE FROM cnefe_streets');
        $pdo->exec('DELETE FROM cnefe_city_bounds');
        $pdo->exec('DELETE FROM cnefe_cities');
    }

    /**
     * @param array<int, array{0: float, 1: float}> $points number => [lat, lng]
     */
    private static function street(string $type, string $name, string $normalized, array $points): array
    {
        $addresses = [];
        foreach ($points as $number => [$lat, $lng]) {
            $addresses[$number] = ['lat' => $lat, 'lng' => $lng, 'geo_level' => 1, 'postal_code' => '13480001'];
        }

        return [
            'street_type' => $type,
            'name' => $name,
            'name_normalized' => $normalized,
            'neighborhood' => 'Centro',
            'addresses' => $addresses,
        ];
    }
}
