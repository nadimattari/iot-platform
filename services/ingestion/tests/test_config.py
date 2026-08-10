import pytest

from app.config import Settings


@pytest.mark.parametrize("env,field,expected", [
    ("PGHOST", "pg_host", "db"),
    ("PGPORT", "pg_port", 5432),
    ("PGUSER", "pg_user", "iiot"),
    ("PGPASSWORD", "pg_password", "s3cret"),
    ("PGDATABASE", "pg_database", "iiot"),
])
def test_settings_reads_standard_pg_env(monkeypatch, env, field, expected):
    monkeypatch.setenv(env, str(expected))
    settings = Settings(_env_file=None)
    assert getattr(settings, field) == expected


def test_settings_defaults_without_env(monkeypatch):
    for var in ("PGHOST", "PGPORT", "PGUSER", "PGPASSWORD", "PGDATABASE"):
        monkeypatch.delenv(var, raising=False)
    settings = Settings(_env_file=None)
    assert settings.pg_host == "localhost"
    assert settings.pg_port == 5432
    assert settings.pg_user == "iiot"
    assert settings.pg_database == "iiot"


def test_settings_has_ingestion_and_mercure_fields(monkeypatch):
    for var in ("PGHOST", "PGPORT", "PGUSER", "PGPASSWORD", "PGDATABASE"):
        monkeypatch.delenv(var, raising=False)
    settings = Settings(_env_file=None)
    assert settings.mqtt_username == "ingestion"
    assert settings.mqtt_password == "change-me"
    assert settings.mercure_publisher_jwt_key == "change-me"
    assert settings.pool_min_size >= 1
    assert settings.pool_max_size >= settings.pool_min_size
