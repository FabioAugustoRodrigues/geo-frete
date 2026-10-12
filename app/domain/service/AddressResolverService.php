<?php

namespace app\domain\service;

use app\domain\exception\AddressNotFoundException;
use app\domain\exception\GeocoderUnavailableException;
use app\domain\geo\AddressNormalizer;
use app\domain\geo\GeocoderInterface;
use app\domain\model\Address;
use app\domain\model\Coordinate;
use app\domain\model\GeocodeResult;
use app\domain\model\Precision;
use app\domain\model\ResolvedAddress;
use app\domain\repository\GeocodingCacheRepository;

/**
 * Turns an address into a coordinate: client-provided coordinates first,
 * then the cache, then the geocoder. Cheapest source first.
 */
class AddressResolverService extends ServiceAbstract
{
    public const SOURCE_CLIENT = 'client';

    public function __construct(
        private GeocoderInterface $geocoder,
        private GeocodingCacheRepository $cacheRepository,
        private AddressNormalizer $normalizer,
        private int $cacheMaxAgeDays
    ) {
    }

    /**
     * @param string $label "origin" or "destination", used in error responses
     *
     * @throws AddressNotFoundException
     * @throws GeocoderUnavailableException
     */
    public function resolve(Address $address, string $label, ?Coordinate $near = null): ResolvedAddress
    {
        if ($address->coordinate !== null) {
            return new ResolvedAddress($address->coordinate, Precision::Provided, self::SOURCE_CLIENT, $address->format());
        }

        $key = $this->normalizer->cacheKey($address->state, $address->city, $address->street, $address->number);

        $result = $this->cacheRepository->find($key, $this->cacheMaxAgeDays);

        if ($result === null) {
            $result = $this->geocoder->geocode($address, $near);

            if ($result === null) {
                throw new AddressNotFoundException($label);
            }

            $this->cacheRepository->save($key, $result);
        }

        return $this->toResolved($result, $address);
    }

    private function toResolved(GeocodeResult $result, Address $address): ResolvedAddress
    {
        return new ResolvedAddress($result->coordinate, $result->precision, $result->source, $address->format());
    }
}
