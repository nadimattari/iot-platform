"""Task 12: Modbus register decoding.

Fixtures cover the datatypes and byte orders listed in the task verification
(int32/float32/uint16 + byte orders), plus the integer family and scaling.
Byte order names:
  big             ABCD  — straight big-endian word order
  little          BADC  — little-endian word order (word swap)
  byte_swap       CDAB  — bytes swapped within each word
  byte_word_swap  DCBA  — byte swap within words, then word swap
"""

import struct

import pytest

from app.decoder import (
    DATATYPE_WIDTHS,
    DecodeError,
    decode_registers,
    register_count,
)


@pytest.mark.parametrize(
    ("datatype", "expected"),
    [
        ("uint8", 1),
        ("int8", 1),
        ("uint16", 1),
        ("int16", 1),
        ("uint32", 2),
        ("int32", 2),
        ("float32", 2),
        ("float64", 4),
    ],
)
def test_register_count(datatype, expected):
    assert register_count(datatype) == expected
    assert DATATYPE_WIDTHS[datatype] == expected


def test_uint16_big_endian():
    assert decode_registers([0x1234], "uint16") == 0x1234


def test_int16_big_endian_negative():
    assert decode_registers([0xFFFE], "int16") == -2


def test_uint32_big_endian():
    assert decode_registers([0x1234, 0x5678], "uint32") == 0x12345678


def test_int32_big_endian_negative():
    assert decode_registers([0xFFFF, 0xFFFE], "int32") == -2


def test_float32_big_endian():
    assert decode_registers([0x4000, 0x0000], "float32") == 2.0


def test_float32_little_endian_word_swap():
    assert decode_registers([0x0000, 0x4000], "float32", byteorder="little") == 2.0


def test_float64_big_endian():
    assert decode_registers([0x4000, 0x0000, 0x0000, 0x0000], "float64") == 2.0


def test_uint32_little_word_swap():
    assert decode_registers([0x5678, 0x1234], "uint32", byteorder="little") == 0x12345678


def test_uint32_byte_swap_within_words():
    assert decode_registers([0x3412, 0x7856], "uint32", byteorder="byte_swap") == 0x12345678


def test_uint32_byte_swap_then_word_swap():
    assert decode_registers([0x7856, 0x3412], "uint32", byteorder="byte_word_swap") == 0x12345678


def test_uint16_byte_swap():
    assert decode_registers([0x3412], "uint16", byteorder="byte_swap") == 0x1234


def test_scale_is_applied_after_decode():
    assert decode_registers([0x0064], "uint16", scale=0.1) == 10.0


def test_scale_applies_to_float():
    assert decode_registers([0x4000, 0x0000], "float32", scale=0.5) == 1.0


def test_uint8_reads_low_byte():
    assert decode_registers([0x0042], "uint8") == 0x42


def test_int8_reads_low_byte_signed():
    assert decode_registers([0x00FE], "int8") == -2


def test_matches_struct_pack_round_trip():
    value = 1234.5
    words = struct.unpack(">HH", struct.pack(">f", value))
    assert decode_registers(list(words), "float32") == pytest.approx(value)


def test_signed_registers_are_masked_to_unsigned():
    assert decode_registers([-32768, 0], "uint32") == 0x80000000


def test_too_few_registers_raises():
    with pytest.raises(DecodeError):
        decode_registers([0x1234], "uint32")


def test_unknown_datatype_raises():
    with pytest.raises(DecodeError):
        decode_registers([0x1234], "word")


def test_unknown_byteorder_raises():
    with pytest.raises(DecodeError):
        decode_registers([0x1234], "uint16", byteorder="sideways")
