-- 003_erase_waiver.sql
-- FK-erase: GDPR erase_waiver action support.
-- - waiver_responses.signature_path: the signature PNG is currently written to
--   storage/signatures/<random>.png (WaiverController::submitGuestForm) but the
--   PATH WAS NEVER PERSISTED anywhere -- only the signature_png LONGBLOB is
--   stored in the DB. Without a persisted path, erase_waiver has no way to
--   find and delete the on-disk signature file. This column closes that gap
--   going forward (existing rows will have signature_path=NULL; their stray
--   on-disk files are pre-existing orphans outside this task's scope, and
--   deleting the waiver_responses row still purges the LONGBLOB itself, which
--   is the PII-bearing artifact G5 calls out explicitly).
-- - audit_events.entity_type: add 'erasure' so an erase_waiver audit row does
--   not have to misrepresent itself as a 'template'/'instance'/'response'
--   event; entity_id is a synthetic 0 (erasure spans N instances, not one).
-- NOTE: this fork has NO migration runner. Hand-apply via:
--   docker compose exec -T db mysql -u root -prootpw waiver_db < migrations/003_erase_waiver.sql
-- Fresh installs get these baked directly into 001_init.sql.

ALTER TABLE waiver_responses
  ADD COLUMN signature_path VARCHAR(512) NULL AFTER pdf_path;

ALTER TABLE audit_events
  MODIFY COLUMN entity_type ENUM('template','instance','response','erasure') NOT NULL;

INSERT INTO schema_migrations (version, applied_at) VALUES ('003_erase_waiver', NOW());
