<?php

namespace app\domain\service;

use app\domain\geo\AddressNormalizer;
use app\domain\model\StreetMatch;
use app\domain\repository\CnefeRepository;

/**
 * Matches typed street names against the city's CNEFE street base, to fix
 * typos ("Senador Vergeiro") and feed autocomplete.
 */
class StreetMatcherService extends ServiceAbstract
{
    /** At or above: confident enough to correct the name silently. */
    public const ACCEPT_SCORE = 0.90;
    /** At or above (and below ACCEPT_SCORE): plausible, ask the user. */
    public const SUGGEST_SCORE = 0.70;

    private const MAX_SUGGESTIONS = 3;

    /** Typed text this long or longer counts as a prefix/substring hit. */
    private const MIN_SUBSTRING_LENGTH = 4;

    public function __construct(
        private CnefeRepository $repository,
        private AddressNormalizer $normalizer
    ) {
    }

    public function match(string $street, string $city, string $state): StreetMatch
    {
        $cityRow = $this->repository->findCity($state, $this->normalizer->normalizeText($city));
        if ($cityRow === null) {
            return StreetMatch::noBase();
        }

        $typed = $this->normalizer->normalizeStreet($street);
        $type = $this->normalizer->streetType($street);

        $exact = $this->repository->findStreets($cityRow['city_code'], $typed);
        if (!empty($exact)) {
            return $this->resolveExact($exact, $type);
        }

        $candidates = $this->repository->listStreets($cityRow['city_code']);

        // An explicit type ("Av.") is strong evidence: a similar name of
        // another type (Rua Barao de Campinas for "Av. Campinas") is a
        // different street, not a typo, so it is neither corrected nor offered.
        if ($type !== null) {
            $candidates = array_filter($candidates, fn ($street) => str_starts_with($street['street_type'], $type));
        }

        $scored = $this->score($typed, $type, $candidates, false);
        $best = $scored[0] ?? null;

        if ($best === null || $best['score'] < self::SUGGEST_SCORE) {
            return StreetMatch::unknown();
        }

        $runnerUp = $scored[1] ?? null;
        $clearWinner = $runnerUp === null || $runnerUp['score'] < $best['score'];

        if ($best['score'] >= self::ACCEPT_SCORE && $clearWinner) {
            return StreetMatch::corrected($best['name'], $best['score']);
        }

        return StreetMatch::ambiguous(array_slice($scored, 0, self::MAX_SUGGESTIONS));
    }

    /**
     * Autocomplete: best matches for partially typed text, or null when the
     * city has no street base.
     *
     * @return array<int, array{name: string, score: float}>|null
     */
    public function suggest(string $street, string $city, string $state, int $limit): ?array
    {
        $cityRow = $this->repository->findCity($state, $this->normalizer->normalizeText($city));
        if ($cityRow === null) {
            return null;
        }

        $scored = $this->score(
            $this->normalizer->normalizeStreet($street),
            $this->normalizer->streetType($street),
            $this->repository->listStreets($cityRow['city_code']),
            true
        );

        return array_slice($scored, 0, $limit);
    }

    /**
     * @param array<int, array{street_type: string, name: string}> $candidates same normalized name
     */
    private function resolveExact(array $candidates, ?string $type): StreetMatch
    {
        if (count($candidates) === 1) {
            return StreetMatch::exact($candidates[0]['name']);
        }

        if ($type !== null) {
            foreach ($candidates as $candidate) {
                if (str_starts_with($candidate['street_type'], $type)) {
                    return StreetMatch::exact($candidate['name']);
                }
            }
        }

        // Rua X and Avenida X both exist and the user did not say which.
        return StreetMatch::ambiguous(array_map(
            fn ($candidate) => ['name' => $candidate['name'], 'score' => 1.0],
            array_slice($candidates, 0, self::MAX_SUGGESTIONS)
        ));
    }

    /**
     * @param bool $partial autocomplete mode: the typed text may be just the
     *                      beginning or a part of the name
     *
     * @return array<int, array{name: string, score: float}> at least SUGGEST_SCORE, best first
     */
    private function score(string $typed, ?string $type, array $streets, bool $partial): array
    {
        if ($typed === '') {
            return [];
        }

        $scored = [];
        foreach ($streets as $street) {
            $score = $partial
                ? $this->partialSimilarity($typed, $street['name_normalized'])
                : $this->similarity($typed, $street['name_normalized']);

            if ($score < self::SUGGEST_SCORE) {
                continue;
            }

            // Same name, matching type: break ties toward what the user typed.
            if ($type !== null && str_starts_with($street['street_type'], $type)) {
                $score += 0.001;
            }

            $scored[] = ['name' => $street['name'], 'score' => round(min($score, 1.0), 3)];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));

        return $scored;
    }

    /**
     * For correcting a complete name: symmetric, so extra words on either
     * side count against the match ("campinas" is not "barao campinas").
     */
    private function similarity(string $typed, string $known): float
    {
        $words = ($this->wordScore($typed, $known) + $this->wordScore($known, $typed)) / 2;

        return max($this->levenshteinScore($typed, $known), $words);
    }

    /**
     * For autocomplete: "senador verg" while typing, or "vergueiro" alone,
     * should still find "senador vergueiro".
     */
    private function partialSimilarity(string $typed, string $known): float
    {
        $score = max($this->levenshteinScore($typed, $known), $this->wordScore($typed, $known));

        if (strlen($typed) >= self::MIN_SUBSTRING_LENGTH && str_contains($known, $typed)) {
            $score = max($score, self::ACCEPT_SCORE);
        }

        return $score;
    }

    private function levenshteinScore(string $a, string $b): float
    {
        $longest = max(strlen($a), strlen($b));

        return $longest === 0 ? 1.0 : 1 - levenshtein($a, $b) / $longest;
    }

    /**
     * Average, over the words of $from, of each word's best match among the
     * words of $to: tolerates swapped words ("vergueiro senador"). One
     * direction only ignores words of $to that $from lacks.
     */
    private function wordScore(string $from, string $to): float
    {
        $fromWords = explode(' ', $from);
        $toWords = explode(' ', $to);

        $total = 0.0;
        foreach ($fromWords as $fromWord) {
            $best = 0.0;
            foreach ($toWords as $toWord) {
                $best = max($best, $this->levenshteinScore($fromWord, $toWord));
            }
            $total += $best;
        }

        return $total / count($fromWords);
    }
}
