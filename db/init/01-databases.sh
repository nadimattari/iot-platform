#!/bin/bash
set -e

# Creates the platform (`iiot`) and ChirpStack (`chirpstack`) databases on a
# fresh PostgreSQL data directory. Runs once, from /docker-entrypoint-initdb.d.
#
# - `iiot`      → platform relational data + `telemetry` schema (TimescaleDB)
# - `chirpstack`→ owned entirely by the ChirpStack service (Task 14)

psql -v ON_ERROR_STOP=1 \
     --username "$POSTGRES_USER" \
     --dbname "postgres" \
     -v iiot_user="$POSTGRES_USER" \
     -v chirpstack_user="$CHIRPSTACK_DB_USER" \
     -v chirpstack_password="$CHIRPSTACK_DB_PASSWORD" <<-EOSQL
    CREATE ROLE :"chirpstack_user" LOGIN PASSWORD :'chirpstack_password';
    CREATE DATABASE iiot;
    CREATE DATABASE chirpstack OWNER :"chirpstack_user";
EOSQL

# TimescaleDB must be enabled per database.
psql -v ON_ERROR_STOP=1 \
     --username "$POSTGRES_USER" \
     --dbname "iiot" <<-EOSQL
    CREATE EXTENSION IF NOT EXISTS timescaledb;
EOSQL
