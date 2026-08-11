#!/usr/bin/env python3
"""Minimal LoRaWAN 1.0.4 OTAA device + gateway simulator for ChirpStack.

Publishes gateway uplinks as gw.UplinkFrame JSON on the region MQTT gateway
topic (what chirpstack-gateway-bridge would publish) and implements the
device-side LoRaWAN crypto (join-request, session-key derivation, FRMPayload
encryption, MIC) using the exact same constructions as the lrwn crate.

Flow:
  1. Publish JoinRequest  -> eu868/gateway/<gw>/event/up
  2. Await JoinAccept     <- eu868/gateway/<gw>/command/down
  3. Derive NwkSKey/AppSKey, publish one or more encrypted uplinks

Requires: paho-mqtt, pycryptodome
"""

import argparse
import base64
import json
import struct
import sys
import time

import paho.mqtt.client as mqtt
from Crypto.Cipher import AES
from Crypto.Hash import CMAC


def cmac(key: bytes, msg: bytes) -> bytes:
    c = CMAC.new(key, msg, ciphermod=AES, mac_len=16)
    return c.digest()


def aes128_enc(key: bytes, block: bytes) -> bytes:
    return AES.new(key, AES.MODE_ECB).encrypt(block)


def aes128_dec(key: bytes, block: bytes) -> bytes:
    return AES.new(key, AES.MODE_ECB).decrypt(block)


def join_request(app_key: bytes, app_eui: bytes, dev_eui: bytes, dev_nonce: bytes) -> bytes:
    mhdr = b"\x00"
    body = app_eui + dev_eui + dev_nonce
    return mhdr + body + cmac(app_key, mhdr + body)[:4]


def decrypt_join_accept(app_key: bytes, ct: bytes) -> bytes:
    # First byte is the unencrypted MHDR (0x20); the rest is the encrypted
    # JoinAccept payload (possibly containing a CFList -> 2 AES blocks).
    # Note: LoRaWAN swaps AES modes for the join-accept: the NS "encrypts"
    # using the decrypt operation, so we decrypt using the encrypt operation.
    return b"".join(aes128_enc(app_key, ct[i : i + 16]) for i in range(1, len(ct), 16))


def derive_s_keys(app_key: bytes, app_nonce: bytes, net_id: bytes, dev_nonce: bytes) -> tuple:
    b = bytearray(16)
    b[0] = 0x01
    b[1:4] = app_nonce
    b[4:7] = net_id
    b[7:9] = dev_nonce
    nwk_s_key = aes128_enc(app_key, bytes(b))
    b[0] = 0x02
    app_s_key = aes128_enc(app_key, bytes(b))
    return nwk_s_key, app_s_key


def encrypt_frm_payload(app_s_key: bytes, dev_addr: bytes, f_cnt: int, data: bytes) -> bytes:
    n = (len(data) + 15) // 16
    out = bytearray()
    for i in range(1, n + 1):
        a = bytearray(16)
        a[0] = 0x01
        a[6:10] = dev_addr
        a[10:14] = struct.pack("<I", f_cnt)
        a[15] = i
        s = aes128_enc(app_s_key, bytes(a))
        block = data[(i - 1) * 16 : i * 16]
        out += bytes(x ^ y for x, y in zip(block, s))
    return bytes(out)


def uplink_phy(nwk_s_key: bytes, app_s_key: bytes, dev_addr: bytes, f_cnt: int, f_port: int, payload: bytes) -> bytes:
    mhdr = b"\x40"
    fhdr = dev_addr + b"\x00" + struct.pack("<H", f_cnt & 0xFFFF)
    enc = encrypt_frm_payload(app_s_key, dev_addr, f_cnt, payload)
    msg = mhdr + fhdr + bytes([f_port]) + enc
    b0 = bytearray(16)
    b0[0] = 0x49
    b0[6:10] = dev_addr
    b0[10:14] = struct.pack("<I", f_cnt)
    b0[15] = len(msg)
    return msg + cmac(nwk_s_key, bytes(b0) + msg)[:4]


def uplink_frame_json(phy: bytes, gw_id: str, freq: int, sf: int, bw: int, channel: int, uplink_id: int) -> dict:
    return {
        "phyPayload": base64.b64encode(phy).decode(),
        "txInfo": {
            "frequency": freq,
            "modulation": {
                "lora": {
                    "bandwidth": bw,
                    "spreadingFactor": sf,
                    "codeRate": "CR_4_5",
                    "polarizationInversion": False,
                }
            },
        },
        "rxInfo": {
            "gatewayId": gw_id,
            "uplinkId": uplink_id,
            "rssi": -60,
            "snr": 8.5,
            "channel": channel,
            "rfChain": 0,
            "crcStatus": "CRC_OK",
            "context": base64.b64encode(b"\x01\x02").decode(),
        },
    }


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--broker", default=os_env("LORASIM_MQTT_HOST", "mqtt:1883"))
    p.add_argument("--username", default=os_env("LORASIM_MQTT_USERNAME", ""))
    p.add_argument("--password", default=os_env("LORASIM_MQTT_PASSWORD", ""))
    p.add_argument("--region", default="eu868")
    p.add_argument("--dev-eui", default="70b3d5499e320001")
    p.add_argument("--app-eui", default="0000000000000000")
    p.add_argument("--app-key", required=True)
    p.add_argument("--gateway-id", default="0102030405060708")
    p.add_argument("--freq", type=int, default=868100000)
    p.add_argument("--sf", type=int, default=12)
    p.add_argument("--channel", type=int, default=0)
    p.add_argument("--payload", default="010203040506")
    p.add_argument("--f-port", type=int, default=1)
    p.add_argument("--uplinks", type=int, default=1)
    p.add_argument("--join-timeout", type=float, default=15.0)
    args = p.parse_args()

    app_key = bytes.fromhex(args.app_key)
    app_eui = bytes.fromhex(args.app_eui)[::-1]
    dev_eui = bytes.fromhex(args.dev_eui)[::-1]
    dev_nonce = struct.pack("<H", int.from_bytes(__import__("os").urandom(2), "big"))
    payload = bytes.fromhex(args.payload)
    gw_topic = f"{args.region}/gateway/{args.gateway_id}"

    received = {}

    def on_command(client, userdata, msg):
        try:
            df = json.loads(msg.payload)
        except json.JSONDecodeError:
            return
        items = df.get("items") or []
        if items:
            received["phy"] = base64.b64decode(items[0]["phyPayload"])

    broker_host, _, broker_port = args.broker.rpartition(":")
    broker_port = int(broker_port or 1883)
    client = mqtt.Client()
    client.username_pw_set(args.username, args.password)
    client.on_message = on_command
    client.connect(broker_host, broker_port, 30)
    client.subscribe(f"{gw_topic}/command/+", qos=0)
    client.loop_start()

    jr = join_request(app_key, app_eui, dev_eui, dev_nonce)
    client.publish(f"{gw_topic}/event/up", json.dumps(uplink_frame_json(jr, args.gateway_id, args.freq, args.sf, 125000, args.channel, 1)))
    print(f"sent join-request  dev_eui={args.dev_eui} gw={args.gateway_id} freq={args.freq} sf={args.sf}")

    deadline = time.time() + args.join_timeout
    while time.time() < deadline and "phy" not in received:
        time.sleep(0.2)
    if "phy" not in received:
        print("ERROR: no join-accept downlink received", file=sys.stderr)
        return 1

    plain = decrypt_join_accept(app_key, received["phy"])
    app_nonce = plain[0:3]
    net_id = plain[3:6]
    dev_addr = plain[6:10]
    print(f"join-accept ok       app_nonce={app_nonce.hex()} net_id={net_id.hex()} dev_addr={dev_addr.hex()}")

    nwk_s_key, app_s_key = derive_s_keys(app_key, app_nonce, net_id, dev_nonce)
    print(f"session keys         nwk_s_key={nwk_s_key.hex()} app_s_key={app_s_key.hex()}")

    for i in range(args.uplinks):
        phy = uplink_phy(nwk_s_key, app_s_key, dev_addr, i, args.f_port, payload)
        client.publish(
            f"{gw_topic}/event/up",
            json.dumps(uplink_frame_json(phy, args.gateway_id, args.freq, args.sf, 125000, args.channel, 2 + i)),
        )
        print(f"sent uplink #{i}      fcnt={i} fport={args.f_port} payload={payload.hex()}")

    time.sleep(1.0)
    client.loop_stop()
    client.disconnect()
    return 0


def os_env(key: str, default: str) -> str:
    import os

    return os.environ.get(key, default)


if __name__ == "__main__":
    sys.exit(main())
