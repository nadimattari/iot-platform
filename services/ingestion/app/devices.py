"""Device identity lookup used by the ingestion pipeline.

The MQTT topics for MQTT/Modbus devices carry the platform device UUID, but
ChirpStack uplinks only carry a `dev_eui`. Uplinks on
`application/+/device/+/event/up` are resolved here against the `devices`
table (owned by device-mgmt) before being written.
"""

import logging

import asyncpg

logger = logging.getLogger(__name__)


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
