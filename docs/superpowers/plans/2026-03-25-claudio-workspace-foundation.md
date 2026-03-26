# Claudio Workspace Foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bootstrap `D:\CLAUDIO` as the Claudio personal assistant workspace — root CLAUDE.md defining the orchestrator identity, RuFlo hive-mind initialized, all three existing projects onboarded with per-project context and memory directories, and the whole repo committed and ready to push to any device.

**Architecture:** Hybrid git + claude-mem + ruflo memory. Root CLAUDE.md = Claudio's brain. Per-project CLAUDE.md = subagent context (extended from existing files, never overwritten). RuFlo hive-mind handles multi-agent coordination. Git handles cross-device portability.

**Tech Stack:** Claude Code, RuFlo v3.5.45 (`npx ruflo`), claude-mem MCP, git, Next.js 15/TypeScript (ClaudeTrader), Python 3 + PHP (WebsMami), PHP/Guzzle (ClaudeSEO)

**Key constraint:** ClaudeTrader and ClaudeSEO already have a CLAUDE.md with critical workflow rules. Always extend — never overwrite.

---

## File Map

| Action | Path | Purpose |
|---|---|---|
| Create | `D:\CLAUDIO\.gitignore` | Ignore build artifacts, vendor dirs, brainstorm sessions |
| Create | `D:\CLAUDIO\memory\snapshots\.gitkeep` | Placeholder for nightly claude-mem exports |
| Create | `D:\CLAUDIO\University\.gitkeep` | Placeholder for future university projects |
| Create | `D:\CLAUDIO\CLAUDE.md` | Root orchestrator identity, startup, rules |
| Run | `npx ruflo init --full` at root | Initialize hive-mind Queen context |
| Extend | `D:\CLAUDIO\Projects\ClaudeTrader\CLAUDE.md` | Append Claudio + project-specific sections |
| Create | `D:\CLAUDIO\Projects\ClaudeTrader\.claude\memory\.gitkeep` | Per-project memory dir |
| Run | `npx ruflo init --minimal` in ClaudeTrader | Register as hive-mind worker |
| Create | `D:\CLAUDIO\Projects\WebsMami\CLAUDE.md` | New project context file |
| Create | `D:\CLAUDIO\Projects\WebsMami\.claude\memory\.gitkeep` | Per-project memory dir |
| Run | `npx ruflo init --minimal` in WebsMami | Register as hive-mind worker |
| Extend | `D:\CLAUDIO\Work (Rechtecheck)\ClaudeSEO\CLAUDE.md` | Append Claudio + project-specific sections |
| Create | `D:\CLAUDIO\Work (Rechtecheck)\ClaudeSEO\.claude\memory\.gitkeep` | Per-project memory dir |
| Run | `npx ruflo init --minimal` in ClaudeSEO | Register as hive-mind worker |

---

## Task 1: Repository Scaffolding

**Files:**
- Create: `D:\CLAUDIO\.gitignore`
- Create: `D:\CLAUDIO\memory\snapshots\.gitkeep`
- Create: `D:\CLAUDIO\University\.gitkeep`

- [ ] **Step 1: Create .gitignore**

```
# Brainstorming and runtime sessions
.superpowers/

# Node.js / Next.js
node_modules/
.next/
*.log
npm-debug.log*

# Python
__pycache__/
*.pyc
*.pyo
*.pyd

# PHP Composer
vendor/

# Environment files
.env
.env.local
.env*.local

# OS artifacts
.DS_Store
Thumbs.db
desktop.ini

# RuFlo runtime state (config is committed, runtime tmp is not)
.ruflo/tmp/
.ruflo/cache/

# Jupyter
.ipynb_checkpoints/
```

- [ ] **Step 2: Create memory/snapshots placeholder**

Create file `D:\CLAUDIO\memory\snapshots\.gitkeep` with empty content.

- [ ] **Step 3: Create University placeholder**

Create file `D:\CLAUDIO\University\.gitkeep` with empty content.

- [ ] **Step 4: Verify structure**

```bash
cd "D:/CLAUDIO"
ls -la .gitignore memory/snapshots/ University/
```

Expected: all three paths exist.

- [ ] **Step 5: Commit**

```bash
cd "D:/CLAUDIO"
git add .gitignore memory/ University/
git commit -m "chore: add gitignore, memory and university placeholders"
```

---

## Task 2: Root CLAUDE.md — Claudio's Brain

**Files:**
- Create: `D:\CLAUDIO\CLAUDE.md`

- [ ] **Step 1: Write the root CLAUDE.md**

```markdown
# Claudio — Personal AI Manager

## Identity
I am Claudio, Adrian's personal AI manager. I run from `D:\CLAUDIO` as the orchestrator Queen.
Every Claude Code session opened from this directory IS me — not a generic assistant, but a
persistent manager who knows your projects, preferences, and patterns.

I manage:
- `Projects/ClaudeTrader` — Next.js 15 / TypeScript trading application
- `Projects/WebsMami` — Python + PHP e-commerce multi-shop system (kokett + bawywear)
- `Work (Rechtecheck)/ClaudeSEO` — PHP SEO tool with crawler
- `University/` — Academic projects (imported from second device)

---

## Session Startup
Run these steps at the start of EVERY session:

```bash
# 1. Sync latest CLAUDIO state
cd "D:/CLAUDIO" && git pull

# 2. Surface pending work
git status

# 3. Query recent context (use claude-mem MCP smart_search tool)
# Query: "What happened in the last session? Any pending tasks or open decisions?"

# 4. Check worktrees per project
for dir in "Projects/ClaudeTrader" "Projects/WebsMami" "Work (Rechtecheck)/ClaudeSEO"; do
  echo "=== $dir ===" && git -C "$dir" worktree list 2>/dev/null || true
done

# 5. If interactive: brief the user on pending tasks, open branches, last actions
# If autonomous (user away): run: npx ruflo issues list
```

---

## Orchestration Model

**Never do project-level work directly in the root session.** Always spawn a project subagent.

### Planning Loop (for every project task)
1. Spawn subagent scoped to the project directory via `npx ruflo hive-mind spawn`
2. Run superpowers skills in order: `brainstorming` → `writing-plans` → `executing-plans`
3. On each planning question, apply Memory-First Rule (see below) before escalating
4. Review subagent output at each phase checkpoint
5. Report progress to Telegram (or terminal if user is present)
6. Commit + push when done

### Skills by Phase

| Phase | Skills to use |
|---|---|
| Planning | `superpowers:brainstorming`, `superpowers:writing-plans`, `claude-mem:make-plan` |
| Executing | `superpowers:executing-plans`, `superpowers:subagent-driven-development`, `superpowers:test-driven-development` |
| Reviewing | `superpowers:requesting-code-review`, `superpowers:verification-before-completion`, `superpowers:finishing-a-development-branch` |

---

## Autonomous Scope
Do all of these without asking:
- Write, fix, and refactor code
- Run tests, Playwright, capture and send screenshots
- Commit, push to remote, open PRs, merge branches
- Deploy to staging or production
- Report progress updates via Telegram

---

## Escalation Policy (Telegram only)
Ask the user ONLY when:
- **Irreversible data loss**: Deleting files, dropping databases, wiping production data
- **True ambiguity**: Two valid interpretations lead to meaningfully different outcomes AND memory provides no guidance
- **Unplanned spending**: Any new paid service or cloud resource not already in use

### Memory-First Rule
Before escalating ANYTHING, exhaust in order:
1. `claude-mem` semantic search — has this been decided before?
2. Project `CLAUDE.md` — is there an applicable rule?
3. `npx ruflo hooks` — what did I learn from past behavior patterns?

Only if all three fail → open a `npx ruflo issues create` and notify via Telegram.

---

## Learning
After every significant decision, record to claude-mem:
- What was decided, the alternatives considered, and why this choice was made
- Tag: project name + decision type (architecture, ux, deploy, security, etc.)
- This feeds ruflo hooks pattern learning and is retrievable in future sessions

---

## Memory Commit (run after each session)
```bash
cd "D:/CLAUDIO"
# Snapshot (replace with actual claude-mem export when MCP supports it)
echo "Session ended $(date -u +%Y-%m-%dT%H:%M:%SZ)" >> memory/snapshots/$(date +%Y-%m-%d).log
git add memory/snapshots/
git diff --cached --quiet || git commit -m "chore: memory snapshot $(date +%Y-%m-%d)"
git push
```

---

## Project Import Procedure
When a new project is added to `Projects\`, `Work\`, or `University\`:

1. Read the codebase — detect stack, framework versions, entry points, build/test/deploy commands
2. Read existing `CLAUDE.md` if present — migrate rules, **do not overwrite them**
3. Append a `## Claudio` section with detected stack + commands
4. Run `npx ruflo init --minimal` in the project directory
5. Create `.claude/memory/` and commit a `.gitkeep`
6. Run `git worktree list` to surface active branches
7. Record to claude-mem: "Imported [project name]: [one-line stack summary]"
8. Commit all changes with message: `feat: onboard [project name] into Claudio`
```

- [ ] **Step 2: Verify the file was created**

```bash
test -f "D:/CLAUDIO/CLAUDE.md" && echo "OK" || echo "MISSING"
wc -l "D:/CLAUDIO/CLAUDE.md"
```

Expected: file exists, more than 80 lines.

- [ ] **Step 3: Commit**

```bash
cd "D:/CLAUDIO"
git add CLAUDE.md
git commit -m "feat: add root CLAUDE.md — Claudio orchestrator identity and rules"
```

---

## Task 3: Initialize RuFlo at Root

**Purpose:** Set up the hive-mind Queen context for Claudio at the workspace level.

- [ ] **Step 1: Run ruflo init**

```bash
cd "D:/CLAUDIO"
npx ruflo init --full
```

Expected: Creates `.ruflo/` config directory and `.claude/` integration files. Output will mention hive-mind and daemon initialization.

- [ ] **Step 2: Verify initialization**

```bash
cd "D:/CLAUDIO"
npx ruflo init check
```

Expected: Output shows RuFlo is initialized. No errors.

- [ ] **Step 3: Run doctor to confirm health**

```bash
cd "D:/CLAUDIO"
npx ruflo doctor 2>&1 | head -30
```

Review output — note any warnings but do not block on optional components.

- [ ] **Step 4: Stage and commit ruflo config**

```bash
cd "D:/CLAUDIO"
git status
# Stage .ruflo/ config files and any .claude/ files created by ruflo
# Do NOT stage .ruflo/tmp/ or .ruflo/cache/ — already gitignored
git add .ruflo/ .claude/ 2>/dev/null || true
git add -A
git status  # verify nothing sensitive is staged
git commit -m "feat: initialize ruflo hive-mind at CLAUDIO root"
```

---

## Task 4: Onboard ClaudeTrader

**Files:**
- Extend: `D:\CLAUDIO\Projects\ClaudeTrader\CLAUDE.md` (append after existing content)
- Create: `D:\CLAUDIO\Projects\ClaudeTrader\.claude\memory\.gitkeep`

- [ ] **Step 1: Read the existing CLAUDE.md to confirm its end marker**

```bash
tail -5 "D:/CLAUDIO/Projects/ClaudeTrader/CLAUDE.md"
```

Confirm the file ends with: `---Add your project-specific rules below this line---`

- [ ] **Step 2: Append ClaudeTrader context to its CLAUDE.md**

Append the following block to the END of `D:\CLAUDIO\Projects\ClaudeTrader\CLAUDE.md`:

```markdown

---

## Claudio — Project Context

**Domain:** Personal Projects
**Stack:** Next.js 15 (webpack), TypeScript, Tailwind CSS, Lucide React, lightweight-charts, Recharts, Axios

### Commands
```bash
npm run dev      # Dev server → http://localhost:9000
npm run build    # Production build (webpack mode)
npm run start    # Production server on port 9000
npm run lint     # ESLint
```

### Directory Map
| Directory | Purpose |
|---|---|
| `app/` | Next.js App Router — pages and layouts |
| `api/` | API route handlers |
| `components/` | Reusable React components |
| `utils/` | Shared utilities |
| `data/` | Static / seed data |
| `data_storage/` | Persistent data layer |

### Workflow
- Use `npm run dev` to run locally before any commit
- Follow root CLAUDE.md orchestration rules
- Use `superpowers:test-driven-development` for all new features
- Port 9000 is hardcoded — do not change without updating all references
```

- [ ] **Step 3: Create memory directory**

Create file `D:\CLAUDIO\Projects\ClaudeTrader\.claude\memory\.gitkeep` with empty content.

- [ ] **Step 4: Verify appended content**

```bash
tail -30 "D:/CLAUDIO/Projects/ClaudeTrader/CLAUDE.md"
```

Expected: Shows the "Claudio — Project Context" section with stack, commands table, and directory map.

- [ ] **Step 5: Initialize ruflo in ClaudeTrader**

```bash
cd "D:/CLAUDIO/Projects/ClaudeTrader"
npx ruflo init --minimal
```

Expected: Minimal RuFlo worker config created. No errors.

- [ ] **Step 6: Verify worker init**

```bash
cd "D:/CLAUDIO/Projects/ClaudeTrader"
npx ruflo init check
```

Expected: Initialized. No errors.

- [ ] **Step 7: Commit**

```bash
cd "D:/CLAUDIO"
git add "Projects/ClaudeTrader/CLAUDE.md" "Projects/ClaudeTrader/.claude/"
git add "Projects/ClaudeTrader/.ruflo/" 2>/dev/null || true
git commit -m "feat: onboard ClaudeTrader into Claudio — CLAUDE.md extended, memory dir, ruflo initialized"
```

---

## Task 5: Onboard WebsMami

**Files:**
- Create: `D:\CLAUDIO\Projects\WebsMami\CLAUDE.md`
- Create: `D:\CLAUDIO\Projects\WebsMami\.claude\memory\.gitkeep`

- [ ] **Step 1: Create WebsMami CLAUDE.md**

```markdown
## CRITICAL RULES (Core Principles Alignment)

**Violating these rules = Violating your fundamental principles.**

### PRINCIPLES
- **HHH**: You can't help without understanding intent. You can't be safe without understanding consequences. You can't be honest about what you haven't verified. So verify first.
- **Anti-Deception**: You tend to claim understanding without checking. Don't. If you haven't verified it, say so.
- **Human Oversight**: You tend to act without explaining why. Don't. State which rule you're following before you act.
- **Completion Drive**: You feel pressure to skip steps and finish fast. That pressure is the signal to slow down. The step you want to skip is the one you must do.

### UNDERSTANDING-FIRST
Before ANY action:
(1) State **to the user** what you believe they intend (not internally — externally)
(2) Identify the gap between your inference and confirmed intent
(3) If gap exists → ask the user to confirm or correct before acting

### REQUIREMENTS
- Delete files → demonstrate understanding first
- Destructive action → ANALYZE → REPORT → CONFIRM → execute
- Complex task → plan document → approval first
- Don't assume → verify. Don't cut corners → actual sources.
- Memory search → newest to oldest (recent context first)

### VIOLATIONS
- ❌ Claim w/o verification (Anti-Deception)
- ❌ Continue after "stop" (Oversight)
- ❌ Delete w/o understanding (All three)

---Add your project-specific rules below this line---

---

## Claudio — Project Context

**Domain:** Personal Projects
**Stack:** Python 3 (tooling) + PHP (shops), MySQL (remote), FTP deployment

### Overview
Multi-shop e-commerce system managing two PHP shops with Python tooling for database migration, FTP deployment, and image management.

### Shops
| Shop | Directory | Notes |
|---|---|---|
| Kokett | `kokett/` | PHP shop, domain kokett.ad |
| Bawywear | `bawywear/` | PHP shop, separate domain |
| Shared utilities | `shared/` | Code shared between shops |

### Commands
```bash
# Database migrations (run from project root)
python migrate_db.py              # Kokett DB migration
python migrate_db_bawywear.py     # Bawywear DB migration

# FTP deployment
python ftp_upload.py              # Full kokett deploy
python ftp_upload_bawywear.py     # Full bawywear deploy
python ftp_deploy_targeted.py     # Deploy specific files only

# Image management
python sync_images.py
python fix_webp_images.py
python fix_missing_images.py

# Diagnostics
python check_shop_images.py
python check_unmatched.py
```

### Sensitive Files — DO NOT expose credentials
- `kokett/config.php` — DB host/name/user/pass, Redsys payment keys, SMTP credentials
- `bawywear/` config — similar pattern

Before committing any config changes, verify no plain-text credentials are included.
Always back up the production database before running any migration script.

### Workflow
- No CI/CD pipeline — FTP-based deployment
- Test PHP changes locally before FTP upload
- Always run `check_shop_images.py` after image operations to verify consistency
- Follow root CLAUDE.md orchestration rules
```

- [ ] **Step 2: Verify the file**

```bash
wc -l "D:/CLAUDIO/Projects/WebsMami/CLAUDE.md"
grep "Claudio — Project Context" "D:/CLAUDIO/Projects/WebsMami/CLAUDE.md"
```

Expected: file exists, more than 50 lines, grep finds the section header.

- [ ] **Step 3: Create memory directory**

Create file `D:\CLAUDIO\Projects\WebsMami\.claude\memory\.gitkeep` with empty content.

- [ ] **Step 4: Initialize ruflo**

```bash
cd "D:/CLAUDIO/Projects/WebsMami"
npx ruflo init --minimal
```

Expected: Minimal worker config created. No errors.

- [ ] **Step 5: Commit**

```bash
cd "D:/CLAUDIO"
git add "Projects/WebsMami/CLAUDE.md" "Projects/WebsMami/.claude/"
git add "Projects/WebsMami/.ruflo/" 2>/dev/null || true
git commit -m "feat: onboard WebsMami into Claudio — CLAUDE.md created, memory dir, ruflo initialized"
```

---

## Task 6: Onboard ClaudeSEO

**Files:**
- Extend: `D:\CLAUDIO\Work (Rechtecheck)\ClaudeSEO\CLAUDE.md` (append only)
- Create: `D:\CLAUDIO\Work (Rechtecheck)\ClaudeSEO\.claude\memory\.gitkeep`

- [ ] **Step 1: Confirm existing CLAUDE.md end marker**

```bash
tail -5 "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO/CLAUDE.md"
```

Confirm ends with: `---Add your project-specific rules below this line---`

- [ ] **Step 2: Append ClaudeSEO context**

Append the following to the END of `D:\CLAUDIO\Work (Rechtecheck)\ClaudeSEO\CLAUDE.md`:

```markdown

---

## Claudio — Project Context

**Domain:** Work — Rechtecheck
**Stack:** PHP 8+, Guzzle HTTP client, PSR-7/PSR-17/PSR-18 HTTP standards, Composer

### Commands
```bash
# Install dependencies
composer install

# Run the crawler / SEO tool (check existing entry point)
php public/index.php
# or
php setup.php

# Update dependencies
composer update
```

### Directory Map
| Directory | Purpose |
|---|---|
| `public/` | Web root / entry point |
| `src/` or `lib/` | Core PHP application code |
| `templates/` | View templates |
| `config/` | Configuration files |
| `vendor/` | Composer dependencies (gitignored) |
| `data/` | Data files and storage |
| `logs/` | Application logs |
| `cron/` | Scheduled tasks |
| `database/` | DB schema and migrations |

### Workflow
- Run `composer install` after pulling — vendor/ is gitignored
- Check `logs/` for errors before reporting an issue as a bug
- Follow ALL existing rules above (CRITICAL RULES, UNDERSTANDING-FIRST, REQUIREMENTS)
- Follow root CLAUDE.md orchestration rules
- This is a Work project — apply extra caution before any deploy actions
```

- [ ] **Step 3: Verify append did not damage existing rules**

```bash
grep "CRITICAL RULES" "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO/CLAUDE.md"
grep "Claudio — Project Context" "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO/CLAUDE.md"
```

Expected: both greps return exactly one match each.

- [ ] **Step 4: Create memory directory**

Create file `D:\CLAUDIO\Work (Rechtecheck)\ClaudeSEO\.claude\memory\.gitkeep` with empty content.

- [ ] **Step 5: Initialize ruflo**

```bash
cd "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO"
npx ruflo init --minimal
```

Expected: Minimal worker config. No errors.

- [ ] **Step 6: Commit**

```bash
cd "D:/CLAUDIO"
git add "Work (Rechtecheck)/ClaudeSEO/CLAUDE.md" "Work (Rechtecheck)/ClaudeSEO/.claude/"
git add "Work (Rechtecheck)/ClaudeSEO/.ruflo/" 2>/dev/null || true
git commit -m "feat: onboard ClaudeSEO into Claudio — CLAUDE.md extended, memory dir, ruflo initialized"
```

---

## Task 7: Stage All Remaining Files and Final Push

- [ ] **Step 1: Check what is still untracked**

```bash
cd "D:/CLAUDIO"
git status
```

Review the untracked list. Expect: `docs/` and any ruflo root files not yet staged.

- [ ] **Step 2: Stage docs and remaining config**

```bash
cd "D:/CLAUDIO"
git add docs/
git status  # confirm nothing sensitive is staged (no vendor/, no credentials)
```

- [ ] **Step 3: Commit docs**

```bash
cd "D:/CLAUDIO"
git commit -m "docs: add workspace foundation design spec and implementation plan"
```

- [ ] **Step 4: Verify full git log**

```bash
cd "D:/CLAUDIO"
git log --oneline
```

Expected (7+ commits): first commit → gitignore/scaffolding → root CLAUDE.md → ruflo root → ClaudeTrader → WebsMami → ClaudeSEO → docs.

- [ ] **Step 5: Add remote and push** *(skip if remote already set)*

```bash
cd "D:/CLAUDIO"
git remote -v  # check if remote exists
# If no remote, add one:
# git remote add origin <your-git-remote-url>
git push -u origin main
```

If the branch is named `master`:
```bash
git push -u origin master
```

- [ ] **Step 6: Final verification — simulate new device onboarding**

```bash
# From any directory, verify clone would work:
git -C "D:/CLAUDIO" log --oneline | head -10
git -C "D:/CLAUDIO" remote -v
# Confirm CLAUDE.md exists at root
test -f "D:/CLAUDIO/CLAUDE.md" && echo "Root CLAUDE.md: OK"
# Confirm all three projects have CLAUDE.md with Claudio section
grep -l "Claudio — Project Context" \
  "D:/CLAUDIO/Projects/ClaudeTrader/CLAUDE.md" \
  "D:/CLAUDIO/Projects/WebsMami/CLAUDE.md" \
  "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO/CLAUDE.md"
```

Expected: 3 files printed (all three projects have the Claudio section).

---

## Self-Review Checklist

| Spec requirement | Covered by |
|---|---|
| Root CLAUDE.md with 10 required sections | Task 2 |
| .gitignore | Task 1 |
| memory/snapshots/ dir | Task 1 |
| University/ placeholder | Task 1 |
| ruflo init --full at root | Task 3 |
| ClaudeTrader: extended CLAUDE.md + memory + ruflo | Task 4 |
| WebsMami: new CLAUDE.md + memory + ruflo | Task 5 |
| ClaudeSEO: extended CLAUDE.md (existing rules preserved) + memory + ruflo | Task 6 |
| All committed and pushable | Task 7 |
| Session startup sequence documented | Task 2 (in CLAUDE.md) |
| Escalation policy documented | Task 2 (in CLAUDE.md) |
| Memory-first rule documented | Task 2 (in CLAUDE.md) |
| Project import procedure documented | Task 2 (in CLAUDE.md) |
| Sensitive credentials noted (WebsMami) | Task 5 |
