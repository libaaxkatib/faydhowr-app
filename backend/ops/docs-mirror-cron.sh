#!/bin/sh
# Hourly employee-document mirror job (cron "0 * * * *", UTC) for the Railway service `docs-mirror`.
# Start command: sh ops/docs-mirror-cron.sh   (service root directory: /backend)
#
# Order: mirror -> freshness check -> disk-capacity check -> heartbeat.
#   success      : GET  $HEARTBEAT_URL
#   any failure  : GET  $HEARTBEAT_URL/fail   and exit 1 (so the Railway run also shows as failed)
# The Sunday 03:00 UTC run (or FORCE_DEEP=1) adds --deep: re-read and re-hash every document on both sides.
# Test switches: DISK_LIMIT_PCT (default 80) and FORCE_DEEP=1.
# The heartbeat URL is a secret: it is never printed.

cd "$(dirname "$0")/.." || exit 1

LIMIT="${DISK_LIMIT_PCT:-80}"
DEEP=""
if [ "${FORCE_DEEP:-0}" = "1" ] || { [ "$(date -u +%u)" = "7" ] && [ "$(date -u +%H)" = "03" ]; }; then
    DEEP="--deep"
fi

heartbeat() {
    if [ -z "${HEARTBEAT_URL:-}" ]; then
        echo "HEARTBEAT_URL is not set; no heartbeat sent" >&2
        return 1
    fi
    curl -fsS -m 15 --retry 3 "$1" >/dev/null 2>&1 || echo "heartbeat could not be delivered" >&2
}

if php artisan hr:documents-mirror --json $DEEP \
    && php artisan hr:documents-mirror-check --max-age-hours=3 \
    && [ "$(df --output=pcent /data | tail -1 | tr -dc 0-9)" -lt "$LIMIT" ]; then
    heartbeat "${HEARTBEAT_URL:-}"
else
    heartbeat "${HEARTBEAT_URL:-}/fail"
    exit 1
fi
