<?php

namespace app\domain\service;

use app\domain\model\Address;

class DistanceCalculationService extends ServiceAbstract
{
    /** Straight line x route factor; replaced by real routing in a later phase. */
    public const METHOD_ESTIMATE = 'estimate';

    public function __construct(
        private AddressResolverService $addressResolver,
        private DistanceService $distanceService
    ) {
    }

    public function calculate(Address $origin, Address $destination): array
    {
        $resolvedOrigin = $this->addressResolver->resolve($origin, 'origin');
        // The destination is usually near the origin (deliveries are local),
        // so the origin biases the destination search.
        $resolvedDestination = $this->addressResolver->resolve($destination, 'destination', $resolvedOrigin->coordinate);

        $from = $resolvedOrigin->coordinate;
        $to = $resolvedDestination->coordinate;

        return [
            'distance_km' => round($this->distanceService->estimatedRouteKm($from, $to), 2),
            'straight_line_km' => round($this->distanceService->straightLineKm($from, $to), 2),
            'method' => self::METHOD_ESTIMATE,
            'origin' => $resolvedOrigin->toArray(),
            'destination' => $resolvedDestination->toArray(),
            'requires_confirmation' => $resolvedOrigin->precision->requiresConfirmation()
                || $resolvedDestination->precision->requiresConfirmation(),
        ];
    }
}
