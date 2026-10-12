<?php

namespace app\domain\repository;

use app\database\Connection;
use app\domain\model\Coordinate;
use app\domain\model\GeocodeResult;
use app\domain\model\Precision;
use PDO;

class GeocodingCacheRepository extends RepositoryAbstract
{
    /**
     * @param int $maxAgeDays entries older than this are ignored (and later overwritten)
     */
    public function find(string $key, int $maxAgeDays): ?GeocodeResult
    {
        $pdo = Connection::getConnection();

        $statement = $pdo->prepare(
            "SELECT lat, lng, precision_level, source FROM geocoding_cache
             WHERE key_hash = :hash AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             LIMIT 1"
        );
        $statement->bindValue(':hash', $this->hash($key));
        $statement->bindValue(':days', $maxAgeDays, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new GeocodeResult(
            new Coordinate((float) $row['lat'], (float) $row['lng']),
            Precision::from($row['precision_level']),
            $row['source']
        );
    }

    public function save(string $key, GeocodeResult $result): void
    {
        $pdo = Connection::getConnection();

        $statement = $pdo->prepare(
            "INSERT INTO geocoding_cache (key_hash, key_text, lat, lng, precision_level, source, created_at)
             VALUES (:hash, :text, :lat, :lng, :precision, :source, NOW())
             ON DUPLICATE KEY UPDATE
                lat = VALUES(lat),
                lng = VALUES(lng),
                precision_level = VALUES(precision_level),
                source = VALUES(source),
                created_at = NOW()"
        );

        $statement->execute([
            ':hash' => $this->hash($key),
            ':text' => mb_substr($key, 0, 500),
            ':lat' => $result->coordinate->lat,
            ':lng' => $result->coordinate->lng,
            ':precision' => $result->precision->value,
            ':source' => $result->source,
        ]);
    }

    private function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
