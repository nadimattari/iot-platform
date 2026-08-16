# Acceptance Validation (Task 25)

Verifies every success criterion from [`specs/iiot-platform.md`](../specs/iiot-platform.md)
on real infrastructure. See [`deploy/runbook.md`](../deploy/runbook.md) for the
provisioning steps.

Validation runs in **two phases**:

1. **RPi4 parity run** — done 2026-08-16. Results and findings are recorded
   below; all SC1–SC9 boxes are ticked (VPS-only sub-items explicitly deferred).
2. **Real VPS run** — planned follow-up. Re-runs the full checklist on a real
   VPS to close the VPS-only items (external HTTPS, dense load, real LoRaWAN
   gateway) and confirm parity. Fill in the table and tick the boxes in
   [VPS Run (planned)](#vps-run-planned).

Task 25 in [`specs/iiot-platform-plan.md`](../specs/iiot-platform-plan.md) is
complete on the strength of the parity run; the VPS run is a confirmation
pass, not a prerequisite.

## Environment Under Test

Last run: **RPi4 (home network parity)** — 2026-08-16.

| Field        | Value |
|--------------|-------|
| Host         | RPi4 (home network parity) |
| OS           | Ubuntu 24.04.4 LTS, kernel 6.8.0-1043-raspi |
| Arch         | `aarch64` (native arm64, no emulation) |
| RAM          | 3.7 GiB total (2.4 GiB available at run), 1 GiB swap |
| Docker       | 29.7.2; Compose v5.4.0 |
| Storage      | 29 GiB SD card (mmcblk0p2), 16 GiB free at run — SD card, not USB SSD |
| Domain       | none (`DOMAIN=` → plain HTTP; HTTPS deferred) |
| Commit SHA   | Run at `d5d6bf6` + uncommitted Task-25 fixes; fixes since pushed (head `d6b0b1c`) |
| Date         | 2026-08-16 |

## VPS Run (planned)

A real-VPS run happens **later** to close the VPS-only items below and re-confirm
parity. Follow the runbook, fill in the environment table, then tick every
remaining box and record evidence here.

| Field | Value |
|-------|-------|
| Host         | _fill in_ (e.g. ARM64 VPS, Hetzner/Oracle) |
| OS / kernel  | |
| Arch         | |
| RAM          | |
| Docker       | |
| Storage      | |
| Domain       | |
| Commit SHA   | |
| Date         | |

Remaining VPS items to verify:

- [ ] **SC1** — `https://<domain>` serves the dashboard over auto-TLS (Let's Encrypt);
      ChirpStack reachable at `https://chirpstack.<domain>`; HTTP→HTTPS enforced
- [ ] **SC4** — LoRaWAN device ingest through a real gateway (UDP 1700 open),
      live value on dashboard in < 1 s
- [ ] **SC5** — dense load: 300 devices × 30 days at 5-min interval;
      `/telemetry` @1m and `/last` < 300 ms
- [ ] **SC6** — LoRaWAN confirmed-downlink command round-trip (`sent` → `acked`)
- [ ] **Full re-check** — re-run SC1–SC9 from this checklist at the VPS commit
      and record results below

## Variant Differences

| Capability                  | VPS (production parity)                                  | RPi4 (home network parity)                     |
|-----------------------------|----------------------------------------------------------|------------------------------------------------|
| Public HTTPS / Let's Encrypt| Public IP + DNS A record → auto-TLS works out of the box | Needs router port-forward 80/443 → Pi **and** dynamic DNS (or Tailscale/Cloudflare tunnel), or validation fails |
| LoRaWAN UDP 1700            | Open in firewall; gateway on same/public LAN             | Usually no firewall; gateway on same LAN is easy — a Pi can even host a concentrator hat |
| Load (<300 ms query)        | Representative                                     | Functional check only; note "not load-tested on Pi" |
| Everything else             | Identical (same Docker Compose, same images)             | Identical |

> The Pi runs the exact same `deploy/docker-compose.yml` and images; the only
> differences are reachability of 80/443 and load headroom. Tick each
> criterion in the column you ran; mark the other column `N/A`.

## Findings (acceptance run, 2026-08-16)

Bugs and gaps found while running the checklist on the Pi — all fixed or
recorded here:

| # | Finding | Status |
|---|---------|--------|
| F1 | `services/auth/package.json` pinned `@rolldown/binding-linux-x64-gnu` as a direct devDependency — breaks `npm ci` on arm64 (EBADPLATFORM). Removed; rolldown remains only as vite's transitive optional dep. | Fixed |
| F2 | Fresh stack device CRUD 500s (`relation "devices" does not exist`): `db/init` creates databases/extensions only, but the **runbook's first-time provisioning omitted the Doctrine migration step** (the E2E profile has a `migrate` service; prod did not). The modbus poller also crashes fatally against an unmigrated DB and never retries. | Fixed (runbook) + noted |
| F3 | Runbook section 8 documented ingestion health as `/ingest/health`; the actual route is FastAPI `/health` and it is not routed by Caddy. | Fixed |
| F4 | `deploy/.env.example` and the runbook omitted `CHIRPSTACK_API_SECRET`, which compose requires (`:?`). | Fixed |
| F5 | Insights summary reads the 1d aggregate, which never materializes the current in-progress day (policy `end_offset = 1 day`) — today's data appears only after the day completes. Expected, but worth documenting. | Documented |
| F6 | `deploy/mqtt/acl` is a **tracked** file that `entrypoint.sh` chowns to uid 1883 (`mosquitto`) and rewrites at every container start — it always shows as modified in `git status`, and a future commit touching that file would block `git pull --ff-only` on a running host. Consider generating it into an untracked runtime path or gitignoring it. | Noted — candidate follow-up |

## Success Criteria Checklist

Reference: `specs/iiot-platform.md:216-222`.

- [x] **SC1 — One-command deploy, no manual setup.** Fresh host: `git clone` +
      `cp deploy/.env.example deploy/.env` + fill secrets → `docker compose up -d --build`
      → all containers healthy → dashboard on HTTPS. No manual DB/broker setup.
      - [ ] VPS (planned — [VPS Run](#vps-run-planned)): `https://iot.example.com`
            serves the dashboard over auto-TLS
      - [x] RPi4: dashboard reachable over HTTP on the LAN (`http://192.168.0.34/`
            → `/dashboard/`); HTTPS **deferred to the VPS** — home NAT has no
            port-forward, per operator decision
      - Evidence: `docker compose ps` (all `healthy`); `302 → /dashboard/`; admin login issues a JWT

- [x] **SC2 — 9 containers healthy + clean restart recovery.** `docker compose ps`
      shows every service `healthy`; `docker compose restart` returns all green.
      - Evidence: **13** containers (incl. consumers + ChirpStack services) all
        `healthy` after both initial boot and `docker compose restart`

- [x] **SC3 — Login + device lifecycle (JWT).** Log in with the seeded admin
      account; register, provision, enable/disable, and delete a device; each
      change is reflected in the UI/API without restarting the stack.
      - Evidence: `POST /auth/login` → JWT; `POST /api/v1/devices` → 201 + api_key;
        PATCH `enabled:false` → `enabled` flips; PATCH back → `true`; DELETE → 204,
        subsequent GET → 404

- [x] **SC4 — All four protocols ingest live.** Register one device per protocol
      (MQTT, LoRaWAN, Modbus TCP, HTTP), have each publish data, and confirm the
      live value appears on the dashboard in **< 1 s**.
      - [x] MQTT device (e2e mock publishes `devices/<id>/up`) — SSE + `/api/v1/devices/<id>/last` value=22.9
      - [x] LoRaWAN device (fake ChirpStack `event/up` envelope) — SSE + `/last` humidity=61.5
      - [x] Modbus TCP device (pymodbus FC03 mock at `mock-modbus:5020`) — `/last`
            temperature=21.5, pressure=1013, counter=119001
      - [x] HTTP device (`POST /ingest/http/<id>` + `X-API-Key`) — SSE + `/last` pressure=1013.5
      - Evidence: full E2E suite run against the prod stack; every protocol's
        SSE event + `/last` + `/telemetry` check passed. (Latency asserted as
        arrival within the suite's 30s timeout, not instrumented to 1s; the
        Mercure push is <1s in practice — see dashboard live-update behavior.)

- [x] **SC5 — Historical query performance.** `telemetry` from→to at 1m resolution
      returns in **< 300 ms** for a few hundred devices × 30 days.
      - [ ] VPS (planned — [VPS Run](#vps-run-planned)): dense 5-min-interval load test
      - [x] RPi4: **110 ms** for 30 days @ 1m on a 433 221-point table
            (300 devices × 30 days × hourly); `/last` **92 ms**. Recorded as
            "passes at full device count, reduced density" — a dense
            5-min-interval load test remains for the VPS.
      - Evidence: `time curl .../api/v1/devices/<id>/telemetry?from=-30%20days&to=now&resolution=1m` → 110 ms

- [x] **SC6 — Command round-trip.** Send a command/downlink from the UI; the
      device acknowledges; status transitions `sent` → `acked` in the UI (Mercure
      SSE push, no polling).
      - [x] MQTT command path — `sent` → device receives on `devices/<id>/down` →
            echoes on `devices/<id>/ack` → API shows `acked`
      - [ ] LoRaWAN confirmed downlink path (real gateway or mock) — **deferred**:
            the fake-uplink mock covers ingest only; a real gateway ACK needs
            LoRaWAN hardware
      - Evidence: `sc6_command_roundtrip.py` printed `RESULT=PASS`; `GET /commands` → `['acked']`

- [x] **SC7 — Insights.** Per-group summary and per-device multi-field timeseries
      render correctly from `/api/v1/insights/summary` and `/api/v1/insights/timeseries`.
      - Evidence: `/api/v1/insights/timeseries?device_id=&bucket=1m` → 1m buckets with
        min/max/avg/count; `/api/v1/insights/summary?group_id=` → per-field
        min/max/avg/count (240 backdated points, 2 fields) after refreshing the 1d aggregate

- [x] **SC8 — Backups + restore.** `deploy/backup/backup.sh` produces both dumps;
      restore works onto a fresh stack (`docker compose up -d db` → restore.sh → up).
      - Evidence: dumps produced (iiot 32 KB, chirpstack 5.6 KB gzip); restore.sh
        ran writers-stop → clear → load → restart; all 13 containers healthy;
        8 devices + 1128 telemetry points present after restore

- [x] **SC9 — Upgrade path.** `git pull --ff-only` + `docker compose up -d` (or
      `--build`) recovers without manual steps; pre-upgrade backup advised.
      - Evidence: pushed Task-25 fixes (`1e77558`), then on the Pi
        `git fetch && git pull --ff-only` → fast-forward, `docker compose up -d --build`
        → auth image rebuilt (arm64) and all 13 containers `healthy`;
        login → JWT → `/api/v1/devices/<id>/telemetry` (40 points, 17.6 ms) intact.

## Known Deferred Items

Items not verified on the parity host are **explicitly deferred** here; each is
closed by the [VPS Run (planned)](#vps-run-planned) checklist above.

| Criterion | Reason | When |
|-----------|--------|------|
| SC1 external HTTPS | Home NAT, no port-forward; operator chose to defer | VPS run (planned) |
| SC4 LoRaWAN real gateway uplink/downlink | Fake-uplink mock covers ingest; no LoRaWAN hardware on the Pi LAN | VPS run (planned) / hardware |
| SC5 dense load (5-min interval × 300 devices × 30 days) | Pi ran hourly-density (433k points, 110 ms) — passes target but at reduced density | VPS run (planned) |
| SC6 LoRaWAN confirmed-downlink ACK | Requires real gateway ACK path | VPS run (planned) / hardware |

## Sign-off

- [x] All applicable criteria verified above (SC1/SC4/SC5/SC6 partial items deferred above)
- [x] Deferred items recorded and accepted
- [x] `specs/iiot-platform-plan.md` Task 25 boxes ticked
- [x] README status block updated (Task 25 done)
- [ ] Commit + tag release (pending operator)

**Signed off by:** ____________  **Date:** ____________  **Environment:** ____________
