"""LoRaWAN uplink handling: ChirpStack event envelope -> normalized fields.

Pure functions only — no I/O — so the module is trivially testable. ChirpStack
publishes each uplink to `application/{application_id}/device/{dev_eui}/event/up`
as a JSON envelope. `data` is the base64-encoded FRMPayload; a payload codec
(configured on the device profile) additionally yields an `object` with decoded
fields. The raw bytes are stored in `telemetry_raw` for replay/audit.
"""

import base64
import binascii
import logging

logger = logging.getLogger(__name__)

RAW_FIELD = "data"


def extract_raw(envelope: dict) -> bytes | None:
    """Return the raw FRMPayload bytes, or None when absent or undecodable.

    Missing or malformed `data` is not fatal: the envelope is still stored in
    `telemetry_raw.payload` so nothing is lost. A value that is present but not
    base64 suggests a non-ChirpStack producer on the topic.
    """
    data = envelope.get(RAW_FIELD)
    if not isinstance(data, str) or not data:
        return None
    try:
        return base64.b64decode(data)
    except (binascii.Error, ValueError):
        logger.debug("ignoring undecodable base64 in %r", RAW_FIELD)
        return None
