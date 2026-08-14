-- F2 fixture (clean-chain 001) — two executable statements.
-- BOTH are deliberately NON-idempotent: re-executing either one raises an
-- error (table already exists / duplicate primary key). That is what makes the
-- idempotency test non-vacuous — a second run that re-ran anything would fail
-- loudly instead of quietly doing the same work twice.
CREATE TABLE f2_clean_a (id INT NOT NULL PRIMARY KEY, note VARCHAR(64) NOT NULL) ENGINE=InnoDB;
INSERT INTO f2_clean_a (id, note) VALUES (1, 'first');
