"""Modbus register decoding: raw 16-bit registers -> typed, scaled values.

Pure logic — no I/O — so it is trivially testable and shared between the
poller (Task 12) and the register config tests. A value occupies one or more
holding registers (16-bit each); the chosen byte order and scale describe how
the raw words map onto the sensor value.
"""

import struct
from collections.abc import Sequence

DATATYPE_WIDTHS: dict[str, int] = {
    "uint8": 1,
    "int8": 1,
    "uint16": 1,
    "int16": 1,
    "uint32": 2,
    "int32": 2,
    "float32": 2,
    "float64": 4,
}

BYTE_ORDERS = frozenset({"big", "little", "byte_swap", "byte_word_swap"})

_STRUCT_FORMATS: dict[str, str] = {
    "uint8": ">B",
    "int8": ">b",
    "uint16": ">H",
    "int16": ">h",
    "uint32": ">I",
    "int32": ">i",
    "float32": ">f",
    "float64": ">d",
}


class DecodeError(Exception):
    """Registers could not be decoded for the requested datatype/byteorder."""


def register_count(datatype: str) -> int:
    """Number of holding registers a value of `datatype` occupies."""
    try:
        return DATATYPE_WIDTHS[datatype]
    except KeyError as exc:
        raise DecodeError(f"unsupported datatype: {datatype!r}") from exc


def decode_registers(
    registers: Sequence[int],
    datatype: str,
    byteorder: str = "big",
    scale: float = 1.0,
) -> float:
    """Decode `registers` (16-bit values) into a typed, scaled float value."""
    if byteorder not in BYTE_ORDERS:
        raise DecodeError(f"unsupported byteorder: {byteorder!r}")
    width = register_count(datatype)
    if len(registers) < width:
        raise DecodeError(
            f"{datatype} needs {width} register(s), got {len(registers)}"
        )

    raw = b"".join((r & 0xFFFF).to_bytes(2, "big") for r in registers[:width])

    # 8-bit values live in the low byte of a single register; byte order is
    # irrelevant for them.
    if datatype in {"uint8", "int8"}:
        packed = raw[1:2]
    else:
        packed = _reorder(raw, byteorder, width)

    value = struct.unpack(_STRUCT_FORMATS[datatype], packed)[0]
    return value * scale


def _reorder(raw: bytes, byteorder: str, width: int) -> bytes:
    if byteorder == "big":
        return raw
    words = [raw[i : i + 2] for i in range(0, len(raw), 2)]
    if byteorder == "little":
        return b"".join(reversed(words))
    if byteorder == "byte_swap":
        return b"".join(word[::-1] for word in words)
    if byteorder == "byte_word_swap":
        return b"".join(word[::-1] for word in reversed(words))
    raise DecodeError(f"unsupported byteorder: {byteorder!r}")
