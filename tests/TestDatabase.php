<?php
namespace Tests;

use App\Database;
use PDO;

/**
 * [F1-fork-erasure] Thin helper wrapping the `waiver_test` MySQL schema for
 * the phpunit micro-harness. Requires the same Docker `db` service the app
 * itself uses (docker-compose.yml), with a `waiver_test` database already
 * created and migrated (see migrations/001_init.sql + 002/003 -- run once,
 * documented in tests/README.md). Never touches `waiver_db`.
 */
class TestDatabase {
  public static function config(): array {
    return require __DIR__.'/../config/config.test.php';
  }

  public static function connect(): Database {
    return new Database(self::config()['db']);
  }

  /** Wipe all app tables between tests (fast TRUNCATE, FK-order safe). */
  public static function reset(PDO $pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['waiver_responses', 'waiver_instances', 'audit_events', 'webhook_nonces', 'waiver_template_versions', 'waiver_templates', 'users'] as $table) {
      $pdo->exec('TRUNCATE TABLE `'.$table.'`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
  }

  /** Insert a minimal template + published version, return its version id. */
  public static function seedPublishedTemplateVersion(PDO $pdo): int {
    $pdo->prepare('INSERT INTO waiver_templates (name, is_active, created_by, created_at, updated_at) VALUES (?,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
      ->execute(['Test Template']);
    $templateId = (int)$pdo->lastInsertId();

    $fields = json_encode([
      ['key'=>'full_name','label'=>'Full name','type'=>'text','required'=>true],
    ]);
    $pdo->prepare('INSERT INTO waiver_template_versions (template_id, version, title, fields_json, requires_signature, created_by, created_at, is_published, published_at) VALUES (?,1,?,?,1,1,UTC_TIMESTAMP(),1,UTC_TIMESTAMP())')
      ->execute([$templateId, 'Test Waiver', $fields]);
    return (int)$pdo->lastInsertId();
  }

  /** Insert a bare waiver_instances row, return its id. */
  public static function seedInstance(PDO $pdo, int $templateVersionId, array $overrides = []): int {
    $row = array_merge([
      'reservation_id' => null,
      'participant_id' => null,
      'customer_id' => 'cust-1',
      'booking_group_id' => null,
      'guest_name' => 'Test Guest',
      'guest_email' => 'guest@example.com',
      'link_token' => bin2hex(random_bytes(16)),
      'group_token' => null,
      'status' => 'pending',
    ], $overrides);

    $stmt = $pdo->prepare('INSERT INTO waiver_instances (template_version_id, reservation_id, participant_id, customer_id, booking_group_id, guest_name, guest_email, link_token, group_token, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $stmt->execute([
      $templateVersionId,
      $row['reservation_id'], $row['participant_id'], $row['customer_id'], $row['booking_group_id'],
      $row['guest_name'], $row['guest_email'], $row['link_token'], $row['group_token'], $row['status'],
    ]);
    return (int)$pdo->lastInsertId();
  }

  /**
   * [GVS-89] Insert a PUBLIC (reception-QR) waiver_instances row -- the shape
   * WaiverController::createPublicInstance() writes: is_public=1, every
   * binding id + guest_name/guest_email NULL, a locale, and an expiry.
   * $expiresInSeconds is relative to the DB clock (UTC_TIMESTAMP()) -- the
   * same clock renderGuestForm()'s expiry gate compares against -- so a
   * negative value seeds an already-expired instance; null seeds a public row
   * with NO expiry (the fail-closed case). Returns the new instance id.
   */
  public static function seedPublicInstance(PDO $pdo, int $templateVersionId, array $overrides = [], ?int $expiresInSeconds = 3600): int {
    $row = array_merge([
      'link_token' => bin2hex(random_bytes(16)),
      'status' => 'pending',
      'locale' => 'ro',
      'participant_id' => null,
      'customer_id' => null,
      'booking_group_id' => null,
    ], $overrides);
    $expiresSql = $expiresInSeconds === null ? 'NULL' : 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL '.(int)$expiresInSeconds.' SECOND)';
    $stmt = $pdo->prepare('INSERT INTO waiver_instances (template_version_id, reservation_id, participant_id, customer_id, booking_group_id, guest_name, guest_email, link_token, group_token, status, is_public, expires_at, locale, created_at, updated_at) VALUES (?,NULL,?,?,?,NULL,NULL,?,NULL,?,1,'.$expiresSql.',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $stmt->execute([
      $templateVersionId,
      $row['participant_id'], $row['customer_id'], $row['booking_group_id'],
      $row['link_token'], $row['status'], $row['locale'],
    ]);
    return (int)$pdo->lastInsertId();
  }

  /**
   * @param array{evidence_sha256?:?string,evidence_object_key?:?string,evidence_blob_key?:?string,evidence_blob_url?:?string} $evidence
   *   [T5] Optional evidence-field overrides (migrations/005_evidence_fields.sql).
   *   Default omits them entirely -> all four NULL, i.e. the "legacy row"
   *   shape get_status() must still return nulls for.
   */
  public static function seedResponse(PDO $pdo, int $instanceId, array $answers = ['full_name'=>'Test Guest'], array $evidence = []): int {
    $stmt = $pdo->prepare('INSERT INTO waiver_responses (waiver_instance_id, answers_json, signature_png, signer_full_name, signed_at, signer_ip, signer_user_agent, hash_sha256, pdf_path, signature_path, evidence_sha256, evidence_object_key, evidence_blob_key, evidence_blob_url, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
    $stmt->execute([
      $instanceId, json_encode($answers), null, 'Test Guest', '127.0.0.1', 'phpunit', hash('sha256', 'x'), null, null,
      $evidence['evidence_sha256'] ?? null,
      $evidence['evidence_object_key'] ?? null,
      $evidence['evidence_blob_key'] ?? null,
      $evidence['evidence_blob_url'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
  }

  public static function seedAuditEvent(PDO $pdo, string $entityType, int $entityId, string $event, array $meta = []): int {
    $stmt = $pdo->prepare('INSERT INTO audit_events (entity_type, entity_id, event, meta_json, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
    $stmt->execute([$entityType, $entityId, $event, json_encode($meta)]);
    return (int)$pdo->lastInsertId();
  }
}
