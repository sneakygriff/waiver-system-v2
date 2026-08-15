-- F2 fixture (future-005-after-baseline 004) — baselined, never executed.
-- Its version is "004_baseline_boundary", NOT "004", so `--through=004` can
-- only match it through the runner's numeric-prefix fallback. That is the form
-- an operator actually types, so it is the form under test.
CREATE TABLE f2_base_004 (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
