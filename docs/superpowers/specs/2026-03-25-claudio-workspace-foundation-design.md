# Claudio — Workspace Foundation Design

**Date:** 2026-03-25
**Subsystem:** 1 of 5 — Workspace Foundation
**Status:** Approved

---

## Overview

`D:\CLAUDIO` is a git repository that IS the assistant. The user runs Claude Code from this directory — that session is Claudio, the personal manager. Claudio orchestrates specialized project subagents, works autonomously, and communicates via Telegram when human input is needed. Pull the repo on any device and have the full assistant with up-to-date knowledge.

This spec covers the foundation: directory structure, session startup, root CLAUDE.md, project import, memory architecture, and RuFlo orchestration integration. The remaining subsystems (Multi-Agent Orchestration detail, Telegram bot, Agent Network Dashboard, Playwright pipeline) are separate specs.

---

## Directory Structure

```
D:\CLAUDIO\
├── CLAUDE.md                          # Root orchestrator — Claudio's identity and rules
├── .gitignore
├── docs\
│   └── superpowers\specs\             # Design docs
├── memory\
│   └── snapshots\                     # Nightly claude-mem exports (committed)
├── Projects\
│   ├── ClaudeTrader\
│   │   ├── CLAUDE.md                  # Auto-generated on import, kept updated
│   │   ├── .claude\memory\            # Per-project memory snapshots (committed)
│   │   └── [project files]
│   └── WebsMami\
│       ├── CLAUDE.md
│       ├── .claude\memory\
│       └── [project files]
├── Work\
│   └── Rechtecheck\
│       └── ClaudeSEO\
│           ├── CLAUDE.md              # Existing — migrated and extended on import
│           ├── .claude\memory\
│           └── [project files]
├── University\                        # Empty — imported from second device later
└── .superpowers\                      # gitignored — brainstorm sessions, temp files
```

New categories (e.g. University) are created as subdirectories when projects are imported. No predefined roles per project — agent context is shaped entirely by what the directory contains.

---

## Root CLAUDE.md — Structure

The root `CLAUDE.md` is Claudio's brain. It must contain these sections:

1. **Identity** — "I am Claudio, personal manager for [user], running from D:\CLAUDIO."
2. **Session startup sequence** — the 5-step boot procedure (see below)
3. **Orchestration model** — how to spawn and review project subagents using RuFlo hive-mind
4. **Skills per phase** — which superpowers skills to use for planning, executing, reviewing
5. **Autonomous scope** — what Claudio does without asking
6. **Escalation policy** — what always requires user input via Telegram
7. **Memory-first decision making** — exhaust memory before escalating
8. **Learning** — how to observe and record user decisions to claude-mem
9. **Memory commit** — export and commit snapshots after each session
10. **Project import procedure** — steps to onboard a new project

---

## Session Startup Sequence

Every session on every device:

1. `git pull` — sync latest CLAUDIO state from remote
2. `git status` — surface pending work, uncommitted changes, open worktrees across all projects
3. claude-mem query — retrieve recent session context ("what happened last session?")
4. `git worktree list` per project — surface active worktrees and branches
5. Brief the user: pending tasks, open PRs, last actions, any Telegram messages queued

If running autonomously (user away): skip step 5, check ruflo issues queue and Telegram for pending tasks instead.

---

## Orchestration Model — RuFlo Hive-Mind

**RuFlo v3** (`ruflo` CLI, installed globally) is the orchestration backbone. Architecture:

```
User → Telegram / Claude Code CLI
  └── Claudio (Queen) — Root CLAUDE.md + superpowers skills + ruflo hive-mind
        ├── Project Agent — Project CLAUDE.md + ruflo worker + claude-mem
        │     └── ruflo task / deploy / test → execution
        └── Project Agent — (another project, parallel)
```

Key RuFlo features used:

| Feature | Purpose |
|---|---|
| `ruflo hive-mind` | Queen-led multi-agent coordination. Claudio = Queen, project agents = workers. Parallel execution with consensus. |
| `ruflo hooks` | Self-learning — absorbs user's workflow patterns automatically over time. |
| `ruflo issues` | Human-in-the-loop escalation. Agent opens an issue; user claims and responds via Telegram. |
| `ruflo mcp` | Exposes all RuFlo capabilities as MCP tools natively in Claude Code sessions. |
| `ruflo deployment` | Deploy + rollback pipelines per project and environment. |
| `ruflo route` | Q-Learning task-to-agent routing — learns which agent handles which task type. |
| `ruflo guidance` | Compiles CLAUDE.md rules into enforceable agent constraints. |

**Planning loop with a project subagent:**

1. Claudio spawns subagent scoped to project directory via `ruflo hive-mind`
2. Claudio + subagent run `superpowers:brainstorming` → `writing-plans` → `executing-plans`
3. When a question arises during planning:
   - Check claude-mem: has user decided this before?
   - Check project CLAUDE.md: is there a rule covering this?
   - Infer from patterns via `ruflo hooks` learned behavior
   - If confident → answer it, document reasoning to claude-mem
   - If genuinely uncertain → open `ruflo issue`, notify user on Telegram
4. Subagent executes, Claudio reviews each phase
5. Claudio reports progress to Telegram
6. Commit memory snapshots + `git push`

**Skills used per phase:**

| Phase | Skills |
|---|---|
| Planning | `superpowers:brainstorming`, `superpowers:writing-plans`, `claude-mem:make-plan` |
| Executing | `superpowers:executing-plans`, `superpowers:subagent-driven-development`, `superpowers:test-driven-development` |
| Reviewing | `superpowers:requesting-code-review`, `superpowers:verification-before-completion`, `superpowers:finishing-a-development-branch` |

---

## Escalation Policy

**Claudio acts autonomously:**
- Write, fix, refactor code
- Run tests, Playwright, screenshots
- Commit, push, open PRs, merge branches
- Deploy to staging or production
- Report progress and results via Telegram

**Claudio escalates to user on Telegram:**
- Delete files, drop databases, or any irreversible data operation
- Requirement is genuinely ambiguous — two valid interpretations with meaningfully different outcomes
- Spend money above a configurable threshold (API costs, cloud resources)
- Action affects production data irreversibly
- Decision not covered by memory, CLAUDE.md, or pattern inference

**Memory-first rule:** Before escalating anything, Claudio must exhaust: (1) claude-mem semantic search, (2) project CLAUDE.md rules, (3) `ruflo hooks` learned patterns. Telegram is a last resort, not a first instinct.

---

## Project Import Procedure

When a project is dropped into any subdirectory:

1. Claudio reads the codebase — detects stack, framework versions, entry points, conventions
2. Reads existing `CLAUDE.md` if present — migrates and extends the rules
3. Generates/updates project `CLAUDE.md` with:
   - Detected stack and versions
   - Build / test / deploy commands
   - Workflow rules inherited from root CLAUDE.md
   - Project-specific conventions discovered from code
4. Runs `ruflo init` — initializes hive-mind context for this project
5. Creates `.claude/memory/` — commits initial snapshot
6. Discovers git worktrees, open branches, existing PRs
7. Records initial understanding to claude-mem: "I now know this project"

---

## Memory Architecture

Four layers, each with a distinct role:

| Layer | Tool | Role | Persists in git? |
|---|---|---|---|
| Live semantic brain | claude-mem MCP | All decisions, patterns, session context. Cross-project semantic search. | Via nightly snapshots |
| Cross-agent state | ruflo memory | Agents share context in real-time within a session via hive-mind. | No |
| Portable snapshots | git-committed exports | Nightly claude-mem exports committed to `memory/snapshots/`. Any device bootstraps offline. | Yes |
| Permanent rules | project CLAUDE.md | Stack facts, workflow rules, conventions. Updated by Claudio as projects evolve. | Yes |

**New device onboarding:**
```bash
git clone <claudio-repo>
ruflo init        # restore hive-mind state
claude .          # Claudio starts, reads memory, ready
```

---

## Remaining Subsystems (separate specs)

| # | Subsystem | Depends on |
|---|---|---|
| 2 | Multi-Agent Orchestration detail | Foundation |
| 3 | Telegram Integration | Foundation |
| 4 | Agent Network Dashboard + Worktrees | Foundation + Orchestration |
| 5 | Playwright + Screenshot Pipeline | Foundation + Telegram |
