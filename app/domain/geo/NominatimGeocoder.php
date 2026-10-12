<?php

namespace app\domain\geo;

use app\domain\exception\GeocoderUnavailableException;
use app\domain\model\Address;
use app\domain\model\Coordinate;
use app\domain\model\GeocodeResult;
use app\domain\model\Precision;
use app\domain\repository\ThrottleRepository;

/**
 * Geocodes through the OpenStreetMap Nominatim API, in at most two requests:
 * a structured search with the house number (which Nominatim itself degrades
 * to the street when the number is not mapped), then the neighborhood.
 */
class NominatimGeocoder implements GeocoderInterface
{
    public const PROVIDER = 'nominatim';

    /**
     * Nominatim's place_rank: 30 = building/house, 26 = street, 17-25 =
     * neighborhoods, 16 and below = city or wider. Anything coarser than these
     * minimums means the street (or neighborhood) itself was not found.
     */
    private const MIN_RANK_STREET = 26;
    private const MIN_RANK_NEIGHBORHOOD = 17;

    /** Half the side of the preference box around $near; 0.3 degrees is about 33 km. */
    private const VIEWBOX_DELTA_DEGREES = 0.3;

    public function __construct(
        private HttpClient $httpClient,
        private ThrottleRepository $throttleRepository,
        private AddressNormalizer $normalizer,
        private string $baseUrl
    ) {
    }

    public function geocode(Address $address, ?Coordinate $near = null): ?GeocodeResult
    {
        $stateName = BrazilianState::name($address->state) ?? $address->state;

        $common = [
            'format' => 'json',
            'addressdetails' => 1,
            'limit' => 1,
            'countrycodes' => 'br',
        ];

        if ($near !== null) {
            // Without "bounded", the viewbox only prefers nearby results: it
            // never excludes the right answer when the address is far away.
            $common['viewbox'] = $this->buildViewbox($near);
        }

        $byStreet = $common + [
            'street' => "{$address->number} {$address->street}",
            'city' => $address->city,
            'state' => $stateName,
        ];
        if ($address->postalCode !== null) {
            $byStreet['postalcode'] = $address->postalCode;
        }

        $byNeighborhood = $common + [
            'q' => "{$address->neighborhood}, {$address->city}, {$stateName}",
        ];

        $anyAnswer = false;

        $place = $this->search($byStreet, $anyAnswer);
        if ($place !== null && $this->isUsable($place, $address, self::MIN_RANK_STREET)) {
            // A search with a house number still returns the bare street when
            // the number is not mapped (common in Brazil): only house_number
            // in the answer proves the number itself was found.
            $precision = isset($place['address']['house_number']) ? Precision::Number : Precision::Street;

            return $this->toResult($place, $precision);
        }

        $place = $this->search($byNeighborhood, $anyAnswer);
        if ($place !== null && $this->isUsable($place, $address, self::MIN_RANK_NEIGHBORHOOD)) {
            return $this->toResult($place, Precision::Neighborhood);
        }

        if (!$anyAnswer) {
            throw new GeocoderUnavailableException(self::PROVIDER);
        }

        return null;
    }

    /**
     * @param bool $anyAnswer set to true when Nominatim responded at all
     */
    private function search(array $query, bool &$anyAnswer): ?array
    {
        $this->throttleRepository->waitForTurn(self::PROVIDER);

        $places = $this->httpClient->getJson($this->baseUrl . '/search', $query);

        if ($places === null) {
            return null;
        }

        $anyAnswer = true;

        return $places[0] ?? null;
    }

    private function isUsable(array $place, Address $address, int $minRank): bool
    {
        if (!isset($place['lat'], $place['lon'])) {
            return false;
        }

        if ((int) ($place['place_rank'] ?? 0) < $minRank) {
            return false;
        }

        return $this->isInCity($place, $address->city);
    }

    /**
     * Guards against a same-named street in a neighboring city winning the
     * search. Nominatim names the municipality "city", "town", "village" or
     * "municipality" depending on its size.
     */
    private function isInCity(array $place, string $city): bool
    {
        $expected = $this->normalizer->normalizeText($city);
        $found = array_filter([
            $place['address']['city'] ?? null,
            $place['address']['town'] ?? null,
            $place['address']['village'] ?? null,
            $place['address']['municipality'] ?? null,
        ]);

        if (empty($found)) {
            return true;
        }

        foreach ($found as $name) {
            if ($this->normalizer->normalizeText($name) === $expected) {
                return true;
            }
        }

        return false;
    }

    private function toResult(array $place, Precision $precision): GeocodeResult
    {
        return new GeocodeResult(
            new Coordinate((float) $place['lat'], (float) $place['lon']),
            $precision,
            self::PROVIDER
        );
    }

    private function buildViewbox(Coordinate $center): string
    {
        $left = $center->lng - self::VIEWBOX_DELTA_DEGREES;
        $top = $center->lat + self::VIEWBOX_DELTA_DEGREES;
        $right = $center->lng + self::VIEWBOX_DELTA_DEGREES;
        $bottom = $center->lat - self::VIEWBOX_DELTA_DEGREES;

        return "{$left},{$top},{$right},{$bottom}";
    }
}
