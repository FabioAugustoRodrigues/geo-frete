# geo-frete

Open-source REST API that computes the **real driving-route distance** between two
Brazilian addresses, built on the [iMono](https://github.com/FabioAugustoRodrigues/iMono)
PHP framework.

> **Status:** work in progress (study project). The distance endpoint works with a
> straight-line estimate; real road routing (OSRM) and house-number geocoding (CNEFE)
> are the next phases.

## How it works

```
address text -> geocoding (Nominatim; IBGE CNEFE planned) -> coordinates
             -> distance (straight-line estimate; OSRM road routing planned)
```

- **Nominatim** geocodes addresses with OpenStreetMap data. In Brazil few house
  numbers are mapped, so many addresses resolve only to their street; the API
  reports that honestly through `precision` and `requires_confirmation`.
- **CNEFE** (*Cadastro Nacional de Endereços para Fins Estatísticos*) is the address
  list of Brazil's 2022 Census, published by IBGE with coordinates for each house number.
- **OSRM** (*Open Source Routing Machine*) computes routes over the OpenStreetMap
  road graph.

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
> iterations, run `php -S localhost:8090 index.php` against the same MySQL container.

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
| `GEOCODING_CACHE_DAYS` | `180` | Days a geocoded address stays cached |
| `ROUTE_FACTOR` | `1.3` | Straight-line multiplier used for the distance estimate |

## Tests

```bash
vendor/bin/phpunit
```

Unit tests need nothing else. Integration and feature tests use the `geo_frete_test`
database created by Docker Compose and are skipped when it is unreachable. No test
touches the network: external providers are replaced by fakes.

## License

[MIT](LICENSE)
