-- F2 fixture (duplicate-prefix) — shares numeric prefix 005 with 005_b.sql.
-- Neither file may apply: discovery itself must refuse the directory before
-- any migration in it (including a genuinely unique one elsewhere) runs.
CREATE TABLE f2_dup_a (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
