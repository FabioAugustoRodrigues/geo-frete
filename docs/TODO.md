# TODO

## Coverage and search
- [ ] **Import every Brazilian city automatically.** The importer must first use constant
  memory (stream into a MySQL staging table, aggregate with SQL): today it holds a
  whole city in PHP memory, and Limeira alone peaks at 128 MB, so big cities fail.
  Stopgap for mid-size cities: `php -d memory_limit=2G bin/import-cnefe.php ...`.
- [ ] **Fast autocomplete in big cities** with full-text search (MySQL `FULLTEXT` with
  the `ngram` parser, or a trigram index), scoring only the candidates it returns.
- [ ] **Real routes beyond Limeira**: clip several cities into the OSRM map, or
  preprocess the whole Brazil extract (~2 GB) on a machine with more RAM.

## API
- [ ] Route geometry, to draw the real path on a map.
- [ ] Multiple stops in one request (A → B → C).
- [ ] Accept `lat`/`lng` without the address fields.
- [ ] Reverse geocoding (nearest known address for a point).

## Operations
- [ ] Rotate the application log (`storage/logs/error_logs.log`).
