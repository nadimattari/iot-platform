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

# ChirpStack's diesel migrations require extensions that it does not create
# itself (see chirpstack/migrations_postgres): pg_trgm (trigram indexes on
# search columns), hstore and pgcrypto (gen_random_uuid() in migrations).
psql -v ON_ERROR_STOP=1 \
     --username "$POSTGRES_USER" \
     --dbname "chirpstack" \
     -v chirpstack_user="$CHIRPSTACK_DB_USER" <<-EOSQL
    -- Make the public schema owned by the ChirpStack role so its automatic
    -- migrations can create tables. On PG15+ a DB created with OWNER already
    -- gets this, but being explicit protects against older/edge states (a DB
    -- created without OWNER leaves public owned by the bootstrap superuser and
    -- ChirpStack then fails every migration with permission denied).
    ALTER SCHEMA public OWNER TO :"chirpstack_user";
    CREATE EXTENSION IF NOT EXISTS pg_trgm;
    CREATE EXTENSION IF NOT EXISTS hstore;
    CREATE EXTENSION IF NOT EXISTS pgcrypto;
    CREATE EXTENSION IF NOT EXISTS citext;
EOSQL
