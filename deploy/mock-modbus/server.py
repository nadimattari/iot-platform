"""Tiny raw Modbus TCP server (FC03 only) for integration testing the poller.

Deliberately hand-rolled so the poller's pymodbus client is exercised against
the real wire protocol without depending on pymodbus' own server stack.
"""

import asyncio
import logging
import struct

logging.basicConfig(level=logging.INFO)

HOLDING = {
    # float32 temperature 21.5 -> words 0x41AC0000
    0: 0x41AC,
    1: 0x0000,
    # uint16 pressure 1013
    2: 0x03F5,
    # int16 negative -5
    8: 0xFFFB,
}

counter = [0]


def read_holding(start: int, qty: int) -> bytes:
    words = []
    for i in range(start, start + qty):
        if i in HOLDING:
            words.append(HOLDING[i])
        elif i in (4, 5):
            # uint32 counter that ticks on each read (4 = high word, 5 = low)
            value = counter[0] * 1000 + 1
            counter[0] += 1
            words.append((value >> 16) & 0xFFFF if i == 4 else value & 0xFFFF)
        else:
            words.append(0)
    body = bytearray()
    body.append(0x03)
    body.append(len(words) * 2)
    for w in words:
        body.extend(struct.pack(">H", w))
    return bytes(body)


async def handle(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    peer = writer.get_extra_info("peername")
    logging.info("connection from %s", peer)
    try:
        while True:
            header = await asyncio.wait_for(reader.readexactly(7), timeout=5)
            tid, pid, length, unit = struct.unpack(">HHHB", header)
            if pid != 0:
                writer.close()
                return
            pdu = await asyncio.wait_for(reader.readexactly(length - 1), timeout=5)
            func = pdu[0]
            if func == 0x03 and len(pdu) >= 5:
                start, qty = struct.unpack(">HH", pdu[1:5])
                qty = min(qty, 120)
                response = read_holding(start, qty)
            else:
                response = bytes([func | 0x80, 0x01])
            frame = struct.pack(">HHHB", tid, 0, len(response) + 1, unit) + response
            writer.write(frame)
            await writer.drain()
    except (asyncio.IncompleteReadError, asyncio.TimeoutError, ConnectionResetError):
        pass
    finally:
        writer.close()


async def main() -> None:
    server = await asyncio.start_server(handle, "0.0.0.0", 5020)
    logging.info("modbus mock listening on 0.0.0.0:5020")
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
