-- F2 fixture (mid-file-failing 001) — two statements, both succeed.
-- Bare `NNN.sql` filename (no _name suffix) on purpose: the runner's discovery
-- regex accepts it and the version is then just "001".
CREATE TABLE f2_mid_a (id INT NOT NULL PRIMARY KEY, tag VARCHAR(16) NOT NULL) ENGINE=InnoDB;
INSERT INTO f2_mid_a (id, tag) VALUES (1, 'one');
