<?php

namespace app\domain\model;

final class Route
{
    /** Real road route computed by a routing engine. */
    public const METHOD_ROUTE = 'route';
    /** Straight line x route factor, used when no routing engine is available. */
    public const METHOD_ESTIMATE = 'estimate';

    public function __construct(
        public readonly float $distanceKm,
        /** Null when the method cannot estimate travel time. */
        public readonly ?float $durationMin,
        public readonly string $method,
        /**
         * How far the routing engine moved each point to reach the nearest
         * road. Null when the method does not snap to roads.
         */
        public readonly ?float $originSnapKm = null,
        public readonly ?float $destinationSnapKm = null
    ) {
    }
}
