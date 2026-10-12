# geo-frete

Open-source study project: a REST API that computes the **real driving-route distance**
between two Brazilian addresses. It knows nothing about prices, customers or orders; it
only returns distance, duration and the coordinates it used. Business rules belong to
the consumer.

Flow: address text -> geocoding (IBGE CNEFE, Nominatim as fallback) -> coordinates ->
route via OSRM -> distance. If OSRM fails, fall back to straight-line (Haversine) x route
factor, and the response says `"method": "estimate"`.

Inspired by the `nive-frete` project (same iMono base), but rewritten, not copied, and
without its known weaknesses.

Pilot city: **Limeira/SP** (CNEFE import and OSRM map clipped around it).

## Stack

- PHP 8.1+ (local: XAMPP 8.2, ZTS build on Windows), iMono framework (PSR-4 `app\` -> `app/`)
- MySQL 8, InnoDB, utf8mb4
- OSRM (routing engine) and MySQL run via Docker Compose
- PHP-DI (container), phpdotenv, PHPUnit 9

## Commands

```bash
composer install
docker compose up -d                 # api + mysql + osrm
vendor/bin/phpunit                   # all tests
vendor/bin/phpunit --testsuite Unit  # unit only (no database needed)
php -S localhost:8080 index.php      # built-in server, without Docker
```

## Architecture

```
app/routes/            routes (api.php = authenticated JSON, web.php = HTML pages)
app/http/controller/   translates HTTP <-> domain only; no business logic here
app/http/middleware/   authentication (API key) and rate limiting
app/domain/service/    rules and orchestration
app/domain/geo/        external integrations and geo data (Nominatim, OSRM, CNEFE, ViaCEP)
app/domain/repository/ database access (PDO, prepared statements)
app/domain/model/      immutable value objects (readonly)
app/domain/exception/  domain exceptions
app/core/              iMono core: do NOT change without a clear need
bin/                   CLI scripts (imports, data preparation)
database/schema.sql    single idempotent schema (CREATE TABLE IF NOT EXISTS)
docker/                Dockerfiles and data-prep scripts (e.g. OSRM map)
```

- Every external provider sits behind an interface in `app/domain/geo/`
  (`GeocoderInterface`, `RoutingEngineInterface`, ...) bound in `app/config/container.php`.
  With `APP_ENV=testing` the container uses the fakes in `app/tests/Fake/`:
  **no test may depend on the network**.
- Name routing classes `Routing*`/`Route*`, never `Router`: `app\core\controller\Router`
  is the iMono HTTP router.
- The database stores technical infrastructure only (cache, address base, rate limit).
  Never add business-entity tables.

## Code conventions

- **Language**: all code, database, JSON fields, API messages, logs, commits and docs in
  **English**. Brazilian terms with no faithful translation keep their name and get a
  one-line explanation where first defined: `CNEFE`, `CEP` (postal code), `UF` only in
  external data mapping (use `state` in the domain).
- Suffixes: `*Controller`, `*Service`, `*Repository`, `*Interface`, `*Exception`, `*Fake`.
- Declared types on parameters, properties and returns; constructor promotion and
  `public readonly` in models. Named arguments when they help readability.
- **Coordinates**: always `lat, lng` order in the domain. OSRM uses `lng,lat`: convert
  only inside the OSRM class, never outside it.
- **Units**: the domain uses km (`float`) and minutes. Meters/seconds exist only inside
  integration classes.
- Configuration: every new variable goes into `.env.example` (with a comment), becomes a
  constant in `app/config/config.php` **with a default**, and is documented in the README.
- Comments explain **why**, not what the code does.

## API

- Versioned routes: `/api/v1/...`. Envelope always `{"data": ..., "message": ""}`.
- Status: `200` ok, `400` validation (`data.errors` lists every problem), `401` API key,
  `422` address problem (`data.reason`: `ambiguous_street` | `not_found`), `429` rate limit,
  `503` providers unavailable (temporary, distinct from 422).
- Never expose stack traces or internal messages in responses; log with `error_log`
  using a tag prefix: `[OSRM ERROR]`, `[CNEFE IMPORT]`, etc.
- Changed an endpoint? Update `docs/API.md` in the same commit.

## Tests

- `app/tests/Unit` (no database/network), `Integration` (database `geo_frete_test`),
  `Feature` (full request, fake providers).
- Every new domain rule ships with a unit test. Every bug fix ships with a test that
  reproduces it.

## Large data and secrets

- Never commit: `.env`, OSM maps (`osrm-data/`), CNEFE files (`data/`), logs. The repo
  keeps only the **scripts** that download and process this data.
- Sources: CNEFE 2022 at `ftp.ibge.gov.br/Cadastro_Nacional_de_Enderecos_para_Fins_Estatisticos/Censo_Demografico_2022/`;
  OSM extracts at Geofabrik (`download.geofabrik.de/south-america/brazil/`).
- When using the public Nominatim, respect 1 req/s and send a User-Agent with a real contact.

## Git

- Small commits, in English, imperative mood ("Add OSRM routing engine").
- One plan phase at a time; every phase ends with passing tests.
