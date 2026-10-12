<?php

namespace app\domain\exception;

use RuntimeException;

/**
 * Every provider answered, and none could locate the address. This is a
 * definitive answer about the address, unlike GeocoderUnavailableException.
 */
class AddressNotFoundException extends RuntimeException
{
    /**
     * @param string $label which address failed ("origin" or "destination")
     */
    public function __construct(private string $label)
    {
        parent::__construct("Address not found: {$label}.");
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
