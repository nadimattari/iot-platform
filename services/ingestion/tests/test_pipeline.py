import asyncio
import json
import uuid
from types import SimpleNamespace

from app.devices import resolve_device_id
from app.main import make_handler
from app.normalizer import normalize


class FakeWriter:
    def __init__(self):
        self.puts = []

    async def put(self, uplink):
        self.puts.append(uplink)


class FakeDb:
    def __init__(self, pool):
        self.pool = pool


class FakePool:
    def __init__(self, result=None, error=False):
        self.result = result
        self.error = error
        self.queries = []

    async def fetchrow(self, sql, *args):
        self.queries.append((sql, args))
        if self.error:
            raise RuntimeError("db down")
        return self.result


async def test_resolve_device_id_returns_matching_device():
    pool = FakePool(result={"id": "d5d1d7e4-9d0a-4d7e-9a9d-9d7e4d7e4d7e"})
    assert await resolve_device_id(pool, "0012345678ABCDEF") == "d5d1d7e4-9d0a-4d7e-9a9d-9d7e4d7e4d7e"
    sql, args = pool.queries[0]
    assert "dev_eui" in sql
    assert args == ("0012345678ABCDEF",)


async def test_resolve_device_id_returns_none_on_miss_or_error():
    assert await resolve_device_id(FakePool(result=None), "X") is None
    assert await resolve_device_id(FakePool(error=True), "X") is None


async def test_pipeline_forwards_mqtt_uplink_unchanged():
    writer = FakeWriter()
    handler = make_handler(FakeDb(FakePool()), writer)
    device_id = str(uuid.uuid4())
    msg = SimpleNamespace(topic=f"devices/{device_id}/up", payload=b'{"temperature": 21.0}')
    await handler(msg)
    assert len(writer.puts) == 1
    assert writer.puts[0].device_id == device_id


async def test_pipeline_resolves_lorawan_dev_eui():
    device_id = str(uuid.uuid4())
    writer = FakeWriter()
    handler = make_handler(FakeDb(FakePool(result={"id": device_id})), writer)
    msg = SimpleNamespace(
        topic="application/1/device/0012345678ABCDEF/event/up",
        payload=b'{"object":{"temperature": 21.0}}',
    )
    await handler(msg)
    assert len(writer.puts) == 1
    uplink = writer.puts[0]
    assert uplink.device_id == device_id
    assert all(p.device_id == device_id for p in uplink.points)


async def test_pipeline_quarantines_unknown_lorawan_device():
    writer = FakeWriter()
    handler = make_handler(FakeDb(FakePool(result=None)), writer)
    msg = SimpleNamespace(
        topic="application/1/device/0012345678ABCDEF/event/up",
        payload=b'{"object":{"temperature": 21.0}}',
    )
    await handler(msg)
    assert writer.puts == []


async def test_pipeline_quarantines_unparseable_payload():
    writer = FakeWriter()
    handler = make_handler(FakeDb(FakePool()), writer)
    msg = SimpleNamespace(topic=f"devices/{uuid.uuid4()}/up", payload=b"not json")
    await handler(msg)
    assert writer.puts == []
