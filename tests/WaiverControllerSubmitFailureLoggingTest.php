<?php
namespace Tests;

use App\WaiverController;
use PHPUnit\Framework\TestCase;

/**
 * [post-incident 2026-08-30] submitGuestForm()'s broad `catch (\Throwable)` used
 * to swallow a save error into a generic HTTP-200 banner with NOTHING logged --
 * the exact shape of the 16h outage (a save against a schema missing a column).
 * These tests pin the hardening that makes such a failure VISIBLE and traceable:
 *
 *   Fix 2a  the catch logs the exception (class/message/code/SQLSTATE/file:line)
 *           + the waiver_instance id + a fresh correlation ref via error_log(),
 *           and NEVER logs participant PII.
 *   Fix 3   the guest banner carries the SAME ref (so staff can grep the server
 *           log from what the guest shows them), one generic message for every
 *           failure cause, with no SQL/internals/PII leaked.
 *
 * The failure is forced the incident's own way: rename a column the INSERT
 * writes, so the real submitGuestForm() INSERT throws "Unknown column" -> the
 * catch fires for real. The column is always restored (finally), so the schema
 * is left exactly as found for the rest of the suite.
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

  public function testSaveFailureIsLoggedWithARefAndBannerCarriesTheSameRefWithoutLeakingPii(): void {
    $piiName = 'Ada PII-LEAK-CANARY Lovelace';
    $instanceId = TestDatabase::seedInstance($this->pdo, $this->versionId, ['customer_id' => 'cust-save-fail']);
    $token = $this->linkTokenOf($instanceId);

    // Force the real waiver_responses INSERT to fail exactly like the incident:
    // a column it writes is missing from the schema. Rename preserves the exact
    // type so the restore in `finally` is lossless.
    $this->pdo->exec('ALTER TABLE waiver_responses CHANGE evidence_object_key evidence_object_key_tmp TEXT NULL');

    // Redirect error_log() to a temp file so we can read back what Fix 2a wrote
    // (in production this goes to /dev/stderr -> Railway; see docker/php/errors.ini).
    $logFile = tempnam(sys_get_temp_dir(), 'waiver_save_err_');
    $prevLog = ini_get('error_log');
    ini_set('error_log', $logFile);

    try {
      $ctl = new WaiverController($this->cfg, $this->db);
      $result = $ctl->submitGuestForm($token, [
        'full_name' => $piiName,
        'signature_data' => self::onePixelPngDataUri(),
      ]);
    } finally {
      ini_set('error_log', $prevLog === false ? '' : $prevLog);
      // Restore the schema no matter what, so later tests see it untouched.
      $this->pdo->exec('ALTER TABLE waiver_responses CHANGE evidence_object_key_tmp evidence_object_key TEXT NULL');
    }

    // --- The guest-facing payload (Fix 3) --------------------------------------
    $this->assertArrayHasKey('error', $result, 'a save failure must return an error banner');
    $this->assertArrayHasKey('ref', $result, 'the error payload must carry a correlation ref');
    $ref = $result['ref'];
    $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string)$ref, 'ref must be 8 hex chars (bin2hex(random_bytes(4)))');
    // The SAME ref must be visible to the guest, inline in the message.
    $this->assertStringContainsString((string)$ref, $result['error'], 'the banner must show the correlation ref');
    // Generic + safe: no SQL internals, no column names, no PII in the banner.
    $this->assertStringNotContainsStringIgnoringCase('sqlstate', $result['error']);
    $this->assertStringNotContainsStringIgnoringCase('unknown column', $result['error']);
    $this->assertStringNotContainsString('evidence_object_key', $result['error']);
    $this->assertStringNotContainsString('PII-LEAK-CANARY', $result['error']);

    // --- The server-side log line (Fix 2a) -------------------------------------
    $log = (string)file_get_contents($logFile);
    @unlink($logFile);
    $this->assertStringContainsString('[WAIVER-SAVE-ERROR]', $log, 'the catch must log a WAIVER-SAVE-ERROR line');
    $this->assertStringContainsString('ref='.$ref, $log, 'the log must carry the same ref the guest was shown');
    $this->assertStringContainsString('waiver_instance_id='.$instanceId, $log, 'the log must identify the waiver instance');
    // It really was the schema break that fired the catch (proves the log is the
    // save-failure path, not some incidental notice).
    $this->assertStringContainsString('PDOException', $log);
    // CRITICAL: the log must NOT contain participant PII.
    $this->assertStringNotContainsString('PII-LEAK-CANARY', $log, 'the log must NOT contain the participant name');
    $this->assertStringNotContainsString($piiName, $log);

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
