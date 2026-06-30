-- 001_init.sql
CREATE TABLE IF NOT EXISTS users (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin') DEFAULT 'admin',
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
);
CREATE TABLE IF NOT EXISTS waiver_templates (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT NOT NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
);
CREATE TABLE IF NOT EXISTS waiver_template_versions (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  template_id BIGINT NOT NULL,
  version INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  fields_json JSON NOT NULL,
  requires_signature TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT NOT NULL,
  created_at DATETIME NOT NULL,
  content_html MEDIUMTEXT NULL,
  print_css TEXT NULL,
  UNIQUE (template_id, version),
  INDEX (template_id)
);
CREATE TABLE IF NOT EXISTS waiver_instances (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  template_version_id BIGINT NOT NULL,
  reservation_id VARCHAR(64) NULL,
  guest_name VARCHAR(255) NULL,
  guest_email VARCHAR(255) NULL,
  link_token CHAR(64) NOT NULL UNIQUE,
  group_token CHAR(16) NULL,
  status ENUM('pending','completed','void') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  INDEX (reservation_id), INDEX (status), INDEX (group_token)
);
CREATE TABLE IF NOT EXISTS waiver_responses (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  waiver_instance_id BIGINT NOT NULL UNIQUE,
  answers_json JSON NOT NULL,
  signature_png LONGBLOB NULL,
  signer_full_name VARCHAR(255) NULL,
  signer_initials VARCHAR(16) NULL,
  signed_at DATETIME NOT NULL,
  signer_ip VARCHAR(45) NULL,
  signer_user_agent TEXT NULL,
  hash_sha256 CHAR(64) NOT NULL,
  pdf_path VARCHAR(512) NULL,
  created_at DATETIME NOT NULL,
  INDEX (signed_at),
  INDEX (waiver_instance_id)
);
CREATE TABLE IF NOT EXISTS audit_events (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  entity_type ENUM('template','instance','response') NOT NULL,
  entity_id BIGINT NOT NULL,
  event VARCHAR(64) NOT NULL,
  meta_json JSON NULL,
  actor_user_id BIGINT NULL,
  actor_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  INDEX (entity_type, entity_id, created_at)
);
