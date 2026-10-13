<?php

namespace app\domain\service;

use app\domain\repository\CnefeRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Which cities have a local address base (CNEFE). Cities are imported by
 * hand with bin/import-cnefe.php; the API only lists them.
 */
class CityService extends ServiceAbstract
{
    public function __construct(private CnefeRepository $repository)
    {
    }

    /**
     * @return array<int, array{name: string, state: string, ibge_code: int, streets: int,
     *     house_numbers: int, bounds: array|null, imported_at: string}>
     */
    public function listImported(): array
    {
        return array_map(fn ($city) => [
            'name' => $city['name'],
            'state' => $city['state'],
            'ibge_code' => $city['city_code'],
            'streets' => $city['street_count'],
            'house_numbers' => $city['address_count'],
            'bounds' => $city['bounds'],
            // Stored as UTC (see Connection): parsing it in PHP's default time
            // zone (America/Sao_Paulo) would shift it by three hours.
            'imported_at' => (new DateTimeImmutable($city['imported_at'], new DateTimeZone('UTC')))->format(DATE_ATOM),
        ], $this->repository->listCities());
    }
}
