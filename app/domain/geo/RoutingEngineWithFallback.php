<?php

namespace app\domain\geo;

use app\domain\exception\RoutingUnavailableException;
use app\domain\model\Coordinate;
use app\domain\model\Route;

/**
 * Tries the primary engine and, if it cannot route, the fallback. Keeps the
 * API answering (with method "estimate") while OSRM is down or reloading.
 */
class RoutingEngineWithFallback implements RoutingEngineInterface
{
    public function __construct(
        private RoutingEngineInterface $primary,
        private RoutingEngineInterface $fallback
    ) {
    }

    public function route(Coordinate $from, Coordinate $to): Route
    {
        try {
            return $this->primary->route($from, $to);
        } catch (RoutingUnavailableException $e) {
            error_log("[ROUTING FALLBACK] " . $e->getMessage());

            return $this->fallback->route($from, $to);
        }
    }

    /**
     * Reports the primary engine: the fallback being available is what makes
     * this class useful, not a sign that routing is healthy.
     */
    public function isAvailable(): bool
    {
        return $this->primary->isAvailable();
    }
}
