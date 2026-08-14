-- F2 fixture (future-005-after-baseline 005) — the genuinely new migration
-- that MUST run after baselining 001..004. It deliberately depends on nothing
-- the baselined files would have created, because they never executed.
CREATE TABLE f2_future_005 (id INT NOT NULL PRIMARY KEY, tag VARCHAR(16) NOT NULL) ENGINE=InnoDB;
INSERT INTO f2_future_005 (id, tag) VALUES (1, 'ran');
