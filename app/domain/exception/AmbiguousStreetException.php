<?php

namespace app\domain\exception;

use RuntimeException;

/**
 * The typed street name is close to more than one known street (or not close
 * enough to one to correct it safely): the user has to pick.
 */
class AmbiguousStreetException extends RuntimeException
{
    /**
     * @param string $label which address failed ("origin" or "destination")
     * @param array<int, array{name: string, score: float}> $suggestions
     */
    public function __construct(private string $label, private array $suggestions)
    {
        parent::__construct("Ambiguous street: {$label}.");
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<int, array{name: string, score: float}>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }
}
