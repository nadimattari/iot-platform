"""Asyncio MQTT subscriber with automatic reconnection.

Subscribes to the platform uplink topics and hands every message to the
pipeline. The paho network loop runs on a worker thread, so awaiting slow
pipeline steps (backpressure) never blocks the socket. Connection errors
cause a retry with a short sleep; cancellation propagates for clean shutdown.
"""

import asyncio
import logging
from collections.abc import Awaitable, Callable

import aiomqtt

from .config import Settings

logger = logging.getLogger(__name__)

TOPIC_FILTERS: list[tuple[str, int]] = [
    ("devices/+/up", 0),
    ("modbus/+/up", 0),
    ("application/+/device/+/event/up", 0),
]


class MqttSubscriber:
    def __init__(
        self,
        settings: Settings,
        on_message: Callable[[aiomqtt.Message], Awaitable[None]],
    ) -> None:
        self._settings = settings
        self._on_message = on_message

    async def run(self) -> None:
        while True:
            try:
                async with aiomqtt.Client(
                    hostname=self._settings.mqtt_host,
                    port=self._settings.mqtt_port,
                    username=self._settings.mqtt_username,
                    password=self._settings.mqtt_password,
                ) as client:
                    for topic, qos in TOPIC_FILTERS:
                        await client.subscribe(topic, qos)
                    logger.info(
                        "connected to MQTT at %s:%s", self._settings.mqtt_host, self._settings.mqtt_port
                    )
                    async for message in client.messages:
                        try:
                            await self._on_message(message)
                        except Exception:
                            logger.exception("error handling message on %r", message.topic)
            except aiomqtt.MqttError as exc:
                logger.warning("MQTT connection lost: %s; reconnecting", exc)
                await asyncio.sleep(1)
            except asyncio.CancelledError:
                raise
