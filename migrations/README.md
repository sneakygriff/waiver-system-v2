# Migrations

`run.php` is the migration runner for this fork. It applies every unapplied
`NNN[_name].sql` in this directory, in numeric order, recording progress **per
statement** so a half-applied file can be resumed exactly where it stopped.

Before this existed, 002/003/004 each carried a "hand-apply via
`docker compose exec -T db mysql ... < migrations/00N.sql`" header — unrunnable
from CI and unauditable afterwards.

## Target database — one contract

The target is a **URL in an environment variable**. Discrete `MYSQL*` vars are
deliberately not supported here.

```bash
export STAGING_WAIVER_DB_URL="mysql://user:pass@host:3306/dbname"   # ?charset=utf8mb4 optional
php migrations/run.php
```

Use `--url-env=OTHER_NAME` to read the URL from a **differently named** variable
(e.g. a prod one later); the *value* format never changes.

The URL is never printed. Error messages pass through a redactor with two
layers: the full raw connection string is always stripped wholesale (no
length floor at all, so even a 1-2 character credential is masked wherever
the URL appears intact — this layer is checked FIRST), and the
password/user/host/db are also masked individually, word-boundary-aware, when
each is ≥ 3 characters (below that floor a value is left out of blind
matching entirely, or it would mangle the runner's own prose — a
compose-style `app`/`app` credential is the live example). Either layer alone
would leave a gap; together, a CI log cannot leak the target even on a
connection failure.

## Commands

| Command | Effect |
| --- | --- |
| `php migrations/run.php` | Apply everything pending; resume any half-applied file. |
| `php migrations/run.php --dry-run` | **Read-only.** Report what would run; writes nothing at all (not even the ledger tables). Safe to point at prod. |
| `php migrations/run.php --baseline` | Mark every migration file present as applied **without executing it**. |
| `php migrations/run.php --baseline --through=004` | Same, bounded to files up to and including `004` (numeric prefix or full version name). |
| `php migrations/run.php --dir=PATH` | Use a different migrations directory (tests/fixtures). |
| `php migrations/run.php --verbose` | Also echo each statement's SQL. |

**Exit codes** (stable — CI and the test suite depend on them):

| Code | Meaning |
| --- | --- |
| `0` | Success, including "nothing to do". |
| `1` | A migration statement failed. The ledger holds the exact half-applied state; re-run to resume at that statement. |
| `2` | Usage/input error (bad flag, missing `--dir`, unparseable or unrecognized `.sql` file). |
| `3` | Environment error (env var unset/unparseable, DB unreachable, advisory lock held). |
| `4` | Ledger integrity error (unrecognized pre-existing ledger shape, or a migration file changed after a partial apply). |

## Baselining — required before the runner touches an existing database

Do this on the compose DB, the M5 staging copy, and prod, **before** the first
real run:

```bash
php migrations/run.php --dry-run     # 1. see what the runner thinks is pending
php migrations/run.php --baseline    # 2. mark the current files applied, executing nothing
php migrations/run.php               # 3. no-op now; later runs apply only genuinely new files
```

Baselining exists because **002 and 003 are already baked into `001_init.sql`**
(both files say so in their own headers). On a database initialized from 001,
re-running 002 dies with `Duplicate column name 'participant_id'`. Baselining
records those files as applied so they are never executed again.

Fresh-DB bootstrap stays compose's `initdb` (which runs `001_init.sql` only).
The runner is *never* expected to make 001 → 002 succeed on a fresh schema.

## The ledger

Two tables. The pre-existing one is **extended, never dropped or recreated**:

- **`schema_migrations(version, applied_at)`** — already exists on every
  initialized DB. A row means *this file is fully applied*; its statements are
  never re-run. `run.php` widens `version` from `VARCHAR(32)` to `VARCHAR(191)`
  when it is still narrow — not cosmetic: the real version string
  `004_erasure_audit_events_backfill` is **33 characters**, so under the MySQL 8
  default `sql_mode` (`STRICT_TRANS_TABLES`) inserting it into `VARCHAR(32)`
  fails with error 1406. Widening a PK `VARCHAR` is lossless.
- **`schema_migration_statements(version, statement_index, checksum, applied_at)`**
  — created if absent. One row per successfully executed statement. `checksum`
  is the SHA-256 of the normalized statement text; if a pending file's already
  applied statements no longer match their recorded checksums, the runner stops
  with exit 4 instead of resuming into a different migration.

`--baseline` writes only the `schema_migrations` row — never statement rows,
because nothing was executed.

## Resumable, not atomic — read this before trusting a failure message

MySQL DDL implicitly commits, so **a migration file is not a transaction** and
this runner never claims a rollback. If statement `k` fails, statements
`0..k-1` are permanent, the file is left unmarked, and the run exits 1. Fix the
cause and re-run: it resumes at exactly `k`.

One irreducible crash window: a statement is executed and *then* its ledger row
is written. A process killed between the two will re-execute that one statement
on the next run. Prefer idempotent DDL (`IF NOT EXISTS`,
`INSERT ... ON DUPLICATE KEY UPDATE`) so a replay is harmless.

Concurrency is blocked by a MySQL advisory lock scoped to the target schema
(10s wait, then exit 3) — two runners interleaving statements would make the
per-statement ledger a lie.

## Writing a new migration

- Name it `NNN_short_name.sql` (numeric prefix, sorted numerically — `10_x` runs
  after `2_x`). Any other `.sql` filename in this directory is a hard error, not
  a silent skip. Two files sharing the same numeric prefix (`005_a.sql` +
  `005_b.sql`) are ALSO a hard error — an ambiguous apply order is almost
  always a merge accident, not intent.
- Plain `;`-separated statements. Quoted strings, `--`/`#`/`/* */` comments and
  `/*! ... */` version comments are all handled; `DELIMITER`, stored procedures,
  triggers and functions are **not** supported (nothing here uses them, and
  pretending to support them is how a real database gets corrupted). A bare
  `DELIMITER` keyword found outside a quoted literal or comment REJECTS THE
  WHOLE FILE up front, during parsing, before any of its statements run —
  never mis-split on the stored-program body's own internal `;`s.
- A comment-only file is valid: it is recorded applied with 0 statements. That
  is exactly what `004_erasure_audit_events_backfill.sql` is — a documented
  one-time ops backfill whose marker row proves an operator ran it.
- Keep statements idempotent where the DDL allows it (see the crash window
  above).

## Local compose note

The bundled compose DB (`waiver_db`) predates 001's marker row: its ledger has
`002_waiver_integration` and `003_erase_waiver` but **not** `001_init`. Running
`--dry-run` against it therefore reports 001 as pending. Baseline it before any
real run rather than letting the runner reason about that history.
