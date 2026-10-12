-- geo-frete - technical infrastructure schema
-- MySQL 8, InnoDB, utf8mb4
--
-- No table here represents a business entity (store, customer, order, user).
-- Every table only supports the API itself: geocoding cache, address base,
-- provider throttling and rate limiting. Tables are added phase by phase.
--
-- This file must stay idempotent (CREATE TABLE IF NOT EXISTS): it runs on
-- the first `docker compose up` and before every integration test run.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- geocoding_cache
-- Coordinates already resolved, so the same address is not geocoded
-- again. Entries older than GEOCODING_CACHE_DAYS are ignored and then
-- overwritten. key_text keeps the readable key for debugging.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS geocoding_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_hash CHAR(64) NOT NULL,
    key_text VARCHAR(500) NOT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,
    precision_level ENUM('number', 'street', 'neighborhood') NOT NULL,
    source VARCHAR(30) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_geocoding_cache_key (key_hash),
    KEY idx_geocoding_cache_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- provider_throttle
-- Timestamp of the last call to each external provider, to respect
-- limits such as Nominatim's 1 request/second across concurrent PHP
-- processes.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_throttle (
    provider VARCHAR(30) NOT NULL,
    last_call_ms BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- rate_limit
-- Request count per client (API key or IP, hashed) in one-minute
-- windows. Old windows are purged opportunistically by the middleware.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limit (
    client_hash CHAR(64) NOT NULL,
    window_minute INT UNSIGNED NOT NULL,
    total INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (client_hash, window_minute)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
