<?php

namespace app\domain\model;

final class ResolvedAddress
{
    public function __construct(
        public readonly Coordinate $coordinate,
        public readonly Precision $precision,
        public readonly string $source,
        public readonly string $formattedAddress,
        /** Street name actually geocoded: the corrected one, if it was corrected. */
        public readonly string $streetUsed,
        public readonly bool $streetCorrected = false
    ) {
    }

    public function toArray(): array
    {
        return [
            'lat' => $this->coordinate->lat,
            'lng' => $this->coordinate->lng,
            'precision' => $this->precision->value,
            'source' => $this->source,
            'street_used' => $this->streetUsed,
            'street_corrected' => $this->streetCorrected,
            'formatted_address' => $this->formattedAddress,
        ];
    }
}
