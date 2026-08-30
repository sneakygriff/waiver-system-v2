<?php
namespace Tests;

use App\WaiverController;
use PHPUnit\Framework\TestCase;

/**
 * [post-incident 2026-08-30] submitGuestForm()'s broad `catch (\Throwable)` used
 * to swallow a save error into a generic HTTP-200 banner with NOTHING logged --
 * the exact shape of the 16h outage (a save against a schema missing a column).
 * These tests pin the hardening that makes such a failure VISIBLE and traceable
 * WITHOUT ever leaking guest PII:
 *
 *   Fix 2a / Grok #1  the catch logs ONLY non-PII structured fields (ref,
 *           waiver_instance_id, exception CLASS, SQLSTATE, numeric driver code,
 *           file:line) via error_log(). It NEVER logs $e->getMessage() OR the
 *           PDO driver message (errorInfo[2]) -- because a PDO message embeds
 *           the offending VALUE ("Duplicate entry 'jane@example.com' ...", or
 *           bytes of an over-long full_name), which is guest PII.
 *   Fix #9  the handler is no-throw: the ref is minted first, the log line is
 *           written first, and the rollback/cleanup cannot suppress the response.
 *   Fix 3 / #10  the guest banner carries the SAME ref, one generic message,
 *           and the internal-failure payload signals http_status=500.
 *
 * NON-VACUITY (Grok #4): the earlier version of this test induced an
 * "Unknown column" failure whose driver message contains NO PII, so its no-PII
 * assertion could not fail even if the code logged getMessage(). This version
 * forces a DUPLICATE-ENTRY (MySQL 1062) on a canary VALUE, so the suppressed
 * driver message LITERALLY contains the canary name/email/DOB tokens. If the
 * handler ever logs getMessage()/errorInfo[2] again, those tokens reappear in
 * the log and the denylist assertions below DIE -- that is the mutation guard.
 *
 * REQUIRES the `waiver_test` MySQL schema (tests/README.md); skips if it is
 * unreachable, matching this harness's DB-gated convention.
 */
final class WaiverControllerSubmitFailureLoggingTest extends TestCase {
  private \PDO $pdo;
  private array $cfg;
  private \App\Database $db;
  private int $versionId;

  protected function setUp(): void {
    $this->cfg = TestDatabase::config();
    try {
      $this->db = TestDatabase::connect();
    } catch (\Throwable $e) {
      $this->markTestSkipped('waiver_test DB unreachable: '.$e->getMessage());
    }
    $this->pdo = $this->db->pdo();
    TestDatabase::reset($this->pdo);
    $this->versionId = TestDatabase::seedPublishedTemplateVersion($this->pdo);
  }

  public function testSaveFailureLogsOnlySafeFieldsNeverPiiAndBannerCarriesRefAnd500(): void {
    // Canary PII, packed into full_name so the induced DUPLICATE-ENTRY driver
    // message will contain it verbatim (MySQL 1062 echoes the offending value).
    $canaryEmail    = 'jane.canary-pii@example.test';
    $canaryDob      = '1988-02-29';
    $canaryFullName = 'Ada CANARY-LEAK Lovelace '.$canaryEmail.' DOB '.$canaryDob; // < 255 chars
    $sigBase64Prefix = 'iVBORw0KGgo'; // body prefix of the 1x1 PNG below

    $instanceId = TestDatabase::seedInstance($this->pdo, $this->versionId, ['customer_id' => 'cust-save-fail']);
    $token = $this->linkTokenOf($instanceId);

    // Force the real waiver_responses INSERT to collide on a canary VALUE:
    //   (1) add a temporary UNIQUE index on signer_full_name,
    //   (2) pre-seed a row whose signer_full_name IS the canary (with an unused
    //       waiver_instance_id so its own UNIQUE key doesn't collide first),
    //   (3) submit full_name = the SAME canary -> 1062 Duplicate entry
    //       '<canary>' for key 'signer_full_name'. Both the index and the seed
    //       row are removed in `finally` so later tests see a pristine schema.
    $this->pdo->exec('ALTER TABLE waiver_responses ADD UNIQUE KEY uniq_signer_full_name_canary (signer_full_name)');
    $seed = $this->pdo->prepare('INSERT INTO waiver_responses (waiver_instance_id, answers_json, signed_at, hash_sha256, signer_full_name, created_at) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP())');
    $seed->execute([999999, json_encode(['x' => 1]), hash('sha256', 'x'), $canaryFullName]);

    // Redirect error_log() to a temp file so we can read back what the handler
    // wrote (in production this goes to /dev/stderr -> Railway; docker/php/errors.ini).
    $logFile = tempnam(sys_get_temp_dir(), 'waiver_save_err_');
    $prevLog = ini_get('error_log');
    ini_set('error_log', $logFile);

    try {
      $ctl = new WaiverController($this->cfg, $this->db);
      $result = $ctl->submitGuestForm($token, [
        'full_name' => $canaryFullName,
        'signature_data' => self::onePixelPngDataUri(),
      ]);
    } finally {
      ini_set('error_log', $prevLog === false ? '' : $prevLog);
      $this->pdo->exec('ALTER TABLE waiver_responses DROP KEY uniq_signer_full_name_canary');
      $this->pdo->exec('DELETE FROM waiver_responses WHERE waiver_instance_id=999999');
    }

    // --- The guest-facing payload (Fix 3 + #10) --------------------------------
    $this->assertArrayHasKey('error', $result, 'a save failure must return an error banner');
    $this->assertArrayHasKey('ref', $result, 'the error payload must carry a correlation ref');
    $ref = (string)$result['ref'];
    $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $ref, 'ref must be 8 hex chars');
    $this->assertStringContainsString($ref, $result['error'], 'the banner must show the correlation ref');
    // #10: an INTERNAL persistence failure must signal HTTP 500 (validation
    // errors, returned before the try, must NOT set http_status).
    $this->assertSame(500, $result['http_status'] ?? null, 'internal save failure must signal http_status=500');
    // Generic + safe banner: no SQL internals, no canary PII.
    $this->assertStringNotContainsStringIgnoringCase('duplicate entry', $result['error']);
    $this->assertStringNotContainsString('signer_full_name', $result['error']);
    $this->assertStringNotContainsString($canaryFullName, $result['error']);
    $this->assertStringNotContainsString($canaryEmail, $result['error']);

    // --- The server-side log line (Fix 2a / Grok #1) ---------------------------
    $log = (string)file_get_contents($logFile);
    @unlink($logFile);

    // Safe, structured fields ARE present. Asserting them proves this really is
    // the save-failure path AND that the failure was the duplicate-entry whose
    // driver message carried the canary -- which is what makes the no-PII
    // assertions below NON-VACUOUS.
    $this->assertStringContainsString('[WAIVER-SAVE-ERROR]', $log, 'the catch must log a WAIVER-SAVE-ERROR line');
    $this->assertStringContainsString('ref='.$ref, $log, 'the log must carry the same ref the guest was shown');
    $this->assertStringContainsString('waiver_instance_id='.$instanceId, $log, 'the log must identify the waiver instance');
    $this->assertStringContainsString('exception=PDOException', $log, 'the failing class must be recorded');
    $this->assertStringContainsString('sqlstate=23000', $log, 'SQLSTATE 23000 (integrity constraint violation) proves the dup fired');
    $this->assertStringContainsString('driver_code=1062', $log, 'driver code 1062 (duplicate entry) proves the dup fired');

    // CRITICAL (Grok #1 + #4): NONE of the canary PII tokens -- all present in
    // the driver message we deliberately did NOT log -- may appear anywhere in
    // the log. If the handler regresses to logging getMessage()/errorInfo[2],
    // one of these fires.
    foreach ([$canaryFullName, $canaryEmail, $canaryDob, 'CANARY-LEAK', 'Lovelace', $sigBase64Prefix, 'data:image/png'] as $needle) {
      $this->assertStringNotContainsString($needle, $log, 'PII/secret leaked into the log: '.$needle);
    }
    // The whole driver message text, and the old raw-message/driver-message
    // fields, must be gone.
    $this->assertStringNotContainsString('Duplicate entry', $log, 'the driver message text must never be logged');
    $this->assertStringNotContainsString('message=', $log, 'the raw exception-message field must be gone');
    $this->assertStringNotContainsString('driver_msg=', $log, 'the driver-message field must be gone');

    // The catch also rolled the atomic claim back so the guest can retry.
    $st = $this->pdo->prepare('SELECT status FROM waiver_instances WHERE id=?');
    $st->execute([$instanceId]);
    $this->assertSame('pending', $st->fetchColumn(), 'the instance must be rolled back to pending for retry');
  }

  private function linkTokenOf(int $instanceId): string {
    $st = $this->pdo->prepare('SELECT link_token FROM waiver_instances WHERE id=?');
    $st->execute([$instanceId]);
    $token = $st->fetchColumn();
    $this->assertIsString($token, "instance $instanceId must exist and carry a link_token");
    return $token;
  }

  private static function onePixelPngDataUri(): string {
    // Minimal valid 1x1 transparent PNG -- passes submitGuestForm's PNG-signature
    // check so execution reaches the failing INSERT.
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
  }
}
