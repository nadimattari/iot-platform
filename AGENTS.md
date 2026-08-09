# AGENTS.md

This file provides guidance to AI coding agents when working in this repository.

## What This Repo Is

This is [addyosmani/agent-skills](https://github.com/addyosmani/agent-skills) — a collection of production-grade skill files (structured Markdown workflows) for AI coding agents. **There is no application code.** This is a documentation/plugin repo distributed via the Claude Code plugin marketplace.

## Project Structure

```
skills/          → 23 skills (22 lifecycle + 1 meta), each with SKILL.md
agents/          → 3 specialist personas (code-reviewer, security-auditor, test-engineer)
.claude/commands/ → 7 slash commands (/spec, /plan, /build, /test, /review, /code-simplify, /ship)
references/      → 4 supplementary checklists
docs/            → Setup guides per tool
.hooks/          → Session lifecycle hooks
.opencode/       → OpenCode plugin wrapper (package.json for @opencode-ai/plugin)
.claude-plugin/  → Claude Code plugin manifests
scripts/         → CI validation scripts
```

## Mandatory: Skill-Driven Execution

OpenCode uses a **skill-driven execution model**. For every request:

1. If a skill applies (even 1% chance), invoke it via the `skill` tool
2. Follow the skill workflow exactly — do not partially apply
3. Only proceed to implementation after required steps (spec, plan, etc.) are complete

**Intent → Skill mapping:**

| Intent | Skill(s) |
|--------|----------|
| Feature / new functionality | `spec-driven-development` → `incremental-implementation` → `test-driven-development` |
| Planning / breakdown | `planning-and-task-breakdown` |
| Bug / failure | `debugging-and-error-recovery` |
| Code review | `code-review-and-quality` |
| Refactoring | `code-simplification` |
| API design | `api-and-interface-design` |
| UI work | `frontend-ui-engineering` |

**Anti-rationalization:** "This is too small for a skill" and "I'll gather context first" are wrong. Always check skills first.

## Lifecycle

OpenCode has no slash commands like `/spec` or `/plan`. Agents follow this lifecycle internally:

DEFINE → `spec-driven-development` → PLAN → `planning-and-task-breakdown` → BUILD → `incremental-implementation` + `test-driven-development` → VERIFY → `debugging-and-error-recovery` → REVIEW → `code-review-and-quality` → SHIP → `shipping-and-launch`

## Orchestration Rules

- **Skills** = the *how* (workflows with steps and exit criteria)
- **Personas** = the *who* (roles with perspectives and output formats)
- **Commands** = the *when* (user-facing entry points)

**Composition rule:** The user (or a slash command) is the orchestrator. **Personas do not invoke other personas.** A persona may invoke skills.

The only multi-persona pattern endorsed is **parallel fan-out with merge**: `/ship` runs `code-reviewer` + `security-auditor` + `test-engineer` concurrently, then synthesizes. Do not build a "router" persona.

**Claude Code interop:** Plugin agents silently ignore `hooks`, `mcpServers`, and `permissionMode` frontmatter fields. Subagents cannot spawn other subagents; teams cannot nest.

See `agents/README.md` for the decision matrix and `references/orchestration-patterns.md` for the full catalog.

## Creating a New Skill

### Directory Structure

```
skills/{skill-name}/           # kebab-case
  SKILL.md                     # Required: skill definition
  scripts/{script-name}.sh     # Optional: executable bash scripts
skills/{skill-name}.zip        # Required: packaged for distribution
```

### Conventions

- SKILL.md must have YAML frontmatter with `name` and `description`
- Description starts with what the skill does (third person), then "Use when..." trigger conditions
- Standard sections: Overview, When to Use, Process, Common Rationalizations, Red Flags, Verification
- Keep SKILL.md under 500 lines; put detailed reference in separate files
- References belong in `references/`, not inside skill directories
- Never duplicate content between skills — reference instead

### Script Requirements

- `#!/bin/bash` shebang, `set -e` for fail-fast
- Status messages to stderr, machine-readable output (JSON) to stdout
- Include a cleanup trap for temp files
- Path reference: `/mnt/skills/user/{skill-name}/scripts/{script}.sh`

### Packaging

After creating or updating a skill:

```bash
cd skills && zip -r {skill-name}.zip {skill-name}/
```

## CI / Validation

- `node scripts/validate-skills.js` — validates all SKILL.md files have correct YAML frontmatter (name + description)
- `claude plugin validate .` — validates plugin structure (requires `@anthropic-ai/claude-code` installed)
- CI runs on push/PR: validate skills → validate plugin → test marketplace install

## Code Style

- No generic software advice, tutorials, or obvious conventions in this file
- When in doubt, prefer short sections and bullets
- Preserve only guidance an agent would otherwise miss
