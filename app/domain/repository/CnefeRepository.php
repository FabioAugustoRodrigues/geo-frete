<?php

namespace app\domain\repository;

use app\database\Connection;
use PDO;
use Throwable;

/**
 * Local address base built from IBGE's CNEFE (see database/schema.sql).
 */
class CnefeRepository extends RepositoryAbstract
{
    /** Rows per multi-row INSERT: fast, and far below max_allowed_packet. */
    private const INSERT_BATCH = 500;

    /**
     * @return array{city_code: int, name: string}|null
     */
    public function findCity(string $state, string $nameNormalized): ?array
    {
        $statement = Connection::getConnection()->prepare(
            "SELECT city_code, name FROM cnefe_cities WHERE state = :state AND name_normalized = :name"
        );
        $statement->execute([':state' => strtoupper($state), ':name' => $nameNormalized]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['city_code' => (int) $row['city_code'], 'name' => $row['name']];
    }

    /**
     * Every imported city, by state then name.
     *
     * @return array<int, array{city_code: int, state: string, name: string, street_count: int,
     *     address_count: int, imported_at: string, bounds: array{south: float, west: float,
     *     north: float, east: float}|null}>
     */
    public function listCities(): array
    {
        $rows = Connection::getConnection()->query(
            "SELECT c.city_code, c.state, c.name, c.street_count, c.address_count, c.imported_at,
                    b.south, b.west, b.north, b.east
             FROM cnefe_cities c LEFT JOIN cnefe_city_bounds b ON b.city_code = c.city_code
             ORDER BY c.state, c.name"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn ($row) => [
            'city_code' => (int) $row['city_code'],
            'state' => $row['state'],
            'name' => $row['name'],
            'street_count' => (int) $row['street_count'],
            'address_count' => (int) $row['address_count'],
            'imported_at' => $row['imported_at'],
            'bounds' => $row['south'] === null ? null : [
                'south' => (float) $row['south'],
                'west' => (float) $row['west'],
                'north' => (float) $row['north'],
                'east' => (float) $row['east'],
            ],
        ], $rows);
    }

    /**
     * Streets whose normalized name is exactly this (one per street type).
     *
     * @return array<int, array{id: int, street_type: string, name: string}>
     */
    public function findStreets(int $cityCode, string $nameNormalized): array
    {
        $statement = Connection::getConnection()->prepare(
            "SELECT id, street_type, name FROM cnefe_streets
             WHERE city_code = :city AND name_normalized = :name
             ORDER BY address_count DESC"
        );
        $statement->execute([':city' => $cityCode, ':name' => $nameNormalized]);

        return array_map(fn ($row) => ['id' => (int) $row['id']] + $row, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, array{id: int, street_type: string, name: string, name_normalized: string}>
     */
    public function listStreets(int $cityCode): array
    {
        $statement = Connection::getConnection()->prepare(
            "SELECT id, street_type, name, name_normalized FROM cnefe_streets WHERE city_code = :city"
        );
        $statement->execute([':city' => $cityCode]);

        return array_map(fn ($row) => ['id' => (int) $row['id']] + $row, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findAddress(int $streetId, int $number): ?array
    {
        return $this->fetchAddress(
            "SELECT number, lat, lng, geo_level FROM cnefe_addresses WHERE street_id = :street AND number = :number",
            [':street' => $streetId, ':number' => $number]
        );
    }

    /**
     * Nearest known number strictly below (or above) $number on the street.
     *
     * @param bool $sameParity Brazilian streets put odd numbers on one side and
     *                         even on the other, so same-side neighbors
     *                         interpolate along the right curb
     */
    public function findNeighbor(int $streetId, int $number, bool $above, bool $sameParity): ?array
    {
        $comparison = $above ? '>' : '<';
        $order = $above ? 'ASC' : 'DESC';
        $parity = $sameParity ? 'AND MOD(number, 2) = MOD(:parity_of, 2)' : '';

        $params = [':street' => $streetId, ':number' => $number];
        if ($sameParity) {
            $params[':parity_of'] = $number;
        }

        return $this->fetchAddress(
            "SELECT number, lat, lng, geo_level FROM cnefe_addresses
             WHERE street_id = :street AND number {$comparison} :number {$parity}
             ORDER BY number {$order} LIMIT 1",
            $params
        );
    }

    /**
     * The known number in the middle of the street's numbering: a sensible
     * single point for "somewhere on this street".
     */
    public function findMedianAddress(int $streetId): ?array
    {
        $pdo = Connection::getConnection();

        $count = $pdo->prepare("SELECT COUNT(*) FROM cnefe_addresses WHERE street_id = :street");
        $count->execute([':street' => $streetId]);
        $total = (int) $count->fetchColumn();

        if ($total === 0) {
            return null;
        }

        $statement = $pdo->prepare(
            "SELECT number, lat, lng, geo_level FROM cnefe_addresses
             WHERE street_id = :street ORDER BY number LIMIT 1 OFFSET :offset"
        );
        $statement->bindValue(':street', $streetId, PDO::PARAM_INT);
        $statement->bindValue(':offset', intdiv($total, 2), PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->castAddress($row);
    }

    /**
     * Random house numbers of a city, reproducible for a given seed.
     *
     * @return array<int, array{street: string, number: int, lat: float, lng: float}>
     */
    public function sampleAddresses(int $cityCode, int $count, int $seed): array
    {
        $statement = Connection::getConnection()->prepare(
            "SELECT s.name AS street, a.number, a.lat, a.lng
             FROM cnefe_addresses a JOIN cnefe_streets s ON s.id = a.street_id
             WHERE s.city_code = :city
             ORDER BY RAND(:seed) LIMIT :count"
        );
        $statement->bindValue(':city', $cityCode, PDO::PARAM_INT);
        $statement->bindValue(':seed', $seed, PDO::PARAM_INT);
        $statement->bindValue(':count', $count, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn ($row) => [
            'street' => $row['street'],
            'number' => (int) $row['number'],
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Replaces everything known about a city in one transaction, so a failed
     * import never leaves it half-loaded.
     *
     * @param array{city_code: int, state: string, name: string, name_normalized: string} $city
     * @param array<int, array{street_type: string, name: string, name_normalized: string,
     *     neighborhood: string, addresses: array<int, array{lat: float, lng: float,
     *     geo_level: int, postal_code: ?string}>}> $streets addresses keyed by number
     */
    public function replaceCity(array $city, array $streets): void
    {
        $pdo = Connection::getConnection();
        $pdo->beginTransaction();

        try {
            $this->deleteCity($pdo, $city['city_code']);

            $insertStreet = $pdo->prepare(
                "INSERT INTO cnefe_streets (city_code, street_type, name, name_normalized, neighborhood, address_count)
                 VALUES (:city, :type, :name, :normalized, :neighborhood, :count)"
            );

            $addressRows = [];
            $addressCount = 0;
            $bounds = null;

            foreach ($streets as $street) {
                $insertStreet->execute([
                    ':city' => $city['city_code'],
                    ':type' => $street['street_type'],
                    ':name' => $street['name'],
                    ':normalized' => $street['name_normalized'],
                    ':neighborhood' => $street['neighborhood'],
                    ':count' => count($street['addresses']),
                ]);
                $streetId = (int) $pdo->lastInsertId();

                foreach ($street['addresses'] as $number => $address) {
                    $addressRows[] = [$streetId, $number, $address['lat'], $address['lng'], $address['geo_level'], $address['postal_code']];
                    $addressCount++;
                    $bounds = $this->extendBounds($bounds, $address['lat'], $address['lng']);

                    if (count($addressRows) === self::INSERT_BATCH) {
                        $this->insertAddresses($pdo, $addressRows);
                        $addressRows = [];
                    }
                }
            }

            $this->insertAddresses($pdo, $addressRows);

            $insertCity = $pdo->prepare(
                "INSERT INTO cnefe_cities (city_code, state, name, name_normalized, street_count, address_count, imported_at)
                 VALUES (:city, :state, :name, :normalized, :streets, :addresses, NOW())"
            );
            $insertCity->execute([
                ':city' => $city['city_code'],
                ':state' => strtoupper($city['state']),
                ':name' => $city['name'],
                ':normalized' => $city['name_normalized'],
                ':streets' => count($streets),
                ':addresses' => $addressCount,
            ]);

            if ($bounds !== null) {
                $pdo->prepare(
                    "INSERT INTO cnefe_city_bounds (city_code, south, west, north, east)
                     VALUES (:city, :south, :west, :north, :east)"
                )->execute([
                    ':city' => $city['city_code'],
                    ':south' => $bounds['south'],
                    ':west' => $bounds['west'],
                    ':north' => $bounds['north'],
                    ':east' => $bounds['east'],
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function deleteCity(PDO $pdo, int $cityCode): void
    {
        $statement = $pdo->prepare(
            "DELETE a FROM cnefe_addresses a JOIN cnefe_streets s ON s.id = a.street_id WHERE s.city_code = :city"
        );
        $statement->execute([':city' => $cityCode]);

        $pdo->prepare("DELETE FROM cnefe_streets WHERE city_code = :city")->execute([':city' => $cityCode]);
        $pdo->prepare("DELETE FROM cnefe_city_bounds WHERE city_code = :city")->execute([':city' => $cityCode]);
        $pdo->prepare("DELETE FROM cnefe_cities WHERE city_code = :city")->execute([':city' => $cityCode]);
    }

    /**
     * @param array{south: float, west: float, north: float, east: float}|null $bounds
     *
     * @return array{south: float, west: float, north: float, east: float}
     */
    private function extendBounds(?array $bounds, float $lat, float $lng): array
    {
        if ($bounds === null) {
            return ['south' => $lat, 'west' => $lng, 'north' => $lat, 'east' => $lng];
        }

        return [
            'south' => min($bounds['south'], $lat),
            'west' => min($bounds['west'], $lng),
            'north' => max($bounds['north'], $lat),
            'east' => max($bounds['east'], $lng),
        ];
    }

    /**
     * @param array<int, array{0: int, 1: int, 2: float, 3: float, 4: int, 5: ?string}> $rows
     */
    private function insertAddresses(PDO $pdo, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?)'));
        $statement = $pdo->prepare(
            "INSERT INTO cnefe_addresses (street_id, number, lat, lng, geo_level, postal_code) VALUES {$placeholders}"
        );
        $statement->execute(array_merge(...$rows));
    }

    private function fetchAddress(string $sql, array $params): ?array
    {
        $statement = Connection::getConnection()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->castAddress($row);
    }

    /**
     * @return array{number: int, lat: float, lng: float, geo_level: int}
     */
    private function castAddress(array $row): array
    {
        return [
            'number' => (int) $row['number'],
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
            'geo_level' => (int) $row['geo_level'],
        ];
    }
}
