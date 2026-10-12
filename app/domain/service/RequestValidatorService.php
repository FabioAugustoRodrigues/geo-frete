<?php

namespace app\domain\service;

use app\domain\exception\ValidationException;
use app\domain\geo\BrazilianState;
use app\domain\model\Address;

class RequestValidatorService extends ServiceAbstract
{
    /** Bounding box of Brazil's territory, with a small margin. */
    private const LAT_MIN = -34.0;
    private const LAT_MAX = 6.0;
    private const LNG_MIN = -74.0;
    private const LNG_MAX = -34.0;

    private const MAX_LENGTHS = [
        'street' => 150,
        'number' => 20,
        'neighborhood' => 100,
        'city' => 100,
    ];

    /**
     * @return array{0: Address, 1: Address} origin and destination
     *
     * @throws ValidationException listing every problem found
     */
    public function validateDistanceRequest(array $data): array
    {
        $errors = [];

        foreach (['origin', 'destination'] as $label) {
            if (!is_array($data[$label] ?? null)) {
                $errors[] = "The field '{$label}' is required.";
                continue;
            }

            $errors = array_merge($errors, $this->validateAddress($data[$label], $label));
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return [Address::fromArray($data['origin']), Address::fromArray($data['destination'])];
    }

    /**
     * @return string[]
     */
    private function validateAddress(array $address, string $label): array
    {
        $errors = [];

        foreach (['street', 'number', 'neighborhood', 'city', 'state'] as $field) {
            if (!is_scalar($address[$field] ?? null) || trim((string) $address[$field]) === '') {
                $errors[] = "The field '{$label}.{$field}' is required.";
            }
        }

        foreach (self::MAX_LENGTHS as $field => $max) {
            if (is_scalar($address[$field] ?? null) && mb_strlen((string) $address[$field]) > $max) {
                $errors[] = "The field '{$label}.{$field}' must have at most {$max} characters.";
            }
        }

        $state = $address['state'] ?? null;
        if (is_scalar($state) && trim((string) $state) !== '' && !BrazilianState::isValid((string) $state)) {
            $errors[] = "The field '{$label}.state' must be a valid Brazilian state code (e.g. SP).";
        }

        $postalCode = $address['postal_code'] ?? null;
        if ($postalCode !== null && $postalCode !== '') {
            if (!is_scalar($postalCode) || strlen(preg_replace('/\D/', '', (string) $postalCode)) !== 8) {
                $errors[] = "The field '{$label}.postal_code' must have 8 digits.";
            }
        }

        return array_merge($errors, $this->validateCoordinate($address, $label));
    }

    /**
     * @return string[]
     */
    private function validateCoordinate(array $address, string $label): array
    {
        $hasLat = ($address['lat'] ?? null) !== null && $address['lat'] !== '';
        $hasLng = ($address['lng'] ?? null) !== null && $address['lng'] !== '';

        if (!$hasLat && !$hasLng) {
            return [];
        }

        if ($hasLat !== $hasLng) {
            return ["The fields '{$label}.lat' and '{$label}.lng' must be sent together."];
        }

        if (!is_numeric($address['lat']) || !is_numeric($address['lng'])) {
            return ["The fields '{$label}.lat' and '{$label}.lng' must be numeric."];
        }

        $lat = (float) $address['lat'];
        $lng = (float) $address['lng'];

        if ($lat < self::LAT_MIN || $lat > self::LAT_MAX || $lng < self::LNG_MIN || $lng > self::LNG_MAX) {
            return ["The coordinates of '{$label}' are outside Brazil."];
        }

        return [];
    }
}
