import asyncio

from app.config import Settings
from app.db import Database


def test_ping_returns_false_when_pool_not_open(monkeypatch):
    for var in ("PGHOST", "PGPORT", "PGUSER", "PGPASSWORD", "PGDATABASE"):
        monkeypatch.delenv(var, raising=False)
    db = Database(Settings(_env_file=None))
    assert asyncio.run(db.ping()) is False


async def test_open_retries_until_pool_connects(monkeypatch):
    """A failed initial connection must not leave the pool dead forever."""
    attempts = {"n": 0}

    class FakePool:
        pass

    async def flaky_create_pool(**kwargs):
        attempts["n"] += 1
        if attempts["n"] < 3:
            raise ConnectionRefusedError("not ready yet")
        return FakePool()

    monkeypatch.setattr("app.db.asyncpg.create_pool", flaky_create_pool)
    settings = Settings(
        _env_file=None,
        pg_host="127.0.0.1",
        pg_port=1,
        pg_connect_timeout_secs=None,
        pg_retry_interval_secs=0,
    )
    db = Database(settings)
    await db.open()
    assert attempts["n"] == 3
    assert db.pool is not None


async def test_open_gives_up_after_timeout(monkeypatch):
    async def never_connect(**kwargs):
        raise ConnectionRefusedError("not ready yet")

    monkeypatch.setattr("app.db.asyncpg.create_pool", never_connect)
    settings = Settings(
        _env_file=None,
        pg_host="127.0.0.1",
        pg_port=1,
        pg_connect_timeout_secs=0,
        pg_retry_interval_secs=0,
    )
    db = Database(settings)
    await db.open()
    assert db.pool is None
