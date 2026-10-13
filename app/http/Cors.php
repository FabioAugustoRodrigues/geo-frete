<?php

namespace app\http;

/**
 * CORS headers, so browser apps on other domains (e.g. the demo site) can
 * call the API. CORS only restrains browsers: it is not authentication.
 */
final class Cors
{
    /** Browsers may cache a preflight answer for this long (seconds). */
    private const PREFLIGHT_MAX_AGE = 600;

    /**
     * @param string $allowedOrigins "*" (or empty) for any site, or a
     *                               comma-separated list such as
     *                               "https://site.com,http://localhost:5173"
     * @param string|null $origin    the request's Origin header
     *
     * @return string[] header lines
     */
    public static function headers(string $allowedOrigins, ?string $origin): array
    {
        $common = [
            'Access-Control-Allow-Headers: Authorization, Content-Type',
            'Access-Control-Allow-Methods: GET, POST, OPTIONS',
            'Access-Control-Max-Age: ' . self::PREFLIGHT_MAX_AGE,
        ];

        $allowed = array_values(array_filter(array_map(
            fn ($entry) => rtrim(trim($entry), '/'),
            explode(',', $allowedOrigins)
        )));

        if (empty($allowed) || in_array('*', $allowed, true)) {
            return array_merge(['Access-Control-Allow-Origin: *'], $common);
        }

        // The answer depends on the Origin header: caches must not reuse it
        // for another origin.
        $headers = ['Vary: Origin'];

        if ($origin !== null && in_array(rtrim($origin, '/'), $allowed, true)) {
            $headers[] = 'Access-Control-Allow-Origin: ' . $origin;
            $headers = array_merge($headers, $common);
        }

        return $headers;
    }
}
