import asyncio

from app.config import Settings
from app.db import Database


def test_ping_returns_false_when_pool_not_open(monkeypatch):
    for var in ("PGHOST", "PGPORT", "PGUSER", "PGPASSWORD", "PGDATABASE"):
        monkeypatch.delenv(var, raising=False)
    db = Database(Settings(_env_file=None))
    assert asyncio.run(db.ping()) is False
