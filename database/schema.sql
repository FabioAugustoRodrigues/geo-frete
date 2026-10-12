-- kmzinho - technical infrastructure schema
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
    precision_level VARCHAR(20) NOT NULL,
    source VARCHAR(30) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_geocoding_cache_key (key_hash),
    KEY idx_geocoding_cache_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Databases created before precision_level became a VARCHAR still have the
-- old ENUM; MODIFY is idempotent, so this is safe to run every time.
ALTER TABLE geocoding_cache MODIFY precision_level VARCHAR(20) NOT NULL;

-- ---------------------------------------------------------------------
-- cnefe_cities
-- Municipalities whose CNEFE (Cadastro Nacional de Enderecos para Fins
-- Estatisticos, IBGE's 2022 Census address list) was imported. A city
-- missing here has no local address base: geocoding falls back to
-- Nominatim and street names are not corrected.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cnefe_cities (
    city_code INT UNSIGNED NOT NULL,
    state CHAR(2) NOT NULL,
    name VARCHAR(120) NOT NULL,
    name_normalized VARCHAR(120) NOT NULL,
    street_count INT UNSIGNED NOT NULL,
    address_count INT UNSIGNED NOT NULL,
    imported_at DATETIME NOT NULL,
    PRIMARY KEY (city_code),
    UNIQUE KEY uk_cnefe_cities_state_name (state, name_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- cnefe_streets
-- Street names per city, for street-name correction and autocomplete.
-- The type ("rua", "avenida"...) is kept apart from the name because
-- cities have different streets that share a name (Rua X and Avenida X).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cnefe_streets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    city_code INT UNSIGNED NOT NULL,
    street_type VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    name_normalized VARCHAR(150) NOT NULL,
    neighborhood VARCHAR(120) NOT NULL,
    address_count INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cnefe_streets_name (city_code, name_normalized, street_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- cnefe_addresses
-- One row per street + house number. CNEFE lists every dwelling (each
-- apartment of a building shares a number), so rows are merged at import:
-- the coordinate is the average of the most precise rows for that number.
-- geo_level is CNEFE's NV_GEO_COORD (1 = surveyed on site, 2 = adjusted
-- for buildings, 3 = estimated); coarser levels are not imported.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cnefe_addresses (
    street_id BIGINT UNSIGNED NOT NULL,
    number INT UNSIGNED NOT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,
    geo_level TINYINT UNSIGNED NOT NULL,
    postal_code CHAR(8) NULL,
    PRIMARY KEY (street_id, number)
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
