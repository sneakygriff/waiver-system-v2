-- [GVS-89 / gate 89-M4 r2, Fable P2-1] Session-state replay across a
-- `DEALLOCATE PREPARE`. Run 1: #0..#3 build and use f2_dealloc_made, #4..#7
-- re-establish a handle `q` (with a DEALLOCATE in between), and the EXECUTE
-- (#8) fails because f2_dealloc_target does not exist yet. Run 2 (after the
-- test creates it) resumes at #8 in a NEW connection; its replay window is
-- #3..#7:
--   #3 `DEALLOCATE PREPARE p` -- p was prepared BEFORE the window (#1), so in
--      the new connection there is nothing to release: re-running it fails
--      with MySQL 1243 and wedges the resume. It must be skipped.
--   #6 must NOT end the window either: @alter_ddl (#4) and `q` (#7, prepared
--      FROM @alter_ddl) are both needed by the EXECUTE, so the walk has to go
--      past the DEALLOCATE back to #4.
SET @create_ddl = 'CREATE TABLE f2_dealloc_made (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB';
PREPARE p FROM @create_ddl;
EXECUTE p;
DEALLOCATE PREPARE p;
SET @alter_ddl = 'ALTER TABLE f2_dealloc_target ADD COLUMN added_on_resume INT NULL';
PREPARE q FROM @alter_ddl;
DEALLOCATE PREPARE q;
PREPARE q FROM @alter_ddl;
EXECUTE q;
