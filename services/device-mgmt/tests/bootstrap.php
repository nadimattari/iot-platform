<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Ensure the `telemetry` schema (used by the telemetry read API) exists in the
// test database. Idempotent; mirrors db/init/02-telemetry.sql.
$telemetryDsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $_SERVER['DATABASE_URL'] ? parse_url($_SERVER['DATABASE_URL'], PHP_URL_HOST) : '127.0.0.1',
    $_SERVER['DATABASE_URL'] ? parse_url($_SERVER['DATABASE_URL'], PHP_URL_PORT) : '5432',
    $_SERVER['DATABASE_URL'] ? ltrim((string) parse_url($_SERVER['DATABASE_URL'], PHP_URL_PATH), '/') : 'iiot_test',
);
try {
    $pdo = new PDO($telemetryDsn, 'iiot', 'change-me');
    $pdo->exec("CREATE EXTENSION IF NOT EXISTS timescaledb");
    $pdo->exec(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS telemetry;
CREATE TABLE IF NOT EXISTS telemetry.telemetry_raw (
    time      TIMESTAMPTZ NOT NULL,
    device_id UUID        NOT NULL,
    protocol  TEXT        NOT NULL,
    payload   JSONB       NOT NULL
);
CREATE TABLE IF NOT EXISTS telemetry.telemetry_points (
    time      TIMESTAMPTZ      NOT NULL,
    device_id UUID             NOT NULL,
    field     TEXT             NOT NULL,
    value     DOUBLE PRECISION NOT NULL,
    type      TEXT             NOT NULL,
    quality   SMALLINT         NOT NULL DEFAULT 0
);
SELECT create_hypertable('telemetry.telemetry_points', 'time', if_not_exists => TRUE);
CREATE INDEX IF NOT EXISTS idx_telemetry_points_device_time
    ON telemetry.telemetry_points (device_id, time DESC);
CREATE INDEX IF NOT EXISTS idx_telemetry_points_field_time
    ON telemetry.telemetry_points (field, time DESC);
CREATE MATERIALIZED VIEW IF NOT EXISTS telemetry.telemetry_1m
WITH (timescaledb.continuous) AS
SELECT time_bucket('1 minute', time)                    AS bucket,
       device_id,
       field,
       COUNT(*) AS count,
       MIN(value) AS min,
       MAX(value) AS max,
       AVG(value) AS avg
FROM telemetry.telemetry_points
GROUP BY bucket, device_id, field
WITH NO DATA;
CREATE MATERIALIZED VIEW IF NOT EXISTS telemetry.telemetry_1h
WITH (timescaledb.continuous) AS
SELECT time_bucket('1 hour', time)                      AS bucket,
       device_id,
       field,
       COUNT(*) AS count,
       MIN(value) AS min,
       MAX(value) AS max,
       AVG(value) AS avg
FROM telemetry.telemetry_points
GROUP BY bucket, device_id, field
WITH NO DATA;
CREATE MATERIALIZED VIEW IF NOT EXISTS telemetry.telemetry_1d
WITH (timescaledb.continuous) AS
SELECT time_bucket('1 day', time)                       AS bucket,
       device_id,
       field,
       COUNT(*) AS count,
       MIN(value) AS min,
       MAX(value) AS max,
       AVG(value) AS avg
FROM telemetry.telemetry_points
GROUP BY bucket, device_id, field
WITH NO DATA;
SQL);
} catch (PDOException $e) {
    fwrite(STDERR, sprintf("WARNING: could not prepare telemetry schema for tests: %s\n", $e->getMessage()));
}
