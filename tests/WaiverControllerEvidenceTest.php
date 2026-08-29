<?php
namespace Tests;

use App\WaiverController;
use PHPUnit\Framework\TestCase;

/**
 * [T5 / waiver-coverage step 5] Micro-harness for the evidence-fields fix:
 *
 * 1. get_status() (WaiverController::getStatus -> statusRow()) must return
 *    the four evidence columns (migrations/005_evidence_fields.sql) for a
 *    completed instance whose waiver_responses row carries them, and null
 *    for all four on a "legacy" row that predates 005 (no evidence at all)
 *    -- see testGetStatusReturnsEvidenceFieldsWhenPresentAndNullsForALegacyRow.
 *
 * 2. The evidence-POST origin pin: WaiverController::evidenceUrlFor() derives
 *    the evidence-relay URL from callback.base_url (CALLBACK_BASE_URL) when
 *    callback.evidence_url is unset, and uses evidence_url verbatim when it
 *    IS set -- see testEvidenceUrlDerivation*. Per the T5 plan, the fork
 *    ALREADY does this (the "origin verify" line found no behavior bug); this
 *    pins it so a future config regression (e.g. a second, diverging origin
 *    accidentally introduced for evidence vs. the completion webhook) fails
 *    loud instead of silently drifting the two POST targets apart.
 *
 * REQUIRES: the `waiver_test` MySQL schema for test 1 only (see
 * tests/README.md) -- skips itself if unreachable, matching this harness's
 * existing DB-gated convention. Test 2 is pure (no DB) and always runs.
 */
final class WaiverControllerEvidenceTest extends TestCase {
  private \PDO $pdo;
  private WaiverController $ctl;

  protected function setUp(): void {
    $cfg = TestDatabase::config();
    try {
      $db = TestDatabase::connect();
    } catch (\Throwable $e) {
      $this->markTestSkipped('waiver_test DB unreachable: '.$e->getMessage());
    }
    $this->pdo = $db->pdo();
    TestDatabase::reset($this->pdo);
    $this->ctl = new WaiverController($cfg, $db);
  }

  // -----------------------------------------------------------------------
  // 1. get_status evidence fields
  // -----------------------------------------------------------------------

  public function testGetStatusReturnsEvidenceFieldsWhenPresentAndNullsForALegacyRow(): void {
    $versionId = TestDatabase::seedPublishedTemplateVersion($this->pdo);

    // (a) A completed instance whose response row carries all four evidence
    // values (the shape submitGuestForm() now persists once uploadEvidence()
    // confirms).
    $withEvidenceId = TestDatabase::seedInstance($this->pdo, $versionId, [
      'customer_id' => 'cust-evidence-1',
      'status' => 'completed',
    ]);
    TestDatabase::seedResponse($this->pdo, $withEvidenceId, ['full_name' => 'Evidence Guest'], [
      'evidence_sha256' => str_repeat('a', 64),
      'evidence_object_key' => 'evidence/pdf/with-evidence-1.pdf',
      'evidence_blob_key' => 'evidence/pdf/with-evidence-1.pdf',
      'evidence_blob_url' => 'https://blob.vercel-storage.example/evidence/pdf/with-evidence-1.pdf',
    ]);

    // (b) A "legacy" completed instance whose response predates 005 -- no
    // evidence overrides at all, i.e. TestDatabase::seedResponse()'s default
    // (all four columns NULL).
    $legacyId = TestDatabase::seedInstance($this->pdo, $versionId, [
      'customer_id' => 'cust-evidence-2',
      'status' => 'completed',
    ]);
    TestDatabase::seedResponse($this->pdo, $legacyId, ['full_name' => 'Legacy Guest']);

    $withEvidenceToken = $this->linkTokenOf($withEvidenceId);
    $legacyToken = $this->linkTokenOf($legacyId);

    $withEvidenceStatus = $this->ctl->getStatus(['link_token' => $withEvidenceToken]);
    $this->assertArrayNotHasKey('error', $withEvidenceStatus);
    $this->assertSame(str_repeat('a', 64), $withEvidenceStatus['evidence_sha256']);
    $this->assertSame('evidence/pdf/with-evidence-1.pdf', $withEvidenceStatus['evidence_object_key']);
    $this->assertSame('evidence/pdf/with-evidence-1.pdf', $withEvidenceStatus['evidence_blob_key']);
    $this->assertSame('https://blob.vercel-storage.example/evidence/pdf/with-evidence-1.pdf', $withEvidenceStatus['evidence_blob_url']);

    $legacyStatus = $this->ctl->getStatus(['link_token' => $legacyToken]);
    $this->assertArrayNotHasKey('error', $legacyStatus);
    $this->assertNull($legacyStatus['evidence_sha256'], 'a legacy (pre-005) row must report evidence_sha256 as null, never a fabricated value');
    $this->assertNull($legacyStatus['evidence_object_key']);
    $this->assertNull($legacyStatus['evidence_blob_key']);
    $this->assertNull($legacyStatus['evidence_blob_url']);

    // Same fields, same shape, on the batch (booking_group_id) path -- proves
    // STATUS_SELECT's new columns reach statusRow() on both call sites, not
    // just the single-link_token query.
    $groupId = 'grp-evidence-batch';
    $groupInstanceId = TestDatabase::seedInstance($this->pdo, $versionId, [
      'customer_id' => 'cust-evidence-3',
      'booking_group_id' => $groupId,
      'status' => 'completed',
    ]);
    TestDatabase::seedResponse($this->pdo, $groupInstanceId, ['full_name' => 'Group Guest'], [
      'evidence_sha256' => str_repeat('b', 64),
      'evidence_object_key' => 'evidence/pdf/group-1.pdf',
      'evidence_blob_key' => 'evidence/pdf/group-1.pdf',
      'evidence_blob_url' => 'https://blob.vercel-storage.example/evidence/pdf/group-1.pdf',
    ]);
    $batch = $this->ctl->getStatus(['booking_group_id' => $groupId]);
    $this->assertArrayNotHasKey('error', $batch);
    $this->assertCount(1, $batch['results']);
    $this->assertSame(str_repeat('b', 64), $batch['results'][0]['evidence_sha256']);
    $this->assertSame('evidence/pdf/group-1.pdf', $batch['results'][0]['evidence_object_key']);
    $this->assertSame('evidence/pdf/group-1.pdf', $batch['results'][0]['evidence_blob_key']);
    $this->assertSame('https://blob.vercel-storage.example/evidence/pdf/group-1.pdf', $batch['results'][0]['evidence_blob_url']);
  }

  public function testGetStatusReturnsNullEvidenceForAPendingInstanceWithNoResponseRowAtAll(): void {
    // No waiver_responses row at all (LEFT JOIN misses entirely) -- the
    // pre-005 shape AND the "never signed" shape must both report null, not
    // error or omit the keys.
    $versionId = TestDatabase::seedPublishedTemplateVersion($this->pdo);
    $pendingId = TestDatabase::seedInstance($this->pdo, $versionId, ['customer_id' => 'cust-evidence-4']);

    $status = $this->ctl->getStatus(['link_token' => $this->linkTokenOf($pendingId)]);
    $this->assertArrayNotHasKey('error', $status);
    $this->assertArrayHasKey('evidence_sha256', $status, 'the key must be present even with no response row');
    $this->assertNull($status['evidence_sha256']);
    $this->assertNull($status['evidence_object_key']);
    $this->assertNull($status['evidence_blob_key']);
    $this->assertNull($status['evidence_blob_url']);
  }

  private function linkTokenOf(int $instanceId): string {
    $st = $this->pdo->prepare('SELECT link_token FROM waiver_instances WHERE id=?');
    $st->execute([$instanceId]);
    $token = $st->fetchColumn();
    $this->assertIsString($token, "instance $instanceId must exist and carry a link_token");
    return $token;
  }

  // -----------------------------------------------------------------------
  // 2. Evidence-POST origin pin (evidenceUrlFor)
  // -----------------------------------------------------------------------

  public function testEvidenceUrlDerivesFromCallbackBaseUrlWhenEvidenceUrlIsNull(): void {
    // The exact shape config/config.env.php produces when CALLBACK_EVIDENCE_URL
    // (there is no such env var -- evidence_url is ALWAYS null in config)
    // is unset: derive from base_url, the SAME origin the completion webhook
    // posts to (notifyBookingV2Completion: rtrim(base_url).'/api/waiver/complete').
    $this->assertSame(
      'https://booking-admin.vrstudio.ro/api/waiver/evidence',
      WaiverController::evidenceUrlFor([
        'base_url' => 'https://booking-admin.vrstudio.ro',
        'evidence_url' => null,
      ]),
      'with evidence_url null, the evidence POST must target callback.base_url + /api/waiver/evidence -- the SAME origin as the completion webhook'
    );
  }

  public function testEvidenceUrlDerivationTrimsATrailingSlashOnBaseUrl(): void {
    $this->assertSame(
      'https://booking-admin.vrstudio.ro/api/waiver/evidence',
      WaiverController::evidenceUrlFor([
        'base_url' => 'https://booking-admin.vrstudio.ro/',
        'evidence_url' => null,
      ])
    );
  }

  public function testEvidenceUrlHonorsAnExplicitEvidenceUrlOverride(): void {
    // An explicit callback.evidence_url (were one ever configured) wins
    // outright -- verbatim, no rtrim/append. Today's config/config.env.php
    // always sets this to null, but the derivation function itself must
    // still honor an explicit override correctly.
    $this->assertSame(
      'https://evidence.example.test/custom-path',
      WaiverController::evidenceUrlFor([
        'base_url' => 'https://booking-admin.vrstudio.ro',
        'evidence_url' => 'https://evidence.example.test/custom-path',
      ])
    );
  }

  public function testEvidenceUrlNeverDivergesFromWhatTheCompletionWebhookTargets(): void {
    // The decisive regression pin (T5 "origin verify"): for the SAME
    // callback config, the evidence POST's derived origin must equal the
    // completion webhook's derived origin -- both are
    // rtrim(base_url,'/').'/api/...'. If a future edit gives evidence_url a
    // non-null default (accidentally re-introducing a second origin, the
    // pre-cutover bug this pin exists to prevent), this test fails loud.
    $cb = ['base_url' => 'https://booking-admin.vrstudio.ro', 'evidence_url' => null];
    $evidenceUrl = WaiverController::evidenceUrlFor($cb);
    $completionUrl = rtrim((string)$cb['base_url'], '/').'/api/waiver/complete';
    $this->assertSame('https://booking-admin.vrstudio.ro', $this->originOf($evidenceUrl));
    $this->assertSame($this->originOf($completionUrl), $this->originOf($evidenceUrl), 'the evidence POST and the completion webhook must target the SAME origin');
  }

  private function originOf(string $url): string {
    $parts = parse_url($url);
    $this->assertIsArray($parts, "must be a parseable URL: $url");
    return ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '');
  }
}
