<?php

namespace app\domain\geo;

use app\domain\model\Address;
use app\domain\model\Coordinate;
use app\domain\model\GeocodeResult;
use app\domain\model\Precision;
use app\domain\repository\CnefeRepository;

/**
 * Geocodes against the local CNEFE base (IBGE's 2022 Census addresses).
 * Only answers for imported cities; returns null otherwise, so the caller
 * falls back to another geocoder.
 */
class CnefeGeocoder implements GeocoderInterface
{
    public const PROVIDER = 'cnefe';

    /**
     * Neighbors farther apart than this (in house numbers) are not
     * interpolated. Many Brazilian cities number houses by meters from the
     * street start, so this is roughly a 200 m gap.
     */
    private const MAX_INTERPOLATION_GAP = 200;

    /** A known number this close is a good enough stand-in for the requested one. */
    private const MAX_NEIGHBOR_DISTANCE = 20;

    public function __construct(
        private CnefeRepository $repository,
        private AddressNormalizer $normalizer
    ) {
    }

    public function geocode(Address $address, ?Coordinate $near = null): ?GeocodeResult
    {
        $city = $this->repository->findCity($address->state, $this->normalizer->normalizeText($address->city));
        if ($city === null) {
            return null;
        }

        $streetId = $this->findStreetId($city['city_code'], $address->street);
        if ($streetId === null) {
            return null;
        }

        $number = $this->normalizer->houseNumber($address->number);

        if ($number !== null) {
            $exact = $this->repository->findAddress($streetId, $number);
            if ($exact !== null) {
                return $this->result($exact['lat'], $exact['lng'], Precision::Number);
            }

            $interpolated = $this->interpolate($streetId, $number, true) ?? $this->interpolate($streetId, $number, false);
            if ($interpolated !== null) {
                return $interpolated;
            }

            $nearest = $this->nearestNeighbor($streetId, $number);
            if ($nearest !== null) {
                return $nearest;
            }
        }

        $median = $this->repository->findMedianAddress($streetId);

        return $median === null ? null : $this->result($median['lat'], $median['lng'], Precision::Street);
    }

    /**
     * When several streets share the name (Rua X and Avenida X), the type the
     * user typed decides; without one, the street with most addresses wins
     * (ambiguity was already raised during name matching, if it mattered).
     */
    private function findStreetId(int $cityCode, string $street): ?int
    {
        $candidates = $this->repository->findStreets($cityCode, $this->normalizer->normalizeStreet($street));
        if (empty($candidates)) {
            return null;
        }

        $type = $this->normalizer->streetType($street);
        if ($type !== null) {
            foreach ($candidates as $candidate) {
                if (str_starts_with($candidate['street_type'], $type)) {
                    return $candidate['id'];
                }
            }
        }

        return $candidates[0]['id'];
    }

    private function interpolate(int $streetId, int $number, bool $sameParity): ?GeocodeResult
    {
        $below = $this->repository->findNeighbor($streetId, $number, false, $sameParity);
        $above = $this->repository->findNeighbor($streetId, $number, true, $sameParity);

        if ($below === null || $above === null || $above['number'] - $below['number'] > self::MAX_INTERPOLATION_GAP) {
            return null;
        }

        $fraction = ($number - $below['number']) / ($above['number'] - $below['number']);

        return $this->result(
            $below['lat'] + ($above['lat'] - $below['lat']) * $fraction,
            $below['lng'] + ($above['lng'] - $below['lng']) * $fraction,
            Precision::Interpolated
        );
    }

    /**
     * Past the last known number (or before the first) there is nothing to
     * interpolate with, but a close neighbor is still a good estimate.
     */
    private function nearestNeighbor(int $streetId, int $number): ?GeocodeResult
    {
        $candidates = array_filter([
            $this->repository->findNeighbor($streetId, $number, false, false),
            $this->repository->findNeighbor($streetId, $number, true, false),
        ]);

        usort($candidates, fn ($a, $b) => abs($a['number'] - $number) <=> abs($b['number'] - $number));

        $nearest = $candidates[0] ?? null;
        if ($nearest === null || abs($nearest['number'] - $number) > self::MAX_NEIGHBOR_DISTANCE) {
            return null;
        }

        return $this->result($nearest['lat'], $nearest['lng'], Precision::Interpolated);
    }

    private function result(float $lat, float $lng, Precision $precision): GeocodeResult
    {
        return new GeocodeResult(new Coordinate(round($lat, 7), round($lng, 7)), $precision, self::PROVIDER);
    }
}
