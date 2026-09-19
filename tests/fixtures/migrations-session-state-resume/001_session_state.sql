-- [GVS-89 / gate 89-M4, Codex P2] Session-state resume fixture: the guarded
-- `SET @ddl / PREPARE / EXECUTE` shape 005 and 006 use, reduced to its core.
-- Run 1: f2_resume_target does not exist, so the EXECUTE (#2) fails while #0
-- (a user variable) and #1 (a prepared handle) are already ledgered -- state
-- that lives only in run 1's connection. Run 2 (after the test creates the
-- table) resumes at #2 in a NEW connection and must re-establish #0..#1 first.
SET @probe_ddl = 'ALTER TABLE f2_resume_target ADD COLUMN added_on_resume INT NULL';
PREPARE probe_ddl FROM @probe_ddl;
EXECUTE probe_ddl;
