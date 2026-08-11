"""Modbus TCP poller (Task 12).

Loads `modbus_register_config` (owned by device-mgmt) grouped per device,
polls each configured register on its own interval, decodes the raw words via
`decoder.py` and publishes the sample on `modbus/{deviceId}/up` so it flows
through the normalizer/writer pipeline like any other uplink.

Scheduling guarantees:

- per-register cadence: every register keeps its own next-due timestamp;
- dead-device isolation: each device runs in its own task, so a hung or
  unresponsive PLC only stalls its own registers, never other devices;
- live config reload: register config CRUD is picked up on a short reload
  loop, restarting only the device tasks whose config changed.
"""

import asyncio
import json
import logging
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from datetime import UTC, datetime
from time import monotonic
from typing import Any, Protocol

from .config import Settings
from .decoder import BYTE_ORDERS, DATATYPE_WIDTHS, DecodeError, decode_registers, register_count
from .devices import parse_json

logger = logging.getLogger(__name__)


def _iso_now() -> str:
    return datetime.now(UTC).isoformat()


@dataclass(frozen=True)
class ModbusConnection:
    host: str
    port: int
    unit_id: int


@dataclass(frozen=True)
class RegisterConfig:
    name: str
    address: int
    datatype: str
    byteorder: str
    scale: float
    interval_secs: float


class AsyncModbusReader(Protocol):
    async def read(self, address: int, count: int) -> list[int]: ...

    async def close(self) -> None: ...


class PymodbusReader:
    """pymodbus-backed reader with a lazily (re)connecting TCP client."""

    def __init__(self, connection: ModbusConnection, *, timeout: float) -> None:
        from pymodbus.client import AsyncModbusTcpClient

        self._connection = connection
        self._timeout = timeout
        self._client: AsyncModbusTcpClient | None = None

    async def read(self, address: int, count: int) -> list[int]:
        if self._client is None or not self._client.connected:
            await self._connect()
        assert self._client is not None
        result = await self._client.read_holding_registers(
            address=address, count=count, device_id=self._connection.unit_id
        )
        if result.isError():
            raise ConnectionError(f"modbus read error: {result}")
        return list(result.registers)

    async def _connect(self) -> None:
        from pymodbus.client import AsyncModbusTcpClient

        client = AsyncModbusTcpClient(
            self._connection.host,
            port=self._connection.port,
            timeout=self._timeout,
        )
        connected = await client.connect()
        if not connected:
            client.close()
            raise ConnectionError(
                f"cannot connect to modbus device {self._connection.host}:{self._connection.port}"
            )
        self._client = client

    async def close(self) -> None:
        if self._client is not None:
            self._client.close()
            self._client = None


class MqttPublisher:
    """Connect-per-publish MQTT publisher used by the poller.

    A short-lived connection keeps the poller independent of broker state:
    a broker outage only costs a timeout on the affected sample, and the next
    poll interval retries.
    """

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._lock = asyncio.Lock()

    async def publish(self, topic: str, payload: dict) -> None:
        import aiomqtt

        body = json.dumps(payload, ensure_ascii=False).encode()

        async def _do() -> None:
            async with aiomqtt.Client(
                hostname=self._settings.mqtt_host,
                port=self._settings.mqtt_port,
                username=self._settings.mqtt_username,
                password=self._settings.mqtt_password,
                timeout=self._settings.modbus_publish_timeout,
            ) as client:
                await client.publish(topic, body)

        async with self._lock:
            await asyncio.wait_for(_do(), timeout=self._settings.modbus_publish_timeout)


class DevicePoller:
    """Polls a single device's registers on their own intervals."""

    def __init__(
        self,
        device_id: str,
        connection: ModbusConnection,
        registers: Sequence[RegisterConfig],
        publisher: Any,
        reader: AsyncModbusReader,
        *,
        timeout: float,
    ) -> None:
        self._device_id = device_id
        self._connection = connection
        self._registers = tuple(registers)
        self._publisher = publisher
        self._reader = reader
        self._timeout = timeout

    async def run(self) -> None:
        next_due = [monotonic()] * len(self._registers)
        try:
            while True:
                index = min(range(len(next_due)), key=next_due.__getitem__)
                delay = next_due[index] - monotonic()
                if delay > 0:
                    await asyncio.sleep(delay)

                register = self._registers[index]
                started = monotonic()
                try:
                    value = await asyncio.wait_for(self._read(register), timeout=self._timeout)
                except asyncio.CancelledError:
                    raise
                except Exception as exc:
                    await self._reader.close()
                    logger.warning(
                        "modbus read failed device=%s register=%s: %s",
                        self._device_id,
                        register.name,
                        exc,
                    )
                    next_due[index] = started + register.interval_secs
                    continue

                next_due[index] = started + register.interval_secs
                payload = {"time": _iso_now(), register.name: value}
                try:
                    await self._publisher.publish(f"modbus/{self._device_id}/up", payload)
                except Exception as exc:
                    logger.warning(
                        "modbus publish failed device=%s register=%s: %s",
                        self._device_id,
                        register.name,
                        exc,
                    )
        finally:
            await self._reader.close()

    async def _read(self, register: RegisterConfig) -> float:
        words = await self._reader.read(register.address, register_count(register.datatype))
        return decode_registers(
            words, register.datatype, register.byteorder, register.scale
        )


async def load_register_configs(
    pool: Any, cfg: Settings
) -> dict[str, tuple[ModbusConnection, tuple[RegisterConfig, ...]]]:
    """Load register config grouped per enabled device, resolving connections.

    Devices whose connection host cannot be resolved are skipped (with a
    warning) so an unconfigured device never spams the broker.
    """
    rows = await pool.fetch(
        """
        SELECT d.id AS device_id, d.metadata,
               r.name, r.address, r.datatype, r.byteorder, r.scale, r.interval_secs
        FROM modbus_register_config r
        JOIN devices d ON d.id = r.device_id
        WHERE d.enabled = true
        ORDER BY d.id, r.address
        """
    )

    devices: dict[str, dict[str, Any]] = {}
    for row in rows:
        try:
            register = RegisterConfig(
                name=row["name"],
                address=int(row["address"]),
                datatype=row["datatype"],
                byteorder=row["byteorder"],
                scale=float(row["scale"]),
                interval_secs=float(row["interval_secs"]),
            )
            if register.address < 0 or register.interval_secs < 1:
                raise ValueError("address/interval_secs out of range")
            if (
                register.datatype not in DATATYPE_WIDTHS
                or register.byteorder not in BYTE_ORDERS
            ):
                raise ValueError("unknown datatype/byteorder")
        except (TypeError, ValueError, KeyError):
            logger.warning("skipping malformed modbus register config: %r", row)
            continue
        entry = devices.setdefault(
            row["device_id"], {"metadata": row["metadata"], "registers": []}
        )
        entry["registers"].append(register)

    groups: dict[str, tuple[ModbusConnection, tuple[RegisterConfig, ...]]] = {}
    for device_id, entry in devices.items():
        metadata = parse_json(entry["metadata"])
        if not isinstance(metadata, dict):
            metadata = {}
        host = metadata.get("modbus_host") or cfg.modbus_default_host
        if not host:
            logger.warning("skipping modbus device %s: no host configured", device_id)
            continue
        connection = ModbusConnection(
            host=host,
            port=int(metadata.get("modbus_port") or cfg.modbus_default_port),
            unit_id=int(metadata.get("modbus_unit_id") or cfg.modbus_default_unit_id),
        )
        groups[device_id] = (connection, tuple(entry["registers"]))

    return groups


def plan_reconcile(
    existing: dict[str, tuple[int, Any]],
    desired: dict[str, tuple[ModbusConnection, tuple[RegisterConfig, ...]]],
) -> tuple[dict[str, tuple[ModbusConnection, tuple[RegisterConfig, ...]]], set[str]]:
    """Return (configs to start, device ids to cancel) to converge tasks.

    A device is restarted only when its connection or register set hash
    changed; unchanged devices keep their running task.
    """
    to_cancel = set(existing) - set(desired)
    to_start: dict[str, tuple[ModbusConnection, tuple[RegisterConfig, ...]]] = {}
    for device_id, (connection, registers) in desired.items():
        config_hash = hash((connection, registers))
        current = existing.get(device_id)
        if current is not None and current[0] == config_hash:
            continue
        if current is not None:
            to_cancel.add(device_id)
        to_start[device_id] = (connection, registers)
    return to_start, to_cancel


class ModbusPoller:
    def __init__(
        self,
        db: Any,
        publisher: Any,
        settings: Settings,
        reader_factory: Callable[[ModbusConnection], AsyncModbusReader] | None = None,
    ) -> None:
        self._db = db
        self._publisher = publisher
        self._settings = settings
        self._reader_factory = reader_factory or (
            lambda conn: PymodbusReader(conn, timeout=settings.modbus_read_timeout)
        )
        self._tasks: dict[str, tuple[int, asyncio.Task]] = {}

    async def run(self) -> None:
        try:
            while True:
                groups = await load_register_configs(self._db, self._settings)
                await self._reconcile(groups)
                await asyncio.sleep(self._settings.modbus_reload_interval)
        finally:
            for _, task in self._tasks.values():
                task.cancel()
            await asyncio.gather(
                *(task for _, task in self._tasks.values()), return_exceptions=True
            )

    async def _reconcile(
        self, groups: dict[str, tuple[ModbusConnection, tuple[RegisterConfig, ...]]]
    ) -> None:
        to_start, to_cancel = plan_reconcile(self._tasks, groups)

        cancelled: list[asyncio.Task] = []
        for device_id in to_cancel:
            _, task = self._tasks.pop(device_id)
            task.cancel()
            cancelled.append(task)
        if cancelled:
            await asyncio.gather(*cancelled, return_exceptions=True)

        for device_id, (connection, registers) in to_start.items():
            reader = self._reader_factory(connection)
            poller = DevicePoller(
                device_id,
                connection,
                registers,
                self._publisher,
                reader,
                timeout=self._settings.modbus_read_timeout,
            )
            task = asyncio.create_task(poller.run())
            self._tasks[device_id] = (hash((connection, registers)), task)
