-- F2 fixture (duplicate-prefix) — shares numeric prefix 005 with 005_a.sql.
-- Without the duplicate-prefix guard, usort's [seq, version] tiebreak would
-- silently apply this AND 005_a.sql, ordered only by name -- an ambiguous
-- apply order that is almost always a merge accident, not intent.
CREATE TABLE f2_dup_b (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
