# Step 1 of the map pipeline: download a regional OpenStreetMap extract and
# clip one city out of it. osmium is not packaged for the OSRM image's
# Alpine base, so this step has its own Debian image.
FROM debian:trixie-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends osmium-tool curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY clip-map.sh /usr/local/bin/clip-map.sh
RUN chmod +x /usr/local/bin/clip-map.sh

ENTRYPOINT ["/usr/local/bin/clip-map.sh"]
