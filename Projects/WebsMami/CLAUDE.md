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
