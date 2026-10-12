# geo-frete

Open-source REST API that computes the **real driving-route distance** between two
Brazilian addresses, built on the [iMono](https://github.com/FabioAugustoRodrigues/iMono)
PHP framework.

> **Status:** study project. Pilot city: Limeira/SP, with house-number geocoding from
> IBGE's CNEFE and road routing from OSRM. Any other city works the same way once its
> data is imported.

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
  from it are interpolated between same-side neighbors.
- **Nominatim** geocodes with OpenStreetMap data. In Brazil few house numbers are
  mapped, so it often finds only the street: the fallback for what CNEFE lacks.
- **OSRM** (*Open Source Routing Machine*) computes the fastest route over the
  OpenStreetMap road graph, in milliseconds, from a map preprocessed once.

Why it matters: for one Limeira address, Nominatim placed "the middle of the street"
680 m from the actual house number that CNEFE knows; and the classic "straight line
x 1.3" estimate came out 15% short of the real road route.

See [docs/API.md](docs/API.md) for the endpoints.

## Running locally

Requirements: PHP 8.1+ with `curl`, `pdo_mysql`, `mbstring` and `zip`, Composer, Docker.

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

## Tests

```bash
vendor/bin/phpunit
```

Unit tests need nothing else. Integration and feature tests use the `geo_frete_test`
database created by Docker Compose and are skipped when it is unreachable. No test
touches the network: external providers are replaced by fakes, and CNEFE tests load
a small fictional municipality.

## Data and licenses

- Addresses: CNEFE, Censo Demográfico 2022, © [IBGE](https://www.ibge.gov.br/)
  (Instituto Brasileiro de Geografia e Estatística).
- Map data © [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors,
  available under the Open Database License (ODbL), downloaded from
  [Geofabrik](https://download.geofabrik.de/).

Code: [MIT](LICENSE).
