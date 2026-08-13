#!/bin/bash
# Backup the IIoT platform databases (iiot + chirpstack) to gzipped plain-SQL
# dumps. Restore with restore.sh; the dumps are plain SQL so a TimescaleDB
# hypertable/continuous-aggregate definition round-trips cleanly.
#
# Usage:
#   deploy/backup/backup.sh                 # dumps into deploy/backup/backups/
#   BACKUP_DIR=/srv/backups deploy/backup/backup.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_FILE="$PROJECT_DIR/deploy/docker-compose.yml"
COMPOSE=(docker compose --file "$COMPOSE_FILE")

BACKUP_DIR="${BACKUP_DIR:-$SCRIPT_DIR/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-7}"
DB_USER="${DB_USER:-iiot}"
STAMP="$(date +%Y%m%d-%H%M%S%Z)"

mkdir -p "$BACKUP_DIR"

# Refuse to run unless the stack is up (backup reads via the db container).
if ! docker compose --file "$COMPOSE_FILE" ps --status running --services | grep -q '^db$'; then
    echo "error: the 'db' service is not running; start the stack first" >&2
    exit 1
fi

dump_db() {
    local db="$1"
    local out="$BACKUP_DIR/${db}-${STAMP}.sql.gz"
    echo "dumping ${db} -> ${out}" >&2
    "${COMPOSE[@]}" exec -T db sh -c \
        "pg_dump --dbname='$db' --username=\"\$POSTGRES_USER\" --format=plain --no-owner --no-privileges --no-comments" \
        | gzip -9 > "$out"
    echo "  $(du -h "$out" | cut -f1)" >&2
}

dump_db iiot
dump_db chirpstack

# A tiny manifest so a restore can confirm it has the right pair.
ls -1 "$BACKUP_DIR" | grep -E "iiot-.*\.sql\.gz$" | tail -1 > "$BACKUP_DIR/latest"
echo "latest backup marker: $(cat "$BACKUP_DIR/latest")" >&2

# Retention: prune dumps older than RETENTION_DAYS.
find "$BACKUP_DIR" -name '*.sql.gz' -mtime "+$RETENTION_DAYS" -delete 2>/dev/null || true
echo "backup complete (retention: ${RETENTION_DAYS}d)" >&2
