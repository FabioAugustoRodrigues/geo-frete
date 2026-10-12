#!/bin/sh
# Step 1: download the regional extract (only when it changed upstream) and
# clip a bounding box around the city. Clipping first means OSRM
# preprocesses a few MB in minutes instead of a whole region in hours.
set -eu

REGION_URL="${OSRM_REGION_URL:-https://download.geofabrik.de/south-america/brazil/sudeste-latest.osm.pbf}"
# min_lng,min_lat,max_lng,max_lat. Default: Limeira/SP plus ~20 km of margin,
# enough for deliveries that cross into neighboring towns.
BBOX="${OSRM_BBOX:--47.70,-22.85,-47.15,-22.35}"
NAME="${OSRM_MAP_NAME:-limeira}"

SOURCE="/data/source/$(basename "$REGION_URL")"
mkdir -p /data/source /data/build

echo "==> Downloading $REGION_URL (skipped when the local copy is up to date)"
if [ -f "$SOURCE" ]; then
    curl -fL --progress-bar -z "$SOURCE" -o "$SOURCE" "$REGION_URL"
else
    curl -fL --progress-bar -o "$SOURCE" "$REGION_URL"
fi

echo "==> Clipping bbox $BBOX into build/$NAME.osm.pbf"
osmium extract --bbox "$BBOX" --overwrite -o "/data/build/$NAME.osm.pbf" "$SOURCE"

ls -lh "/data/build/$NAME.osm.pbf"
echo "==> Next: docker compose run --rm map-build"
