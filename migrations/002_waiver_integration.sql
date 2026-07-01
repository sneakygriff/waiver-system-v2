-- 002_waiver_integration.sql
-- FK-T5: link_token widen, participant/customer/booking_group columns + indexes,
-- is_published gate on waiver_template_versions, webhook_nonces, schema_migrations.
-- NOTE: this fork has NO migration runner. This file documents the ALTERs for
-- existing (already-initialized) databases; hand-apply via:
--   docker compose exec -T db mysql -u root -prootpw waiver_db < migrations/002_waiver_integration.sql
-- Fresh installs get these baked directly into 001_init.sql and do not need this file.

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(32) PRIMARY KEY,
  applied_at DATETIME NOT NULL
);

-- Guard: only apply if not already recorded (safe to re-run manually).
-- (No native "IF NOT applied" in MySQL DDL; operator should check schema_migrations first.)

ALTER TABLE waiver_instances MODIFY COLUMN link_token VARCHAR(128) NOT NULL;   -- was CHAR(64), UNIQUE preserved

ALTER TABLE waiver_instances
  ADD COLUMN participant_id  VARCHAR(64) NULL AFTER reservation_id,
  ADD COLUMN customer_id     VARCHAR(64) NULL AFTER participant_id,
  ADD COLUMN booking_group_id VARCHAR(64) NULL AFTER customer_id,
  ADD INDEX idx_participant (participant_id),
  ADD INDEX idx_customer (customer_id),
  ADD INDEX idx_booking_group (booking_group_id);

CREATE TABLE IF NOT EXISTS webhook_nonces (
  nonce VARCHAR(64) PRIMARY KEY,
  expires_at DATETIME NOT NULL
);

-- [Gap1] published-version gate: the fork has NO publish concept today (picks MAX(version)).
ALTER TABLE waiver_template_versions
  ADD COLUMN is_published TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN published_at DATETIME NULL,
  ADD INDEX idx_template_published (template_id, is_published);

INSERT INTO schema_migrations (version, applied_at) VALUES ('002_waiver_integration', NOW());
