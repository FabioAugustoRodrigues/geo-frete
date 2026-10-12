<?php

namespace app\domain\model;

/**
 * An address as sent by the client, already validated.
 */
final class Address
{
    public function __construct(
        public readonly string $street,
        public readonly string $number,
        public readonly string $neighborhood,
        public readonly string $city,
        /** Two-letter state code (UF), uppercase. */
        public readonly string $state,
        /** CEP (Brazilian postal code), digits only. */
        public readonly ?string $postalCode = null,
        public readonly ?Coordinate $coordinate = null,
        /** The client confirms the street name as typed: skip name correction. */
        public readonly bool $streetConfirmed = false
    ) {
    }

    /**
     * Expects data that already passed RequestValidatorService.
     */
    public static function fromArray(array $data): self
    {
        $hasCoordinate = isset($data['lat'], $data['lng']) && $data['lat'] !== '' && $data['lng'] !== '';

        $postalCode = null;
        if (isset($data['postal_code']) && trim((string) $data['postal_code']) !== '') {
            $postalCode = preg_replace('/\D/', '', (string) $data['postal_code']);
        }

        return new self(
            street: trim((string) $data['street']),
            number: trim((string) $data['number']),
            neighborhood: trim((string) $data['neighborhood']),
            city: trim((string) $data['city']),
            state: strtoupper(trim((string) $data['state'])),
            postalCode: $postalCode,
            coordinate: $hasCoordinate ? new Coordinate((float) $data['lat'], (float) $data['lng']) : null,
            streetConfirmed: filter_var($data['street_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );
    }

    public function withStreet(string $street): self
    {
        return new self(
            $street,
            $this->number,
            $this->neighborhood,
            $this->city,
            $this->state,
            $this->postalCode,
            $this->coordinate,
            $this->streetConfirmed
        );
    }

    public function format(): string
    {
        return "{$this->street}, {$this->number}, {$this->neighborhood}, {$this->city}, {$this->state}";
    }
}
