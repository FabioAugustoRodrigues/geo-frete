<?php

namespace app\domain\geo;

/**
 * Turns address text into a canonical form, so that "Av. Dr. João Pessoa",
 * "avenida doutor joao pessoa" and "AV DR JOAO PESSOA" compare as equal.
 */
final class AddressNormalizer
{
    /** Common Brazilian street-name abbreviations and their full form. */
    private const ABBREVIATIONS = [
        'r' => 'rua',
        'av' => 'avenida',
        'al' => 'alameda',
        'tv' => 'travessa',
        'trav' => 'travessa',
        'est' => 'estrada',
        'rod' => 'rodovia',
        'pca' => 'praca',
        'dr' => 'doutor',
        'dra' => 'doutora',
        'prof' => 'professor',
        'profa' => 'professora',
        'eng' => 'engenheiro',
        'cel' => 'coronel',
        'gen' => 'general',
        'cap' => 'capitao',
        'ten' => 'tenente',
        'sgt' => 'sargento',
        'gov' => 'governador',
        'pres' => 'presidente',
        'sta' => 'santa',
        'sto' => 'santo',
        'pe' => 'padre',
        'vl' => 'vila',
        'jd' => 'jardim',
        'pq' => 'parque',
    ];

    /** Street types ("rua", "avenida"...): dropped from the start, people often omit or swap them. */
    private const STREET_TYPES = [
        'rua', 'avenida', 'alameda', 'travessa', 'estrada', 'rodovia', 'praca', 'largo', 'viela', 'via',
    ];

    /** Portuguese prepositions ("Rua Barão de Jaguara"): carry no meaning for matching. */
    private const PREPOSITIONS = ['de', 'da', 'do', 'dos', 'das', 'e'];

    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y',
    ];

    /**
     * Lowercase, no accents, no punctuation, single spaces.
     */
    public function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = $this->removeAccents($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * normalizeText() plus expanded abbreviations, without the leading street
     * type and without prepositions.
     */
    public function normalizeStreet(string $name): string
    {
        $text = $this->normalizeText($name);

        $text = preg_replace('/\bn\s*sra\b/', 'nossa senhora', $text);
        $text = preg_replace('/\bnsa\b/', 'nossa senhora', $text);

        $words = array_values(array_filter(explode(' ', $text), fn ($word) => $word !== ''));
        $words = array_map(fn ($word) => self::ABBREVIATIONS[$word] ?? $word, $words);

        if (!empty($words) && in_array($words[0], self::STREET_TYPES, true)) {
            array_shift($words);
        }

        $words = array_filter($words, fn ($word) => !in_array($word, self::PREPOSITIONS, true));

        return implode(' ', $words);
    }

    public function cacheKey(string $state, string $city, string $street, string $number): string
    {
        return implode('|', [
            strtoupper(trim($state)),
            $this->normalizeText($city),
            $this->normalizeStreet($street),
            $this->normalizeText($number),
        ]);
    }

    private function removeAccents(string $text): string
    {
        // The map covers Portuguese reliably. iconv's TRANSLIT is only a
        // fallback for leftovers: on some builds (seen on Windows ZTS) it
        // transliterates characters like "ã" wrongly, inserting stray spaces.
        $text = strtr($text, self::ACCENTS);

        if (preg_match('/[^\x00-\x7F]/', $text) !== 1) {
            return $text;
        }

        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $converted !== false ? $converted : $text;
    }
}
