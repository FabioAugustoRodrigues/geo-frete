# kmzinho

[![tests](https://github.com/FabioAugustoRodrigues/kmzinho/actions/workflows/tests.yml/badge.svg)](https://github.com/FabioAugustoRodrigues/kmzinho/actions/workflows/tests.yml)

*[Leia em português](pt-BR.md)*

Open-source REST API that computes the **real driving-route distance** between two
Brazilian addresses, built on the [iMono](https://github.com/FabioAugustoRodrigues/iMono)
PHP framework. A study project, piloted in Limeira/SP.

It knows nothing about prices, customers or orders: it reports distance, driving time
and how precisely each address was located. Business rules belong to the consumer.

## How it works

```
address text -> street-name correction (CNEFE street base)
             -> house number -> coordinates (CNEFE; Nominatim as fallback)
             -> road route on OSRM -> distance in km + driving time
                (falls back to a straight-line estimate if OSRM is down)
```

- **CNEFE** (*Cadastro Nacional de Endereços para Fins Estatísticos*) is the address
  list of Brazil's 2022 Census, published by IBGE with a surveyed coordinate for each
  dwelling. Limeira alone has 92,588 house numbers on 3,013 streets. Numbers missing
  from it are interpolated between neighbors on the same side of the street.
- **Nominatim** geocodes with OpenStreetMap data. In Brazil few house numbers are
  mapped, so it often finds only the street: it is the fallback for what CNEFE lacks.
- **OSRM** (*Open Source Routing Machine*) computes the fastest route over the
  OpenStreetMap road graph, in milliseconds, from a map preprocessed once.

## System design

![kmzinho system design](images/system-design.png)

Three Docker containers and three external data sources:

| Piece | Role |
|---|---|
| **api** (PHP 8.2 + Apache, iMono) | Layered code: HTTP (auth, rate limit, controllers) → services (orchestration, address resolution, street matching) → geo integrations behind interfaces → repositories (PDO) |
| **mysql** (MySQL 8.4) | Technical data only: the CNEFE address base, the Nominatim cache, rate-limit counters and the provider throttle |
| **osrm** (OSRM v26.4) | Serves the preprocessed road graph of the pilot city; routes in milliseconds |
| **Nominatim** (internet) | Geocoding fallback, called only when CNEFE cannot place an address precisely; cached, at most 1 request/s |
| **IBGE** (internet) | Source of the CNEFE files, downloaded by `bin/import-cnefe.php` (offline) |
| **Geofabrik** (internet) | Source of the OpenStreetMap extract that `map-clip` and `map-build` turn into the routing map (offline) |

Numbered steps follow one `POST /api/v1/distance`. Solid arrows happen on every
request; dashed arrows are one-time setup. The diagram is generated from
[design/system-design.html](design/system-design.html); see
[design/README.md](design/README.md) to edit and regenerate it.

## Findings

A common shortcut for delivery fees is "straight-line distance x 1.3". Measured on
299 random pairs of real house numbers in Limeira, routed by car
(`bin/compare-distances.php SP Limeira 300 42`):

| | min | median | mean | p90 | max |
|---|---|---|---|---|---|
| road route / straight line | 1.09 | 1.46 | 1.51 | 1.81 | 3.50 |

- The x1.3 estimate is off by **11.5% in the median** and 28% at the 90th percentile.
- Only **43.5%** of the pairs land within 10% of the real route.
- Worst case: the estimate is **63% too short**. Rivers, highways and dead ends make
  some routes 3.5x the straight line; no fixed factor can absorb that.
- The best single factor for this city would be 1.49, and it would still miss the
  outliers. Hence real routing.

Geocoding matters as much: for one address Nominatim returned "the middle of the
street", 680 m from the house number CNEFE knows.

## API

Full reference: [API.md](API.md).

| Endpoint | |
|---|---|
| `POST /api/v1/distance` | road distance and driving time between two addresses |
| `GET /api/v1/streets/suggestions` | street-name autocomplete from the local base |
| `GET /api/v1/health` | database and routing-engine status |

## Running locally

Requirements: PHP 8.1+ with `curl`, `pdo_mysql`, `mbstring` and `zip`, Composer, Docker.

```bash
composer install
cp .env.example .env
docker compose up -d --build
curl http://localhost:8080/api/v1/health
```

The API listens on `http://localhost:8080`; MySQL is published on host port `3307`.

The schema is applied automatically only when the MySQL volume is created. After
pulling schema changes, apply them to an existing volume with:

```bash
docker compose exec -T mysql mysql -ugeo_frete -pgeo_frete geo_frete < database/schema.sql
```

> On Windows, Docker bind mounts make each request ~0.4 s slower. For fast local
> iterations, run `php -S localhost:8090 index.php` against the same containers.

### Address base (CNEFE)

Import each city you serve (re-running replaces its previous import):

```bash
docker compose exec api php bin/import-cnefe.php SP Limeira
```

It looks up the municipality's IBGE code, downloads its CNEFE file (a few MB, cached
in `data/cnefe/`), merges apartments of the same number, and loads streets and house
numbers. Limeira takes about 10 seconds. Without an import the city still works,
through Nominatim only, and without street-name correction.

> Running it with the host PHP instead (`php bin/import-cnefe.php ...`) needs HTTPS
> certificates configured; XAMPP ships without them (set `curl.cainfo` in `php.ini`).

### Routing map (OSRM)

Without a map the `osrm` container exits and the API answers with straight-line
estimates (`"method": "estimate"`). To build the map for the pilot city:

```bash
docker compose run --rm map-clip    # downloads the Southeast extract (~860 MB) and clips Limeira
docker compose run --rm map-build   # OSRM preprocessing of the clipped map (~20 s)
docker compose restart osrm
```

The download is skipped on later runs unless Geofabrik published a newer extract,
so re-running the three commands (e.g. monthly) refreshes the map. Another city:
set `OSRM_REGION_URL`, `OSRM_BBOX` (`min_lng,min_lat,max_lng,max_lat`) and
`OSRM_MAP_NAME` for both steps. Files live in `osrm-data/` (not versioned); the
regional download in `osrm-data/source/` can be deleted to free space.

### Reproducing the findings

With a city imported and its map built:

```bash
docker compose exec api php bin/compare-distances.php SP Limeira 300 42
```

Arguments: state, city, number of pairs, random seed (same seed, same pairs).

## Configuration

| Variable | Default | Description |
|---|---|---|
| `APP_ENV` | `production` | `production`, `development` or `testing` (testing swaps providers for fakes) |
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` | `127.0.0.1`, `3306`, `root`, empty, `geo_frete` | MySQL connection |
| `API_KEYS` | empty | Comma-separated accepted keys; empty disables authentication |
| `RATE_LIMIT_PER_MINUTE` | `120` | Requests per minute per key (or per IP without authentication) |
| `APP_USER_AGENT` | `kmzinho/0.1` | Identifies the app to OpenStreetMap services (required by their usage policy) |
| `APP_CONTACT` | empty | Optional contact appended to the User-Agent; recommended, never a fake one |
| `NOMINATIM_URL` | `https://nominatim.openstreetmap.org` | Geocoding fallback |
| `HTTP_TIMEOUT` | `8` | Seconds before an external call gives up |
| `IBGE_LOCALITIES_URL` | IBGE localities API | Municipality codes, for the CNEFE import |
| `CNEFE_BASE_URL` | IBGE FTP, CNEFE 2022 per municipality | Where CNEFE files are downloaded from |
| `CNEFE_DATA_DIR` | `data/cnefe` | Download cache for CNEFE files |
| `OSRM_URL` | `http://127.0.0.1:5000` | Routing engine (Compose sets `http://osrm:5000` for the api service) |
| `OSRM_TIMEOUT` | `3` | Seconds to wait for OSRM before falling back to the estimate |
| `SNAP_WARNING_KM` | `0.3` | Distance a point may be moved to reach a road before it needs confirmation |
| `OSRM_MAP_NAME` | `limeira` | Name of the routing map built by `map-clip`/`map-build` |
| `GEOCODING_CACHE_DAYS` | `180` | Days a Nominatim answer stays cached |
| `ROUTE_FACTOR` | `1.3` | Straight-line multiplier, used only when OSRM cannot answer |

## Project layout

```
app/http/            controllers and middlewares (API key, rate limit)
app/domain/service/  orchestration: address resolution, street matching, distance
app/domain/geo/      integrations behind interfaces: CNEFE, Nominatim, OSRM
app/domain/model/    immutable value objects
app/tests/           Unit, Integration (MySQL) and Feature (full request) suites
bin/                 CNEFE import and the route-factor study
docker/              PHP image and the OSRM map pipeline
```

Every external provider sits behind an interface (`GeocoderInterface`,
`RoutingEngineInterface`) and is swapped for a fake in tests, so the suite never
touches the network.

## Tests

```bash
vendor/bin/phpunit
```

Unit tests need nothing else. Integration and feature tests use the `geo_frete_test`
database created by Docker Compose and are skipped when it is unreachable. CI runs
the whole suite on PHP 8.1, 8.2 and 8.3 against MySQL 8.4.

## Data and licenses

- Addresses: CNEFE, Censo Demográfico 2022, © [IBGE](https://www.ibge.gov.br/)
  (Instituto Brasileiro de Geografia e Estatística).
- Map data © [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors,
  available under the Open Database License (ODbL), downloaded from
  [Geofabrik](https://download.geofabrik.de/).

Code: [MIT](../LICENSE).
