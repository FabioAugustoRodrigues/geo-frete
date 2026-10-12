<?php

namespace app\domain\model;

/**
 * What a geocoder found for an address.
 */
final class GeocodeResult
{
    public function __construct(
        public readonly Coordinate $coordinate,
        public readonly Precision $precision,
        /** Provider that produced the coordinate (e.g. "nominatim"). */
        public readonly string $source
    ) {
    }
}
