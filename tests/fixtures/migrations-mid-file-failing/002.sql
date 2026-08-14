-- F2 fixture (mid-file-failing 002) — three statements:
--   #0 succeeds and is NOT idempotent (a bare CREATE TABLE),
--   #1 FAILS (column `nope` does not exist),
--   #2 succeeds once #1 has been repaired.
-- The non-idempotent #0 is the whole point: a runner that restarted the file at
-- index 0 instead of resuming at index 1 would die on "table already exists",
-- so "resumed" and "restarted" are distinguishable from the outside.
CREATE TABLE f2_mid_b (id INT NOT NULL PRIMARY KEY, tag VARCHAR(16) NOT NULL) ENGINE=InnoDB;
INSERT INTO f2_mid_b (id, nope) VALUES (2, 'two');
INSERT INTO f2_mid_b (id, tag) VALUES (3, 'three');
