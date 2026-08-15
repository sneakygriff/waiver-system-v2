-- F2 fixture (comment-only) — ZERO executable statements. The fork's real
-- 004_erasure_audit_events_backfill.sql is exactly this shape: documentation
-- only, no DDL. The runner must record the file applied with 0 statements
-- rather than error or silently skip it.
--
-- Note the semicolons below: they live inside comments, so a comment-blind
-- splitter would emit garbage fragments and try to execute them.
# hash-style comment; also not a statement
/* block comment spanning
   two lines; with a semicolon inside */
