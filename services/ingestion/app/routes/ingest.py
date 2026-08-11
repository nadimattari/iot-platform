"""HTTP ingest endpoint (Task 11).

`POST /ingest/http/{device_id}` authenticates a device with its API key
(`X-API-Key`, constant-time SHA-256 compare against `devices.api_key_hash`),
validates the JSON body against the device's profile schema (resolved from
`metadata.profile_id` -> `device_profiles.field_defs`) and enqueues the
normalized points through the same writer as the MQTT path.
"""

import json
import logging
import uuid
from typing import Any

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse

from ..devices import fetch_device, fetch_profile_schema, verify_api_key
from ..normalizer import NormalizeError, normalize_payload
from ..schema import SchemaValidationError

logger = logging.getLogger(__name__)

router = APIRouter()

_MAX_BODY_BYTES = 64 * 1024


def _error(status: int, code: str, message: str, details: Any = None) -> JSONResponse:
    payload: dict[str, Any] = {"code": code, "message": message}
    if details is not None:
        payload["details"] = details
    return JSONResponse(status_code=status, content={"error": payload})


@router.post("/ingest/http/{device_id}", status_code=202)
async def ingest_http(device_id: str, request: Request) -> Any:
    state = request.app.state

    try:
        uuid.UUID(device_id)
    except ValueError:
        return _error(401, "UNAUTHORIZED", "invalid device id")

    api_key = request.headers.get("X-API-Key")
    identity = await fetch_device(state.db.pool, device_id)
    if not verify_api_key(identity, api_key) or (identity is not None and not identity.enabled):
        return _error(401, "UNAUTHORIZED", "invalid device credentials")

    raw = await request.body()
    if len(raw) > _MAX_BODY_BYTES:
        return _error(413, "PAYLOAD_TOO_LARGE", f"body exceeds {_MAX_BODY_BYTES} bytes")

    try:
        payload = json.loads(raw)
    except (json.JSONDecodeError, UnicodeDecodeError):
        return _error(422, "VALIDATION_ERROR", "body must be a JSON object")
    if not isinstance(payload, dict):
        return _error(422, "VALIDATION_ERROR", "body must be a JSON object")

    schema = await fetch_profile_schema(state.db.pool, identity)
    if schema is not None:
        try:
            schema.validate(payload)
        except SchemaValidationError as exc:
            return _error(
                422,
                "VALIDATION_ERROR",
                "payload does not satisfy the device profile schema",
                details=exc.errors,
            )

    try:
        uplink = normalize_payload(identity.device_id, payload)
    except NormalizeError as exc:
        return _error(422, "VALIDATION_ERROR", str(exc))

    await state.writer.put(uplink)
    return {"accepted": True, "points": len(uplink.points)}
