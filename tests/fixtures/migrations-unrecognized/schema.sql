-- F2 fixture (unrecognized) — NOT a migration: no NNN prefix. A whole-schema
-- dump dropped into a migrations directory is the realistic version of this
-- mistake. The runner must exit 2 naming this file, never skip it quietly.
CREATE TABLE f2_should_never_exist (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
