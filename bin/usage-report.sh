#!/bin/sh
# Usage report from the running containers: who calls the API, how much, and
# how many addresses went to Nominatim (the shared, rate-limited resource).
#
#   sh bin/usage-report.sh [hours=24] [stack=kmzinho]
#
# "stack" is the Compose project name: kmzinho in production, geo-frete locally.
set -eu

HOURS="${1:-24}"
STACK="${2:-kmzinho}"
API="${STACK}-api-1"
MYSQL="${STACK}-mysql-1"

LOG=$(mktemp)
trap 'rm -f "$LOG"' EXIT

# Apache writes its access log (combined format) to stdout and errors to
# stderr: keep only access lines, which start with the client IP.
docker logs --since "${HOURS}h" "$API" 2>/dev/null | grep -E '^[0-9a-fA-F.:]+ - ' > "$LOG" || true

TOTAL=$(wc -l < "$LOG" | tr -d ' ')
echo "== Requests in the last ${HOURS}h: ${TOTAL}"

if [ "$TOTAL" -gt 0 ]; then
    echo
    echo "== Top client IPs"
    awk '{print $1}' "$LOG" | sort | uniq -c | sort -rn | head -10

    echo
    echo "== Status codes (401 = bad key, 429 = rate limited)"
    awk '{print $9}' "$LOG" | sort | uniq -c | sort -rn

    echo
    echo "== Endpoints"
    awk '{print $6, $7}' "$LOG" | tr -d '"' | sed 's/?.*//' | sort | uniq -c | sort -rn | head -10

    echo
    echo "== Clients (User-Agent): browsers are your site; curl/python/etc. are scripts"
    awk -F'"' '{print $6}' "$LOG" | cut -c1-70 | sort | uniq -c | sort -rn | head -10

    echo
    echo "== Requests per hour"
    awk -F'[][]' '{print substr($2, 1, 14)}' "$LOG" | uniq -c | tail -24
fi

# Each geocoding_cache row is one address resolved by Nominatim. A jump here,
# or many cities other than the imported ones, means heavy outside use.
if docker inspect "$MYSQL" > /dev/null 2>&1; then
    echo
    echo "== Nominatim lookups per day, and the cities asked for (last 7 days)"
    docker exec "$MYSQL" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" --table 2>/dev/null -e "
        SELECT DATE(created_at) AS day, COUNT(*) AS lookups FROM geocoding_cache
        WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY day ORDER BY day DESC;
        SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(key_text, \"|\", 2), \"|\", -1) AS city, COUNT(*) AS lookups
        FROM geocoding_cache WHERE created_at >= NOW() - INTERVAL 7 DAY
        GROUP BY city ORDER BY lookups DESC LIMIT 10;"'
fi
