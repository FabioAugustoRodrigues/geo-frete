<?php

namespace app\domain\service;

use app\domain\geo\AddressNormalizer;
use app\domain\geo\CnefeCsvReader;
use app\domain\geo\CnefeDownloader;
use app\domain\repository\CnefeRepository;

class CnefeImportService extends ServiceAbstract
{
    public function __construct(
        private CnefeDownloader $downloader,
        private CnefeCsvReader $reader,
        private CnefeRepository $repository,
        private AddressNormalizer $normalizer
    ) {
    }

    /**
     * Downloads (if needed) and imports one municipality, replacing any
     * previous import of it.
     *
     * @return array{name: string, code: int, streets: int, addresses: int}
     */
    public function import(string $state, string $city): array
    {
        $municipality = $this->downloader->findMunicipality($state, $city);
        $csvPath = $this->downloader->fetchCsv($state, $municipality['code']);

        $streets = $this->reader->read($csvPath);

        $this->repository->replaceCity(
            [
                'city_code' => $municipality['code'],
                'state' => $state,
                'name' => $municipality['name'],
                'name_normalized' => $this->normalizer->normalizeText($municipality['name']),
            ],
            $streets
        );

        return [
            'name' => $municipality['name'],
            'code' => $municipality['code'],
            'streets' => count($streets),
            'addresses' => array_sum(array_map(fn ($street) => count($street['addresses']), $streets)),
        ];
    }
}
