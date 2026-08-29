-- 005_evidence_fields.sql
-- [T5 / waiver-coverage step 5] Persist the evidence-relay identifiers that
-- WaiverController::uploadEvidence() already computes/receives from
-- BookingV2's /api/waiver/evidence relay but previously DROPPED on the floor
-- (never written to waiver_responses, never returned by get_status). Without
-- these columns get_status() has nothing to return for evidence, so
-- BookingV2's reconcile path (waiver-reconcile.ts's toCompletionFields, which
-- ALREADY maps evidence_sha256/evidence_object_key/evidence_blob_key/
-- evidence_blob_url) receives nothing to map -- this was the fork-only half
-- of the go-live evidence gap (docs/designs/waiver-coverage.md, step 5, on
-- the BookingV2 side; T5 brief's "KEY DISCOVERY").
--
-- Column meanings (deliberately named identically to BookingV2's
-- prisma/schema.prisma WaiverCompletionEvent model, so reconcile's field
-- mapping is a straight passthrough -- see toCompletionFields):
--   evidence_sha256     SHA-256 of the exact PDF bytes uploaded (Gap2).
--                       Fixed-width CHAR(64): sha256 hex digests are ALWAYS
--                       exactly 64 lowercase hex characters by construction
--                       (hash('sha256', ...) / TS sha256Hex()) -- no
--                       truncation risk, not touched by the M5 gate fold F7
--                       widening below.
--   evidence_object_key the Vercel Blob key for the stored PDF -- the
--                       evidence relay's top-level `blob_key` in its JSON
--                       response. This is the SAME identifier the real-time
--                       completion webhook already carries today under this
--                       exact field name (notifyBookingV2Completion's
--                       `evidence_object_key`) -- unchanged by this migration.
--   evidence_blob_key   the Blob pathname the relay stored the PDF bytes
--                       under (BookingV2's BK-T15a naming) -- the SAME value
--                       as evidence_object_key. Kept as its own column
--                       because BookingV2's ledger (WaiverCompletionEvent)
--                       has a separate column of this name that reconcile
--                       maps independently; duplicating rather than aliasing
--                       avoids inventing a second, possibly-diverging
--                       identifier where only one exists on the relay side.
--   evidence_blob_url   the Blob-returned public URL for the PDF -- the
--                       relay's top-level `blob_url`. Previously computed by
--                       the relay but never read back by uploadEvidence().
--
-- All four are NULLable: a row completed before this migration ships, or
-- whose relay upload never confirmed (uploadEvidence's `$none`/failure
-- paths), has no evidence to report -- get_status() must return null for
-- these, never a fabricated value. Existing rows are backfilled to NULL
-- implicitly (no UPDATE needed); the one-off repair for fork
-- waiver_instances 19/21 is a SEPARATE, manual re-push through the (now
-- origin-verified) evidence route plus BookingV2's guarded
-- scripts/backfill-waiver-evidence.ts -- not part of this migration.
--
-- WIDTH (M5 gate fold F7 -- grok "VARCHAR(512) + STRICT_TRANS_TABLES can
-- abort a real completion"): evidence_object_key / evidence_blob_key are
-- TEXT, not VARCHAR(512). BookingV2's actual key format
-- (`evidenceBlobKey()`, src/lib/waiver/evidence-store.ts) is
-- `waiver-evidence/<participantId>/<waiverInstanceId>/<kind>.<ext>` -- well
-- under 512 bytes for today's cuid participant ids -- but this column
-- persists an identifier BookingV2 mints and this fork only ever COPIES
-- verbatim (never validates the shape of); a future format change on the
-- BookingV2 side must not be able to abort a guest's completion after their
-- PDF is ALREADY durably stored in Blob (STRICT_TRANS_TABLES turns "value
-- too long for column" into a hard INSERT failure, not a silent truncation
-- -- see WaiverController.php's submitGuestForm catch block, which would
-- roll the instance back to `pending` while the blob stays orphaned in
-- storage). TEXT (65,535 bytes) matches evidence_blob_url's existing type
-- below and cannot truncate any string this app will ever construct.
--
-- LEDGER NOTE (departs from 002/003's older hand-apply convention): this
-- file contains ONLY DDL. Do NOT add a manual
-- `INSERT INTO schema_migrations (...)` statement here the way 002/003 do --
-- those predate migrations/run.php (the per-statement ledger runner). The
-- runner records file completion itself, via recordFileApplied(), once every
-- statement below has executed successfully; a duplicate manual INSERT here
-- would collide with that on schema_migrations' `version` primary key and
-- fail the apply with a duplicate-key error.
--
-- IDEMPOTENCY (M5 gate fold F6 -- code-review P2-6 disproved the OLD "harmless
-- Duplicate column name" claim below: under migrations/run.php ANY failed
-- statement is EXIT_MIGRATION_FAILED and leaves the file unrecorded, so a
-- naive re-run (or a fresh install baselined only --through=004) would wedge
-- here forever, not degrade gracefully). Each ADD COLUMN below is now guarded
-- by an INFORMATION_SCHEMA.COLUMNS existence check compiled into a dynamic
-- statement via PREPARE/EXECUTE -- the portable, MySQL-version-agnostic
-- pattern (works on any MySQL 5.x/8.x; does NOT rely on the native
-- `ADD COLUMN IF NOT EXISTS` syntax, which needs MySQL >= 8.0.29 and this
-- fork's `docker-compose.yml` pins only the FLOATING `mysql:8.0` tag -- no
-- guaranteed patch floor). No DELIMITER / stored routine is used (run.php's
-- splitStatements() explicitly rejects DELIMITER and cannot execute one) --
-- SET/PREPARE/EXECUTE are ordinary statements, each its own ledger row, fully
-- compatible with the runner's per-statement resume model. Re-using the
-- prepared-statement name `add_evidence_col` across the four blocks is safe:
-- per the MySQL manual, PREPARE with an already-used name implicitly
-- deallocates the prior statement first, so no explicit DEALLOCATE PREPARE is
-- needed (and skipping it removes one more statement from the per-column
-- crash window, on a 4-row-at-go-live -- 21-row at full studio scale --
-- table where each ALTER itself is near-instant).
--
-- THE NO-OP BRANCH IS `DO 0`, NOT `SELECT 1` (found by the gate-fold's own
-- verification run against the real docker phpunit suite, not by inspection
-- alone): `EXECUTE add_evidence_col` when the compiled statement is a SELECT
-- returns a result set, and the runner's statement runner -- which invokes
-- the PHP PDO "run a statement, discard nothing" call for every line,
-- unconditionally -- does not fully consume/close it. The NEXT statement on
-- the same connection (the following block's `SET`, or the runner's own
-- ledger-row INSERT) then fails with MySQL error 2014 ("Cannot execute
-- queries while other unbuffered queries are active"), reproduced end to end
-- by
-- testReal005EvidenceFieldsMigrationNoOpsOnAFreshInstallSchemaThatAlreadyHasTheColumns
-- in tests/MigrationRunnerTest.php. `DO 0` (MySQL's "evaluate and discard"
-- statement) NEVER produces a result set -- neither branch of the `IF()`
-- does now, matching what the runner's statement call expects on every
-- statement in this file, always.
--
-- CONVERGENCE (the fold's actual requirement): this ONE file now behaves
-- identically on every schema shape it can ever meet --
--   * an EXISTING pre-005 database (staging/prod, baselined --through=004):
--     each column is genuinely missing -> each ALTER genuinely runs.
--   * a FRESH database built from the CURRENT 001_init.sql (the columns are
--     baked in there too -- same convention 002/003 already follow): every
--     existence check finds the column already present -> every guarded
--     ALTER is a no-op (`DO 0`) -> the file is recorded APPLIED with zero
--     real DDL. 005 is a genuinely SAFE NO-OP on a fresh install now, not an
--     error an operator must route around with a wider --baseline.
--   * a crash-window replay (the runner's own irreducible "executed but not
--     yet recorded" gap, see run.php's header): re-running an already-applied
--     ADD COLUMN hits the SAME existence check and again no-ops, instead of
--     the old `Duplicate column name` hard failure.
-- The old header claimed the fresh-install duplicate-column case was
-- "harmless" -- true only under the pre-runner hand-apply convention it was
-- written for, and FALSE under migrations/run.php (a failed statement is a
-- hard stop, per the module's own EXIT_MIGRATION_FAILED contract). This is
-- corrected here, not just in the gate report.

SET @evidence_sha256_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'waiver_responses' AND COLUMN_NAME = 'evidence_sha256'
);
SET @add_evidence_sha256 = IF(@evidence_sha256_exists = 0,
  'ALTER TABLE waiver_responses ADD COLUMN evidence_sha256 CHAR(64) NULL AFTER signature_path',
  'DO 0'
);
PREPARE add_evidence_col FROM @add_evidence_sha256;
EXECUTE add_evidence_col;

SET @evidence_object_key_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'waiver_responses' AND COLUMN_NAME = 'evidence_object_key'
);
SET @add_evidence_object_key = IF(@evidence_object_key_exists = 0,
  'ALTER TABLE waiver_responses ADD COLUMN evidence_object_key TEXT NULL AFTER evidence_sha256',
  'DO 0'
);
PREPARE add_evidence_col FROM @add_evidence_object_key;
EXECUTE add_evidence_col;

SET @evidence_blob_key_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'waiver_responses' AND COLUMN_NAME = 'evidence_blob_key'
);
SET @add_evidence_blob_key = IF(@evidence_blob_key_exists = 0,
  'ALTER TABLE waiver_responses ADD COLUMN evidence_blob_key TEXT NULL AFTER evidence_object_key',
  'DO 0'
);
PREPARE add_evidence_col FROM @add_evidence_blob_key;
EXECUTE add_evidence_col;

SET @evidence_blob_url_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'waiver_responses' AND COLUMN_NAME = 'evidence_blob_url'
);
SET @add_evidence_blob_url = IF(@evidence_blob_url_exists = 0,
  'ALTER TABLE waiver_responses ADD COLUMN evidence_blob_url TEXT NULL AFTER evidence_blob_key',
  'DO 0'
);
PREPARE add_evidence_col FROM @add_evidence_blob_url;
EXECUTE add_evidence_col;
