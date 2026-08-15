-- F2 fixture (future-005-after-baseline 001) — baselined, never executed.
-- The table is the observable marker: if it exists after a --baseline run, the
-- runner executed a file it only promised to MARK.
CREATE TABLE f2_base_001 (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
