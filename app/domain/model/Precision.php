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
}
