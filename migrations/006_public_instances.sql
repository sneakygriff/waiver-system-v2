-- 006_public_instances.sql
-- [GVS-89 / waiver-coverage T6 "Reception QR"] Public (booking-less) waiver
-- instances. BookingV2 mints a walk-in signup at its public page
-- `/waiver/receptie`, then asks this fork to create a PUBLIC instance for it
-- (new api.php action `create_public_instance`). The instance is keyed by the
-- BookingV2-minted signup token stored in the EXISTING `link_token` column
-- (so w.php?token=, uploadEvidence and get_status keep working unchanged), and
-- it carries NO reservation binding: reservation_id / participant_id /
-- customer_id / booking_group_id are ALREADY nullable since 001_init.sql, so
-- this migration adds no nullability change -- only the three columns and the
-- index below.
--
-- Column meanings:
--   is_public  1 = a reception-QR instance created by create_public_instance;
--              0 = every other instance (reservation-bound or legacy walk-in).
--              NOT NULL DEFAULT 0, so every row that predates this migration
--              is backfilled to 0 by the ALTER itself (no UPDATE needed) and
--              keeps its exact current behaviour.
--   expires_at UTC DATETIME after which w.php refuses to RENDER a public
--              instance's form (WaiverController::renderGuestForm). NULL for
--              non-public rows. A public row with a NULL expires_at is treated
--              as EXPIRED (fail closed) -- create_public_instance always sets it.
--   locale     'ro' | 'en' -- the language BookingV2's mint page ran in; used
--              for the public-only guest-facing messages (the "link expired"
--              page today). NULL for non-public rows.
--   idx_public_expires (is_public, expires_at) -- supports any "public
--              instances by expiry" scan without touching reservation-bound rows.
--
-- LEDGER NOTE: DDL only -- no manual `INSERT INTO schema_migrations`; the
-- runner (migrations/run.php) records the file itself once every statement
-- has executed (see 005_evidence_fields.sql's LEDGER NOTE for why a manual
-- INSERT here would fail the apply on the version primary key).
--
-- BAKED INTO 001_init.sql (same convention as 002/003/005): a FRESH database
-- gets these columns + index from 001_init.sql directly, and
-- dev/predeploy.php's MAX_BAKED_MIGRATION is bumped to this file so a fresh
-- bootstrap baselines it. Types MUST stay identical to the guarded ALTERs
-- below (pinned by tests/MigrationRunnerTest.php, which checks both shapes
-- against the same expectation).
--
-- IDEMPOTENCY: the whole change is ONE guarded, dynamically built ALTER. A
-- single INFORMATION_SCHEMA read lists which of the three columns and the
-- index are MISSING, and only those clauses go into the ALTER (compiled via
-- PREPARE/EXECUTE -- the 005_evidence_fields.sql technique: portable to any
-- MySQL 5.7/8.x, no native `ADD COLUMN IF NOT EXISTS`, no DELIMITER / stored
-- routine, which run.php rejects). When nothing is missing the statement is
-- `DO 0`, never `SELECT 1`: an EXECUTEd SELECT leaves an unconsumed result set
-- that breaks the runner's next statement with MySQL error 2014 (see 005's
-- header). So this ONE file converges on every schema shape it can meet:
--   * an EXISTING pre-006 database (staging/prod, 001..005 ledgered): all four
--     objects are missing -> one ALTER adds them all;
--   * a FRESH database built from the CURRENT 001_init.sql (006 baked in):
--     nothing is missing -> `DO 0`;
--   * a crash-window replay, a partially-migrated schema, or any re-run: only
--     what is still missing is added, never `Duplicate column name` /
--     `Duplicate key name`. (Columns are added before the index within the
--     one statement, and `expires_at ... AFTER is_public` may name a column
--     added earlier in the same ALTER.)
--
-- ONLINE, AND BOUNDED [gate 89-M4, Codex P2 "metadata-lock exposure"]:
--   * ONE ALTER, not four: a single metadata-lock (MDL) acquisition and a
--     single table rebuild instead of up to four of each.
--   * `ALGORITHM=INPLACE, LOCK=NONE` is REQUESTED, not hoped for: MySQL then
--     either performs the change online (concurrent reads AND writes allowed
--     for the whole rebuild; only a brief exclusive MDL at start and commit)
--     or refuses with an error -- it can never silently fall back to a
--     table-COPY that blocks waiver renders/submissions. (`AFTER` rules out
--     INSTANT on MySQL < 8.0.29 and ADD INDEX rules it out on every version,
--     so INPLACE is the best guaranteed-online algorithm here.)
--   * `lock_wait_timeout = 10` for this session: an ALTER waiting for its
--     exclusive MDL queues every later query on waiver_instances behind it,
--     so a long-running transaction elsewhere would otherwise stall the
--     guest pages for up to the server default (one YEAR). Instead the ALTER
--     gives up after 10 s (error 1205), the runner exits 1 with nothing
--     changed (a failed DDL is atomic), predeploy fails, the deploy is
--     blocked, and the next deploy retries. The session value is restored at
--     the end of the file.
--
-- RESUMABILITY [gate 89-M4, Codex P2]: the runner ledgers each statement;
-- `SET @var`, `SET SESSION` and `PREPARE` are session state that a NEW
-- runner process does not have. migrations/run.php therefore re-executes the
-- contiguous run of such session-only statements that precedes its resume
-- point before resuming (see run.php "SESSION-STATE REPLAY"), so a failure at
-- the EXECUTE (e.g. the 10 s MDL timeout above) resumes cleanly: the replayed
-- `SET @public_instances_ddl` re-reads INFORMATION_SCHEMA and recompiles
-- exactly what is still missing. There is deliberately no trailing
-- `DEALLOCATE PREPARE` (it would fail on a resume that lands just after the
-- EXECUTE; the handle dies with the session anyway).
-- waiver_instances is small (one row per issued waiver link), so the rebuild
-- itself is sub-second.

SET SESSION lock_wait_timeout = 10;

SET @public_instances_ddl = (
  SELECT CONCAT_WS(', ',
    IF(SUM(COLUMN_NAME = 'is_public') = 0,
       'ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER completed_at', NULL),
    IF(SUM(COLUMN_NAME = 'expires_at') = 0,
       'ADD COLUMN expires_at DATETIME NULL AFTER is_public', NULL),
    IF(SUM(COLUMN_NAME = 'locale') = 0,
       'ADD COLUMN locale VARCHAR(2) NULL AFTER expires_at', NULL),
    IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'waiver_instances'
           AND INDEX_NAME = 'idx_public_expires') = 0,
       'ADD INDEX idx_public_expires (is_public, expires_at)', NULL)
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'waiver_instances'
);

SET @public_instances_ddl = IF(@public_instances_ddl IS NULL OR @public_instances_ddl = '',
  'DO 0',
  CONCAT('ALTER TABLE waiver_instances ', @public_instances_ddl, ', ALGORITHM=INPLACE, LOCK=NONE')
);

PREPARE add_public_instance_ddl FROM @public_instances_ddl;
EXECUTE add_public_instance_ddl;

SET SESSION lock_wait_timeout = DEFAULT;
