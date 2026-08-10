import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.main import create_app


def test_health_returns_200_without_database():
    settings = Settings(
        _env_file=None,
        pg_host="127.0.0.1",
        pg_port=1,
        pool_min_size=1,
        pool_max_size=1,
        mqtt_enabled=False,
    )
    with TestClient(create_app(settings)) as client:
        res = client.get("/health")
        body = res.json()
    assert res.status_code == 200
    assert body["status"] == "ok"
    assert body["db"] in {"connected", "unavailable"}
