-- F2 fixture (unrecognized) — a perfectly valid migration sitting next to a
-- file the runner cannot version. It must NOT be applied: the runner stops on
-- the unrecognized sibling instead, because a silently skipped migration is
-- worse than a loud refusal.
CREATE TABLE f2_never (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
