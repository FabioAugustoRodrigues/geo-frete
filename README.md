# geo-frete

Open-source REST API that computes the **real driving-route distance** between two
Brazilian addresses, built on the [iMono](https://github.com/FabioAugustoRodrigues/iMono)
PHP framework.

> **Status:** work in progress (study project). Phase 0 (foundation) is done; the
> distance endpoints are being built.

## How it will work

```
address text -> geocoding (IBGE CNEFE, Nominatim as fallback) -> coordinates
             -> route on OSRM (OpenStreetMap) -> distance in km + duration
```

- **CNEFE** (*Cadastro Nacional de Endereços para Fins Estatísticos*) is the address
  list of Brazil's 2022 Census, published by IBGE with coordinates for each house number.
- **OSRM** (*Open Source Routing Machine*) computes shortest/fastest routes over the
  OpenStreetMap road graph.

## Running locally

Requirements: PHP 8.1+ with `curl`, `pdo_mysql` and `mbstring`, Composer, Docker.

```bash
composer install
cp .env.example .env
docker compose up -d --build
curl http://localhost:8080/api/v1/health
```

The API listens on `http://localhost:8080`; MySQL is published on host port `3307`.

## Tests

```bash
vendor/bin/phpunit
```

Integration and feature tests use the `geo_frete_test` database created by Docker
Compose; they are skipped when it is unreachable.

## License

[MIT](LICENSE)
