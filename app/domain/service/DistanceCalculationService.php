<?php

namespace app\domain\service;

use app\domain\geo\RoutingEngineInterface;
use app\domain\model\Address;
use app\domain\model\ResolvedAddress;

class DistanceCalculationService extends ServiceAbstract
{
    /**
     * @param float $snapWarningKm when the routing engine moves a point farther
     *                             than this to reach a road, the geocoded point
     *                             is probably wrong and must be confirmed
     */
    public function __construct(
        private AddressResolverService $addressResolver,
        private RoutingEngineInterface $routingEngine,
        private DistanceService $distanceService,
        private float $snapWarningKm
    ) {
    }

    public function calculate(Address $origin, Address $destination): array
    {
        $resolvedOrigin = $this->addressResolver->resolve($origin, 'origin');
        // The destination is usually near the origin (deliveries are local),
        // so the origin biases the destination search.
        $resolvedDestination = $this->addressResolver->resolve($destination, 'destination', $resolvedOrigin->coordinate);

        $route = $this->routingEngine->route($resolvedOrigin->coordinate, $resolvedDestination->coordinate);

        return [
            'distance_km' => round($route->distanceKm, 2),
            'duration_min' => $route->durationMin === null ? null : round($route->durationMin, 1),
            'straight_line_km' => round(
                $this->distanceService->straightLineKm($resolvedOrigin->coordinate, $resolvedDestination->coordinate),
                2
            ),
            'method' => $route->method,
            'origin' => $this->describe($resolvedOrigin, $route->originSnapKm),
            'destination' => $this->describe($resolvedDestination, $route->destinationSnapKm),
            'requires_confirmation' => $this->requiresConfirmation($resolvedOrigin, $route->originSnapKm)
                || $this->requiresConfirmation($resolvedDestination, $route->destinationSnapKm),
        ];
    }

    private function describe(ResolvedAddress $address, ?float $snapKm): array
    {
        return $address->toArray() + [
            'snap_distance_km' => $snapKm === null ? null : round($snapKm, 3),
        ];
    }

    private function requiresConfirmation(ResolvedAddress $address, ?float $snapKm): bool
    {
        return $address->precision->requiresConfirmation()
            || ($snapKm !== null && $snapKm > $this->snapWarningKm);
    }
}
