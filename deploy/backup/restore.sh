#!/bin/bash
# Restore the IIoT platform databases from gzipped plain-SQL dumps produced by
# backup.sh. Stopping writers first prevents races; services are restarted at
# the end.
#
# Usage:
#   deploy/backup/restore.sh                          # latest pair from backup dir
#   deploy/backup/restore.sh <iiot.sql.gz> [chirpstack.sql.gz]
#   BACKUP_DIR=/srv/backups deploy/backup/restore.sh  # explicit dir
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_FILE="$PROJECT_DIR/deploy/docker-compose.yml"
COMPOSE=(docker compose --file "$COMPOSE_FILE")

BACKUP_DIR="${BACKUP_DIR:-$SCRIPT_DIR/backups}"
IIOT_DUMP="${1:-}"
CHIRPSTACK_DUMP="${2:-}"

if [ -z "$IIOT_DUMP" ]; then
    latest="$(cat "$BACKUP_DIR/latest" 2>/dev/null || true)"
    if [ -z "$latest" ] || [ ! -f "$BACKUP_DIR/$latest" ]; then
        echo "error: no dump specified and no $BACKUP_DIR/latest marker" >&2
        echo "usage: $0 <iiot.sql.gz> [chirpstack.sql.gz]" >&2
        exit 1
    fi
    IIOT_DUMP="$BACKUP_DIR/$latest"
    # best-effort sibling chirpstack dump with the same timestamp
    stamp="${latest#iiot-}"
    stamp="${stamp%.sql.gz}"
    sibling="$BACKUP_DIR/chirpstack-${stamp}.sql.gz"
    [ -f "$sibling" ] && CHIRPSTACK_DUMP="$sibling"
fi

[ -f "$IIOT_DUMP" ] || { echo "error: $IIOT_DUMP not found" >&2; exit 1; }

echo "==> restoring $IIOT_DUMP" >&2
[ -n "$CHIRPSTACK_DUMP" ] && [ -f "$CHIRPSTACK_DUMP" ] \
    && echo "==> restoring $CHIRPSTACK_DUMP" >&2 \
    || { echo "warning: no chirpstack dump to restore" >&2; CHIRPSTACK_DUMP=""; }

echo "==> stopping writers (ingestion, consumers, auth, chirpstack)" >&2
"${COMPOSE[@]}" stop \
    ingestion \
    device-mgmt-consumer \
    device-mgmt-consumer-acks \
    auth \
    chirpstack \
    chirpstack-rest-api \
    chirpstack-gateway-bridge >/dev/null

restore_db() {
    local db="$1" dump="$2"
    echo "==> clearing $db" >&2
    # Drop the TimescaleDB extension (removes hypertable/chunk catalog), then
    # every remaining application schema. The dump recreates the extension and
    # hypertables; this mirrors the official timescaledb restore flow.
    "${COMPOSE[@]}" exec -T db sh -c \
        'psql --dbname="$1" --username="$POSTGRES_USER" --set=ON_ERROR_STOP=1' sh "$db" <<'SQL'
DROP EXTENSION IF EXISTS timescaledb CASCADE;
DO $$
DECLARE s text;
BEGIN
  FOR s IN SELECT nspname FROM pg_namespace
           WHERE nspname NOT IN ('pg_catalog', 'information_schema')
             AND nspname NOT LIKE 'pg\_%'
  LOOP
    EXECUTE format('DROP SCHEMA IF EXISTS %I CASCADE', s);
  END LOOP;
END
$$;
CREATE SCHEMA IF NOT EXISTS public;
SQL
    echo "==> loading $dump into $db" >&2
    if [ "$db" = "iiot" ]; then
        # TimescaleDB plain dumps restore chunk data directly into
        # _timescaledb_internal._hyper_* tables, which requires restoring mode
        # (pre_restore() sets it). No post_restore() call: TimescaleDB 3.x no
        # longer ships that function and the dump restores cleanly without it.
        {
            echo "CREATE EXTENSION IF NOT EXISTS timescaledb;"
            echo "SELECT timescaledb_pre_restore();"
            gunzip -c "$dump"
        } | "${COMPOSE[@]}" exec -T db sh -c \
            "psql --dbname='$db' --username=\"\$POSTGRES_USER\" --set=ON_ERROR_STOP=1"
    else
        gunzip -c "$dump" | "${COMPOSE[@]}" exec -T db sh -c \
            "psql --dbname='$db' --username=\"\$POSTGRES_USER\" --set=ON_ERROR_STOP=1"
    fi
    echo "==> $db restored" >&2
}

restore_db iiot "$IIOT_DUMP"
[ -n "$CHIRPSTACK_DUMP" ] && restore_db chirpstack "$CHIRPSTACK_DUMP"

echo "==> restarting writers" >&2
"${COMPOSE[@]}" start \
    ingestion \
    device-mgmt-consumer \
    device-mgmt-consumer-acks \
    auth \
    chirpstack \
    chirpstack-rest-api \
    chirpstack-gateway-bridge >/dev/null

echo "restore complete" >&2
