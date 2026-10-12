<?php

namespace app\domain\exception;

use RuntimeException;

/**
 * The geocoding provider could not be reached (network failure, timeout,
 * non-2xx status). Temporary: retrying later may succeed.
 */
class GeocoderUnavailableException extends RuntimeException
{
    public function __construct(string $provider)
    {
        parent::__construct("Geocoder unavailable: {$provider}.");
    }
}
