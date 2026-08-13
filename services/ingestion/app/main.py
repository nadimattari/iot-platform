"""Ingestion service entrypoint.

Wires together the FastAPI app, the asyncpg pool, the batched writer task and
the MQTT subscriber (when enabled). `create_app` is a factory so tests can
inject a `Settings` without a broker or database.
"""

import asyncio
import logging
from collections.abc import AsyncIterator, Awaitable, Callable
from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI

from .config import Settings
from .db import Database
from .devices import resolve_device_id
from .mercure import MercurePublisher
from .mqtt import MqttSubscriber
from .normalizer import NormalizeError, Uplink, normalize
from .routes.ingest import router as ingest_router
from .writer import Writer

logger = logging.getLogger(__name__)


def supervise(task: asyncio.Task, name: str) -> asyncio.Task:
    """Log a background task's terminal exception so it never dies silently.

    A task created with `create_task` only reports its exception when garbage
    collected; the lifespan keeps references, so without this a crashed writer
    or poller would stall the pipeline with no trace. `exc_info` is captured in
    the callback to render the full traceback.
    """

    def _done(completed: asyncio.Task) -> None:
        if completed.cancelled():
            return
        exc = completed.exception()
        if exc is not None:
            logger.error("%s task crashed: %s", name, exc, exc_info=exc)

    task.add_done_callback(_done)
    return task


def make_handler(
    db: Database,
    writer: Writer,
) -> Callable[[Any], Awaitable[None]]:
    """Build the per-message pipeline: normalize -> resolve -> enqueue."""

    async def handler(message: Any) -> None:
        try:
            uplink = normalize(message.topic, message.payload)
        except NormalizeError as exc:
            logger.warning("quarantining message on %r: %s", message.topic, exc)
            return
        if uplink.device_id is None:
            if not uplink.dev_eui:
                logger.warning("quarantining message on %r: no device identifier", message.topic)
                return
            device_id = await resolve_device_id(db.pool, uplink.dev_eui)
            if device_id is None:
                logger.warning("quarantining LoRaWAN uplink: unknown dev_eui %r", uplink.dev_eui)
                return
            uplink = uplink.with_device_id(device_id)
        await writer.put(uplink)

    return handler


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or Settings()

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        db = Database(settings)
        await db.open()
        app.state.db = db

        mercure = MercurePublisher(settings.mercure_hub_url, settings.mercure_publisher_jwt_key)
        app.state.mercure = mercure

        writer = Writer(
            db,
            batch_size=settings.write_batch_size,
            batch_timeout=settings.write_batch_timeout,
            queue_maxsize=settings.write_queue_maxsize,
            mercure=mercure,
        )
        app.state.writer = writer
        writer_task = supervise(asyncio.create_task(writer.run()), "writer")

        subscriber_task: asyncio.Task[None] | None = None
        if settings.mqtt_enabled:
            subscriber = MqttSubscriber(settings, make_handler(db, writer))
            subscriber_task = supervise(asyncio.create_task(subscriber.run()), "mqtt subscriber")

        modbus_task: asyncio.Task[None] | None = None
        if settings.modbus_enabled:
            from .modbus import ModbusPoller, MqttPublisher

            poller = ModbusPoller(db.pool, MqttPublisher(settings), settings)
            modbus_task = supervise(asyncio.create_task(poller.run()), "modbus poller")

        try:
            yield
        finally:
            for task in (subscriber_task, writer_task, modbus_task):
                if task is not None:
                    task.cancel()
            await asyncio.gather(
                *(t for t in (subscriber_task, writer_task, modbus_task) if t is not None),
                return_exceptions=True,
            )
            await mercure.aclose()
            await db.close()

    app = FastAPI(title="iiot ingestion service", version="0.1.0", lifespan=lifespan)

    app.include_router(ingest_router)

    @app.get("/health")
    async def health() -> dict[str, str]:
        connected = await app.state.db.ping()
        return {"status": "ok", "db": "connected" if connected else "unavailable"}

    return app


app = create_app()
