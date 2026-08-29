<?php
namespace Tests;

use App\Database;
use App\WaiverController;
use PHPUnit\Framework\TestCase;

/**
 * [M5 gate fold F8 -- code-review P2-7] `submitGuestForm()`'s WRITE path,
 * exercised for real: WaiverControllerEvidenceTest.php only ever pins the
 * READ side (get_status()/statusRow()) against rows seeded directly via
 * TestDatabase::seedResponse() -- the controller's widened 15-column INSERT
 * in submitGuestForm() and uploadEvidence()'s widened 4-key return were
 * NEVER actually executed by any test (verified by hand in the gate review:
 * 15 columns, 2 UTC_TIMESTAMP(), 13 placeholders, 13 params, matching order
 * -- correct, but untested, so a future param reorder would ship silently
 * broken). This suite drives submitGuestForm() itself, through a REAL
 * (subprocess, `php -S`) stand-in for BookingV2's evidence relay -- both
 * shapes uploadEvidence() can return:
 *
 *   1. CONFIRMED upload (relay answers 200 with a usable blob_key/blob_url)
 *      -> all four evidence columns land on the waiver_responses row,
 *      exactly as the relay returned them; local pdf/signature files are
 *      cleaned up (nothing durable left to point at locally).
 *   2. FAILED relay (nothing listening -- curl fails every attempt) ->
 *      evidence_sha256 still lands (computed locally, before the relay POST
 *      ever happens); evidence_object_key/blob_key/blob_url all NULL; local
 *      files are RETAINED (pdf_path/signature_path point at them) and an
 *      `evidence_upload_failed` + `evidence_retained_locally` audit trail is
 *      written -- the GDPR-erasure-reachability contract submitGuestForm's
 *      own doc comment describes.
 *
 * REQUIRES: the `waiver_test` MySQL schema (see tests/README.md) AND a
 * `php` binary on PATH able to run `php -S` (the same interpreter running
 * phpunit itself -- always true in this repo's docker image). Skips itself
 * if either is unavailable, matching this harness's DB-gated convention.
 */
final class WaiverControllerSubmitEvidenceTest extends TestCase {
  private \PDO $pdo;
  private array $baseCfg;
  private Database $db;
  private int $versionId;

  /** @var array<int, resource> */
  private array $relayProcs = [];

  protected function setUp(): void {
    $this->baseCfg = TestDatabase::config();
    try {
      $this->db = TestDatabase::connect();
    } catch (\Throwable $e) {
      $this->markTestSkipped('waiver_test DB unreachable: '.$e->getMessage());
    }
    $this->pdo = $this->db->pdo();
    TestDatabase::reset($this->pdo);
    $this->versionId = TestDatabase::seedPublishedTemplateVersion($this->pdo);
  }

  protected function tearDown(): void {
    foreach ($this->relayProcs as $proc) {
      if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
      }
    }
    $this->relayProcs = [];
  }

  // -----------------------------------------------------------------------
  // 1. Confirmed relay upload -> all four evidence columns land verbatim.
  // -----------------------------------------------------------------------

  public function testSubmitGuestFormPersistsAllFourEvidenceColumnsWhenTheRelayConfirms(): void {
    $port = $this->startMockRelay(__DIR__.'/fixtures/mock-evidence-relay.php');

    $cfg = $this->baseCfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $ctl = new WaiverController($cfg, $this->db);

    $instanceId = TestDatabase::seedInstance($this->pdo, $this->versionId, ['customer_id' => 'cust-submit-confirmed']);
    $token = $this->linkTokenOf($instanceId);

    $result = $ctl->submitGuestForm($token, [
      'full_name' => 'Confirmed Guest',
      'signature_data' => self::onePixelPngDataUri(),
    ]);

    $this->assertArrayNotHasKey('error', $result, 'submitGuestForm must succeed: '.json_encode($result));
    $this->assertTrue($result['ok'] ?? false);

    $row = $this->responseRowFor($instanceId);
    $this->assertNotNull($row, 'submitGuestForm must INSERT a waiver_responses row');
    // evidence_sha256 is computed locally over the generated PDF's bytes --
    // deterministic content is not asserted, only the SHAPE (a real sha256).
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$row['evidence_sha256']);
    // The other three come straight from the mock relay's fixed response --
    // proves uploadEvidence()'s widened 4-key return actually reaches the
    // INSERT, not just that SOME value lands.
    $this->assertSame('waiver-evidence/mock-relay-fixture/999/pdf.pdf', $row['evidence_object_key']);
    $this->assertSame('waiver-evidence/mock-relay-fixture/999/pdf.pdf', $row['evidence_blob_key']);
    $this->assertSame('https://mock-blob-store.example.invalid/waiver-evidence/mock-relay-fixture/999/pdf.pdf', $row['evidence_blob_url']);

    // Confirmed durable storage -> nothing local left to point at (see
    // submitGuestForm's own [FK-T15 / FK-evidence-keep] comment).
    $this->assertNull($row['pdf_path']);
    $this->assertNull($row['signature_path']);
  }

  // -----------------------------------------------------------------------
  // 2. Failed relay -> sha-only shape, files retained, audit trail written.
  // -----------------------------------------------------------------------

  public function testSubmitGuestFormPersistsShaOnlyAndRetainsFilesWhenTheRelayFails(): void {
    // Deliberately nothing listening on this port -- every curl attempt in
    // postSignedEnvelopeWithResponse() fails closed (connection refused),
    // exhausting its 3-attempt budget, TWICE (submitGuestForm retries
    // uploadEvidence() once when the first call returns a null object key).
    $deadPort = $this->reserveDeadPort();

    $cfg = $this->baseCfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$deadPort;
    $ctl = new WaiverController($cfg, $this->db);

    $instanceId = TestDatabase::seedInstance($this->pdo, $this->versionId, ['customer_id' => 'cust-submit-failed']);
    $token = $this->linkTokenOf($instanceId);

    $result = $ctl->submitGuestForm($token, [
      'full_name' => 'Failed-Relay Guest',
      'signature_data' => self::onePixelPngDataUri(),
    ]);

    // A relay failure is non-fatal to the guest -- submission still
    // succeeds (submitGuestForm's own doc: "never throw, never block/revert
    // completion").
    $this->assertArrayNotHasKey('error', $result, 'a failed relay must not fail the submission: '.json_encode($result));
    $this->assertTrue($result['ok'] ?? false);

    $row = $this->responseRowFor($instanceId);
    $this->assertNotNull($row);
    // sha256 was computed locally BEFORE the relay POST -- present even
    // though the upload itself never confirmed.
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$row['evidence_sha256']);
    $this->assertNull($row['evidence_object_key']);
    $this->assertNull($row['evidence_blob_key']);
    $this->assertNull($row['evidence_blob_url']);

    // Retained-locally path: pdf_path/signature_path point at the files
    // GDPR erasure must still be able to reach (submitGuestForm's
    // [FK-erase / FK-evidence-keep] comment).
    $this->assertNotNull($row['pdf_path']);
    $this->assertIsString($row['pdf_path']);
    $this->assertFileExists($row['pdf_path'], 'the PDF must be RETAINED on disk when the relay never confirmed');
    $this->assertNotNull($row['signature_path']);
    $this->assertIsString($row['signature_path']);
    $this->assertFileExists($row['signature_path']);

    // Audit trail: both the per-attempt failure marker and the
    // retained-locally note (submitGuestForm writes the latter; uploadEvidence
    // writes the former on every failed attempt -- twice here, once per retry).
    $events = $this->auditEventsFor('instance', $instanceId);
    $this->assertContains('evidence_upload_failed', $events);
    $this->assertContains('evidence_retained_locally', $events);

    // Cleanup: this test is the one case that leaves real files on disk by
    // design (proving retention) -- remove them so repeated local runs don't
    // accumulate garbage in sys_get_temp_dir().
    @unlink($row['pdf_path']);
    @unlink($row['signature_path']);
  }

  // -----------------------------------------------------------------------
  // Helpers
  // -----------------------------------------------------------------------

  private static function onePixelPngDataUri(): string {
    // A well-known minimal valid 1x1 transparent PNG (67 bytes) -- passes
    // submitGuestForm's `strncmp($png, "\x89PNG\r\n\x1a\n", 8)` signature
    // check.
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
  }

  private function linkTokenOf(int $instanceId): string {
    $st = $this->pdo->prepare('SELECT link_token FROM waiver_instances WHERE id=?');
    $st->execute([$instanceId]);
    $token = $st->fetchColumn();
    $this->assertIsString($token, "instance $instanceId must exist and carry a link_token");
    return $token;
  }

  /** @return array<string,mixed>|null */
  private function responseRowFor(int $instanceId): ?array {
    $st = $this->pdo->prepare('SELECT * FROM waiver_responses WHERE waiver_instance_id=? LIMIT 1');
    $st->execute([$instanceId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
  }

  /** @return list<string> */
  private function auditEventsFor(string $entityType, int $entityId): array {
    $st = $this->pdo->prepare('SELECT event FROM audit_events WHERE entity_type=? AND entity_id=? ORDER BY id ASC');
    $st->execute([$entityType, $entityId]);
    return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
  }

  /**
   * Starts `php -S 127.0.0.1:<port>` serving `$routerFile` as a background
   * subprocess and waits (bounded) for it to accept connections before
   * returning the port. Registered for teardown via $this->relayProcs.
   */
  private function startMockRelay(string $routerFile): int {
    $this->assertFileExists($routerFile);
    $port = $this->freeLocalPort();

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(
      [PHP_BINARY, '-S', '127.0.0.1:'.$port, $routerFile],
      $descriptors,
      $pipes,
      __DIR__
    );
    $this->assertIsResource($proc, 'could not spawn the mock evidence-relay `php -S` subprocess');
    // Never block the test on the child's stdio buffers.
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $this->relayProcs[] = $proc;

    // Bounded poll: `php -S` binds its socket within a few ms locally, but
    // never assume -- retry a real TCP connect instead of a fixed sleep.
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
      $conn = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $errstr, 0.1);
      if ($conn !== false) {
        fclose($conn);
        return $port;
      }
      usleep(20000);
    }
    $this->fail('mock evidence-relay did not start listening on port '.$port.' within 5s');
  }

  /** A free-looking local port -- PID-offset so parallel/CI runs rarely collide. */
  private function freeLocalPort(): int {
    return 19200 + (getmypid() % 500);
  }

  /**
   * A port NOTHING is listening on, for the "relay fails closed" case --
   * distinct range from freeLocalPort() so the two never collide within one
   * test run.
   */
  private function reserveDeadPort(): int {
    return 19800 + (getmypid() % 500);
  }
}
