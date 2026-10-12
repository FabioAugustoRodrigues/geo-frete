<?php

namespace app\domain\model;

/**
 * Outcome of matching a typed street name against a city's street base.
 */
final class StreetMatch
{
    /** The name matches a known street (ignoring accents, case, abbreviations). */
    public const EXACT = 'exact';
    /** A typo was confidently corrected to a known street. */
    public const CORRECTED = 'corrected';
    /** Several known streets are plausible: the user must choose. */
    public const AMBIGUOUS = 'ambiguous';
    /** Nothing close enough: use the name as typed. */
    public const UNKNOWN = 'unknown';
    /** The city has no street base: nothing to match against. */
    public const NO_BASE = 'no_base';

    /**
     * @param array<int, array{name: string, score: float}> $suggestions best first
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $name = null,
        public readonly array $suggestions = []
    ) {
    }

    public static function exact(string $name): self
    {
        return new self(self::EXACT, $name);
    }

    public static function corrected(string $name, float $score): self
    {
        return new self(self::CORRECTED, $name, [['name' => $name, 'score' => $score]]);
    }

    /**
     * @param array<int, array{name: string, score: float}> $suggestions
     */
    public static function ambiguous(array $suggestions): self
    {
        return new self(self::AMBIGUOUS, null, $suggestions);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    public static function noBase(): self
    {
        return new self(self::NO_BASE);
    }
}
