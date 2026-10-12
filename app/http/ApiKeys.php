<?php

namespace app\http;

use app\core\controller\Request;

/**
 * Shared by the authentication and rate-limit middlewares, so both agree on
 * which keys exist and how a key is read from a request.
 */
final class ApiKeys
{
    /**
     * @return string[] keys from the API_KEYS setting; empty means authentication is off
     */
    public static function configured(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) API_KEYS))));
    }

    public static function isEnabled(): bool
    {
        return !empty(self::configured());
    }

    /**
     * Reads "Authorization: Bearer <key>"; header names are case-insensitive.
     */
    public static function fromRequest(Request $request): ?string
    {
        foreach ((array) $request->getHeaders() as $name => $value) {
            if (strtolower((string) $name) === 'authorization'
                && preg_match('/^Bearer\s+(\S+)$/i', trim((string) $value), $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    public static function isValid(string $key): bool
    {
        foreach (self::configured() as $validKey) {
            // hash_equals: constant-time comparison, no timing side channel.
            if (hash_equals($validKey, $key)) {
                return true;
            }
        }

        return false;
    }
}
