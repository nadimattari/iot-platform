"""Batched telemetry writer with backpressure.

The MQTT loop only ever awaits `put()` on a bounded queue — it never touches
the database. A single writer task drains the queue and flushes batches to
`telemetry.telemetry_raw` (audit) and `telemetry.telemetry_points` (canonical)
inside one transaction, using `COPY` for burst throughput.
"""

import asyncio
import json
import logging
from typing import Any

from .db import Database
from .mercure import MercurePublisher, telemetry_points_by_device
from .normalizer import Uplink

logger = logging.getLogger(__name__)

_RAW_COLUMNS = ["time", "device_id", "protocol", "raw", "payload"]
_POINT_COLUMNS = ["time", "device_id", "field", "value", "type", "quality"]


class Writer:
    def __init__(
        self,
        db: Database,
        *,
        batch_size: int = 500,
        batch_timeout: float = 0.2,
        queue_maxsize: int = 10000,
        mercure: MercurePublisher | None = None,
    ) -> None:
        self._db = db
        self._batch_size = batch_size
        self._batch_timeout = batch_timeout
        self._queue: asyncio.Queue[Uplink] = asyncio.Queue(maxsize=queue_maxsize)
        self._mercure = mercure

    @property
    def queue_size(self) -> int:
        return self._queue.qsize()

    async def put(self, uplink: Uplink) -> None:
        """Enqueue an uplink. Backpressure: pauses the producer, never blocks the socket."""
        await self._queue.put(uplink)

    async def run(self) -> None:
        while True:
            batch = [await self._queue.get()]
            loop = asyncio.get_running_loop()
            deadline = loop.time() + self._batch_timeout
            while len(batch) < self._batch_size:
                remaining = deadline - loop.time()
                if remaining <= 0:
                    break
                try:
                    batch.append(await asyncio.wait_for(self._queue.get(), remaining))
                except asyncio.TimeoutError:
                    break
            await self._flush(batch)

    async def _flush(self, batch: list[Uplink]) -> None:
        pool = self._db.pool
        if pool is None:
            logger.warning("dropping %d uplink(s): no database pool", len(batch))
            return
        raw_rows = [
            (u.time, u.device_id, u.protocol, u.raw, json.dumps(u.payload))
            for u in batch
            if u.device_id is not None
        ]
        point_rows = [
            (p.time, p.device_id, p.field, p.value, p.type, p.quality)
            for u in batch
            if u.device_id is not None
            for p in u.points
        ]
        if not raw_rows and not point_rows:
            return
        try:
            async with pool.acquire() as conn:
                async with conn.transaction():
                    if raw_rows:
                        await conn.copy_records_to_table(
                            "telemetry_raw", records=raw_rows,
                            columns=_RAW_COLUMNS, schema_name="telemetry",
                        )
                    if point_rows:
                        await conn.copy_records_to_table(
                            "telemetry_points", records=point_rows,
                            columns=_POINT_COLUMNS, schema_name="telemetry",
                        )
        except Exception:
            logger.exception("write batch of %d uplink(s) failed", len(batch))
            return

        await self._publish_events(point_rows)

    async def _publish_events(self, point_rows: list[tuple[Any, ...]]) -> None:
        """Push one Mercure event per device in the batch, after a successful write."""
        if self._mercure is None or not point_rows:
            return
        time_iso = point_rows[0][0].isoformat()
        for device_id, points in telemetry_points_by_device(point_rows).items():
            await self._mercure.publish_telemetry(device_id, time_iso, points)
