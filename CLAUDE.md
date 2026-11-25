# Self-hosted IIoT Platform

This is the [`iot-platform`](https://github.com/nadimattari/iot-platform) repository: a single-tenant, self-hosted industrial IoT platform that connects, monitors, and controls devices over MQTT, LoRaWAN, Modbus TCP, and HTTP/REST, with a Vue dashboard, TimescaleDB insights, and command/downlink control.

> **Scope:** This file configures agents working in this repository. It applies the conventions of the vendored [`addyosmani/agent-skills`](https://github.com/addyosmani/agent-skills) plugin to this project; the reusable assets are `skills/`, `agents/`, and `.claude/commands/`. The plugin's own documentation lives in the `AGENT-SKILLS-*` files at the repository root.

## Project Structure

Application:

```
services/     → auth (Node/Fastify), device-mgmt (Symfony), ingestion (Python), dashboard (Vue 3 SPA)
deploy/       → compose files (prod stack + E2E profile), Caddyfile, backup/, runbook.md
db/           → database init scripts (telemetry hypertable + continuous aggregates)
specs/        → implementation plan
tests/        → full-stack E2E suite (run in CI)
docs/         → project docs plus vendored agent/IDE setup guides
```

Vendored `agent-skills` assets:

```
skills/       → Core skills (SKILL.md per directory)
agents/       → Reusable agent personas (code-reviewer, test-engineer, security-auditor, web-performance-auditor, accessibility-auditor)
hooks/        → Session lifecycle hooks
.claude/commands/ → Slash commands (/spec, /plan, /build, /test, /review, /code-simplify, /ship; plus the /webperf and /a11y specialist audits)
references/   → Supplementary checklists (testing, performance, security, accessibility, observability)
evals/        → Skill eval cases + framework (see evals/README.md)
AGENT-SKILLS-* → Upstream README, CONTRIBUTING guide and licence for the vendored plugin
```

## Skills by Phase (vendored catalog)

**Define:** interview-me, idea-refine, spec-driven-development
**Plan:** planning-and-task-breakdown
**Build:** incremental-implementation, test-driven-development, context-engineering, source-driven-development, doubt-driven-development, frontend-ui-engineering, api-and-interface-design, constraint-driven-development
**Verify:** browser-testing-with-devtools, debugging-and-error-recovery
**Review:** code-review-and-quality, code-simplification, security-and-hardening, performance-optimization, accessibility-engineering
**Ship:** git-workflow-and-versioning, ci-cd-and-automation, deprecation-and-migration, documentation-and-adrs, observability-and-instrumentation, shipping-and-launch

## Conventions

- Every skill lives in `skills/<name>/SKILL.md`
- YAML frontmatter with `name` and `description` fields
- Description starts with what the skill does (third person), followed by trigger conditions ("Use when...")
- Every skill has: Overview, When to Use, Process, Common Rationalizations, Red Flags, Verification
- Shared references are in the root `references/` directory; the emerging convention for self-contained, distributable skills keeps a skill's own references inside `skills/<name>/references/`
- Supporting files only created when content exceeds 100 lines

## Contributing

Before adding a new skill or significantly reworking an existing one, run the pre-flight checks in [AGENT-SKILLS-CONTRIBUTING.md](AGENT-SKILLS-CONTRIBUTING.md#before-proposing-a-new-skill): search the catalog, check open PRs, confirm the idea fits [docs/skill-anatomy.md](docs/skill-anatomy.md), and justify the gap. Prefer extending an existing skill over adding a near-duplicate. AGENT-SKILLS-CONTRIBUTING.md is the single source of truth for this workflow; do not restate its checklist here or elsewhere, link to it.

## Commands

Platform:

- `docker compose -f deploy/docker-compose.yml up -d` — start the stack (see `README.md` and `deploy/runbook.md`)
- `docker compose -p iiot-platform-e2e -f deploy/docker-compose.yml -f deploy/docker-compose.test.yml up --exit-code-from e2e` — full-stack E2E suite
- Per-service unit tests: `npm test` in `services/auth`, `npm run test:unit` in `services/dashboard`, `vendor/bin/phpunit` in `services/device-mgmt`, `pytest` in `services/ingestion`

Vendored `agent-skills` assets:

- Validate: `node scripts/validate-skills.js`, `validate-commands.js`, `validate-reference-links.js`, `validate-artifact-paths.js`, `validate-versions.js`
- Shared references: `node scripts/sync-shared-references.js --check`
- Evals: `node scripts/run-evals.js` — trigger/routing evals for every skill (CI); `--behavioral <skill>` for graded runs

## Pull Requests

PRs target this repository's `main` branch (`origin`). When changing vendored agent-skills assets, also track the upstream `addyosmani/agent-skills` repository for equivalent changes so local edits don't conflict with the next sync.

- Before opening a PR, search this repository's open PRs and issues for work that touches the same files, and check upstream when vendored assets are involved. If anything overlaps, coordinate (build on it, align your rules with it, or rebase after it merges) instead of opening a conflicting PR.
- Prefer small, focused PRs over large refactors of widely shared files (for example, files under `scripts/`), which are more likely to collide with in-flight work.

## Boundaries

- Always: Run the AGENT-SKILLS-CONTRIBUTING.md pre-flight checks before creating a new skill directory
- Always: Follow the skill-anatomy.md format for new skills
- Always: Check this repo's open PRs and issues for overlap before opening a new PR, plus upstream when editing vendored assets
- Never: Add skills that are vague advice instead of actionable processes
- Never: Duplicate content between skills — reference other skills instead. The one exception: the copies in `skills/<name>/references/shared/` are generated from the root `references/` by `node scripts/sync-shared-references.js` and are never edited or deleted by hand
