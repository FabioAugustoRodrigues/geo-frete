# geo-frete

Open-source REST API that computes the **real driving-route distance** between two
Brazilian addresses, built on the [iMono](https://github.com/FabioAugustoRodrigues/iMono)
PHP framework.

> **Status:** work in progress (study project). Road routing with OSRM works for the
> pilot city (Limeira/SP); house-number geocoding with IBGE's CNEFE is the next phase.

## How it works

```
address text -> geocoding (Nominatim; IBGE CNEFE planned) -> coordinates
             -> road route on OSRM -> distance in km + driving time
                (falls back to a straight-line estimate if OSRM is down)
```

- **Nominatim** geocodes addresses with OpenStreetMap data. In Brazil few house
  numbers are mapped, so many addresses resolve only to their street; the API
  reports that honestly through `precision` and `requires_confirmation`.
- **OSRM** (*Open Source Routing Machine*) computes the fastest route over the
  OpenStreetMap road graph, in milliseconds, from a map preprocessed once.
- **CNEFE** (*Cadastro Nacional de Endereços para Fins Estatísticos*) is the address
  list of Brazil's 2022 Census, published by IBGE with coordinates for each house number.

See [docs/API.md](docs/API.md) for the endpoints.

## Running locally

Requirements: PHP 8.1+ with `curl`, `pdo_mysql` and `mbstring`, Composer, Docker.

```bash
composer install
cp .env.example .env              # set APP_CONTACT to a real e-mail (Nominatim policy)
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

### Routing map (OSRM)

Without a map the `osrm` container exits and the API answers with straight-line
estimates (`"method": "estimate"`). To build the map for the pilot city:

```bash
docker compose run --rm map-clip    # downloads the Southeast extract (~860 MB) and clips Limeira
docker compose run --rm map-build   # OSRM preprocessing of the clipped map
docker compose restart osrm
```

The download is skipped on later runs unless Geofabrik published a newer extract,
so re-running the three commands (e.g. monthly) refreshes the map. Another city:
set `OSRM_REGION_URL`, `OSRM_BBOX` (`min_lng,min_lat,max_lng,max_lat`) and
`OSRM_MAP_NAME` for both steps. Files live in `osrm-data/` (not versioned); the
regional download in `osrm-data/source/` can be deleted to free space.

## Configuration

| Variable | Default | Description |
|---|---|---|
| `APP_ENV` | `production` | `production`, `development` or `testing` (testing swaps providers for fakes) |
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` | `127.0.0.1`, `3306`, `root`, empty, `geo_frete` | MySQL connection |
| `API_KEYS` | empty | Comma-separated accepted keys; empty disables authentication |
| `RATE_LIMIT_PER_MINUTE` | `120` | Requests per minute per key (or per IP without authentication) |
| `APP_USER_AGENT`, `APP_CONTACT` | `geo-frete/0.1`, empty | Identification required by OpenStreetMap services |
| `NOMINATIM_URL` | `https://nominatim.openstreetmap.org` | Geocoding provider |
| `HTTP_TIMEOUT` | `8` | Seconds before an external call gives up |
| `OSRM_URL` | `http://127.0.0.1:5000` | Routing engine (Compose sets `http://osrm:5000` for the api service) |
| `OSRM_TIMEOUT` | `3` | Seconds to wait for OSRM before falling back to the estimate |
| `SNAP_WARNING_KM` | `0.3` | Distance a point may be moved to reach a road before it needs confirmation |
| `OSRM_MAP_NAME` | `limeira` | Name of the routing map built by `map-clip`/`map-build` |
| `GEOCODING_CACHE_DAYS` | `180` | Days a geocoded address stays cached |
| `ROUTE_FACTOR` | `1.3` | Straight-line multiplier, used only when OSRM cannot answer |

## Tests

```bash
vendor/bin/phpunit
```

Unit tests need nothing else. Integration and feature tests use the `geo_frete_test`
database created by Docker Compose and are skipped when it is unreachable. No test
touches the network: external providers are replaced by fakes.

## Data and licenses

Map data © [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors,
available under the Open Database License (ODbL), downloaded from
[Geofabrik](https://download.geofabrik.de/).

Code: [MIT](LICENSE).
