import asyncio
import json
import uuid

from app.mercure import MercurePublisher
from app.normalizer import normalize
from app.writer import Writer


class FakeConn:
    def __init__(self):
        self.flushes = []

    async def copy_records_to_table(self, table, records, *, columns=None, schema_name=None):
        self.flushes.append((table, records, columns))

    async def __aenter__(self):
        return self

    async def __aexit__(self, *args):
        pass

    def transaction(self):
        return self


class FakePool:
    def __init__(self, conn):
        self._conn = conn

    def acquire(self):
        return self

    async def __aenter__(self):
        return self._conn

    async def __aexit__(self, *args):
        pass


class FakeDatabase:
    def __init__(self, pool):
        self.pool = pool


def make_uplink(field="temperature", value=22.5):
    device_id = str(uuid.uuid4())
    return normalize(
        f"devices/{device_id}/up",
        json.dumps({field: value}).encode(),
    )


async def test_flush_writes_raw_audit_and_points():
    conn = FakeConn()
    writer = Writer(FakeDatabase(FakePool(conn)))
    uplink = make_uplink()

    await writer._flush([uplink])

    raw = [f for f in conn.flushes if f[0] == "telemetry_raw"]
    points = [f for f in conn.flushes if f[0] == "telemetry_points"]
    assert len(raw) == 1 and len(points) == 1

    _, raw_records, raw_columns = raw[0]
    assert raw_columns == ["time", "device_id", "protocol", "raw", "payload"]
    assert raw_records[0][0] == uplink.time
    assert raw_records[0][1] == uplink.device_id
    assert raw_records[0][2] == "mqtt"
    assert raw_records[0][3] is None
    assert json.loads(raw_records[0][4]) == uplink.payload

    _, point_records, point_columns = points[0]
    assert point_columns == ["time", "device_id", "field", "value", "type", "quality"]
    assert point_records[0][:2] == (uplink.time, uplink.device_id)


async def test_flush_stores_lorawan_raw_bytes():
    conn = FakeConn()
    writer = Writer(FakeDatabase(FakePool(conn)))
    uplink = normalize(
        "application/1/device/0012345678ABCDEF/event/up",
        b'{"data":"AQIDBAUG","object":{"temperature": 21.0}}',
    ).with_device_id(str(uuid.uuid4()))

    await writer._flush([uplink])

    raw = [f for f in conn.flushes if f[0] == "telemetry_raw"]
    assert len(raw) == 1
    _, raw_records, _ = raw[0]
    assert raw_records[0][3] == bytes([1, 2, 3, 4, 5, 6])
    points = [f for f in conn.flushes if f[0] == "telemetry_points"]
    assert len(points) == 1


async def test_flush_is_idempotent_without_pool():
    writer = Writer(FakeDatabase(pool=None))
    await writer._flush([make_uplink()])


async def test_flush_publishes_one_mercure_event_per_device():
    conn = FakeConn()
    mercure = FakeMercurePublisher()
    writer = Writer(FakeDatabase(FakePool(conn)), mercure=mercure)
    d1 = str(uuid.uuid4())
    d2 = str(uuid.uuid4())
    await writer._flush([
        normalize(f"devices/{d1}/up", json.dumps({"a": 1.0}).encode()),
        normalize(f"devices/{d1}/up", json.dumps({"b": 2.0}).encode()),
        normalize(f"devices/{d2}/up", json.dumps({"a": 3.0}).encode()),
    ])

    assert {e["device_id"] for e in mercure.events} == {d1, d2}
    d1_event = [e for e in mercure.events if e["device_id"] == d1][0]
    assert len(d1_event["points"]) == 2


async def test_flush_does_not_publish_without_mercure():
    conn = FakeConn()
    writer = Writer(FakeDatabase(FakePool(conn)))
    await writer._flush([make_uplink()])
    # no exception expected; nothing to assert


class FakeMercurePublisher(MercurePublisher):
    def __init__(self):
        self.events = []

    async def publish_telemetry(self, device_id, time_iso, points):
        self.events.append({"device_id": device_id, "time": time_iso, "points": points})


async def test_run_drains_queue_into_one_batch():
    conn = FakeConn()
    writer = Writer(FakeDatabase(FakePool(conn)), batch_size=100, batch_timeout=0.05)
    for _ in range(3):
        await writer.put(make_uplink())

    task = asyncio.create_task(writer.run())
    await asyncio.sleep(0.3)
    task.cancel()
    await asyncio.gather(task, return_exceptions=True)

    points = [r for t, r, _ in conn.flushes if t == "telemetry_points"]
    assert points and len(points[0]) == 3


async def test_run_batches_larger_than_max():
    conn = FakeConn()
    writer = Writer(FakeDatabase(FakePool(conn)), batch_size=2, batch_timeout=0.02)
    for _ in range(5):
        await writer.put(make_uplink())

    task = asyncio.create_task(writer.run())
    await asyncio.sleep(0.3)
    task.cancel()
    await asyncio.gather(task, return_exceptions=True)

    points = [r for t, r, _ in conn.flushes if t == "telemetry_points"]
    assert points
    assert max(len(r) for r in points) == 2
