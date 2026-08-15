-- F2 fixture (delimiter, 001) — an ordinary, valid statement in the file
-- BEFORE the DELIMITER-bearing one. Proves the "prior sibling file already
-- applied cleanly" half of the DELIMITER test.
CREATE TABLE f2_delim_before (id INT NOT NULL PRIMARY KEY, tag VARCHAR(16) NOT NULL) ENGINE=InnoDB;
INSERT INTO f2_delim_before (id, tag) VALUES (1, 'before');
