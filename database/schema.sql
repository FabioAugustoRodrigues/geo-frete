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
