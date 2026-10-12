#!/bin/sh
# Step 2: OSRM preprocessing (MLD pipeline: extract -> partition -> customize)
# of the clipped map. Runs on the same OSRM image as the server, because the
# generated files are only readable by the version that wrote them.
set -eu

NAME="${OSRM_MAP_NAME:-limeira}"
PROFILE="${OSRM_PROFILE:-/opt/car.lua}"
BUILD=/data/build

if [ ! -f "$BUILD/$NAME.osm.pbf" ]; then
    echo "Missing $BUILD/$NAME.osm.pbf. Run first: docker compose run --rm map-clip" >&2
    exit 1
fi

rm -f "$BUILD/$NAME".osrm*

echo "==> osrm-extract with profile $PROFILE"
osrm-extract -p "$PROFILE" "$BUILD/$NAME.osm.pbf"
echo "==> osrm-partition"
osrm-partition "$BUILD/$NAME.osrm"
echo "==> osrm-customize"
osrm-customize "$BUILD/$NAME.osrm"

# Built in a separate folder and swapped in only now: a failed run never
# leaves the server with half-written files.
echo "==> Installing $NAME.osrm*"
rm -f /data/"$NAME".osrm*
mv "$BUILD/$NAME".osrm* /data/

echo "==> Done. Load the new map with: docker compose restart osrm"
