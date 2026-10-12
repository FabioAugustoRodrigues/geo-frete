<?php

namespace app\domain\geo;

use app\domain\exception\GeocoderUnavailableException;
use app\domain\model\Address;
use app\domain\model\Coordinate;
use app\domain\model\GeocodeResult;

interface GeocoderInterface
{
    /**
     * Locates an address as precisely as the provider allows.
     *
     * @param Coordinate|null $near a point the address is probably close to
     *                              (e.g. the origin, when geocoding the destination)
     *
     * @return GeocodeResult|null null when the provider answered but could not find it
     *
     * @throws GeocoderUnavailableException when the provider could not be reached
     */
    public function geocode(Address $address, ?Coordinate $near = null): ?GeocodeResult;
}
