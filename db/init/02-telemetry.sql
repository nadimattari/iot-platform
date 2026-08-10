-- Telemetry schema for the `iiot` database.
-- Runs once, from /docker-entrypoint-initdb.d, after 01-databases.sh.

\connect iiot

CREATE SCHEMA IF NOT EXISTS telemetry;

-- Raw payload audit/replay store: one row per received sample, pre-normalization.
CREATE TABLE IF NOT EXISTS telemetry.telemetry_raw (
    time      TIMESTAMPTZ NOT NULL,
    device_id UUID        NOT NULL,
    protocol  TEXT        NOT NULL,
    payload   JSONB       NOT NULL
);

-- Canonical normalized telemetry: one row per device field per sample.
CREATE TABLE IF NOT EXISTS telemetry.telemetry_points (
    time      TIMESTAMPTZ      NOT NULL,
    device_id UUID             NOT NULL,
    field     TEXT             NOT NULL,
    value     DOUBLE PRECISION NOT NULL,
    type      TEXT             NOT NULL,
    quality   SMALLINT         NOT NULL DEFAULT 0
);

SELECT create_hypertable('telemetry.telemetry_points', 'time',
                         if_not_exists => TRUE);

CREATE INDEX IF NOT EXISTS idx_telemetry_points_device_time
    ON telemetry.telemetry_points (device_id, time DESC);
CREATE INDEX IF NOT EXISTS idx_telemetry_points_field_time
    ON telemetry.telemetry_points (field, time DESC);
CREATE INDEX IF NOT EXISTS idx_telemetry_raw_device_time
    ON telemetry.telemetry_raw (device_id, time DESC);

-- Insight rollups via continuous aggregates.
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

-- Keep 30 days of history for rollups; refresh 1m/1h/1d respectively.
SELECT add_continuous_aggregate_policy('telemetry.telemetry_1m',
    start_offset       => INTERVAL '30 days',
    end_offset         => INTERVAL '1 minute',
    schedule_interval  => INTERVAL '5 minutes');

SELECT add_continuous_aggregate_policy('telemetry.telemetry_1h',
    start_offset       => INTERVAL '30 days',
    end_offset         => INTERVAL '1 hour',
    schedule_interval  => INTERVAL '30 minutes');

SELECT add_continuous_aggregate_policy('telemetry.telemetry_1d',
    start_offset       => INTERVAL '30 days',
    end_offset         => INTERVAL '1 day',
    schedule_interval  => INTERVAL '6 hours');
