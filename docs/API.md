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

### How an address is located

1. `lat`/`lng` sent by the client are used as they are.
2. The street name is matched against the city's **CNEFE** base (IBGE's 2022
   Census address list), when the city was imported: typos are corrected,
   ambiguous names are sent back to the user (`422 ambiguous_street`).
3. **CNEFE** locates the house number: exactly, or interpolated between known
   neighboring numbers of the same side of the street.
4. Addresses CNEFE cannot place precisely go to **Nominatim** (OpenStreetMap);
   the more precise of the two answers wins.

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
    "street": "Avenida Antonio Conselheiro",
    "number": "100",
    "neighborhood": "Vila Cristovam",
    "city": "Limeira",
    "state": "SP",
    "street_confirmed": true
  }
}
```

| Field | Required | Rule |
|---|---|---|
| `street` | yes | up to 150 characters |
| `number` | yes | up to 20 characters; suffixes are fine (`500A`, `12 fundos`), `S/N` means no number |
| `neighborhood` | yes | up to 100 characters |
| `city` | yes | up to 100 characters |
| `state` | yes | Brazilian state code (`SP`, `RJ`...) |
| `postal_code` | no | CEP, 8 digits (punctuation is ignored) |
| `lat`, `lng` | no | must be sent together and lie inside Brazil; skip geocoding entirely |
| `street_confirmed` | no | boolean; `true` skips street-name correction (send it after the user picked a suggestion) |

### Response `200`

```json
{
  "data": {
    "distance_km": 0.56,
    "duration_min": 1.2,
    "straight_line_km": 0.17,
    "method": "route",
    "origin": {
      "lat": -22.562669,
      "lng": -47.401122,
      "precision": "interpolated",
      "source": "cnefe",
      "street_used": "Rua Senador Vergueiro",
      "street_corrected": false,
      "formatted_address": "Rua Senador Vergueiro, 500, Centro, Limeira, SP",
      "snap_distance_km": 0.003
    },
    "destination": { "...": "..." },
    "requires_confirmation": false
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
- `precision`: how the address was located, best first.
  - `provided`: the client sent `lat`/`lng`.
  - `number`: the exact house number was found.
  - `interpolated`: estimated from known neighboring numbers (typically tens of meters off).
  - `street`: only the street was found; the point may be anywhere along it.
  - `neighborhood`: only the neighborhood was found.
- `source`: who produced the coordinate (`client`, `cnefe`, `nominatim`).
- `street_used`: the street name actually located; differs from the request
  when `street_corrected` is `true`.
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
| `422` | street name matches more than one known street | `{ "reason": "ambiguous_street", "address": "destination", "suggestions": [{ "name": "Rua Antonio Conselheiro", "score": 1 }, ...] }` |
| `422` | an address could not be located | `{ "reason": "not_found", "address": "origin" }` |
| `429` | rate limit exceeded | `null` |
| `503` | geocoding provider unreachable (temporary; retry later) | `null` |

On `ambiguous_street`, show the suggestions, then resend with the chosen
`name` as `street` and `"street_confirmed": true`. A typed street type
disambiguates on its own: `Av. Antonio Conselheiro` is not ambiguous.

`not_found` is a definitive answer about the address: ask the user to fix it
or to point it on a map and resend with `lat`/`lng`. `503` is temporary. An
unavailable routing engine never causes an error: the answer falls back to
`method: "estimate"`.

## `GET /api/v1/streets/suggestions`

Street-name autocomplete for address forms. Reads only the local CNEFE base,
so it is fast enough to call on every keystroke.

| Query param | Required | Rule |
|---|---|---|
| `street` | yes | text typed so far, up to 150 characters |
| `city` | yes | up to 100 characters |
| `state` | yes | Brazilian state code |
| `limit` | no | integer from 1 to 10 (default 5) |

```bash
curl "http://localhost:8080/api/v1/streets/suggestions?street=senador+verg&city=Limeira&state=SP" \
  -H "Authorization: Bearer YOUR_KEY"
```

```json
{
  "data": {
    "suggestions": [{ "name": "Rua Senador Vergueiro", "score": 0.9 }],
    "available": true
  },
  "message": ""
}
```

`available: false` (with an empty list) means the city has no imported street
base. Names come from CNEFE, which has no accents (`Rua Barao de Campinas`).

## Recommendations for consumers

- **Store the returned `lat`/`lng`** with the address and send them back on
  later requests: it skips geocoding, answers faster and avoids external calls.
- When `requires_confirmation` is `true`, let the user confirm the point on a
  map before storing it.
- Use `/streets/suggestions` in the address form: picking a known name avoids
  `ambiguous_street` later.
- Business rules (price per km, free-delivery radius, rounding) belong to the
  consumer; the API only reports distances.
