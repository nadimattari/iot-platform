"""Device identity lookup used by the ingestion pipeline.

The MQTT topics for MQTT/Modbus devices carry the platform device UUID, but
ChirpStack uplinks only carry a `dev_eui`. Uplinks on
`application/+/device/+/event/up` are resolved here against the `devices`
table (owned by device-mgmt) before being written. The HTTP ingest endpoint
uses the same table to authenticate device API keys (Task 11).
"""

import hashlib
import hmac
import json
import logging
from dataclasses import dataclass

import asyncpg

from .schema import ProfileSchema

logger = logging.getLogger(__name__)


def parse_json(value: object) -> object:
    """asyncpg returns jsonb columns as str; decode them when needed."""
    if isinstance(value, str):
        try:
            return json.loads(value)
        except json.JSONDecodeError:
            return None
    return value


@dataclass(frozen=True)
class DeviceIdentity:
    device_id: str
    enabled: bool
    api_key_hash: str | None
    metadata: dict


async def resolve_device_id(pool: asyncpg.Pool, dev_eui: str) -> str | None:
    """Return the platform device id for a LoRaWAN dev_eui, or None if unknown."""
    try:
        row = await pool.fetchrow(
            "SELECT id FROM devices WHERE LOWER(dev_eui) = LOWER($1)", dev_eui
        )
    except Exception:
        logger.warning("device lookup failed for dev_eui %r", dev_eui, exc_info=True)
        return None
    return row["id"] if row is not None else None


async def fetch_device(pool: asyncpg.Pool, device_id: str) -> DeviceIdentity | None:
    """Return the device identity used to authenticate HTTP ingest, or None."""
    try:
        row = await pool.fetchrow(
            "SELECT id, enabled, api_key_hash, metadata FROM devices WHERE id = $1",
            device_id,
        )
    except Exception:
        logger.warning("device lookup failed for id %r", device_id, exc_info=True)
        return None
    if row is None:
        return None
    metadata = parse_json(row["metadata"])
    return DeviceIdentity(
        device_id=row["id"],
        enabled=row["enabled"],
        api_key_hash=row["api_key_hash"],
        metadata=metadata if isinstance(metadata, dict) else {},
    )


def verify_api_key(identity: DeviceIdentity | None, api_key: str | None) -> bool:
    """Constant-time check of an API key against a device's stored SHA-256 hash.

    An unknown device or a device without a hash compares against the empty
    hash so response timing does not reveal whether a device id exists.
    """
    expected = (identity.api_key_hash if identity is not None else None) or ""
    supplied = hashlib.sha256((api_key or "").encode()).hexdigest()
    return hmac.compare_digest(supplied, expected)


async def fetch_profile_schema(
    pool: asyncpg.Pool, identity: DeviceIdentity
) -> ProfileSchema | None:
    """Resolve the device's schema from `metadata.profile_id`, if referenced."""
    profile_id = identity.metadata.get("profile_id")
    if not isinstance(profile_id, str) or not profile_id:
        return None
    try:
        row = await pool.fetchrow(
            "SELECT field_defs FROM device_profiles WHERE id = $1", profile_id
        )
    except Exception:
        logger.warning("profile lookup failed for id %r", profile_id, exc_info=True)
        return None
    if row is None:
        logger.warning(
            "device %s references unknown profile %r", identity.device_id, profile_id
        )
        return None
    field_defs = parse_json(row["field_defs"])
    return ProfileSchema.from_field_defs(field_defs)
