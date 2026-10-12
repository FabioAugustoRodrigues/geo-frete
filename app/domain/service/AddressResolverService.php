<?php

namespace app\domain\service;

use app\domain\exception\AddressNotFoundException;
use app\domain\exception\AmbiguousStreetException;
use app\domain\exception\GeocoderUnavailableException;
use app\domain\geo\AddressNormalizer;
use app\domain\geo\GeocoderInterface;
use app\domain\model\Address;
use app\domain\model\Coordinate;
use app\domain\model\GeocodeResult;
use app\domain\model\Precision;
use app\domain\model\ResolvedAddress;
use app\domain\model\StreetMatch;
use app\domain\repository\GeocodingCacheRepository;

/**
 * Turns an address into a coordinate, cheapest and most precise source first:
 * client coordinates, then the local address base, then the cache, then the
 * remote geocoder.
 */
class AddressResolverService extends ServiceAbstract
{
    public const SOURCE_CLIENT = 'client';

    /**
     * @param GeocoderInterface $localGeocoder  local, authoritative base (CNEFE): consulted before
     *                                          the cache, so a stale remote answer never hides it
     * @param GeocoderInterface $remoteGeocoder slow external provider (Nominatim): cached
     */
    public function __construct(
        private GeocoderInterface $localGeocoder,
        private GeocoderInterface $remoteGeocoder,
        private StreetMatcherService $streetMatcher,
        private GeocodingCacheRepository $cacheRepository,
        private AddressNormalizer $normalizer,
        private int $cacheMaxAgeDays
    ) {
    }

    /**
     * @param string $label "origin" or "destination", used in error responses
     *
     * @throws AmbiguousStreetException
     * @throws AddressNotFoundException
     * @throws GeocoderUnavailableException
     */
    public function resolve(Address $address, string $label, ?Coordinate $near = null): ResolvedAddress
    {
        if ($address->coordinate !== null) {
            return new ResolvedAddress(
                $address->coordinate,
                Precision::Provided,
                self::SOURCE_CLIENT,
                $address->format(),
                $address->street
            );
        }

        [$street, $corrected] = $this->resolveStreetName($address, $label);
        $address = $address->withStreet($street);

        $result = $this->geocode($address, $near);

        if ($result === null) {
            throw new AddressNotFoundException($label);
        }

        return new ResolvedAddress($result->coordinate, $result->precision, $result->source, $address->format(), $street, $corrected);
    }

    /**
     * @return array{0: string, 1: bool} street name to use, and whether it was corrected
     *
     * @throws AmbiguousStreetException
     */
    private function resolveStreetName(Address $address, string $label): array
    {
        if ($address->streetConfirmed) {
            return [$address->street, false];
        }

        $match = $this->streetMatcher->match($address->street, $address->city, $address->state);

        return match ($match->status) {
            StreetMatch::CORRECTED => [$match->name, true],
            StreetMatch::AMBIGUOUS => throw new AmbiguousStreetException($label, $match->suggestions),
            // EXACT keeps the user's spelling: CNEFE names have no accents.
            default => [$address->street, false],
        };
    }

    private function geocode(Address $address, ?Coordinate $near): ?GeocodeResult
    {
        $local = $this->localGeocoder->geocode($address, $near);

        if ($local !== null && !$local->precision->requiresConfirmation()) {
            return $local;
        }

        try {
            $remote = $this->geocodeRemotely($address, $near);
        } catch (GeocoderUnavailableException $e) {
            // A rough local answer beats an error.
            if ($local !== null) {
                return $local;
            }

            throw $e;
        }

        return $this->best($local, $remote);
    }

    private function geocodeRemotely(Address $address, ?Coordinate $near): ?GeocodeResult
    {
        $key = $this->normalizer->cacheKey($address->state, $address->city, $address->street, $address->number);

        $result = $this->cacheRepository->find($key, $this->cacheMaxAgeDays);

        if ($result === null) {
            $result = $this->remoteGeocoder->geocode($address, $near);

            if ($result !== null) {
                $this->cacheRepository->save($key, $result);
            }
        }

        return $result;
    }

    /**
     * Keeps the more precise answer; on a tie, the first (local) one.
     */
    private function best(?GeocodeResult $first, ?GeocodeResult $second): ?GeocodeResult
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return $second->precision->rank() < $first->precision->rank() ? $second : $first;
    }
}
