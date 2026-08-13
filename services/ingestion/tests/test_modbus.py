"""Task 12: Modbus TCP poller.

Unit tests use fakes for the Modbus reader and MQTT publisher so scheduling,
decoding, error isolation and config reload are tested without I/O.
"""

import asyncio
import uuid

from app.config import Settings
from app.modbus import (
    ModbusConnection,
    ModbusPoller,
    RegisterConfig,
    load_register_configs,
    plan_reconcile,
)
from app.modbus import DevicePoller
from app.normalizer import normalize


class FakeReader:
    def __init__(self, values=None, hang=False, error=None):
        self.values = values or {}
        self.hang = hang
        self.error = error
        self.calls = []
        self.closed = False

    async def read(self, address, count):
        self.calls.append((address, count))
        if self.hang:
            await asyncio.sleep(3600)
        if self.error is not None:
            raise self.error
        if (address, count) in self.values:
            return self.values[(address, count)]
        return [0] * count

    async def close(self):
        self.closed = True


class FakePublisher:
    def __init__(self):
        self.messages = []

    async def publish(self, topic, payload):
        self.messages.append((topic, payload))


def settings(**overrides):
    return Settings(
        _env_file=None,
        pg_host="127.0.0.1",
        pg_port=1,
        pool_min_size=1,
        pool_max_size=1,
        mqtt_enabled=False,
        modbus_enabled=False,
        **overrides,
    )


async def test_load_groups_registers_and_resolves_connection():
    class Pool:
        async def fetch(self, sql, *args):
            return [
                {
                    "device_id": "d1",
                    "metadata": '{"modbus_host": "plc-1", "modbus_port": 1502, "modbus_unit_id": 3}',
                    "name": "temperature",
                    "address": 0,
                    "datatype": "float32",
                    "byteorder": "big",
                    "scale": 0.1,
                    "interval_secs": 5,
                },
                {
                    "device_id": "d1",
                    "metadata": '{"modbus_host": "plc-1", "modbus_port": 1502, "modbus_unit_id": 3}',
                    "name": "rpm",
                    "address": 2,
                    "datatype": "uint32",
                    "byteorder": "little",
                    "scale": 1.0,
                    "interval_secs": 30,
                },
                {
                    "device_id": "d2",
                    "metadata": "{}",
                    "name": "voltage",
                    "address": 0,
                    "datatype": "uint16",
                    "byteorder": "big",
                    "scale": 1.0,
                    "interval_secs": 10,
                },
            ]

    groups = await load_register_configs(Pool(), settings(modbus_default_host="gw", modbus_default_port=502))

    assert "d1" in groups
    conn, regs = groups["d1"]
    assert conn == ModbusConnection("plc-1", 1502, 3)
    assert [r.name for r in regs] == ["temperature", "rpm"]
    assert regs[0].scale == 0.1
    assert regs[0].interval_secs == 5

    conn2, regs2 = groups["d2"]
    assert conn2 == ModbusConnection("gw", 502, 1)


async def test_load_normalizes_uuid_device_id_to_str():
    """asyncpg returns UUID columns as uuid.UUID; group keys must stay strings
    so the poller's `modbus/{id}/up` topic serializes cleanly."""

    class Pool:
        async def fetch(self, sql, *args):
            return [
                {
                    "device_id": uuid.UUID("019feb7d-1111-2222-3333-444455556666"),
                    "metadata": '{"modbus_host": "plc-1"}',
                    "name": "x",
                    "address": 0,
                    "datatype": "uint16",
                    "byteorder": "big",
                    "scale": 1.0,
                    "interval_secs": 10,
                }
            ]

    groups = await load_register_configs(Pool(), settings(modbus_default_host="gw"))

    assert "019feb7d-1111-2222-3333-444455556666" in groups
    assert all(isinstance(key, str) for key in groups)


async def test_load_skips_devices_without_host():
    class Pool:
        async def fetch(self, sql, *args):
            return [
                {
                    "device_id": "d1",
                    "metadata": "{}",
                    "name": "x",
                    "address": 0,
                    "datatype": "uint16",
                    "byteorder": "big",
                    "scale": 1.0,
                    "interval_secs": 10,
                }
            ]

    groups = await load_register_configs(Pool(), settings(modbus_default_host=""))
    assert groups == {}


async def test_load_skips_malformed_rows():
    class Pool:
        async def fetch(self, sql, *args):
            return [
                {
                    "device_id": "d1",
                    "metadata": '{"modbus_host": "plc"}',
                    "name": "ok",
                    "address": 0,
                    "datatype": "uint16",
                    "byteorder": "big",
                    "scale": 1.0,
                    "interval_secs": 10,
                },
                {
                    "device_id": "d1",
                    "metadata": '{"modbus_host": "plc"}',
                    "name": "bad-datatype",
                    "address": 1,
                    "datatype": "word",
                    "byteorder": "big",
                    "scale": 1.0,
                    "interval_secs": 10,
                },
            ]

    groups = await load_register_configs(Pool(), settings(modbus_default_host=""))
    assert [r.name for r in groups["d1"][1]] == ["ok"]


async def test_device_poller_publishes_decoded_sample():
    device_id = "d1"
    reader = FakeReader(values={(0, 2): [0x4000, 0x0000]})
    publisher = FakePublisher()
    poller = DevicePoller(
        device_id,
        ModbusConnection("plc", 502, 1),
        (RegisterConfig("temperature", 0, "float32", "big", 1.0, 60),),
        publisher,
        reader,
        timeout=5.0,
    )

    task = asyncio.create_task(poller.run())
    await asyncio.sleep(0.1)
    task.cancel()
    await asyncio.gather(task, return_exceptions=True)

    assert reader.closed
    assert len(publisher.messages) >= 1
    topic, payload = publisher.messages[0]
    assert topic == f"modbus/{device_id}/up"
    assert payload["temperature"] == 2.0
    assert "time" in payload


async def test_read_error_is_isolated_and_retried():
    reader = FakeReader(error=RuntimeError("device offline"))
    publisher = FakePublisher()
    poller = DevicePoller(
        "d1",
        ModbusConnection("plc", 502, 1),
        (RegisterConfig("temperature", 0, "float32", "big", 1.0, 0.05),),
        publisher,
        reader,
        timeout=1.0,
    )

    task = asyncio.create_task(poller.run())
    await asyncio.sleep(0.15)
    task.cancel()
    await asyncio.gather(task, return_exceptions=True)

    assert publisher.messages == []
    assert len(reader.calls) >= 2


async def test_register_with_shorter_interval_polls_more_often():
    reader = FakeReader(values={(0, 1): [0x0064], (4, 1): [0x00C8]})
    publisher = FakePublisher()
    poller = DevicePoller(
        "d1",
        ModbusConnection("plc", 502, 1),
        (
            RegisterConfig("fast", 0, "uint16", "big", 1.0, 0.02),
            RegisterConfig("slow", 4, "uint16", "big", 1.0, 0.2),
        ),
        publisher,
        reader,
        timeout=1.0,
    )

    task = asyncio.create_task(poller.run())
    await asyncio.sleep(0.35)
    task.cancel()
    await asyncio.gather(task, return_exceptions=True)

    fast_reads = sum(1 for addr, _ in reader.calls if addr == 0)
    slow_reads = sum(1 for addr, _ in reader.calls if addr == 4)
    assert fast_reads > slow_reads
    assert fast_reads >= 5


async def test_dead_device_does_not_stall_other_devices():
    hanging = FakeReader(hang=True)
    healthy = FakeReader(values={(0, 1): [0x0064]})
    publisher = FakePublisher()

    dead = DevicePoller(
        "dead",
        ModbusConnection("plc", 502, 1),
        (RegisterConfig("x", 0, "uint16", "big", 1.0, 0.05),),
        publisher,
        hanging,
        timeout=0.2,
    )
    alive = DevicePoller(
        "alive",
        ModbusConnection("plc", 502, 1),
        (RegisterConfig("y", 0, "uint16", "big", 1.0, 0.05),),
        publisher,
        healthy,
        timeout=0.2,
    )

    tasks = [asyncio.create_task(dead.run()), asyncio.create_task(alive.run())]
    await asyncio.sleep(0.3)
    for t in tasks:
        t.cancel()
    await asyncio.gather(*tasks, return_exceptions=True)

    assert publisher.messages != []
    assert all(topic == "modbus/alive/up" for topic, _ in publisher.messages)


def test_plan_reconcile_starts_keeps_restarts_cancels():
    regs = lambda name: (RegisterConfig(name, 0, "uint16", "big", 1.0, 60),)
    conn = ModbusConnection("plc", 502, 1)

    existing = {
        "a": (hash((conn, regs("a"))), object()),  # unchanged
        "b": (hash((conn, regs("b"))), object()),  # config changes -> restart
    }

    desired = {
        "a": (conn, regs("a")),  # unchanged
        "b": (conn, regs("renamed")),  # changed -> restart
        "c": (conn, regs("c")),  # new
    }

    to_start, to_cancel = plan_reconcile(existing, desired)

    assert to_cancel == {"b"}
    assert set(to_start) == {"b", "c"}
    assert "a" not in to_start


async def test_reconcile_drives_live_poller():
    regs = lambda name: (RegisterConfig(name, 0, "uint16", "big", 1.0, 60),)
    conn = ModbusConnection("plc", 502, 1)

    class Pool:
        def __init__(self):
            self.groups = {}

        async def fetch(self, sql, *args):
            rows = []
            for device_id, (c, rset) in self.groups.items():
                for r in rset:
                    rows.append(
                        {
                            "device_id": device_id,
                            "metadata": '{"modbus_host": "plc"}',
                            "name": r.name,
                            "address": r.address,
                            "datatype": r.datatype,
                            "byteorder": r.byteorder,
                            "scale": r.scale,
                            "interval_secs": r.interval_secs,
                        }
                    )
            return rows

    pool = Pool()
    pool.groups = {"a": (conn, regs("temperature"))}

    publisher = FakePublisher()
    poller = ModbusPoller(
        pool,
        publisher,
        settings(modbus_reload_interval=0.05, modbus_default_host="plc"),
        reader_factory=lambda _c: FakeReader(values={(0, 1): [0x0064]}),
    )

    task = asyncio.create_task(poller.run())
    await asyncio.sleep(0.15)
    assert any(topic == "modbus/a/up" for topic, _ in publisher.messages)

    pool.groups = {"a": (conn, regs("renamed"))}
    await asyncio.sleep(0.15)
    assert len(publisher.messages) >= 1

    pool.groups = {}
    await asyncio.sleep(0.15)
    task.cancel()
    await asyncio.gather(task, return_exceptions=True)


def test_decoded_payload_flows_through_normalizer():
    from app.normalizer import normalize

    device_id = "019feb7d-042f-7b39-a521-506bddd3e243"
    topic = f"modbus/{device_id}/up"
    payload = b'{"time":"2026-08-10T10:00:00Z","temperature":2.0}'
    uplink = normalize(topic, payload)
    assert uplink.protocol == "modbus"
    assert uplink.points[0].field == "temperature"
    assert uplink.points[0].value == 2.0
