-- F2 fixture (mid-file-failing 003) — must NOT be reached while 002 is failing;
-- the row it inserts is the observable marker for "the runner kept going past a
-- failed file", which it must never do.
INSERT INTO f2_mid_a (id, tag) VALUES (9, 'after');
