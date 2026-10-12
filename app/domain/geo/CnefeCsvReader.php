<?php

namespace app\domain\geo;

use RuntimeException;

/**
 * Reads one municipality's CNEFE CSV and aggregates it into streets and
 * house numbers, ready for CnefeRepository::replaceCity().
 *
 * CNEFE has one row per dwelling or establishment (each apartment of a
 * building repeats the street and number), in uppercase ASCII, ';'-separated.
 */
class CnefeCsvReader
{
    private const COLUMNS = [
        'NOM_TIPO_SEGLOGR', 'NOM_TITULO_SEGLOGR', 'NOM_SEGLOGR', 'NUM_ENDERECO',
        'LATITUDE', 'LONGITUDE', 'NV_GEO_COORD', 'CEP', 'DSC_LOCALIDADE',
    ];

    /**
     * NV_GEO_COORD 1-3 locate the address itself; 4 (block face), 5 (locality)
     * and 6 (census tract) are too coarse to stand for a house number.
     */
    private const MAX_GEO_LEVEL = 3;

    /** Placeholder name for unnamed streets: useless for searching by name. */
    private const UNNAMED = 'sem denominacao';

    private const LOWERCASE_WORDS = ['de', 'da', 'do', 'dos', 'das', 'e'];

    public function __construct(private AddressNormalizer $normalizer)
    {
    }

    /**
     * @return array<int, array{street_type: string, name: string, name_normalized: string,
     *     neighborhood: string, addresses: array<int, array{lat: float, lng: float,
     *     geo_level: int, postal_code: ?string}>}>
     */
    public function read(string $csvPath): array
    {
        $handle = @fopen($csvPath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$csvPath}.");
        }

        try {
            $columns = $this->readHeader($handle);
            $streets = [];

            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if (count($row) < count($columns)) {
                    continue;
                }

                $this->accumulate($streets, $this->pick($row, $columns));
            }
        } finally {
            fclose($handle);
        }

        return array_values(array_map(fn ($street) => $this->finish($street), $streets));
    }

    /**
     * @return array<string, int> column name => index
     */
    private function readHeader($handle): array
    {
        $header = fgetcsv($handle, 0, ';');
        $columns = is_array($header) ? array_flip(array_map('trim', $header)) : [];

        $missing = array_diff(self::COLUMNS, array_keys($columns));
        if (!empty($missing)) {
            throw new RuntimeException('Not a CNEFE CSV; missing columns: ' . implode(', ', $missing));
        }

        return $columns;
    }

    private function pick(array $row, array $columns): array
    {
        $picked = [];
        foreach (self::COLUMNS as $column) {
            $picked[$column] = trim((string) $row[$columns[$column]]);
        }

        return $picked;
    }

    private function accumulate(array &$streets, array $row): void
    {
        $rawName = preg_replace('/\s+/', ' ', trim($row['NOM_TITULO_SEGLOGR'] . ' ' . $row['NOM_SEGLOGR']));
        $nameNormalized = $this->normalizer->normalizeStreet($rawName);

        if ($nameNormalized === '' || str_contains($nameNormalized, self::UNNAMED)) {
            return;
        }

        $type = $this->normalizer->normalizeText($row['NOM_TIPO_SEGLOGR']);
        $key = $type . '|' . $nameNormalized;

        $street = &$streets[$key];
        $street['street_type'] = $type;
        $street['name_normalized'] = $nameNormalized;
        $this->increment($street['variants'], trim($row['NOM_TIPO_SEGLOGR'] . ' ' . $rawName));
        $this->increment($street['neighborhoods'], $row['DSC_LOCALIDADE']);
        $street['numbers'] ??= [];

        $number = $this->normalizer->houseNumber($row['NUM_ENDERECO']);
        $level = (int) $row['NV_GEO_COORD'];

        if ($number === null || $level < 1 || $level > self::MAX_GEO_LEVEL
            || !is_numeric($row['LATITUDE']) || !is_numeric($row['LONGITUDE'])) {
            return;
        }

        $current = $street['numbers'][$number] ?? null;

        // Only the most precise rows of a number count; a better level resets
        // the average, a worse one is ignored.
        if ($current === null || $level < $current['level']) {
            $current = ['level' => $level, 'lat' => 0.0, 'lng' => 0.0, 'count' => 0, 'postal_codes' => []];
        } elseif ($level > $current['level']) {
            return;
        }

        $current['lat'] += (float) $row['LATITUDE'];
        $current['lng'] += (float) $row['LONGITUDE'];
        $current['count']++;
        if (preg_match('/^\d{8}$/', $row['CEP']) === 1) {
            $this->increment($current['postal_codes'], $row['CEP']);
        }

        $street['numbers'][$number] = $current;
    }

    private function finish(array $street): array
    {
        $addresses = [];
        foreach ($street['numbers'] as $number => $data) {
            $addresses[$number] = [
                'lat' => round($data['lat'] / $data['count'], 7),
                'lng' => round($data['lng'] / $data['count'], 7),
                'geo_level' => $data['level'],
                'postal_code' => $this->mostFrequent($data['postal_codes']),
            ];
        }
        ksort($addresses);

        return [
            'street_type' => $street['street_type'],
            'name' => $this->displayName($this->mostFrequent($street['variants'])),
            'name_normalized' => $street['name_normalized'],
            'neighborhood' => $this->displayName((string) $this->mostFrequent($street['neighborhoods'])),
            'addresses' => $addresses,
        ];
    }

    /**
     * "RUA BARAO DE JAGUARA" -> "Rua Barao de Jaguara". CNEFE has no accents,
     * so neither do these names.
     */
    private function displayName(string $upper): string
    {
        $words = explode(' ', mb_strtolower($upper, 'UTF-8'));

        foreach ($words as $i => $word) {
            if ($i === 0 || !in_array($word, self::LOWERCASE_WORDS, true)) {
                $words[$i] = ucfirst($word);
            }
        }

        return implode(' ', $words);
    }

    private function increment(?array &$counts, string $value): void
    {
        $counts ??= [];
        if ($value !== '') {
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
    }

    private function mostFrequent(array $counts): ?string
    {
        if (empty($counts)) {
            return null;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }
}
