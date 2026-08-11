"""Declarative field validation against a device profile's `field_defs`.

Pure logic (no I/O) so it is trivially testable and shared by the HTTP ingest
endpoint. `field_defs` is the blueprint stored on `device_profiles`:
`[{ "field": "temperature", "type": "float" }]`. Type checks reuse the same
coercion rules as the normalizer, so anything the schema accepts is also
something `normalize_payload` will store as a numeric point.
"""

from dataclasses import dataclass

from .normalizer import classify_value

KNOWN_TYPES = frozenset({"int", "float", "number", "bool", "string"})


class SchemaValidationError(Exception):
    """Payload does not satisfy the profile schema.

    `errors` maps field name -> list of human-readable problems.
    """

    def __init__(self, errors: dict[str, list[str]]) -> None:
        super().__init__(f"schema validation failed: {errors!r}")
        self.errors = errors


def type_matches(declared: str, value: object) -> bool:
    """Return True when `value` is acceptable for a field declared as `declared`."""
    if declared == "string":
        return isinstance(value, str)
    classified = classify_value(value)
    if classified is None:
        return False
    actual, _ = classified
    if declared in {"float", "number"}:
        return actual in {"int", "float"}
    return actual == declared


@dataclass(frozen=True)
class ProfileSchema:
    """Enforces that a payload carries every declared field with a matching type."""

    fields: dict[str, str]

    @classmethod
    def from_field_defs(cls, field_defs: object) -> "ProfileSchema | None":
        """Build a schema from a `field_defs` list, or None when nothing is enforceable.

        Unknown declared types and malformed entries are logged and skipped: a
        profile is a hint, never a reason to reject a device's data.
        """
        if not isinstance(field_defs, list):
            return None
        fields: dict[str, str] = {}
        for entry in field_defs:
            if not isinstance(entry, dict):
                continue
            field, type_ = entry.get("field"), entry.get("type")
            if not isinstance(field, str) or not isinstance(type_, str) or type_ not in KNOWN_TYPES:
                continue
            fields[field] = type_
        return cls(fields) if fields else None

    def validate(self, payload: dict) -> None:
        """Raise `SchemaValidationError` if a declared field is missing or mistyped."""
        errors: dict[str, list[str]] = {}
        for field, type_ in self.fields.items():
            if field not in payload:
                errors[field] = ["required field missing"]
            elif not type_matches(type_, payload[field]):
                errors[field] = [f"expected type '{type_}'"]
        if errors:
            raise SchemaValidationError(errors)
