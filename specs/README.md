# IIoT Platform — Documentation Index

A self-hosted IIoT platform that collects time-series telemetry from
industrial devices over four protocols — MQTT, HTTP, Modbus TCP and LoRaWAN —
stores it in TimescaleDB, and exposes it through a REST API and a live
dashboard (real-time updates via Mercure server-sent events). The stack is a
single Docker Compose project: four product services (auth, device-mgmt,
ingestion, dashboard) plus the infrastructure they rely on (Caddy/Mercure
edge, Mosquitto, TimescaleDB, Redis, ChirpStack LoRaWAN network server and
gateway bridge).

These documents describe the platform's architecture, data flows, APIs,
security model and operations. Code, compose files and migrations remain the
source of truth; these docs describe behaviour and link back to them. The
design specification and the implementation plan (Tasks 1–25) are kept as
separate records.

## Document index

| # | Document | What it covers |
|---|----------|----------------|
| 1 | [Overview](iiot-platform-overview.md) | What the platform is, the four protocols, the services at a glance |
| 2 | [Architecture](iiot-platform-architecture.md) | The 15-container stack, networks, published ports, Caddy routing, runtimes |
| 3 | [Database ERD](iiot-platform-erd.md) | Platform, telemetry and ChirpStack schemas; cross-service references |
| 4 | [API reference](iiot-platform-api.md) | Endpoints, payloads, errors for auth, device-mgmt and ingestion |
| 5 | [Authentication](iiot-platform-auth.md) | JWT/JWKS, refresh rotation, Mercure tokens, device API keys |
| 6 | [Data flow](iiot-platform-data-flow.md) | End-to-end uplink and downlink flows for all four protocols |
| 7 | [Telemetry pipeline](iiot-platform-telemetry.md) | Normaliser, batch writer, hypertables, continuous aggregates |
| 8 | [Commands](iiot-platform-commands.md) | Downlink lifecycle and ack tracking (`pending → sent → acked \| failed`) |
| 9 | [LoRaWAN](iiot-platform-lorawan.md) | ChirpStack, gateway bridge, EUI resolution, admin access |
| 10 | [MQTT](iiot-platform-mqtt.md) | Mosquitto topics, ACLs, per-device credential provisioning |
| 11 | [Deployment](iiot-platform-deployment.md) | Compose stack, networks, bootstrapping, backups, upgrades |
| 12 | [Hosting](iiot-platform-hosting.md) | Minimum server requirements: cloud VPS and industrial edge (RPi) |
| 13 | [Security](iiot-platform-security.md) | Trust boundaries, credentials, known gaps |
| 14 | [Testing](iiot-platform-testing.md) | Unit suites and the full-stack E2E suite, CI |
| 15 | [Glossary](iiot-platform-glossary.md) | Terminology used across the docs |

## Related documents

- [Design specification](iiot-platform.md) — the original design spec (SC1–SC9).
- [Implementation plan](iiot-platform-plan.md) — how the platform was built (Tasks 1–25).
- [Deployment runbook](../deploy/runbook.md) — step-by-step operations (provision, backup, restore, upgrade).
- [Quick start](../README.md) — the project README.

## Suggested reading order

For a new reader: **Overview → Architecture → Data flow → API**, then the
topic documents (telemetry, commands, LoRaWAN, MQTT) as needed, then
**Deployment → Security → Testing** for operating the platform.
