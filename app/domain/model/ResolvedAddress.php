<?php

namespace app\domain\model;

final class ResolvedAddress
{
    public function __construct(
        public readonly Coordinate $coordinate,
        public readonly Precision $precision,
        public readonly string $source,
        public readonly string $formattedAddress
    ) {
    }

    public function toArray(): array
    {
        return [
            'lat' => $this->coordinate->lat,
            'lng' => $this->coordinate->lng,
            'precision' => $this->precision->value,
            'source' => $this->source,
            'formatted_address' => $this->formattedAddress,
        ];
    }
}
