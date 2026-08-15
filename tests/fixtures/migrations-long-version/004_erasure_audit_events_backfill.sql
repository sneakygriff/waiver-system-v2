-- F2 fixture (long-version) — the version string "004_erasure_audit_events_backfill"
-- is 33 characters, one more than the VARCHAR(32) `schema_migrations.version`
-- that this fork's 001_init.sql creates. Under MySQL 8's default
-- STRICT_TRANS_TABLES the ledger INSERT then fails with error 1406 ("Data too
-- long"), so a runner that does not widen the column first is DOA on the
-- fork's own fourth migration. The filename is copied from the real one on
-- purpose; shortening it would silently disarm this test.
CREATE TABLE f2_long_version (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
