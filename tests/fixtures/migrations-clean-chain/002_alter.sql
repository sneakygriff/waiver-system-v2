-- F2 fixture (clean-chain 002) — one executable statement.
-- The column it adds (`extra`) is the observable marker for "did this file
-- actually execute?": the pre-existing-ledger-row test asserts the column is
-- ABSENT after a run that reports 002 as already applied.
ALTER TABLE f2_clean_a ADD COLUMN extra VARCHAR(32) NULL;
