"""Payload normalization: topic + raw JSON -> telemetry points.

Pure functions only — no I/O — so the module is trivially testable and
reusable by the HTTP ingest endpoint (Task 11) and the Modbus poller
(Task 12).
"""

import json
import logging
import re
import uuid
from dataclasses import dataclass, replace
from datetime import UTC, datetime

logger = logging.getLogger(__name__)

# ChirpStack v4 event envelopes carry a lot of metadata; only the decoded
# `object` (when a codec is configured) or explicit telemetry keys are used.
_LORAWAN_META_KEYS = {
    "applicationID",
    "applicationName",
    "deviceName",
    "devEUI",
    "deviceProfileID",
    "deviceProfileName",
    "data",
    "fPort",
    "fCnt",
    "rxInfo",
    "txInfo",
    "tags",
    "acknowledged",
    "confirmed",
    "publishedAt",
    "adr",
    "dr",
    "frequency",
    "rawBytes",
    "location",
    "time",
}

_FLOAT_RE = re.compile(r"^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$")
_INT_RE = re.compile(r"^[+-]?\d+$")


class NormalizeError(Exception):
    """Payload could not be parsed; the message must be quarantined."""


@dataclass(frozen=True)
class TelemetryPoint:
    time: datetime
    device_id: str | None
    field: str
    value: float
    type: str
    quality: int = 0


@dataclass(frozen=True)
class Uplink:
    topic: str
    protocol: str
    device_id: str | None
    dev_eui: str | None
    time: datetime
    payload: dict
    points: tuple[TelemetryPoint, ...] = ()

    def with_device_id(self, device_id: str) -> "Uplink":
        """Return a copy with the (resolved) device id set on all points."""
        points = tuple(
            replace(p, device_id=device_id) if p.device_id is None else p
            for p in self.points
        )
        return replace(self, device_id=device_id, points=points)


def normalize_payload(device_id: str, data: dict) -> Uplink:
    """Normalize a plain JSON object (e.g. an HTTP ingest body) for a device.

    The HTTP body is treated exactly like the flat payload MQTT devices send:
    numeric fields map to points, nested/string fields are skipped, and an
    optional top-level `time` sets the sample timestamp.
    """
    sample_time = _parse_time(data.get("time"))
    points = tuple(_to_points(data, sample_time, device_id))
    return Uplink(
        topic=f"http/{device_id}",
        protocol="http",
        device_id=device_id,
        dev_eui=None,
        time=sample_time,
        payload=data,
        points=points,
    )


def normalize(topic: str, payload: bytes | str) -> Uplink:
    topic = str(topic)  # aiomqtt 2.x messages carry a paho `Topic`, not a plain str
    protocol, device_id, dev_eui = _parse_topic(topic)
    data = _load_json(payload)

    if protocol == "lorawan":
        fields = data.get("object") if isinstance(data.get("object"), dict) else None
        if fields is None:
            fields = {k: v for k, v in data.items() if k not in _LORAWAN_META_KEYS}
    else:
        fields = data

    sample_time = _parse_time(fields.get("time") or data.get("time"))
    points = tuple(_to_points(fields, sample_time, device_id))
    return Uplink(
        topic=topic,
        protocol=protocol,
        device_id=device_id,
        dev_eui=dev_eui,
        time=sample_time,
        payload=data,
        points=points,
    )


def _parse_topic(topic: str) -> tuple[str, str | None, str | None]:
    """Return (protocol, device_id, dev_eui) from a known topic pattern."""
    parts = topic.split("/")
    if len(parts) == 3 and parts[0] == "devices" and parts[2] == "up":
        return "mqtt", _require_uuid(parts[1], topic), None
    if len(parts) == 3 and parts[0] == "modbus" and parts[2] == "up":
        return "modbus", _require_uuid(parts[1], topic), None
    if (
        len(parts) == 6
        and parts[0] == "application"
        and parts[1]
        and parts[2] == "device"
        and parts[3]
        and parts[4] == "event"
        and parts[5] == "up"
    ):
        return "lorawan", None, parts[3]
    raise NormalizeError(f"unrecognized topic pattern: {topic!r}")


def _require_uuid(value: str, topic: str) -> str:
    try:
        return str(uuid.UUID(value))
    except ValueError as exc:
        raise NormalizeError(f"invalid device id in topic {topic!r}") from exc


def _load_json(payload: bytes | str) -> dict:
    if isinstance(payload, bytes):
        try:
            payload = payload.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise NormalizeError("payload is not UTF-8 JSON") from exc
    try:
        data = json.loads(payload)
    except json.JSONDecodeError as exc:
        raise NormalizeError("payload is not valid JSON") from exc
    if not isinstance(data, dict):
        raise NormalizeError("payload must be a JSON object")
    return data


def _parse_time(raw: object) -> datetime:
    if raw is None:
        return datetime.now(UTC)
    if isinstance(raw, datetime):
        return raw
    try:
        parsed = datetime.fromisoformat(str(raw))
    except ValueError as exc:
        raise NormalizeError(f"unparseable timestamp: {raw!r}") from exc
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=UTC)
    return parsed


def _to_points(fields: dict, sample_time: datetime, device_id: str | None) -> list[TelemetryPoint]:
    points = []
    for field, value in fields.items():
        if field == "time":
            continue
        numeric = classify_value(value)
        if numeric is None:
            logger.debug("skipping non-numeric field %r=%r", field, value)
            continue
        points.append(
            TelemetryPoint(
                time=sample_time,
                device_id=device_id,
                field=field,
                value=numeric[1],
                type=numeric[0],
            )
        )
    return points


def classify_value(value: object) -> tuple[str, float] | None:
    """Return (type, float value) for a telemetry field, or None to skip it."""
    if isinstance(value, bool):
        return ("bool", 1.0 if value else 0.0)
    if isinstance(value, int):
        return ("int", float(value))
    if isinstance(value, float):
        return ("float", value)
    if isinstance(value, str) and _FLOAT_RE.match(value):
        if _INT_RE.match(value):
            return ("int", float(value))
        return ("float", float(value))
    return None
