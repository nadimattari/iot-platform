"""Mercure SSE publisher.

Pushes normalized telemetry events to the Mercure hub so the dashboard can
subscribe to `/devices/{deviceId}` and update live values without polling.
Publish authorization uses a per-topic HS256 JWT signed with the shared
publisher key (Mercure `publish` claim), scoped to exactly the topic being
published so no caller can publish to another device's stream.
"""

import base64
import hashlib
import hmac
import json
import logging
import time
from typing import Any

import httpx

logger = logging.getLogger(__name__)


def _b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def _jwt(jwt_key: str, topic: str) -> str:
    """Build an HS256 JWT authorizing publication to one topic."""
    header = _b64url(json.dumps({"alg": "HS256", "typ": "JWT"}).encode())
    payload = _b64url(
        json.dumps(
            {
                "mercure": {"publish": [topic]},
                "exp": int(time.time()) + 300,
            }
        ).encode()
    )
    signing_input = f"{header}.{payload}".encode()
    signature = hmac.new(jwt_key.encode(), signing_input, hashlib.sha256).digest()
    return f"{header}.{payload}.{_b64url(signature)}"


class MercurePublisher:
    """Publishes events to the Mercure hub over HTTP POST.

    Failures are logged and swallowed: a broken hub must never stall the
    telemetry ingestion pipeline.
    """

    def __init__(self, hub_url: str, jwt_key: str, timeout: float = 3.0) -> None:
        self._hub_url = hub_url.rstrip("/")
        self._jwt_key = jwt_key
        self._client = httpx.AsyncClient(timeout=timeout)

    async def aclose(self) -> None:
        await self._client.aclose()

    async def publish_telemetry(self, device_id: str, time_iso: str, points: list[dict]) -> None:
        """Publish one SSE event with all the device's points in a write batch."""
        await self._publish(
            f"/devices/{device_id}",
            json.dumps({"device_id": device_id, "time": time_iso, "points": points}),
        )

    async def _publish(self, topic: str, data: str) -> None:
        try:
            response = await self._client.post(
                self._hub_url,
                data={"topic": topic, "data": data},
                headers={"Authorization": f"Bearer {_jwt(self._jwt_key, topic)}"},
            )
            response.raise_for_status()
        except httpx.HTTPError:
            logger.warning("mercure publish to %s failed", topic, exc_info=True)


def telemetry_points_by_device(
    point_rows: list[tuple[Any, ...]],
) -> dict[str, list[dict]]:
    """Group COPY point rows `(time, device_id, field, value, type, quality)`
    into per-device list of point dicts for the SSE payload."""
    by_device: dict[str, list[dict]] = {}
    for time_, device_id, field, value, type_, quality in point_rows:
        by_device.setdefault(device_id, []).append(
            {"field": field, "value": value, "type": type_, "quality": quality}
        )
    return by_device
