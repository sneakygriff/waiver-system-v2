<?php
namespace Tests;

use App\Database;
use App\WaiverController;
use PHPUnit\Framework\TestCase;

/**
 * [orphan-response-fix 2026-08-30] submitGuestForm()'s completion path used to
 * COMMIT the waiver_responses INSERT (autocommit) and only THEN run the
 * 'submitted' audit insert — a second write that can throw. If that audit (or
 * anything after the committed INSERT) threw, the broad catch reset the instance
 * to 'pending' but left the already-committed waiver_responses row ORPHANED.
 * Because waiver_responses.waiver_instance_id is UNIQUE (migrations/001_init.sql),
 * every subsequent retry re-claimed the now-pending instance and then died
 * FOREVER on the duplicate-key INSERT — a permanent, per-instance "could not
 * save" outage, distinct from the migration-drift outage of the same day.
 *
 * These tests force a post-INSERT failure (the audit insert throws) and pin the
 * fix: the waiver_responses INSERT and its required 'submitted' audit now commit
 * inside ONE transaction, so a post-insert throw rolls the row back. The catch
 * then reverts the instance to a genuinely clean 'pending' the guest can retry.
 *
 * NON-VACUITY: under the pre-fix code both properties below fail — the orphan row
 * is present after the faulted attempt, and the retry returns the ref-banner
 * (duplicate-key 1062), never ok=true. Both were verified RED against
 * origin/master before the fix landed.
 *
 * REQUIRES the `waiver_test` MySQL schema (tests/README.md); skips if unreachable,
 * matching this harness's DB-gated convention.
 */
final class WaiverControllerSubmitOrphanResponseTest extends TestCase {
  private \PDO $pdo;
  private array $cfg;
  private Database $db;
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

  /**
   * Property (a)+(b): a post-INSERT failure leaves NO orphan waiver_responses
   * row and reverts the instance to 'pending'.
   */
  public function testPostInsertAuditFailureLeavesNoOrphanResponseRow(): void {
    [$instanceId, $post] = $this->seedSubmittableInstance('cust-orphan-a');
    $token = $this->linkTokenOf($instanceId);

    $failResult = $this->faultingController()->submitGuestForm($token, $post);

    // The faulted attempt returns the generic internal-failure banner.
    $this->assertArrayHasKey('error', $failResult, 'the faulted attempt must return an error banner');
    $this->assertSame(500, $failResult['http_status'] ?? null, 'a post-insert failure is an internal 500');

    // The claim is rolled back so the guest can retry ...
    $this->assertSame('pending', $this->statusOf($instanceId), 'the instance must be reverted to pending after the faulted attempt');

    // ... and — the crux of the bug — NO orphan waiver_responses row survives.
    // Pre-fix: the committed INSERT is still present here -> this fails.
    $this->assertSame(0, $this->responseCountFor($instanceId), 'a post-insert failure must leave NO orphan waiver_responses row');
  }

  /**
   * Property (c): after a post-INSERT failure, a genuine retry COMPLETES.
   * Pre-fix this was permanently impossible — the orphan row above collided on
   * the UNIQUE waiver_instance_id, so every retry returned the ref banner.
   */
  public function testGuestCanRetryAfterAPostInsertAuditFailure(): void {
    [$instanceId, $post] = $this->seedSubmittableInstance('cust-orphan-c');
    $token = $this->linkTokenOf($instanceId);

    // Attempt 1 fails after the INSERT.
    $failResult = $this->faultingController()->submitGuestForm($token, $post);
    $this->assertArrayHasKey('error', $failResult, 'attempt 1 must fail (the injected audit throw)');
    $this->assertArrayNotHasKey('ok', $failResult, 'attempt 1 must not report success');

    // Attempt 2 is a real retry (real audit, no injected fault) — it must now
    // complete instead of colliding on the UNIQUE waiver_instance_id.
    $retryResult = (new WaiverController($this->cfg, $this->db))->submitGuestForm($token, $post);

    $this->assertArrayHasKey('ok', $retryResult, 'the retry must succeed, not return an error banner');
    $this->assertTrue($retryResult['ok'], 'the retry must complete the waiver (ok=true)');
    $this->assertSame('completed', $this->statusOf($instanceId), 'the instance must be completed after a successful retry');
    $this->assertSame(1, $this->responseCountFor($instanceId), 'exactly one waiver_responses row must exist after the successful retry');
  }

  /**
   * A WaiverController whose audit() throws ONLY for the ('response','submitted')
   * event — reproducing "the INSERT committed, then a following statement threw"
   * — and delegates every other audit to the real implementation so the rest of
   * the completion flow is untouched.
   */
  private function faultingController(): WaiverController {
    return new class($this->cfg, $this->db) extends WaiverController {
      public function audit(string $type, int $id, string $event, array $meta = []): void {
        if ($type === 'response' && $event === 'submitted') {
          throw new \RuntimeException('injected post-insert audit failure');
        }
        parent::audit($type, $id, $event, $meta);
      }
    };
  }

  /** @return array{0:int,1:array<string,string>} [instanceId, valid POST body] */
  private function seedSubmittableInstance(string $customerId): array {
    $instanceId = TestDatabase::seedInstance($this->pdo, $this->versionId, ['customer_id' => $customerId]);
    $post = [
      'full_name' => 'Orphan Retry Guest',
      'signature_data' => self::onePixelPngDataUri(),
    ];
    return [$instanceId, $post];
  }

  private function linkTokenOf(int $instanceId): string {
    $st = $this->pdo->prepare('SELECT link_token FROM waiver_instances WHERE id=?');
    $st->execute([$instanceId]);
    $token = $st->fetchColumn();
    $this->assertIsString($token, "instance $instanceId must exist and carry a link_token");
    return $token;
  }

  private function statusOf(int $instanceId): string {
    $st = $this->pdo->prepare('SELECT status FROM waiver_instances WHERE id=?');
    $st->execute([$instanceId]);
    return (string)$st->fetchColumn();
  }

  private function responseCountFor(int $instanceId): int {
    $st = $this->pdo->prepare('SELECT COUNT(*) FROM waiver_responses WHERE waiver_instance_id=?');
    $st->execute([$instanceId]);
    return (int)$st->fetchColumn();
  }

  private static function onePixelPngDataUri(): string {
    // Minimal valid 1x1 transparent PNG — passes submitGuestForm's PNG-signature
    // check so execution reaches the waiver_responses INSERT + the audit insert.
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
  }
}
