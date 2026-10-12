<?php

namespace app\domain\model;

/**
 * How precisely an address was located, from best to worst.
 */
enum Precision: string
{
    /** The client sent lat/lng itself. */
    case Provided = 'provided';
    /** The exact house number was found. */
    case Number = 'number';
    /** Estimated between known neighboring numbers of the same street (tens of meters off). */
    case Interpolated = 'interpolated';
    /** Only the street was found: the point can be anywhere along it. */
    case Street = 'street';
    /** Only the neighborhood was found. */
    case Neighborhood = 'neighborhood';

    /**
     * Street- and neighborhood-level points can be kilometers away from the
     * real address, so the consumer should ask the user to confirm them.
     */
    public function requiresConfirmation(): bool
    {
        return $this === self::Street || $this === self::Neighborhood;
    }

    /**
     * Lower is better; used to keep the best of several geocoders' answers.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Provided => 0,
            self::Number => 1,
            self::Interpolated => 2,
            self::Street => 3,
            self::Neighborhood => 4,
        };
    }
}
