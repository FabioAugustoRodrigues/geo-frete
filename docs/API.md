# geo-frete API

Base URL in development: `http://localhost:8080`.

Every response uses the same envelope:

```json
{ "data": ..., "message": "" }
```

## Authentication and rate limit

All routes except `/api/v1/health` require:

```
Authorization: Bearer <api-key>
```

Keys come from the `API_KEYS` setting (comma-separated). When it is empty,
authentication is disabled; use that only in development.

Each key (or each IP, when authentication is disabled) may send
`RATE_LIMIT_PER_MINUTE` requests per minute. Above that, the API answers `429`.

## `GET /api/v1/health`

No authentication. Checks the database and the routing engine.

```json
{
  "data": { "database": "ok", "routing_engine": "ok", "server_time": "2026-10-11T22:19:04-03:00" },
  "message": ""
}
```

Returns `503` with `"database": "unavailable"` when MySQL is unreachable.
`"routing_engine": "unavailable"` keeps the status at `200`: distances are still
answered, as straight-line estimates.

## `POST /api/v1/distance`

Computes the road distance between two addresses.

### Request

```json
{
  "origin": {
    "street": "Rua Senador Vergueiro",
    "number": "500",
    "neighborhood": "Centro",
    "city": "Limeira",
    "state": "SP"
  },
  "destination": {
    "street": "Av. Campinas",
    "number": "1000",
    "neighborhood": "Vila Camargo",
    "city": "Limeira",
    "state": "SP",
    "lat": -22.576532,
    "lng": -47.391889
  }
}
```

| Field | Required | Rule |
|---|---|---|
| `street` | yes | up to 150 characters |
| `number` | yes | up to 20 characters |
| `neighborhood` | yes | up to 100 characters |
| `city` | yes | up to 100 characters |
| `state` | yes | Brazilian state code (`SP`, `RJ`...) |
| `postal_code` | no | CEP, 8 digits (punctuation is ignored) |
| `lat`, `lng` | no | must be sent together and lie inside Brazil; skip geocoding entirely |

### Response `200`

```json
{
  "data": {
    "distance_km": 2.83,
    "duration_min": 6.7,
    "straight_line_km": 1.78,
    "method": "route",
    "origin": {
      "lat": -22.5670678,
      "lng": -47.4058422,
      "precision": "street",
      "source": "nominatim",
      "formatted_address": "Rua Senador Vergueiro, 500, Centro, Limeira, SP",
      "snap_distance_km": 0.013
    },
    "destination": { "...": "..." },
    "requires_confirmation": true
  },
  "message": ""
}
```

- `method`: how the distance was computed.
  - `route`: real road route from the OSRM routing engine (fastest route by car).
  - `estimate`: OSRM was unavailable; straight line x `ROUTE_FACTOR`.
- `distance_km`: road distance (or the estimate). `straight_line_km` is always
  the great-circle distance, for comparison.
- `duration_min`: driving time without traffic; `null` with `estimate`.
- `precision`: how the address was located.
  - `provided`: the client sent `lat`/`lng`.
  - `number`: the exact house number was found.
  - `street`: only the street was found; the point may be anywhere along it.
  - `neighborhood`: only the neighborhood was found.
- `source`: who produced the coordinate (`client`, `nominatim`).
- `snap_distance_km`: how far OSRM moved the point to reach the nearest road;
  `null` with `estimate`.
- `requires_confirmation`: `true` when either address is `street` or
  `neighborhood` precision, or when its `snap_distance_km` exceeds
  `SNAP_WARNING_KM` (a point far from any road was probably geocoded wrong).
  The distance is still computed; consider asking the user to confirm the
  point on a map.
- Routes go from origin to destination: one-way streets can make the way back
  longer or shorter.

### Errors

| Status | When | `data` |
|---|---|---|
| `400` | invalid body | `{ "errors": ["The field 'origin.street' is required.", ...] }` |
| `401` | missing or invalid API key | `null` |
| `422` | an address could not be located | `{ "reason": "not_found", "address": "origin" }` |
| `429` | rate limit exceeded | `null` |
| `503` | geocoding provider unreachable (temporary; retry later) | `null` |

`422` is a definitive answer about the address: ask the user to fix it or to
point it on a map and resend with `lat`/`lng`. `503` is temporary. An
unavailable routing engine never causes an error: the answer falls back to
`method: "estimate"`.

## Recommendations for consumers

- **Store the returned `lat`/`lng`** with the address and send them back on
  later requests: it skips geocoding, answers faster and avoids external calls.
- When `requires_confirmation` is `true`, let the user confirm the point on a
  map before storing it.
- Business rules (price per km, free-delivery radius, rounding) belong to the
  consumer; the API only reports distances.
