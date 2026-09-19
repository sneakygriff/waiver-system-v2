<?php
namespace Tests;

use App\WaiverController;
use PHPUnit\Framework\TestCase;

/**
 * [GVS-89 / 89-M4.2] Reception-QR PUBLIC instances -- the fork half of the
 * frozen wire contract (decomposition §7.0):
 *
 *   create_public_instance {template_id, link_token, locale, expires_at}
 *     -> {ok, waiver_id, link, link_token, reused}; is_public=1, every binding
 *        id NULL, no guest email; IDEMPOTENT per link_token; never reuses a
 *        reservation-bound token (and create_waiver never reuses a public one).
 *   public_status {link_token}
 *     -> get_status's single-token row + is_public:true + expires_at; a
 *        reservation-bound token answers exactly like an unknown one.
 *   renderGuestForm (w.php GET)
 *     -> an EXPIRED public instance never renders a form (410 + localized
 *        "scan the QR again" copy); non-public instances are never
 *        expiry-gated.
 *
 * REQUIRES the `waiver_test` schema built from the CURRENT 001_init.sql (006's
 * columns baked in) -- see tests/README.md; skips itself if unreachable, like
 * every other DB-gated case in this harness (CI runs --fail-on-skipped). The
 * parseIsoInstant cases are pure and always run.
 */
final class WaiverControllerPublicTest extends TestCase {
  private ?\PDO $pdo = null;
  private WaiverController $ctl;
  private array $cfg;
  private string $tzBefore;

  /**
   * [89-M4.3/M4.4] `php -S` subprocesses started by startCaptureServer(),
   * standing in for BookingV2 (evidence relay + completion webhooks) --
   * same technique as WaiverControllerSubmitEvidenceTest's startMockRelay().
   * Registered here for teardown regardless of which test started them.
   * @var array<int, resource>
   */
  private array $relayProcs = [];

  /** @var list<string> retained-evidence fixture files to remove in tearDown */
  private array $tempFiles = [];

  protected function setUp(): void {
    $this->tzBefore = date_default_timezone_get();
    $this->cfg = TestDatabase::config();
    if (str_starts_with($this->name(), 'testParseIsoInstant')) {
      return; // pure cases: no DB needed
    }
    try {
      $db = TestDatabase::connect();
    } catch (\Throwable $e) {
      $this->markTestSkipped('waiver_test DB unreachable: '.$e->getMessage());
    }
    $this->pdo = $db->pdo();
    TestDatabase::reset($this->pdo);
    $this->ctl = new WaiverController($this->cfg, $db);
  }

  protected function tearDown(): void {
    // WaiverController's constructor sets the process-wide default timezone;
    // the Europe/Bucharest case below must not leak into later tests.
    date_default_timezone_set($this->tzBefore);
    foreach ($this->relayProcs as $proc) {
      if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
      }
    }
    $this->relayProcs = [];
    foreach ($this->tempFiles as $file) {
      if (is_file($file)) @unlink($file);
    }
    $this->tempFiles = [];
  }

  // =========================================================================
  // create_public_instance
  // =========================================================================

  public function testCreatePublicInstanceInsertsAPublicRowWithNullBindingsAndNoEmail(): void {
    [$templateId, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $expiresTs = time() + 7200;

    $res = $this->ctl->createPublicInstance([
      'action' => 'create_public_instance',
      'template_id' => (string)$templateId, // BookingV2 sends WaiverConfig.waiverTemplateId as a string
      'link_token' => $token,
      'locale' => 'en',
      'expires_at' => gmdate('Y-m-d\TH:i:s', $expiresTs).'.987Z', // Date#toISOString() shape
    ]);

    $this->assertSame(['ok', 'waiver_id', 'link', 'link_token', 'reused'], array_keys($res), 'exact contract shape');
    $this->assertTrue($res['ok']);
    $this->assertFalse($res['reused']);
    $this->assertIsInt($res['waiver_id']);
    $this->assertSame($token, $res['link_token']);
    $this->assertSame('http://localhost:8080/w.php?token='.$token, $res['link']);

    $row = $this->instanceRow($token);
    $this->assertSame($res['waiver_id'], (int)$row['id']);
    $this->assertSame('1', (string)$row['is_public']);
    $this->assertSame('pending', $row['status']);
    $this->assertSame($versionId, (int)$row['template_version_id']);
    foreach (['reservation_id', 'participant_id', 'customer_id', 'booking_group_id', 'group_token', 'guest_name', 'guest_email'] as $col) {
      $this->assertNull($row[$col], "$col must be NULL on a public instance");
    }
    $this->assertSame('en', $row['locale']);
    // UTC, fractional seconds truncated.
    $this->assertSame(gmdate('Y-m-d H:i:s', $expiresTs), $row['expires_at']);

    // One 'created' audit row, flagged public, with neither the token (a
    // bearer credential for the signing page) nor any PII in it.
    $audit = $this->auditRows((int)$row['id'], 'created');
    $this->assertCount(1, $audit);
    $meta = json_decode($audit[0]['meta_json'], true);
    $this->assertTrue($meta['public']);
    $this->assertSame('en', $meta['locale']);
    $this->assertStringNotContainsString($token, $audit[0]['meta_json']);
  }

  public function testCreatePublicInstanceIsIdempotentPerLinkToken(): void {
    [$templateId] = $this->seedTemplate();
    $token = self::uuid();
    $payload = $this->validPayload($templateId, $token, ['locale' => 'ro', 'expires_at' => self::isoIn(7200)]);

    $first = $this->ctl->createPublicInstance($payload);
    $this->assertFalse($first['reused']);
    $storedBefore = $this->instanceRow($token);

    // The exact retry BookingV2's bounded transient retry sends.
    $replay = $this->ctl->createPublicInstance($payload);
    $this->assertSame(['ok'=>true, 'waiver_id'=>$first['waiver_id'], 'link'=>$first['link'], 'link_token'=>$token, 'reused'=>true], $replay);

    // Same token, different locale/expiry: still the SAME instance, and the
    // stored values win -- a replay never mutates the row.
    $divergent = $this->ctl->createPublicInstance($this->validPayload($templateId, $token, ['locale' => 'en', 'expires_at' => self::isoIn(3 * 86400)]));
    $this->assertTrue($divergent['reused']);
    $this->assertSame($first['waiver_id'], $divergent['waiver_id']);

    $this->assertSame(1, $this->countByToken($token), 'idempotent: exactly one row per link_token');
    $storedAfter = $this->instanceRow($token);
    $this->assertSame($storedBefore['locale'], $storedAfter['locale']);
    $this->assertSame($storedBefore['expires_at'], $storedAfter['expires_at']);
    $this->assertSame($storedBefore['updated_at'], $storedAfter['updated_at']);
    $this->assertCount(1, $this->auditRows((int)$first['waiver_id'], 'created'), 'a reuse is not a second creation');
  }

  public function testCreatePublicInstanceRetryStillReturnsReusedTrueAfterTheTemplateIsRepublishedIntoAnAmbiguousDobShape(): void {
    // [gate 89-M4 r3 P2-5 fix / Codex r2] link_token idempotency exists so a
    // retry of an already-succeeded call (a lost response, a network
    // timeout) is harmless. Before this fix, resolvePublicDobField() ran
    // BEFORE the reuse lookup, so such a retry could hit a LATER, broken
    // template version and answer template_missing_dob/template_ambiguous_dob
    // for a token that already names a perfectly good instance -- turning a
    // harmless retry into a hard failure over an unrelated later template
    // edit. The reuse check must win.
    [$templateId] = $this->seedTemplate(); // one 'dob' date field: resolvable
    $token = self::uuid();
    $first = $this->ctl->createPublicInstance($this->validPayload($templateId, $token));
    $this->assertTrue($first['ok'] ?? false, json_encode($first));
    $this->assertFalse($first['reused']);

    // Republish: two UNMARKED date fields -- ambiguous, per
    // resolvePublicDobField()'s own rule.
    $this->pdo->exec('UPDATE waiver_template_versions SET is_published=0 WHERE template_id='.(int)$templateId);
    $ambiguousFields = json_encode([
      ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
      ['key' => 'arrival_date', 'label' => 'Arrival', 'type' => 'date', 'required' => true],
      ['key' => 'checkout_date', 'label' => 'Checkout', 'type' => 'date', 'required' => true],
    ]);
    $this->pdo->prepare('INSERT INTO waiver_template_versions (template_id, version, title, fields_json, requires_signature, created_by, created_at, is_published, published_at) VALUES (?,2,?,?,1,1,UTC_TIMESTAMP(),1,UTC_TIMESTAMP())')
      ->execute([$templateId, 'Republished Ambiguous', $ambiguousFields]);

    // Sanity, non-vacuity: a BRAND NEW create against this template now
    // really does fail closed -- proves the republish alone would break
    // create_public_instance if not for the reuse check winning below.
    $freshAttempt = $this->ctl->createPublicInstance($this->validPayload($templateId, self::uuid()));
    $this->assertSame('template_ambiguous_dob', $freshAttempt['error'] ?? null, 'sanity: the republished version is genuinely unresolvable for a NEW instance');

    // The RETRY with the ORIGINAL token must still say reused:true, not
    // template_ambiguous_dob.
    $retry = $this->ctl->createPublicInstance($this->validPayload($templateId, $token));
    $this->assertSame(['ok' => true, 'waiver_id' => $first['waiver_id'], 'link' => $first['link'], 'link_token' => $token, 'reused' => true], $retry);
  }

  public function testCreatePublicInstanceNeverReusesAReservationBoundToken(): void {
    [$templateId, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'customer_id' => 'cust-bound', 'participant_id' => 'part-bound']);

    $res = $this->ctl->createPublicInstance($this->validPayload($templateId, $token));

    $this->assertSame(['error' => 'link_token_conflict'], $res, 'no ok/link/waiver_id for another kind of token');
    $this->assertSame(1, $this->countByToken($token));
    $row = $this->instanceRow($token);
    $this->assertSame('0', (string)$row['is_public'], 'the reservation-bound row is not converted');
    $this->assertSame('cust-bound', $row['customer_id']);
    $this->assertNull($row['expires_at']);
  }

  public function testCreateWaiverNeverReusesAPublicTokenButStillReusesItsOwnKind(): void {
    [$templateId] = $this->seedTemplate();
    $publicToken = self::uuid();
    $this->assertTrue($this->ctl->createPublicInstance($this->validPayload($templateId, $publicToken))['ok']);

    $res = $this->ctl->createInstance([
      'template_id' => (string)$templateId, 'link_token' => $publicToken,
      'participant_id' => 'p-1', 'customer_id' => 'c-1', 'booking_group_id' => 'g-1',
    ]);
    $this->assertSame(['error' => 'link_token_conflict'], $res);
    $row = $this->instanceRow($publicToken);
    $this->assertSame('1', (string)$row['is_public']);
    $this->assertNull($row['participant_id']);

    // The pre-existing reservation-bound idempotency is untouched.
    $boundToken = self::uuid();
    $bound = ['template_id' => (string)$templateId, 'link_token' => $boundToken, 'participant_id' => 'p-2', 'customer_id' => 'c-2', 'booking_group_id' => 'g-2'];
    $created = $this->ctl->createInstance($bound);
    $this->assertFalse($created['reused']);
    $again = $this->ctl->createInstance($bound);
    $this->assertTrue($again['reused']);
    $this->assertSame($created['waiver_id'], $again['waiver_id']);
    $this->assertSame('0', (string)$this->instanceRow($boundToken)['is_public'], 'create_waiver still mints NON-public rows');
  }

  /**
   * @dataProvider malformedCreatePayloadProvider
   */
  public function testCreatePublicInstanceRejectsMalformedInputAndWritesNothing(string $field, $value): void {
    [$templateId] = $this->seedTemplate();
    $payload = $this->validPayload($templateId, self::uuid());
    if ($value === '__UNSET__') {
      unset($payload[$field]);
    } else {
      $payload[$field] = $value;
    }

    $res = $this->ctl->createPublicInstance($payload);

    $this->assertSame('invalid_request', $res['error'] ?? null, 'field '.$field.' => '.var_export($value, true));
    $this->assertIsString($res['detail']);
    $this->assertArrayNotHasKey('ok', $res);
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_instances')->fetchColumn());
  }

  public static function malformedCreatePayloadProvider(): array {
    return [
      'template_id missing'        => ['template_id', '__UNSET__'],
      'template_id empty'          => ['template_id', ''],
      'template_id zero'           => ['template_id', '0'],
      'template_id non-numeric'    => ['template_id', 'abc'],
      'template_id bool'           => ['template_id', true],
      'template_id array'          => ['template_id', ['1']],
      'template_id trailing nl'    => ['template_id', "1\n"],
      'link_token missing'         => ['link_token', '__UNSET__'],
      'link_token too short'       => ['link_token', str_repeat('a', 15)],
      'link_token too long'        => ['link_token', str_repeat('a', 129)],
      'link_token bad charset'     => ['link_token', str_repeat('a', 20).'.x'],
      'link_token trailing nl'     => ['link_token', str_repeat('a', 32)."\n"],
      'link_token non-string'      => ['link_token', 12345678901234567],
      'locale missing'             => ['locale', '__UNSET__'],
      'locale unsupported'         => ['locale', 'fr'],
      'locale wrong case'          => ['locale', 'RO'],
      'locale array'               => ['locale', ['ro']],
      'expires_at missing'         => ['expires_at', '__UNSET__'],
      'expires_at empty'           => ['expires_at', ''],
      'expires_at garbage'         => ['expires_at', 'tomorrow'],
      'expires_at no zone'         => ['expires_at', gmdate('Y-m-d\TH:i:s', time() + 3600)],
      'expires_at epoch int'       => ['expires_at', time() + 3600],
      'expires_at in the past'     => ['expires_at', self::isoIn(-60)],
      'expires_at beyond 7 days'   => ['expires_at', self::isoIn(7 * 86400 + 600)],
    ];
  }

  public function testCreatePublicInstanceAcceptsAnExpiryJustInsideTheSevenDayCeiling(): void {
    // Non-vacuity partner of the 'beyond 7 days' case above: the ceiling
    // rejects only what is actually past it.
    [$templateId] = $this->seedTemplate();
    $res = $this->ctl->createPublicInstance($this->validPayload($templateId, self::uuid(), ['expires_at' => self::isoIn(7 * 86400 - 600)]));
    $this->assertTrue($res['ok'] ?? false, json_encode($res));
  }

  public function testCreatePublicInstanceRequiresAPublishedVersion(): void {
    // A template with ONLY a draft version -> same [Gap1] gate as create_waiver.
    $this->pdo->prepare('INSERT INTO waiver_templates (name, is_active, created_by, created_at, updated_at) VALUES (?,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['Draft only']);
    $draftTemplateId = (int)$this->pdo->lastInsertId();
    $this->pdo->prepare('INSERT INTO waiver_template_versions (template_id, version, title, fields_json, requires_signature, created_by, created_at, is_published) VALUES (?,1,?,?,1,1,UTC_TIMESTAMP(),0)')
      ->execute([$draftTemplateId, 'Draft', json_encode([['key'=>'full_name','type'=>'text']])]);

    $this->assertSame(['error' => 'no_published_version'], $this->ctl->createPublicInstance($this->validPayload($draftTemplateId, self::uuid())));
    $this->assertSame(['error' => 'no_published_version'], $this->ctl->createPublicInstance($this->validPayload(999999, self::uuid())));
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_instances')->fetchColumn());
  }

  public function testExpiryIsStoredAndReportedInUtcWhateverTheAppTimezone(): void {
    // Production runs with app.timezone = Europe/Bucharest (config.env.php);
    // the test config is UTC, which would hide a local-time conversion bug.
    $cfg = $this->cfg;
    $cfg['app']['timezone'] = 'Europe/Bucharest';
    $ctl = new WaiverController($cfg, TestDatabase::connect());
    [$templateId] = $this->seedTemplate();
    $token = self::uuid();
    $expiresTs = time() + 5400;
    // Same instant expressed at +03:00 (Bucharest summer time).
    $local = (new \DateTimeImmutable('@'.$expiresTs))->setTimezone(new \DateTimeZone('+03:00'))->format('Y-m-d\TH:i:sP');

    $this->assertTrue($ctl->createPublicInstance($this->validPayload($templateId, $token, ['expires_at' => $local]))['ok']);
    $this->assertSame(gmdate('Y-m-d H:i:s', $expiresTs), $this->instanceRow($token)['expires_at'], 'stored as UTC');
    $this->assertSame(gmdate('c', $expiresTs), $ctl->publicStatus(['link_token' => $token])['expires_at'], 'reported as UTC ISO-8601');
  }

  // =========================================================================
  // parseIsoInstant (pure)
  // =========================================================================

  public function testParseIsoInstantAcceptsZuluOffsetAndFractionalForms(): void {
    $ts = gmmktime(14, 0, 0, 9, 19, 2026);
    $this->assertSame($ts, WaiverController::parseIsoInstant('2026-09-19T14:00:00Z'));
    $this->assertSame($ts, WaiverController::parseIsoInstant('2026-09-19T14:00:00.000Z'));
    $this->assertSame($ts, WaiverController::parseIsoInstant('2026-09-19T14:00:00.999Z'), 'fractional seconds are truncated');
    $this->assertSame($ts, WaiverController::parseIsoInstant('2026-09-19T17:00:00+03:00'));
    $this->assertSame($ts, WaiverController::parseIsoInstant('2026-09-19T09:30:00-04:30'));
  }

  /**
   * @dataProvider unparseableInstantProvider
   */
  public function testParseIsoInstantRejects($raw): void {
    $this->assertNull(WaiverController::parseIsoInstant($raw));
  }

  public static function unparseableInstantProvider(): array {
    return [
      'no zone designator'           => ['2026-09-19T14:00:00'],
      'space instead of T'           => ['2026-09-19 14:00:00Z'],
      'lowercase z'                  => ['2026-09-19T14:00:00z'],
      'trailing newline'             => ["2026-09-19T14:00:00Z\n"],
      'day 31 of a 30-day month'     => ['2026-09-31T10:00:00Z'], // PHP would roll this to 2026-10-01
      'hour 24'                      => ['2026-09-20T24:30:00Z'], // PHP would roll this to 2026-09-21 00:30
      'minute 60'                    => ['2026-09-20T10:60:00Z'],
      'second 60'                    => ['2026-09-20T10:00:60Z'],
      'offset beyond +14:00'         => ['2026-09-19T14:00:00+15:00'],
      'offset minutes 60'            => ['2026-09-19T14:00:00+03:60'],
      'date only'                    => ['2026-09-19'],
      'garbage'                      => ['next tuesday'],
      'empty'                        => [''],
      'null'                         => [null],
      'int epoch'                    => [1789135200],
      'array'                        => [['2026-09-19T14:00:00Z']],
    ];
  }

  // =========================================================================
  // public_status
  // =========================================================================

  public function testPublicStatusIsGetStatusRowShapePlusPublicFields(): void {
    [$templateId] = $this->seedTemplate();
    $token = self::uuid();
    $expiresTs = time() + 3600;
    $created = $this->ctl->createPublicInstance($this->validPayload($templateId, $token, ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresTs)]));

    $public = $this->ctl->publicStatus(['action' => 'public_status', 'link_token' => $token]);
    // get_status keeps answering public tokens unchanged (§4 DEFAULT #13) --
    // and its single-token row IS the shape public_status extends.
    $plain = $this->ctl->getStatus(['link_token' => $token]);
    $this->assertArrayNotHasKey('error', $plain);

    $this->assertSame(array_merge(array_keys($plain), ['is_public', 'expires_at']), array_keys($public), 'same row shape as get_status, plus is_public + expires_at');
    foreach ($plain as $key => $value) {
      $this->assertSame($value, $public[$key], "public_status.$key must match get_status.$key");
    }
    $this->assertSame($created['waiver_id'], $public['waiver_instance_id']);
    $this->assertSame($token, $public['link_token']);
    $this->assertSame('pending', $public['status']);
    $this->assertNull($public['completed_at']);
    $this->assertNull($public['participant_id']);
    $this->assertNull($public['customer_id']);
    $this->assertNull($public['booking_group_id']);
    $this->assertTrue($public['is_public']);
    $this->assertSame(gmdate('c', $expiresTs), $public['expires_at']);
  }

  public function testPublicStatusCarriesTheCompletionFieldsOfACompletedPublicInstance(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);
    $this->pdo->prepare('UPDATE waiver_instances SET completed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
    TestDatabase::seedResponse($this->pdo, $id, ['full_name' => 'Walk In', '_computed_age' => 31, '_minor' => false, 'waiver_consent_granted' => true], [
      'evidence_sha256' => str_repeat('c', 64),
      'evidence_object_key' => 'waiver-evidence/public/sig-1/'.$id.'/pdf.pdf',
      'evidence_blob_key' => 'waiver-evidence/public/sig-1/'.$id.'/pdf.pdf',
      'evidence_blob_url' => 'https://blob.example/waiver-evidence/public/sig-1/'.$id.'/pdf.pdf',
    ]);

    $public = $this->ctl->publicStatus(['link_token' => $token]);

    $this->assertSame('completed', $public['status']);
    $this->assertIsString($public['completed_at']);
    $this->assertSame(31, $public['computed_age']);
    $this->assertFalse($public['minor']);
    $this->assertTrue($public['waiver_consent_granted']);
    $this->assertSame(str_repeat('c', 64), $public['evidence_sha256']);
    $this->assertSame('waiver-evidence/public/sig-1/'.$id.'/pdf.pdf', $public['evidence_blob_key']);
    $this->assertSame(hash('sha256', 'x'), $public['answers_hash']);
    $this->assertSame(1, $public['form_version']);
    $this->assertTrue($public['is_public']);
  }

  public function testPublicStatusKeepsAnsweringAfterExpiry(): void {
    // BookingV2 reconciles a public signup until expires_at + 7 days, so the
    // RENDER expiry must not turn into a status blackout.
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], -3600);

    $public = $this->ctl->publicStatus(['link_token' => $token]);

    $this->assertArrayNotHasKey('error', $public);
    $this->assertTrue($public['is_public']);
    $this->assertLessThan(time(), strtotime($public['expires_at']));
  }

  public function testPublicStatusAnswersAReservationBoundTokenExactlyLikeAnUnknownOne(): void {
    [, $versionId] = $this->seedTemplate();
    $boundToken = self::uuid();
    TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $boundToken, 'customer_id' => 'cust-9', 'participant_id' => 'part-9']);

    $forBound = $this->ctl->publicStatus(['link_token' => $boundToken]);
    $forUnknown = $this->ctl->publicStatus(['link_token' => self::uuid()]);

    $this->assertSame(['error' => 'token_unknown'], $forBound);
    $this->assertSame($forUnknown, $forBound, 'no oracle: a reservation-bound token is indistinguishable from a nonexistent one');
    // ...while get_status still serves that reservation-bound token as before.
    $this->assertSame('part-9', $this->ctl->getStatus(['link_token' => $boundToken])['participant_id']);
  }

  public function testPublicStatusNullsBindingIdsEvenOnAHandEditedPublicRow(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, [
      'link_token' => $token, 'participant_id' => 'p-edited', 'customer_id' => 'c-edited', 'booking_group_id' => 'g-edited',
    ]);

    $public = $this->ctl->publicStatus(['link_token' => $token]);

    $this->assertNull($public['participant_id']);
    $this->assertNull($public['customer_id']);
    $this->assertNull($public['booking_group_id']);
  }

  /**
   * @dataProvider malformedStatusTokenProvider
   */
  public function testPublicStatusRejectsAMalformedLinkToken($value): void {
    $payload = ['action' => 'public_status'];
    if ($value !== '__UNSET__') $payload['link_token'] = $value;
    $res = $this->ctl->publicStatus($payload);
    $this->assertSame('invalid_request', $res['error'] ?? null);
    $this->assertArrayNotHasKey('is_public', $res);
  }

  public static function malformedStatusTokenProvider(): array {
    return [
      'missing'    => ['__UNSET__'],
      'empty'      => [''],
      'too long'   => [str_repeat('a', 129)],
      'int'        => [1234567890123456],
      'array'      => [['a-token-value-000001']],
    ];
  }

  // =========================================================================
  // renderGuestForm expiry gate (w.php GET)
  // =========================================================================

  public function testRenderRefusesAnExpiredPublicInstanceInItsLocale(): void {
    [, $versionId] = $this->seedTemplate();
    $en = self::uuid();
    $ro = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $en, 'locale' => 'en'], -60);
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $ro, 'locale' => 'ro'], -60);

    $enRes = $this->ctl->renderGuestForm($en);
    $this->assertSame([
      'error' => 'This link has expired — please scan the QR again.',
      'error_code' => 'expired',
      'error_title' => 'Link expired',
      'locale' => 'en',
      'http_status' => 410,
    ], $enRes, 'exact shape: nothing about the instance (title/fields) on this path');

    $roRes = $this->ctl->renderGuestForm($ro);
    $this->assertSame('expired', $roRes['error_code']);
    $this->assertSame('Acest link a expirat — te rugăm să scanezi din nou codul QR.', $roRes['error']);
    $this->assertSame('Link expirat', $roRes['error_title']);
    $this->assertArrayNotHasKey('instance', $roRes);
    $this->assertArrayNotHasKey('fields', $roRes);
  }

  public function testRenderServesALivePublicInstance(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'locale' => 'en'], 3600);

    $res = $this->ctl->renderGuestForm($token);

    $this->assertArrayNotHasKey('error', $res);
    $this->assertSame('Test Waiver', $res['instance']['title']);
    $this->assertSame($token, $res['instance']['link_token']);
    $this->assertArrayNotHasKey('db_now_utc', $res['instance'], 'the gate clock is not leaked into the template context');
    $this->assertSame('full_name', $res['fields'][0]['key']);
  }

  public function testRenderFailsClosedForAPublicInstanceWithNoExpiry(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'locale' => null], null);

    $res = $this->ctl->renderGuestForm($token);

    $this->assertSame('expired', $res['error_code'] ?? null);
    $this->assertSame(410, $res['http_status']);
    $this->assertSame('ro', $res['locale'], 'unknown/NULL locale falls back to Romanian');
    $this->assertArrayNotHasKey('instance', $res);
  }

  public function testRenderNeverExpiryGatesANonPublicInstance(): void {
    // Reservation-bound flows are unchanged: the gate is keyed on is_public,
    // not on the mere presence of an (impossible here) past expires_at.
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token]);
    $this->pdo->prepare('UPDATE waiver_instances SET expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), locale="en" WHERE id=?')->execute([$id]);

    $res = $this->ctl->renderGuestForm($token);

    $this->assertArrayNotHasKey('error', $res);
    $this->assertSame($token, $res['instance']['link_token']);
  }

  public function testRenderKeepsItsExistingAnswersForUnknownCompletedAndVoidTokens(): void {
    [, $versionId] = $this->seedTemplate();
    $completedExpired = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $completedExpired, 'status' => 'completed'], -60);
    $voidLive = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $voidLive, 'status' => 'void'], 3600);

    $this->assertSame(['error' => 'Invalid link'], $this->ctl->renderGuestForm(self::uuid()));
    $this->assertSame(['error' => 'This waiver has already been completed.'], $this->ctl->renderGuestForm($completedExpired));
    $this->assertSame(['error' => 'This waiver link is no longer valid. Please use the most recent link you were sent.'], $this->ctl->renderGuestForm($voidLive));
  }

  // =========================================================================
  // submitGuestForm: adults-only gate on PUBLIC instances -- 89-M4.3 / AC4
  // (operator decision Q12, reconfirmed 2026-09-19: reception QR is
  // adults-only at launch, no guardian workflow).
  // =========================================================================

  public function testSubmitGuestFormRefusesA17YearOldOnAPublicInstanceBeforeAnyClaimOrResponseRow(): void {
    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'locale' => 'ro'], 3600);

    $res = $this->ctl->submitGuestForm($token, [
      'full_name' => 'Walk In Minor',
      'dob' => self::dobForAge(17),
      'signature_data' => self::onePixelPngDataUri(),
    ]);

    $this->assertSame('minor_requires_staff', $res['error_code'] ?? null, json_encode($res));
    $this->assertStringContainsString('18', $res['error']);
    $this->assertStringContainsString('membru al echipei', $res['error'], 'RO copy for a ro-locale instance');

    $row = $this->instanceRow($token);
    $this->assertSame('pending', $row['status'], 'the instance must NOT flip to completed');
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses WHERE waiver_instance_id='.(int)$id)->fetchColumn(), 'no waiver_responses row');
    $this->assertCount(1, $this->auditRows($id, 'age_gate_rejected'));
  }

  public function testSubmitGuestFormMinorCopyIsLocalizedToEnglishForAnEnLocaleInstance(): void {
    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'locale' => 'en'], 3600);

    $res = $this->ctl->submitGuestForm($token, [
      'full_name' => 'Walk In Minor', 'dob' => self::dobForAge(15), 'signature_data' => self::onePixelPngDataUri(),
    ]);

    $this->assertSame('minor_requires_staff', $res['error_code'] ?? null);
    $this->assertStringContainsString('18', $res['error']);
    $this->assertStringContainsString('staff member', $res['error']);
  }

  public function testSubmitGuestFormRefusesAMinorOnAPublicInstanceEvenWithParentalConsentFilled(): void {
    // The reservation-bound flow ACCEPTS this exact input (age 7-17 with a
    // filled parental-consent field -- see the non-public regression test
    // below); the reception-QR flow has no guardian physically present, so
    // it must not.
    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], 3600);

    $res = $this->ctl->submitGuestForm($token, [
      'full_name' => 'Walk In Minor With Consent',
      'dob' => self::dobForAge(12),
      'parental_consent_name' => 'A Guardian',
      'signature_data' => self::onePixelPngDataUri(),
    ]);

    $this->assertSame('minor_requires_staff', $res['error_code'] ?? null, json_encode($res));
  }

  public function testSubmitGuestFormStillAppliesTheHardRejectFloorOnAPublicInstance(): void {
    // age < 7 (AGE_MIN_HARD_REJECT): also relabelled -- still "under 18".
    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], 3600);

    $res = $this->ctl->submitGuestForm($token, [
      'full_name' => 'Toddler', 'dob' => self::dobForAge(5), 'signature_data' => self::onePixelPngDataUri(),
    ]);

    $this->assertSame('minor_requires_staff', $res['error_code'] ?? null, json_encode($res));
  }

  public function testSubmitGuestFormAcceptsAnAdultOnAPublicInstance(): void {
    // Non-vacuity partner: the gate rejects only what is actually under 18.
    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], 3600);

    $res = $this->ctl->submitGuestForm($token, [
      'full_name' => 'Adult Walk In', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri(),
    ]);

    $this->assertArrayNotHasKey('error', $res, json_encode($res));
    $this->assertTrue($res['ok'] ?? false);
    $this->assertSame('completed', $this->instanceRow($token)['status']);
    $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses WHERE waiver_instance_id='.(int)$id)->fetchColumn());
  }

  public function testSubmitGuestFormStillReturnsAGenuineValidationErrorOnAPublicInstance(): void {
    // A plain input-format error (missing DOB) is untouched by the
    // adults-only gate -- still its own message, never relabelled.
    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], 3600);

    $res = $this->ctl->submitGuestForm($token, [
      'full_name' => 'No Dob', 'signature_data' => self::onePixelPngDataUri(),
    ]);

    $this->assertSame('Missing field: dob', $res['error'] ?? null);
    $this->assertArrayNotHasKey('error_code', $res);
  }

  public function testSubmitGuestFormOnANonPublicInstanceIsUnaffectedByTheAdultsOnlyGate(): void {
    // Regression: a reservation-bound minor WITH parental consent still
    // succeeds exactly as before the adults-only gate was added -- the gate
    // is is_public-scoped, never applied to reservation-bound instances.
    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token]);

    $res = $this->ctl->submitGuestForm($token, [
      'full_name' => 'Reservation Minor', 'dob' => self::dobForAge(12),
      'parental_consent_name' => 'A Guardian', 'signature_data' => self::onePixelPngDataUri(),
    ]);

    $this->assertArrayNotHasKey('error', $res, json_encode($res));
    $this->assertSame('completed', $this->instanceRow($token)['status']);
    $answers = json_decode($this->pdo->query('SELECT answers_json FROM waiver_responses WHERE waiver_instance_id='.(int)$id)->fetchColumn(), true);
    $this->assertTrue($answers['_minor']);
    $this->assertSame('A Guardian', $answers['_parental_consent_name']);
  }

  // =========================================================================
  // notifyBookingV2Completion: public completion wire shape -- 89-M4.3 / §7.0
  // =========================================================================

  public function testNotifyBookingV2CompletionPostsToPublicCompleteWithSignupTokenAndNullBindingIds(): void {
    $port = $this->startCaptureServer();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $ctl = new WaiverController($cfg, TestDatabase::connect());

    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], 3600);

    $result = $ctl->submitGuestForm($token, [
      'full_name' => 'Adult Walk In', 'dob' => self::dobForAge(25), 'signature_data' => self::onePixelPngDataUri(),
    ]);
    $this->assertArrayNotHasKey('error', $result, json_encode($result));

    $captured = $this->readCapture($port, '/api/waiver/public-complete');
    $this->assertNotNull($captured, 'a public completion must POST to /api/waiver/public-complete');
    $body = json_decode($captured['body'], true);

    $this->assertSame('waiver.public_completed', $body['event']);
    $this->assertSame($token, $body['signup_token']);
    $this->assertSame($token, $body['link_token']);
    $this->assertSame('wvr-'.$id.'-'.$token, $body['idempotency_key']);
    foreach (['reservation_id', 'booking_group_id', 'participant_id', 'customer_id'] as $key) {
      $this->assertNull($body[$key], "$key must be null on a public completion");
    }
    $this->assertNull($this->readCapture($port, '/api/waiver/complete'), 'must NOT also post to the reservation-bound endpoint');
  }

  public function testNotifyBookingV2CompletionOnANonPublicInstanceStillPostsToTheOriginalCompleteEndpoint(): void {
    // Regression: adding the public-complete branch must not change the
    // existing reservation-bound wire shape (event name, endpoint, no
    // signup_token key at all).
    $port = $this->startCaptureServer();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $ctl = new WaiverController($cfg, TestDatabase::connect());

    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token]);

    $result = $ctl->submitGuestForm($token, [
      'full_name' => 'Reservation Guest', 'dob' => self::dobForAge(25), 'signature_data' => self::onePixelPngDataUri(),
    ]);
    $this->assertArrayNotHasKey('error', $result, json_encode($result));

    $captured = $this->readCapture($port, '/api/waiver/complete');
    $this->assertNotNull($captured, 'a reservation-bound completion must POST to /api/waiver/complete');
    $body = json_decode($captured['body'], true);
    $this->assertSame('waiver.completed', $body['event']);
    $this->assertArrayNotHasKey('signup_token', $body);
    $this->assertNull($this->readCapture($port, '/api/waiver/public-complete'), 'must NOT post to the public endpoint');
  }

  // =========================================================================
  // resend_evidence -- 89-M4.4 / §4 #14 / AC7
  // =========================================================================

  public function testResendEvidenceReturnsTokenUnknownForAnUnknownToken(): void {
    $this->assertSame(['error' => 'token_unknown'], $this->ctl->resendEvidence(['link_token' => self::uuid()]));
  }

  /**
   * @dataProvider malformedStatusTokenProvider
   * [gate 89-M4 P2-4] Same {error:'invalid_request', detail} envelope as the
   * two sibling GVS-89 actions (public_status shares this provider).
   */
  public function testResendEvidenceRejectsAMalformedLinkToken($value): void {
    $payload = ['action' => 'resend_evidence'];
    if ($value !== '__UNSET__') $payload['link_token'] = $value;
    $res = $this->ctl->resendEvidence($payload);
    $this->assertSame('invalid_request', $res['error'] ?? null, json_encode($res));
    $this->assertSame('link_token must be a non-empty string (max 128)', $res['detail'] ?? null);
    $this->assertArrayNotHasKey('pushed', $res);
  }

  public function testResendEvidenceIsANoOpForAPendingInstance(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'pending']);

    $this->assertSame(['ok' => true, 'pushed' => false], $this->ctl->resendEvidence(['link_token' => $token]));
  }

  public function testResendEvidenceIsANoOpForAPendingInstanceEvenWithAStrayRetainedFile(): void {
    // Hand-edited/corrupt-row defense: a waiver_responses row (with a real,
    // readable pdf_path) for a non-completed instance should never happen in
    // practice, but the status check must still gate it -- isolates the
    // `status !== 'completed'` half of the guard. A WORKING relay is
    // configured on purpose: without the status check, the flow would fall
    // through to a real (successful) upload attempt via the stray file, so a
    // weaker test using seedResponse()'s default null pdf_path would pass
    // even with the status check removed (it would still no-op for the
    // unrelated reason that there is no file to read).
    $port = $this->startCaptureServer();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $ctl = new WaiverController($cfg, TestDatabase::connect());

    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'pending']);
    $responseId = TestDatabase::seedResponse($this->pdo, $id, ['full_name' => 'Stray']);
    $strayPdf = sys_get_temp_dir().'/resend-test-stray-'.getmypid().'.pdf';
    file_put_contents($strayPdf, 'stray bytes for a not-actually-completed instance');
    $this->pdo->prepare('UPDATE waiver_responses SET pdf_path=? WHERE id=?')->execute([$strayPdf, $responseId]);

    $res = $ctl->resendEvidence(['link_token' => $token]);

    $this->assertSame(['ok' => true, 'pushed' => false], $res, 'a non-completed instance must never be pushed, even with a stray retained-looking response row');
    $this->assertNull($this->pdo->query('SELECT evidence_object_key FROM waiver_responses WHERE id='.$responseId)->fetchColumn(), 'must not have been uploaded');
    $this->assertFileExists($strayPdf, 'must not have been touched');
    @unlink($strayPdf);
  }

  public function testResendEvidenceIsANoOpForAVoidInstance(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'void']);

    $this->assertSame(['ok' => true, 'pushed' => false], $this->ctl->resendEvidence(['link_token' => $token]));
  }

  public function testResendEvidenceIsANoOpForACompletedInstanceWithNoResponseRowAtAll(): void {
    // Isolates the `response_id === null` half of the guard: status IS
    // 'completed' here, but nothing was ever seeded into waiver_responses.
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);

    $this->assertSame(['ok' => true, 'pushed' => false], $this->ctl->resendEvidence(['link_token' => $token]));
  }

  public function testResendEvidenceIsANoOpWhenEvidenceIsAlreadyDurablyStored(): void {
    // A WORKING relay is configured here on purpose: if the "already has an
    // evidence_object_key" short-circuit were ever removed, this test would
    // catch it by seeing the stale pdf_path actually get re-uploaded and the
    // evidence_object_key overwritten with the mock relay's fixed value.
    $port = $this->startCaptureServer();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $ctl = new WaiverController($cfg, TestDatabase::connect());

    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);
    $responseId = TestDatabase::seedResponse($this->pdo, $id, ['full_name' => 'Test'], ['evidence_object_key' => 'already-there']);
    $stalePdf = sys_get_temp_dir().'/resend-test-stale-'.getmypid().'.pdf';
    file_put_contents($stalePdf, 'stale bytes that must never be re-read');
    $this->pdo->prepare('UPDATE waiver_responses SET pdf_path=? WHERE id=?')->execute([$stalePdf, $responseId]);

    $res = $ctl->resendEvidence(['link_token' => $token]);

    $this->assertSame(['ok' => true, 'pushed' => false], $res);
    $this->assertSame('already-there', $this->pdo->query('SELECT evidence_object_key FROM waiver_responses WHERE id='.$responseId)->fetchColumn(), 'must not be overwritten');
    $this->assertFileExists($stalePdf, 'the stale file must be left alone, not deleted');
    @unlink($stalePdf);
  }

  public function testResendEvidenceIsANoOpWhenTheRetainedPdfIsMissingFromDisk(): void {
    $port = $this->startCaptureServer();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $ctl = new WaiverController($cfg, TestDatabase::connect());

    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);
    $responseId = TestDatabase::seedResponse($this->pdo, $id, ['full_name' => 'Test']);
    $goneMissing = sys_get_temp_dir().'/resend-test-does-not-exist-'.getmypid().'.pdf';
    $this->pdo->prepare('UPDATE waiver_responses SET pdf_path=? WHERE id=?')->execute([$goneMissing, $responseId]);

    $this->assertSame(['ok' => true, 'pushed' => false], $ctl->resendEvidence(['link_token' => $token]));
  }

  public function testResendEvidenceRePushesRetainedFilesForACompletedInstanceAndUpdatesTheResponseRow(): void {
    $port = $this->startCaptureServer();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $ctl = new WaiverController($cfg, TestDatabase::connect());

    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);
    $responseId = TestDatabase::seedResponse($this->pdo, $id, ['full_name' => 'Retained Guest']);
    $pdfPath = sys_get_temp_dir().'/resend-test-'.getmypid().'.pdf';
    $sigPath = sys_get_temp_dir().'/resend-test-'.getmypid().'.png';
    file_put_contents($pdfPath, '%PDF-1.4 fake pdf bytes for resend test');
    file_put_contents($sigPath, "\x89PNG\r\n\x1a\nfake sig bytes");
    $this->pdo->prepare('UPDATE waiver_responses SET pdf_path=?, signature_path=? WHERE id=?')->execute([$pdfPath, $sigPath, $responseId]);

    $res = $ctl->resendEvidence(['link_token' => $token]);

    $this->assertSame(['ok' => true, 'pushed' => true], $res, json_encode($res));
    $row = $this->pdo->query('SELECT * FROM waiver_responses WHERE id='.$responseId)->fetch();
    $this->assertSame('waiver-evidence/capture-fixture/999/pdf.pdf', $row['evidence_object_key']);
    $this->assertSame('waiver-evidence/capture-fixture/999/pdf.pdf', $row['evidence_blob_key']);
    $this->assertSame('https://mock-blob-store.example.invalid/waiver-evidence/capture-fixture/999/pdf.pdf', $row['evidence_blob_url']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['evidence_sha256']);
    $this->assertNull($row['pdf_path']);
    $this->assertNull($row['signature_path']);
    $this->assertFileDoesNotExist($pdfPath, 'the retained local PDF must be removed once durably stored');
    $this->assertFileDoesNotExist($sigPath, 'the retained local signature PNG must be removed once durably stored');
    $this->assertCount(1, $this->auditRows($id, 'evidence_resent'));

    // Non-vacuity / idempotency: a SECOND call now finds evidence_object_key
    // already set and is a clean no-op (the "already durably stored" branch
    // above, exercised here on a row this same test just wrote).
    $again = $ctl->resendEvidence(['link_token' => $token]);
    $this->assertSame(['ok' => true, 'pushed' => false], $again, 'already durably stored now -- a second call must not re-push');
  }

  public function testResendEvidenceLeavesRetainedFilesInPlaceWhenTheRelayIsStillDown(): void {
    $deadPort = $this->reserveDeadPort();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$deadPort;
    $ctl = new WaiverController($cfg, TestDatabase::connect());

    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);
    $responseId = TestDatabase::seedResponse($this->pdo, $id, ['full_name' => 'Still Down Guest']);
    $pdfPath = sys_get_temp_dir().'/resend-test-down-'.getmypid().'.pdf';
    file_put_contents($pdfPath, 'fake pdf bytes');
    $this->pdo->prepare('UPDATE waiver_responses SET pdf_path=? WHERE id=?')->execute([$pdfPath, $responseId]);

    $res = $ctl->resendEvidence(['link_token' => $token]);

    $this->assertSame(['ok' => true, 'pushed' => false], $res);
    $row = $this->pdo->query('SELECT pdf_path, evidence_object_key FROM waiver_responses WHERE id='.$responseId)->fetch();
    $this->assertSame($pdfPath, $row['pdf_path'], 'the retained file pointer must be left in place for a later attempt');
    $this->assertNull($row['evidence_object_key']);
    $this->assertFileExists($pdfPath);
    @unlink($pdfPath);
  }

  // =========================================================================
  // submitGuestForm: submit-time expiry + grace window (orchestrator
  // decision, 2026-09-19). The render gate (isExpiredPublicInstance, above)
  // only ever stopped a GET from showing the form -- a crafted POST straight
  // to w.php with an expired public token still completed the waiver. A
  // submit is now refused once now is past expires_at + 60 minutes, with the
  // SAME 410 copy as the render gate; within that grace window a person who
  // opened the form before expiry can still send it.
  // =========================================================================

  public function testSubmitGuestFormRefusesAPublicInstanceExpiredPastTheGraceWindow(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    // 62 minutes past expires_at: 2 minutes past the 60-minute grace.
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'locale' => 'ro'], -(60 * 62));

    $res = $this->ctl->submitGuestForm($token, ['full_name' => 'Too Late', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);

    // [gate 89-M4 P2-3] EXACTLY the render gate's shape (error_title + locale
    // included), so w.php renders the same localized 410 page for a late POST.
    $this->assertSame([
      'error' => 'Acest link a expirat — te rugăm să scanezi din nou codul QR.',
      'error_code' => 'expired',
      'error_title' => 'Link expirat',
      'locale' => 'ro',
      'http_status' => 410,
    ], $res);
    $this->assertSame('pending', $this->instanceRow($token)['status'], 'must not flip to completed');
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses WHERE waiver_instance_id='.(int)$id)->fetchColumn());
  }

  public function testSubmitGuestFormAcceptsAPublicInstanceWithinTheGraceWindow(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    // 30 minutes past expires_at: inside the 60-minute grace -- the form was
    // opened before expiry and is being sent late.
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], -(60 * 30));

    $res = $this->ctl->submitGuestForm($token, ['full_name' => 'Just In Time', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);

    $this->assertArrayNotHasKey('error', $res, json_encode($res));
    $this->assertSame('completed', $this->instanceRow($token)['status']);
    $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses WHERE waiver_instance_id='.(int)$id)->fetchColumn());
  }

  public function testSubmitGuestFormAcceptsAPublicInstanceNotYetExpiredAtAll(): void {
    // Non-vacuity baseline: an ordinary, not-yet-expired public submit is
    // completely unaffected by the new gate.
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], 3600);

    $res = $this->ctl->submitGuestForm($token, ['full_name' => 'Still Live', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);

    $this->assertArrayNotHasKey('error', $res, json_encode($res));
    $this->assertSame('completed', $this->instanceRow($token)['status']);
  }

  public function testSubmitGuestFormGraceGateNeverAppliesToANonPublicInstance(): void {
    // Regression: reservation-bound flows are unchanged -- an (impossible in
    // practice, but hand-craftable) past expires_at on a non-public row must
    // never gate a submit, mirroring the existing render-gate regression
    // (testRenderNeverExpiryGatesANonPublicInstance).
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token]);
    $this->pdo->prepare('UPDATE waiver_instances SET expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) WHERE id=?')->execute([$id]);

    $res = $this->ctl->submitGuestForm($token, ['full_name' => 'Reservation Guest', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);

    $this->assertArrayNotHasKey('error', $res, json_encode($res));
    $this->assertSame('completed', $this->instanceRow($token)['status']);
  }

  public function testSubmitGuestFormGraceRefusalDoesNotMaskAnAlreadyCompletedInstance(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed'], -(60 * 62));

    $res = $this->ctl->submitGuestForm($token, ['full_name' => 'Retry', 'signature_data' => self::onePixelPngDataUri()]);

    $this->assertSame(['error' => 'Already completed'], $res, 'completed status is checked BEFORE the expiry+grace gate');
  }

  public function testSubmitGuestFormGraceRefusalDoesNotMaskAVoidInstance(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'void'], -(60 * 62));

    $res = $this->ctl->submitGuestForm($token, ['full_name' => 'Retry', 'signature_data' => self::onePixelPngDataUri()]);

    $this->assertSame(['error' => 'This waiver link is no longer valid.'], $res, 'void status is checked BEFORE the expiry+grace gate');
  }

  public function testSubmitGuestFormGraceRefusalUsesTheInstanceLocale(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'locale' => 'en'], -(60 * 62));

    $res = $this->ctl->submitGuestForm($token, ['full_name' => 'Too Late', 'signature_data' => self::onePixelPngDataUri()]);

    $this->assertSame('This link has expired — please scan the QR again.', $res['error']);
    $this->assertSame('expired', $res['error_code']);
  }

  // =========================================================================
  // [gate 89-M4 P1] Adults-only FAILS CLOSED when the template has no DOB
  // =========================================================================

  public function testCreatePublicInstanceRefusesATemplateWithoutADobFieldAndWritesNothing(): void {
    [$templateId] = $this->seedTemplateWithoutDob();

    $res = $this->ctl->createPublicInstance($this->validPayload($templateId, self::uuid()));

    $this->assertSame('template_missing_dob', $res['error'] ?? null, json_encode($res));
    $this->assertStringContainsString('date-of-birth', $res['detail']);
    $this->assertArrayNotHasKey('ok', $res);
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_instances')->fetchColumn(), 'no instance minted');
  }

  public function testSubmitGuestFormFailsClosedOnAPublicInstanceWhoseTemplateHasNoDobField(): void {
    // Minted directly (create_public_instance would refuse this template):
    // the template-edited-after-minting case the submit-side check covers.
    $port = $this->startCaptureServer();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $ctl = new WaiverController($cfg, TestDatabase::connect());
    [, $versionId] = $this->seedTemplateWithoutDob();
    $en = self::uuid();
    $ro = self::uuid();
    $enId = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $en, 'locale' => 'en'], 3600);
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $ro, 'locale' => 'ro'], 3600);

    $res = $ctl->submitGuestForm($en, ['full_name' => 'Unknown Age', 'signature_data' => self::onePixelPngDataUri()]);

    $this->assertSame([
      'error' => "We can't verify your age on this form. Please ask a staff member for help.",
      'error_code' => 'age_unverifiable',
    ], $res);
    $this->assertSame('pending', $this->instanceRow($en)['status'], 'no completion claim');
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses')->fetchColumn(), 'no waiver_responses row');
    $audit = $this->auditRows($enId, 'age_gate_rejected');
    $this->assertCount(1, $audit);
    $this->assertSame(['reason' => 'age_unverifiable'], json_decode($audit[0]['meta_json'], true));
    $this->assertNull($this->readCapture($port, '/api/waiver/public-complete'), 'no completion webhook');

    $roRes = $ctl->submitGuestForm($ro, ['full_name' => 'Unknown Age', 'signature_data' => self::onePixelPngDataUri()]);
    $this->assertSame('age_unverifiable', $roRes['error_code'] ?? null);
    $this->assertSame('Nu putem verifica vârsta pe acest formular. Te rugăm să te adresezi unui membru al echipei.', $roRes['error']);
  }

  public function testSubmitGuestFormOnANonPublicInstanceWithoutADobFieldStillCompletes(): void {
    // Regression: reservation-bound templates without a DOB field keep
    // working exactly as before -- the fail-closed rule is public-only.
    [, $versionId] = $this->seedTemplateWithoutDob();
    $token = self::uuid();
    TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token]);

    $res = $this->ctl->submitGuestForm($token, ['full_name' => 'Reservation Guest', 'signature_data' => self::onePixelPngDataUri()]);

    $this->assertArrayNotHasKey('error', $res, json_encode($res));
    $this->assertSame('completed', $this->instanceRow($token)['status']);
  }

  // =========================================================================
  // [gate 89-M4 r2 P1] The public DOB field is resolved STRICTLY, never
  // "the first type=date field"
  // =========================================================================

  public function testPublicAgeGateUsesTheExplicitlyKeyedDobFieldEvenWhenAVisitDateComesFirst(): void {
    [$templateId, $versionId] = $this->seedTemplateWithFields('Visit Date First', [
      ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
      ['key' => 'visit_date', 'label' => 'Visit date', 'type' => 'date', 'required' => true],
      ['key' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date', 'required' => true],
    ]);
    $this->assertTrue($this->ctl->createPublicInstance($this->validPayload($templateId, self::uuid()))['ok'] ?? false, 'an explicitly keyed DOB field makes the template usable');

    // An honest adult visiting today: the first date field would compute age 0.
    $adult = self::uuid();
    $adultId = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $adult], 3600);
    $res = $this->ctl->submitGuestForm($adult, [
      'full_name' => 'Adult Visitor', 'visit_date' => gmdate('Y-m-d'), 'date_of_birth' => self::dobForAge(30),
      'signature_data' => self::onePixelPngDataUri(),
    ]);
    $this->assertTrue($res['ok'] ?? false, json_encode($res));
    $answers = json_decode((string)$this->pdo->query('SELECT answers_json FROM waiver_responses WHERE waiver_instance_id='.$adultId)->fetchColumn(), true);
    $this->assertSame(30, $answers['_computed_age'], 'age computed from date_of_birth, not from visit_date');

    // A minor with an old "visit date": the first date field would compute an adult age.
    $minor = self::uuid();
    $minorId = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $minor], 3600);
    $res = $this->ctl->submitGuestForm($minor, [
      'full_name' => 'Minor Visitor', 'visit_date' => self::dobForAge(40), 'date_of_birth' => self::dobForAge(16),
      'signature_data' => self::onePixelPngDataUri(),
    ]);
    $this->assertSame('minor_requires_staff', $res['error_code'] ?? null, json_encode($res));
    $this->assertSame('pending', $this->instanceRow($minor)['status']);
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses WHERE waiver_instance_id='.$minorId)->fetchColumn());
  }

  public function testPublicInstanceWithSeveralUnmarkedDateFieldsIsRefusedAtCreateAndFailsClosedAtSubmit(): void {
    [$templateId, $versionId] = $this->seedTemplateWithFields('Two Unmarked Dates', [
      ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
      ['key' => 'visit_date', 'label' => 'Visit date', 'type' => 'date', 'required' => true],
      ['key' => 'arrival_date', 'label' => 'Arrival date', 'type' => 'date', 'required' => true],
    ]);

    $res = $this->ctl->createPublicInstance($this->validPayload($templateId, self::uuid()));
    $this->assertSame('template_ambiguous_dob', $res['error'] ?? null, json_encode($res));
    $this->assertStringContainsString('date_of_birth', $res['detail']);
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_instances')->fetchColumn(), 'no instance minted');

    // Minted directly (template edited after minting): submit fails closed.
    $token = self::uuid();
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'locale' => 'en'], 3600);
    $res = $this->ctl->submitGuestForm($token, [
      'full_name' => 'Ambiguous', 'visit_date' => self::dobForAge(30), 'arrival_date' => self::dobForAge(30),
      'signature_data' => self::onePixelPngDataUri(),
    ]);
    $this->assertSame('age_unverifiable', $res['error_code'] ?? null, json_encode($res));
    $this->assertSame('pending', $this->instanceRow($token)['status'], 'no completion claim');
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses')->fetchColumn());
    $this->assertSame([['reason' => 'age_unverifiable']], array_map(static fn(array $r) => json_decode($r['meta_json'], true), $this->auditRows($id, 'age_gate_rejected')));
  }

  public function testPublicInstanceWithASingleUnmarkedDateFieldAgeGatesOnIt(): void {
    [$templateId, $versionId] = $this->seedTemplateWithFields('One Unmarked Date', [
      ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
      ['key' => 'birthday', 'label' => 'Birthday', 'type' => 'date', 'required' => true],
    ]);
    $this->assertTrue($this->ctl->createPublicInstance($this->validPayload($templateId, self::uuid()))['ok'] ?? false, 'the only date field is unambiguous');

    $minor = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $minor], 3600);
    $res = $this->ctl->submitGuestForm($minor, ['full_name' => 'Minor', 'birthday' => self::dobForAge(16), 'signature_data' => self::onePixelPngDataUri()]);
    $this->assertSame('minor_requires_staff', $res['error_code'] ?? null, json_encode($res));

    $adult = self::uuid();
    $adultId = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $adult], 3600);
    $res = $this->ctl->submitGuestForm($adult, ['full_name' => 'Adult', 'birthday' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);
    $this->assertTrue($res['ok'] ?? false, json_encode($res));
    $answers = json_decode((string)$this->pdo->query('SELECT answers_json FROM waiver_responses WHERE waiver_instance_id='.$adultId)->fetchColumn(), true);
    $this->assertSame(30, $answers['_computed_age']);
  }

  public function testTheDobKeyMarkerIsCaseAndHyphenInsensitiveButTwoMarkedDateFieldsAreAmbiguous(): void {
    [$templateId, $versionId] = $this->seedTemplateWithFields('Marked Variant', [
      ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
      ['key' => 'arrival_date', 'label' => 'Arrival date', 'type' => 'date', 'required' => true],
      ['key' => 'Date-Of-Birth', 'label' => 'Date of birth', 'type' => 'date', 'required' => true],
    ]);
    $this->assertTrue($this->ctl->createPublicInstance($this->validPayload($templateId, self::uuid()))['ok'] ?? false, '"Date-Of-Birth" is the date_of_birth marker');
    $token = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], 3600);
    $res = $this->ctl->submitGuestForm($token, [
      'full_name' => 'Minor', 'arrival_date' => self::dobForAge(40), 'Date-Of-Birth' => self::dobForAge(16),
      'signature_data' => self::onePixelPngDataUri(),
    ]);
    $this->assertSame('minor_requires_staff', $res['error_code'] ?? null, 'gated on the marked field: '.json_encode($res));

    [$twoMarked] = $this->seedTemplateWithFields('Two Marked', [
      ['key' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'required' => true],
      ['key' => 'date_of_birth', 'label' => 'Date of birth (again)', 'type' => 'date', 'required' => true],
    ]);
    $this->assertSame('template_ambiguous_dob', $this->ctl->createPublicInstance($this->validPayload($twoMarked, self::uuid()))['error'] ?? null);
  }

  public function testReservationBoundAgeGateKeepsItsUnchangedFirstDateFieldRule(): void {
    // Pins that the r2 fix is PUBLIC-only, as scoped: a reservation-bound
    // instance still age-gates on its template's FIRST type=date field. The
    // same "wrong date field" limitation therefore still applies to
    // reservation-bound templates; it is REPORTED (89-M4 fix-2 report), not
    // silently changed. Here the first date field says 30, the DOB says 12
    // with no guardian name: the unchanged rule completes it at age 30.
    [, $versionId] = $this->seedTemplateWithFields('Reservation Visit Date First', [
      ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
      ['key' => 'visit_date', 'label' => 'Visit date', 'type' => 'date', 'required' => true],
      ['key' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'required' => true],
      ['key' => 'parental_consent_name', 'label' => 'Parent/guardian name', 'type' => 'parental_consent', 'required' => false],
    ]);
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token]);

    $res = $this->ctl->submitGuestForm($token, [
      'full_name' => 'Reservation Guest', 'visit_date' => self::dobForAge(30), 'dob' => self::dobForAge(12),
      'signature_data' => self::onePixelPngDataUri(),
    ]);

    $this->assertTrue($res['ok'] ?? false, json_encode($res));
    $answers = json_decode((string)$this->pdo->query('SELECT answers_json FROM waiver_responses WHERE waiver_instance_id='.$id)->fetchColumn(), true);
    $this->assertSame(30, $answers['_computed_age']);
  }

  // =========================================================================
  // [gate 89-M4 P2-6] ONE clock for public expiry: the database's
  // =========================================================================

  public function testCreatePublicInstanceJudgesExpiresAtByTheDatabaseClockNotThePhpClock(): void {
    [$templateId] = $this->seedTemplate();
    // SET TIMESTAMP fakes UTC_TIMESTAMP() for this (the controller's own)
    // session only -- a PHP process skewed against MySQL, made deterministic.
    try {
      // DB clock 3 h AHEAD of PHP: now+2h by the PHP clock is already past.
      $this->pdo->exec('SET TIMESTAMP = '.(time() + 3 * 3600));
      $this->assertSame(
        ['error' => 'invalid_request', 'detail' => 'expires_at must be in the future'],
        $this->ctl->createPublicInstance($this->validPayload($templateId, self::uuid(), ['expires_at' => self::isoIn(2 * 3600)]))
      );

      // DB clock 3 h BEHIND: an instant 1 h in the PHP past is 2 h in the
      // DB's future -> accepted, and the render gate (same clock) agrees.
      $this->pdo->exec('SET TIMESTAMP = '.(time() - 3 * 3600));
      $token = self::uuid();
      $ok = $this->ctl->createPublicInstance($this->validPayload($templateId, $token, ['expires_at' => self::isoIn(-3600)]));
      $this->assertTrue($ok['ok'] ?? false, json_encode($ok));
      $this->assertArrayNotHasKey('error', $this->ctl->renderGuestForm($token), 'a freshly minted link must render on the same clock');

      // ...and the 7-day ceiling is measured on the DB clock too.
      $this->assertSame(
        ['error' => 'invalid_request', 'detail' => 'expires_at must be at most 7 days in the future'],
        $this->ctl->createPublicInstance($this->validPayload($templateId, self::uuid(), ['expires_at' => self::isoIn(7 * 86400 - 3600)]))
      );
    } finally {
      $this->pdo->exec('SET TIMESTAMP = DEFAULT');
    }
  }

  public function testRenderAndSubmitGatesFollowTheDatabaseClock(): void {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    // Expired 2 h ago on BOTH clocks -- past the render gate AND the grace.
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], -2 * 3600);
    try {
      // Turn the DB clock back 3 h: for the DB this link expires in 1 h.
      $this->pdo->exec('SET TIMESTAMP = '.(time() - 3 * 3600));

      $render = $this->ctl->renderGuestForm($token);
      $this->assertArrayNotHasKey('error', $render, 'render gate must use the DB clock: '.json_encode($render));

      $submit = $this->ctl->submitGuestForm($token, ['full_name' => 'Db Clock', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);
      $this->assertArrayNotHasKey('error', $submit, 'submit gate must use the DB clock: '.json_encode($submit));
    } finally {
      $this->pdo->exec('SET TIMESTAMP = DEFAULT');
    }
  }

  // =========================================================================
  // [gate 89-M4 Grok/Codex P2] A public instance can never gain a binding
  // =========================================================================

  public function testLinkWaiversNeverBindsAPublicInstance(): void {
    [, $versionId] = $this->seedTemplate();
    $publicId = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => self::uuid()], 3600);
    $boundId = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => self::uuid()]);

    $res = $this->ctl->linkWaiversToReservation('res-89', [$publicId, $boundId], null, true);

    $this->assertSame(1, $res['updated'], json_encode($res));
    $this->assertSame([$boundId], array_map('intval', $res['ids']));
    $rows = $this->pdo->query('SELECT id, reservation_id FROM waiver_instances ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);
    $this->assertNull($rows[$publicId], 'the public instance must stay unbound');
    $this->assertSame('res-89', $rows[$boundId], 'non-vacuity: the reservation-bound one IS linked');
    $this->assertCount(0, $this->auditRows($publicId, 'linked_to_reservation'));
  }

  public function testAPublicCompletionNeverCarriesABindingEvenFromAHandEditedRow(): void {
    $port = $this->startCaptureServer();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $ctl = new WaiverController($cfg, TestDatabase::connect());
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, [
      'link_token' => $token, 'participant_id' => 'p-edited', 'customer_id' => 'c-edited', 'booking_group_id' => 'g-edited',
    ], 3600);
    $this->pdo->prepare('UPDATE waiver_instances SET reservation_id=? WHERE id=?')->execute(['r-edited', $id]);

    $res = $ctl->submitGuestForm($token, ['full_name' => 'Edited Row', 'dob' => self::dobForAge(40), 'signature_data' => self::onePixelPngDataUri()]);
    $this->assertArrayNotHasKey('error', $res, json_encode($res));

    $captured = $this->readCapture($port, '/api/waiver/public-complete');
    $this->assertNotNull($captured);
    $body = json_decode($captured['body'], true);
    foreach (['reservation_id', 'booking_group_id', 'participant_id', 'customer_id'] as $key) {
      $this->assertArrayHasKey($key, $body);
      $this->assertNull($body[$key], "$key must be forced null on a public completion");
    }
  }

  // =========================================================================
  // [gate 89-M4 P2-2] Re-render after a REJECTED submit uses the submit grace
  // =========================================================================

  public function testRerenderAfterARejectedSubmitUsesTheSubmitGraceNotTheRenderGate(): void {
    [, $versionId] = $this->seedTemplate();
    $inGrace = self::uuid();
    $pastGrace = self::uuid();
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $inGrace, 'locale' => 'en'], -30 * 60);
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $pastGrace, 'locale' => 'en'], -62 * 60);

    // A fresh GET inside the grace is refused: the render gate has no grace.
    $this->assertSame('expired', $this->ctl->renderGuestForm($inGrace)['error_code'] ?? null);

    // Re-showing the form after a rejected POST inside the grace serves it.
    $rr = $this->ctl->rerenderGuestFormAfterRejectedSubmit($inGrace);
    $this->assertArrayNotHasKey('error', $rr, json_encode($rr));
    $this->assertSame($inGrace, $rr['instance']['link_token']);
    $this->assertArrayNotHasKey('db_now_utc', $rr['instance']);
    $this->assertSame(['full_name', 'dob'], array_column($rr['fields'], 'key'));

    // Past the grace it is the SAME localized 410 as every other expiry answer.
    $this->assertSame([
      'error' => 'This link has expired — please scan the QR again.',
      'error_code' => 'expired',
      'error_title' => 'Link expired',
      'locale' => 'en',
      'http_status' => 410,
    ], $this->ctl->rerenderGuestFormAfterRejectedSubmit($pastGrace));
  }

  // =========================================================================
  // [gate 89-M4 P1] resend_evidence is SERIALIZED against erase_waiver
  // =========================================================================

  public function testAnEraseFiredWhileAResendUploadIsInFlightWaitsOutTheLockAndThenErasesEverything(): void {
    // The concurrent erase is fired by the relay stand-in INSIDE the upload
    // request -- after resend read the retained files, before it recorded
    // the push (the gate's race window) -- from its own DB session.
    $port = $this->startRouterServer(__DIR__.'/fixtures/mock-bookingv2-erase-race.php', 21400);
    $ctl = $this->controllerWithEvidenceUrl($port, 'erase-via-controller');
    [$id, $token, $pdfPath, $sigPath, $responseId] = $this->seedCompletedWithRetainedFiles();

    $res = $ctl->resendEvidence(['link_token' => $token]);

    $race = $this->readRaceCapture($port);
    $this->assertSame($token, $race['link_token'] ?? null, 'the relay must have received this upload');
    $this->assertSame(['error' => 'evidence_busy'], $race['erase'] ?? $race, 'an erase racing an in-flight resend must NOT interleave with it');
    // The resend therefore completed and recorded normally...
    $this->assertSame(['ok' => true, 'pushed' => true], $res);
    $this->assertSame('waiver-evidence/race-fixture/999/pdf.pdf', $this->pdo->query('SELECT evidence_object_key FROM waiver_responses WHERE id='.$responseId)->fetchColumn());
    $this->assertCount(1, $this->auditRows($id, 'evidence_resent'));
    $this->assertFileDoesNotExist($pdfPath);
    $this->assertFileDoesNotExist($sigPath);

    // ...and the erasure retried AFTER it reaches everything it left behind,
    // including the evidence_resent audit event (no orphan).
    $erase = $ctl->eraseWaiver(['link_tokens' => [$token]]);
    $this->assertSame(1, $erase['instances_deleted'] ?? null, json_encode($erase));
    $this->assertSame(1, $erase['responses_deleted']);
    $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM audit_events WHERE entity_type IN ('instance','response') AND entity_id=".$id)->fetchColumn(), 'no audit event may outlive the erasure');
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_instances')->fetchColumn());
  }

  public function testAResendWhoseRowVanishesMidUploadWritesNoAuditEventAndDropsTheOrphanedFiles(): void {
    // Belt-and-suspenders for a deletion that BYPASSES the evidence lock
    // (manual SQL): the conditional UPDATE's rowCount decides, never "assume
    // it landed".
    $port = $this->startRouterServer(__DIR__.'/fixtures/mock-bookingv2-erase-race.php', 21400);
    $ctl = $this->controllerWithEvidenceUrl($port, 'delete-bypassing-lock');
    [$id, $token, $pdfPath, $sigPath] = $this->seedCompletedWithRetainedFiles();

    $res = $ctl->resendEvidence(['link_token' => $token]);

    $this->assertSame($id, $this->readRaceCapture($port)['deleted_instance_id'] ?? null, 'fixture must have deleted the row mid-upload');
    $this->assertSame(['error' => 'token_unknown'], $res);
    $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM audit_events WHERE entity_type IN ('instance','response') AND entity_id=".$id)->fetchColumn(), 'no orphan evidence_resent audit event');
    $this->assertFileDoesNotExist($pdfPath, 'retained files of a vanished row are unreachable PII orphans: removed');
    $this->assertFileDoesNotExist($sigPath);
  }

  public function testResendNeverWaitsForAndNeverUploadsUnderAnEvidenceLockHeldElsewhere(): void {
    $port = $this->startCaptureServer();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $ctl = new WaiverController($cfg, TestDatabase::connect());
    [$id, $token, $pdfPath, , $responseId] = $this->seedCompletedWithRetainedFiles();
    $holder = $this->holdEvidenceLock($id); // an erase in flight

    $t0 = microtime(true);
    $res = $ctl->resendEvidence(['link_token' => $token]);
    $elapsed = microtime(true) - $t0;

    $this->assertSame(['ok' => true, 'pushed' => false], $res);
    $this->assertLessThan(3.0, $elapsed, 'resend must not wait on the lock');
    $this->assertNull($this->pdo->query('SELECT evidence_object_key FROM waiver_responses WHERE id='.$responseId)->fetchColumn(), 'nothing uploaded/recorded');
    $this->assertSame($pdfPath, $this->pdo->query('SELECT pdf_path FROM waiver_responses WHERE id='.$responseId)->fetchColumn());
    $this->assertFileExists($pdfPath);
    $this->assertCount(0, $this->auditRows($id, 'evidence_resent'));

    // Lock gone -> the same call now pushes (non-vacuity).
    $this->releaseEvidenceLock($holder, $id);
    $this->assertSame(['ok' => true, 'pushed' => true], $ctl->resendEvidence(['link_token' => $token]));
  }

  public function testEraseDeletesNothingAndAnswersEvidenceBusyWhileAResendHoldsTheLock(): void {
    $cfg = $this->cfg;
    $cfg['evidence_lock'] = ['erase_wait_seconds' => 1];
    $ctl = new WaiverController($cfg, TestDatabase::connect());
    [$id, $token, $pdfPath, $sigPath] = $this->seedCompletedWithRetainedFiles();
    $holder = $this->holdEvidenceLock($id); // a resend in flight

    $this->assertSame(['error' => 'evidence_busy'], $ctl->eraseWaiver(['link_tokens' => [$token]]));
    $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_instances')->fetchColumn(), 'nothing erased: never a partial erasure');
    $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses')->fetchColumn());
    $this->assertFileExists($pdfPath);
    $this->assertFileExists($sigPath);

    $this->releaseEvidenceLock($holder, $id);
    $erase = $ctl->eraseWaiver(['link_tokens' => [$token]]);
    $this->assertSame(1, $erase['instances_deleted'] ?? null, json_encode($erase));
    $this->assertSame(2, $erase['files_deleted']);
    $this->assertFileDoesNotExist($pdfPath);
  }

  // =========================================================================
  // [gate 89-M4 r2 P1] submitGuestForm's FIRST upload + record is serialized
  // against erase_waiver by the same evidence lock
  // =========================================================================

  public function testAnEraseFiredWhileASubmitUploadIsInFlightGetsEvidenceBusyAndTheSubmitCompletes(): void {
    // Same deterministic race as the resend case: the relay stand-in fires a
    // real eraseWaiver() from its own DB session INSIDE the submit's upload.
    $racePort = $this->startRouterServer(__DIR__.'/fixtures/mock-bookingv2-erase-race.php', 21400);
    $capturePort = $this->startCaptureServer();
    $ctl = $this->controllerWith($capturePort, 'http://127.0.0.1:'.$racePort.'/api/waiver/evidence?mode=erase-via-controller');
    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], 3600);

    $res = $ctl->submitGuestForm($token, ['full_name' => 'Racing Signer', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);

    $race = $this->readRaceCapture($racePort);
    $this->assertSame($token, $race['link_token'] ?? null, 'the relay must have received this upload');
    $this->assertSame(['error' => 'evidence_busy'], $race['erase'] ?? $race, 'an erase racing an in-flight submit upload must NOT interleave with it');
    $this->assertTrue($res['ok'] ?? false, json_encode($res));
    $this->assertSame('waiver-evidence/race-fixture/999/pdf.pdf', $this->pdo->query('SELECT evidence_object_key FROM waiver_responses WHERE waiver_instance_id='.$id)->fetchColumn());
    $webhook = $this->readCapture($capturePort, '/api/waiver/public-complete');
    $this->assertNotNull($webhook, 'the completion webhook still fires');
    $this->assertSame('waiver-evidence/race-fixture/999/pdf.pdf', json_decode($webhook['body'], true)['evidence_object_key']);

    // The lock was released when the submit returned (another session gets it at once)...
    $holder = $this->holdEvidenceLock($id);
    $this->releaseEvidenceLock($holder, $id);
    // ...and the retried erase reaches everything the submit recorded.
    $erase = $ctl->eraseWaiver(['link_tokens' => [$token]]);
    $this->assertSame(1, $erase['instances_deleted'] ?? null, json_encode($erase));
    $this->assertSame(1, $erase['responses_deleted']);
    $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM audit_events WHERE entity_type IN ('instance','response') AND entity_id=".$id)->fetchColumn());
  }

  public function testASubmitWhoseInstanceIsDeletedMidUploadBypassingTheLockPersistsNothing(): void {
    // Belt-and-suspenders (reservation-bound instance: the lock covers both
    // kinds): a deletion that bypasses the evidence lock lands INSIDE the
    // upload. There is no FK from waiver_responses to waiver_instances, so an
    // unconditional INSERT would leave an orphan PII row nothing can erase.
    $racePort = $this->startRouterServer(__DIR__.'/fixtures/mock-bookingv2-erase-race.php', 21400);
    $capturePort = $this->startCaptureServer();
    $ctl = $this->controllerWith($capturePort, 'http://127.0.0.1:'.$racePort.'/api/waiver/evidence?mode=delete-bypassing-lock');
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token]);
    $filesBefore = $this->storageFiles();

    $res = $ctl->submitGuestForm($token, ['full_name' => 'Vanishing Signer', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);

    $this->assertSame($id, $this->readRaceCapture($racePort)['deleted_instance_id'] ?? null, 'fixture must have deleted the instance mid-upload');
    $this->assertSame(['error' => 'Invalid link'], $res);
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses')->fetchColumn(), 'no orphan waiver_responses row');
    $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM audit_events WHERE entity_type IN ('instance','response') AND entity_id=".$id)->fetchColumn(), 'no orphan audit event');
    $this->assertSame($filesBefore, $this->storageFiles(), 'no local evidence file left behind');
    $this->assertNull($this->readCapture($capturePort, '/api/waiver/complete'), 'no completion webhook for an erased instance');
  }

  public function testASubmitWhoseInstanceIsDeletedMidUploadLogsAnOpaqueOrphanMarkerForTheAlreadyUploadedEvidence(): void {
    // [gate 89-M4 r3 Codex P1-2] Same race and fixture as
    // testASubmitWhoseInstanceIsDeletedMidUploadBypassingTheLockPersistsNothing
    // above: mock-bookingv2-erase-race.php's delete-bypassing-lock mode
    // ALWAYS answers success (a real blob_key), so by the time the
    // commit-time existence check rolls the local row back, BookingV2 is
    // already holding a genuine blob this fork has NO pointer to and NO way
    // to delete (no endpoint/credentials for it). The two things the fork
    // CAN do -- never claim the upload as a success, and leave an opaque,
    // non-PII trace of the orphan instead of silently losing track of it --
    // are what this test pins.
    $racePort = $this->startRouterServer(__DIR__.'/fixtures/mock-bookingv2-erase-race.php', 21400);
    $capturePort = $this->startCaptureServer();
    $ctl = $this->controllerWith($capturePort, 'http://127.0.0.1:'.$racePort.'/api/waiver/evidence?mode=delete-bypassing-lock');
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token]);

    $logFile = tempnam(sys_get_temp_dir(), 'waiver_orphan_evidence_');
    $prevLog = ini_get('error_log');
    ini_set('error_log', $logFile);
    try {
      $res = $ctl->submitGuestForm($token, ['full_name' => 'Vanishing Signer', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);
    } finally {
      ini_set('error_log', $prevLog === false ? '' : $prevLog);
    }
    $log = (string)file_get_contents($logFile);
    @unlink($logFile);

    $this->assertSame(['error' => 'Invalid link'], $res, 'must never claim the upload as a success');
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses')->fetchColumn(), 'no row anywhere persists the uploaded blob key');
    $this->assertStringContainsString('[WAIVER-EVIDENCE-ORPHANED]', $log, 'the already-uploaded blob must leave an opaque orphan marker');
    $this->assertStringContainsString('waiver_instance_id='.$id, $log);
    $this->assertStringContainsString('evidence_object_key=waiver-evidence/race-fixture/999/pdf.pdf', $log, 'the marker must name the actual orphaned blob key');
  }

  public function testASubmitThatCannotTakeTheEvidenceLockStillCompletesAndLeavesTheEvidenceToResend(): void {
    $capturePort = $this->startCaptureServer();
    $ctl = $this->controllerWith($capturePort, null, ['submit_wait_seconds' => 0]);
    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], 3600);
    $holder = $this->holdEvidenceLock($id); // held elsewhere past the submit's wait

    $res = $ctl->submitGuestForm($token, ['full_name' => 'Busy Lock Signer', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);

    $this->assertTrue($res['ok'] ?? false, 'the signature is never lost: '.json_encode($res));
    $this->assertSame('completed', $this->instanceRow($token)['status']);
    $row = $this->pdo->query('SELECT * FROM waiver_responses WHERE waiver_instance_id='.$id)->fetch();
    $this->assertNull($row['evidence_object_key'], 'nothing uploaded without the lock');
    $this->assertNull($row['evidence_sha256']);
    $this->assertNotNull($row['pdf_path']);
    $this->assertNotNull($row['signature_path']);
    $this->tempFiles[] = $row['pdf_path'];
    $this->tempFiles[] = $row['signature_path'];
    $this->assertFileExists($row['pdf_path'], 'evidence retained locally, pointer persisted');
    $this->assertFileExists($row['signature_path']);
    $retained = $this->auditRows($id, 'evidence_retained_locally');
    $this->assertCount(1, $retained);
    $this->assertSame('evidence_lock_busy', json_decode($retained[0]['meta_json'], true)['deferred'] ?? null);
    $webhook = $this->readCapture($capturePort, '/api/waiver/public-complete');
    $this->assertNotNull($webhook, 'the completion is still reported');
    $this->assertNull(json_decode($webhook['body'], true)['evidence_object_key']);

    // Once the lock is free, resend_evidence pushes the retained evidence.
    $this->releaseEvidenceLock($holder, $id);
    $this->assertSame(['ok' => true, 'pushed' => true], $ctl->resendEvidence(['link_token' => $token]));
    $this->assertSame('waiver-evidence/capture-fixture/999/pdf.pdf', $this->pdo->query('SELECT evidence_object_key FROM waiver_responses WHERE waiver_instance_id='.$id)->fetchColumn());
    $this->assertFileDoesNotExist($row['pdf_path']);
  }

  public function testASubmitWaitingOutAnEraseThatDeletesTheInstanceUploadsNothingAndPersistsNothing(): void {
    // A second session holds the evidence lock (an erase in flight) while
    // this submit claims and waits; it then deletes the instance and
    // releases. The submit must see that under the lock and stop BEFORE any
    // upload -- a blob pushed now would have no pointer anywhere.
    $racePort = $this->startRouterServer(__DIR__.'/fixtures/mock-bookingv2-erase-race.php', 21400);
    $capturePort = $this->startCaptureServer();
    $ctl = $this->controllerWith($capturePort, 'http://127.0.0.1:'.$racePort.'/api/waiver/evidence?mode=record-only', ['submit_wait_seconds' => 10]);
    @unlink(sys_get_temp_dir().'/waiver-race-capture-'.$racePort.'.json'); // no stale capture from an earlier test
    [, $versionId] = $this->seedTemplateWithDob();
    $token = self::uuid();
    $id = TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token], 3600);
    $filesBefore = $this->storageFiles();
    $ready = $this->startConcurrentSession('hold-lock-then-delete', $id, 1500);

    $t0 = microtime(true);
    $res = $ctl->submitGuestForm($token, ['full_name' => 'Erased While Waiting', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);
    $elapsed = microtime(true) - $t0;

    $this->awaitConcurrentSession($ready);
    $this->assertSame(['error' => 'Invalid link'], $res);
    $this->assertGreaterThan(0.5, $elapsed, 'the submit waited for the lock instead of falling back');
    $this->assertFileDoesNotExist(sys_get_temp_dir().'/waiver-race-capture-'.$racePort.'.json', 'no evidence upload was attempted');
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses')->fetchColumn());
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_instances')->fetchColumn());
    $this->assertSame($filesBefore, $this->storageFiles(), 'no local evidence file created');
    $this->assertNull($this->readCapture($capturePort, '/api/waiver/public-complete'));
  }

  public function testEraseReadsResponsePathsWithALockingReadSoAnUnlockedWriterCannotStrandItsFiles(): void {
    // A writer WITHOUT the evidence lock (a submit on its lock-busy path) has
    // inserted a response row pointing at a retained file but not committed
    // yet when the erase starts. A snapshot read of the paths would miss the
    // row while the DELETE (a current read) still removes it: file stranded.
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);
    $pdfPath = sys_get_temp_dir().'/erase-locking-read-'.getmypid().'-'.$id.'.pdf';
    file_put_contents($pdfPath, '%PDF-1.4 retained by an unlocked writer');
    $this->tempFiles[] = $pdfPath;
    $ready = $this->startConcurrentSession('uncommitted-response', $id, 1500, $pdfPath);

    $erase = $this->ctl->eraseWaiver(['link_tokens' => [$token]]);

    $this->awaitConcurrentSession($ready);
    $this->assertSame(1, $erase['responses_deleted'] ?? null, json_encode($erase));
    $this->assertSame(1, $erase['files_deleted'], 'the erase saw the committed row and removed its file');
    $this->assertFileDoesNotExist($pdfPath);
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses')->fetchColumn());
  }

  public function testEraseWaiverPinsRepeatableReadSoAnUnlockedInsertCannotLandWhileItsTransactionIsStillOpen(): void {
    // [gate 89-M4 r3 P2-1] The "an unlocked writer either commits first (and
    // is seen by the FOR UPDATE paths-read) or waits for this erasure to
    // finish" guarantee depends on REPEATABLE READ's gap locking: a
    // SELECT ... FOR UPDATE over a waiver_instance_id with NO existing
    // response row takes a gap lock under RR (blocking a concurrent INSERT
    // for that id) but NONE at all under READ COMMITTED. MySQL defaults to
    // RR, so force the SAME connection eraseWaiver() runs on to a READ
    // COMMITTED session default FIRST -- the finding's precondition -- so the
    // ONLY thing standing between this erasure and the race is
    // WaiverController's own per-transaction `SET TRANSACTION ISOLATION
    // LEVEL REPEATABLE READ` pin. Uses the afterErasePathsReadForTesting()
    // test seam to land the race deterministically instead of a real-clock
    // timing gamble.
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);
    // No response row exists yet -- the "zero rows read" precondition the
    // gap lock (or its absence) is about.

    $db = TestDatabase::connect();
    $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $ctl = new class($this->cfg, $db) extends WaiverController {
      public $onAfterPathsRead = null;
      protected function afterErasePathsReadForTesting(array $instanceIds, array $paths, \PDO $pdo): void {
        if ($this->onAfterPathsRead) { ($this->onAfterPathsRead)($instanceIds, $paths, $pdo); }
      }
    };

    $landedFile = sys_get_temp_dir().'/wv-p21-landed-'.getmypid().'-'.bin2hex(random_bytes(4));
    $retainedPdf = sys_get_temp_dir().'/wv-p21-race-'.getmypid().'.pdf';
    file_put_contents($retainedPdf, '%PDF-1.4 unlocked race writer');
    $this->tempFiles[] = $retainedPdf;
    $this->tempFiles[] = $landedFile;
    $this->tempFiles[] = $landedFile.'.connected';
    $this->tempFiles[] = $landedFile.'.done';
    $this->tempFiles[] = $landedFile.'.error';

    $ctl->onAfterPathsRead = function (array $instanceIds, array $paths, \PDO $eraseOwnPdo) use ($id, $retainedPdf, $landedFile) {
      $this->assertSame([$id], $instanceIds);
      $this->assertSame([], $paths, 'must read zero response rows before the race writer fires -- otherwise this is not the scenario under test');
      $proc = proc_open(
        [PHP_BINARY, __DIR__.'/fixtures/concurrent-db-session.php', 'insert-response-bypassing-lock', (string)$id, '0', $landedFile, $retainedPdf],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, __DIR__
      );
      $this->assertIsResource($proc, 'could not spawn the race writer');
      fclose($pipes[0]);
      $this->relayProcs[] = $proc;
      // Wait out the WRITER PROCESS'S OWN startup cost (PHP boot, autoload,
      // DB connect -- unrelated to what is under test and can vary under
      // load) via its '.connected' checkpoint, written BEFORE it attempts
      // the INSERT, rather than folding that variance into a single
      // fixed sleep. Then a short, fixed grace period: an UNBLOCKED insert
      // (the bug -- no isolation pin) is one autocommit statement over an
      // already-open connection and finishes in low single-digit
      // milliseconds; a BLOCKED one (the fix) cannot finish inside it no
      // matter how long we wait here -- only this transaction's own commit
      // below releases the gap lock holding it back.
      $connectedDeadline = microtime(true) + 5.0;
      while (!is_file($landedFile.'.connected') && !is_file($landedFile.'.error') && microtime(true) < $connectedDeadline) usleep(10000);
      $this->assertFileDoesNotExist($landedFile.'.error', (string)@file_get_contents($landedFile.'.error'));
      $this->assertFileExists($landedFile.'.connected', 'the race writer never even reached its DB connection in time');
      usleep(300000);
    };

    $erase = $ctl->eraseWaiver(['link_tokens' => [$token]]);

    $this->assertFileDoesNotExist($landedFile, 'the isolation pin must force the unlocked writer to wait for this transaction to end, not land while it is still open having read zero rows');
    $this->assertSame(0, $erase['responses_deleted'] ?? null, json_encode($erase));
    $this->assertSame(0, $erase['files_deleted'] ?? null, json_encode($erase));
    $this->assertSame(1, $erase['instances_deleted'] ?? null, json_encode($erase));

    // Let the now-unblocked writer finish and confirm it landed safely --
    // AFTER this erasure had already committed, never observed by it (no
    // stranded file, no rowCount-mismatch abort).
    $deadline = microtime(true) + 5.0;
    while (!is_file($landedFile) && microtime(true) < $deadline) usleep(20000);
    $this->assertFileExists($landedFile, 'the writer must eventually land once the transaction above released the gap lock');
    $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses WHERE waiver_instance_id='.$id)->fetchColumn());
  }

  public function testEraseWaiverAbortsAndLogsLoudlyIfADeleteEverRemovesMoreResponseRowsThanItsOwnPathsReadJustSaw(): void {
    // [gate 89-M4 r3 P2-1] The rowCount(DELETE) === count(paths read) check
    // is a defense-in-depth belt beside the isolation pin above: whatever
    // the cause, if the two ever disagree, reporting success would risk a
    // stranded file. Proving it fires does NOT need a real race or an
    // isolation-level nuance -- it needs "the DELETE sees a row the
    // paths-read never did", which the afterErasePathsReadForTesting() test
    // seam can inject directly, deterministically, on the SAME
    // connection/transaction erase itself is using.
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);

    $ctl = new class($this->cfg, TestDatabase::connect()) extends WaiverController {
      public $onAfterPathsRead = null;
      protected function afterErasePathsReadForTesting(array $instanceIds, array $paths, \PDO $pdo): void {
        if ($this->onAfterPathsRead) { ($this->onAfterPathsRead)($instanceIds, $paths, $pdo); }
      }
    };
    $ctl->onAfterPathsRead = function (array $instanceIds, array $paths, \PDO $eraseOwnPdo) use ($id) {
      $this->assertSame([], $paths, 'must read zero response rows before this injection -- otherwise this is not the scenario under test');
      // Same connection/transaction erase itself is using -- deterministic,
      // no concurrency needed -- simulating "the DELETE sees a row the
      // paths-read never did".
      $eraseOwnPdo->prepare('INSERT INTO waiver_responses (waiver_instance_id, answers_json, signed_at, hash_sha256, created_at) VALUES (?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())')
        ->execute([$id, json_encode(['full_name' => 'Mismatch Injection']), hash('sha256', 'mismatch')]);
    };

    $logFile = tempnam(sys_get_temp_dir(), 'waiver_erase_mismatch_');
    $prevLog = ini_get('error_log');
    ini_set('error_log', $logFile);
    $threw = null;
    try {
      $ctl->eraseWaiver(['link_tokens' => [$token]]);
    } catch (\Throwable $e) {
      $threw = $e;
    } finally {
      ini_set('error_log', $prevLog === false ? '' : $prevLog);
    }
    $log = (string)file_get_contents($logFile);
    @unlink($logFile);

    $this->assertInstanceOf(\RuntimeException::class, $threw, 'a paths/rows mismatch must abort the erasure loudly, never report success over it');
    $this->assertStringContainsString('erase paths/rows mismatch', $threw->getMessage());
    $this->assertStringContainsString('[WAIVER-ERASE-PATHS-MISMATCH]', $log);
    $this->assertStringContainsString('paths_read=0', $log);
    $this->assertStringContainsString('responses_deleted=1', $log);
    // The WHOLE erasure rolled back: nothing committed, including the
    // injected row -- an abort, not a partial erasure.
    $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_instances WHERE id='.$id)->fetchColumn(), 'instance must still exist -- the whole erasure rolled back');
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_responses WHERE waiver_instance_id='.$id)->fetchColumn(), 'the injected row itself rolled back too');
  }

  public function testASubmitOnTheLockBusyFallbackSkipsTheWebhookAndLeavesNoOrphanAuditWhenErasedRightAfterCommit(): void {
    // [gate 89-M4 r3 Codex P1-1 / Fable P2-2] On the lock-busy fallback
    // ($evidenceLocked === false) nothing serializes this request against a
    // concurrent erase after the guarded commit -- the completion webhook
    // and the retained-evidence audit row used to run unconditionally,
    // unlocked, afterwards. Uses the afterSubmitCommitForTesting() test seam
    // to land a bypass-the-lock erasure deterministically in that exact
    // window (a real-clock race would make this test flaky either way).
    $capturePort = $this->startCaptureServer();
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$capturePort;
    $cfg['callback']['evidence_url'] = null;
    $cfg['evidence_lock'] = ['submit_wait_seconds' => 0];
    $ctl = new class($cfg, TestDatabase::connect()) extends WaiverController {
      public $onAfterCommit = null;
      protected function afterSubmitCommitForTesting(int $instanceId): void {
        if ($this->onAfterCommit) { ($this->onAfterCommit)($instanceId); }
      }
    };
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token]);
    $holder = $this->holdEvidenceLock($id); // forces $evidenceLocked=false the whole call
    $filesBefore = $this->storageFiles();

    $hookFired = false;
    $ctl->onAfterCommit = function (int $instanceId) use (&$hookFired) {
      $hookFired = true;
      // A bypass-the-lock erasure (raw SQL, no evidence lock -- the SAME
      // shape mock-bookingv2-erase-race.php's delete-bypassing-lock mode
      // uses), landing in the exact gap between the guarded commit and the
      // webhook/audit that used to follow it unconditionally.
      $this->pdo->prepare('DELETE FROM waiver_responses WHERE waiver_instance_id=?')->execute([$instanceId]);
      $this->pdo->prepare("DELETE FROM audit_events WHERE entity_type IN ('instance','response') AND entity_id=?")->execute([$instanceId]);
      $this->pdo->prepare('DELETE FROM waiver_instances WHERE id=?')->execute([$instanceId]);
    };

    $logFile = tempnam(sys_get_temp_dir(), 'waiver_webhook_skip_');
    $prevLog = ini_get('error_log');
    ini_set('error_log', $logFile);
    try {
      $res = $ctl->submitGuestForm($token, ['full_name' => 'Erased Right After Commit', 'dob' => self::dobForAge(30), 'signature_data' => self::onePixelPngDataUri()]);
    } finally {
      ini_set('error_log', $prevLog === false ? '' : $prevLog);
    }
    $this->releaseEvidenceLock($holder, $id);
    $log = (string)file_get_contents($logFile);
    @unlink($logFile);

    $this->assertTrue($hookFired, 'the test seam must have fired for this assertion to be meaningful');
    $this->assertTrue($res['ok'] ?? false, 'the guest-facing result must still report success -- the row genuinely committed before the erasure ran: '.json_encode($res));
    $this->assertStringContainsString('[WAIVER-WEBHOOK-SKIPPED-ERASED]', $log, 'the deferred webhook must log why it did not send');
    $this->assertStringContainsString('waiver_instance_id='.$id, $log);
    $this->assertNull($this->readCapture($capturePort, '/api/waiver/complete'), 'no completion webhook (full PII) for an instance erased right after commit');
    $this->assertSame(0, (int)$this->pdo->query(
      "SELECT COUNT(*) FROM audit_events WHERE entity_type IN ('instance','response') AND entity_id=".$id
    )->fetchColumn(), 'no orphan audit row (submitted or evidence_retained_locally) survives the erasure');

    // Clean up whatever local files this run retained (the row that would
    // have named them was deleted by the hook before the happy-path cleanup
    // could reference it).
    foreach (array_diff($this->storageFiles(), $filesBefore) as $leftover) { $this->tempFiles[] = $leftover; }
  }

  // =========================================================================
  // helpers
  // =========================================================================

  /** A controller whose callback posts to the capture server; evidence to $evidenceUrl (null = derived from base_url). */
  private function controllerWith(int $capturePort, ?string $evidenceUrl, array $evidenceLock = []): WaiverController {
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$capturePort;
    $cfg['callback']['evidence_url'] = $evidenceUrl;
    if ($evidenceLock) $cfg['evidence_lock'] = $evidenceLock;
    return new WaiverController($cfg, TestDatabase::connect());
  }

  /** @return list<string> every file currently in the test storage dirs (signatures + artifacts). */
  private function storageFiles(): array {
    $files = [];
    foreach ([$this->cfg['storage']['signatures_path'], $this->cfg['storage']['artifacts_path']] as $dir) {
      foreach (glob($dir.'/*') ?: [] as $f) $files[] = $f;
    }
    sort($files);
    return $files;
  }

  /**
   * Start tests/fixtures/concurrent-db-session.php (a second MySQL session in
   * its own process) and wait until its first move is in place. Returns the
   * ready-file path for awaitConcurrentSession().
   */
  private function startConcurrentSession(string $mode, int $instanceId, int $holdMs, ?string $pdfPath = null): string {
    $ready = sys_get_temp_dir().'/wv-concurrent-'.getmypid().'-'.bin2hex(random_bytes(4));
    $args = [PHP_BINARY, __DIR__.'/fixtures/concurrent-db-session.php', $mode, (string)$instanceId, (string)$holdMs, $ready];
    if ($pdfPath !== null) $args[] = $pdfPath;
    $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    $this->assertIsResource($proc, 'could not spawn the concurrent DB session');
    fclose($pipes[0]);
    $this->relayProcs[] = $proc;
    $this->tempFiles[] = $ready;
    $this->tempFiles[] = $ready.'.done';
    $this->tempFiles[] = $ready.'.error';
    $deadline = microtime(true) + 10.0;
    while (!is_file($ready) && !is_file($ready.'.error') && microtime(true) < $deadline) usleep(20000);
    $this->assertFileDoesNotExist($ready.'.error', (string)@file_get_contents($ready.'.error'));
    $this->assertFileExists($ready, 'the concurrent DB session never got into position');
    return $ready;
  }

  private function awaitConcurrentSession(string $ready): void {
    $deadline = microtime(true) + 15.0;
    while (!is_file($ready.'.done') && !is_file($ready.'.error') && microtime(true) < $deadline) usleep(20000);
    $this->assertFileDoesNotExist($ready.'.error', (string)@file_get_contents($ready.'.error'));
    $this->assertFileExists($ready.'.done', 'the concurrent DB session did not finish');
  }

  /**
   * A completed (reservation-bound) instance whose first evidence upload never
   * confirmed: response row with pdf_path/signature_path pointing at real
   * retained files and no evidence_object_key -- the one state resend pushes.
   * @return array{0:int,1:string,2:string,3:string,4:int} [instance id, token, pdf, sig, response id]
   */
  private function seedCompletedWithRetainedFiles(): array {
    [, $versionId] = $this->seedTemplate();
    $token = self::uuid();
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);
    $responseId = TestDatabase::seedResponse($this->pdo, $id, ['full_name' => 'Retained Guest']);
    $pdfPath = sys_get_temp_dir().'/resend-race-'.getmypid().'-'.$id.'.pdf';
    $sigPath = sys_get_temp_dir().'/resend-race-'.getmypid().'-'.$id.'.png';
    file_put_contents($pdfPath, '%PDF-1.4 retained bytes');
    file_put_contents($sigPath, "\x89PNG\r\n\x1a\nretained sig");
    $this->tempFiles[] = $pdfPath;
    $this->tempFiles[] = $sigPath;
    $this->pdo->prepare('UPDATE waiver_responses SET pdf_path=?, signature_path=? WHERE id=?')->execute([$pdfPath, $sigPath, $responseId]);
    return [$id, $token, $pdfPath, $sigPath, $responseId];
  }

  private function controllerWithEvidenceUrl(int $port, string $mode): WaiverController {
    $cfg = $this->cfg;
    $cfg['callback']['base_url'] = 'http://127.0.0.1:'.$port;
    $cfg['callback']['evidence_url'] = 'http://127.0.0.1:'.$port.'/api/waiver/evidence?mode='.$mode;
    return new WaiverController($cfg, TestDatabase::connect());
  }

  /** @return array<string,mixed> */
  private function readRaceCapture(int $port): array {
    $file = sys_get_temp_dir().'/waiver-race-capture-'.$port.'.json';
    $this->assertFileExists($file, 'the race relay stand-in was never called');
    $decoded = json_decode((string)file_get_contents($file), true);
    @unlink($file);
    $this->assertIsArray($decoded);
    $this->assertArrayNotHasKey('exception', $decoded, (string)($decoded['exception'] ?? ''));
    return $decoded;
  }

  /**
   * Take an instance's evidence lock from a SEPARATE DB session -- standing
   * in for an erase (or resend) in flight in another request. Mirrors
   * WaiverController::acquireEvidenceLock()'s name on purpose; the name-
   * agnostic proof is the erase-race test above (controller vs controller).
   */
  private function holdEvidenceLock(int $instanceId): \PDO {
    $other = TestDatabase::connect()->pdo();
    $q = $other->prepare("SELECT GET_LOCK(CONCAT('wvr_evidence:', LEFT(SHA1(DATABASE()), 16), ':', ?), 0)");
    $q->execute([(string)$instanceId]);
    $this->assertSame(1, (int)$q->fetchColumn(), 'test setup: could not take the evidence lock');
    return $other;
  }

  private function releaseEvidenceLock(\PDO $holder, int $instanceId): void {
    $q = $holder->prepare("SELECT RELEASE_LOCK(CONCAT('wvr_evidence:', LEFT(SHA1(DATABASE()), 16), ':', ?))");
    $q->execute([(string)$instanceId]);
    $this->assertSame(1, (int)$q->fetchColumn());
  }

  /**
   * The default template for this suite: a published version WITH a
   * date-of-birth (type=date) field -- the only kind create_public_instance
   * accepts since the adults-only gate fails closed [gate 89-M4 P1]. Same
   * `full_name` first field and 'Test Waiver' title the render tests pin.
   * @return array{0:int,1:int} [template_id, published version id]
   */
  private function seedTemplate(): array {
    return $this->seedTemplateWithFields('Test Waiver', [
      ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
      ['key' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'required' => true],
    ]);
  }

  /**
   * [gate 89-M4 P1] A published version with NO type=date field (the shape
   * TestDatabase::seedPublishedTemplateVersion() and the fork's other suites
   * use): adults-only cannot be enforced against it.
   * @return array{0:int,1:int} [template_id, published version id]
   */
  private function seedTemplateWithoutDob(): array {
    $versionId = TestDatabase::seedPublishedTemplateVersion($this->pdo);
    $templateId = (int)$this->pdo->query('SELECT template_id FROM waiver_template_versions WHERE id='.(int)$versionId)->fetchColumn();
    return [$templateId, $versionId];
  }

  /** @return array{0:int,1:int} [template_id, published version id] */
  private function seedTemplateWithFields(string $title, array $fields): array {
    $this->pdo->prepare('INSERT INTO waiver_templates (name, is_active, created_by, created_at, updated_at) VALUES (?,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
      ->execute([$title.' template']);
    $templateId = (int)$this->pdo->lastInsertId();
    $this->pdo->prepare('INSERT INTO waiver_template_versions (template_id, version, title, fields_json, requires_signature, created_by, created_at, is_published, published_at) VALUES (?,1,?,?,1,1,UTC_TIMESTAMP(),1,UTC_TIMESTAMP())')
      ->execute([$templateId, $title, json_encode($fields)]);
    return [$templateId, (int)$this->pdo->lastInsertId()];
  }

  /**
   * [89-M4.3] seedTemplate()'s template (via seedPublishedTemplateVersion())
   * carries only a `full_name` text field -- no `date` field for
   * evaluateAgeGate() to find, so age-gate behavior can't be exercised
   * against it. This variant adds a `dob` (type=date) field and a
   * `parental_consent_name` (type=parental_consent) field, mirroring the
   * production template shape the reservation-bound minor-consent flow
   * already relies on.
   * @return array{0:int,1:int} [template_id, published version id]
   */
  private function seedTemplateWithDob(): array {
    $this->pdo->prepare('INSERT INTO waiver_templates (name, is_active, created_by, created_at, updated_at) VALUES (?,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
      ->execute(['Test Template With DOB']);
    $templateId = (int)$this->pdo->lastInsertId();
    $fields = json_encode([
      ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
      ['key' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'required' => true],
      ['key' => 'parental_consent_name', 'label' => 'Parent/guardian name', 'type' => 'parental_consent', 'required' => false],
    ]);
    $this->pdo->prepare('INSERT INTO waiver_template_versions (template_id, version, title, fields_json, requires_signature, created_by, created_at, is_published, published_at) VALUES (?,1,?,?,1,1,UTC_TIMESTAMP(),1,UTC_TIMESTAMP())')
      ->execute([$templateId, 'Test Waiver With DOB', $fields]);
    return [$templateId, (int)$this->pdo->lastInsertId()];
  }

  /** A Y-m-d date of birth putting the signer's computed age at exactly $age today (UTC). */
  private static function dobForAge(int $age): string {
    return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
      ->modify('-'.$age.' years')
      ->modify('-1 day')
      ->format('Y-m-d');
  }

  /** A well-known minimal valid 1x1 transparent PNG data URI (passes submitGuestForm's signature-magic-bytes check). */
  private static function onePixelPngDataUri(): string {
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
  }

  private function validPayload(int $templateId, string $token, array $overrides = []): array {
    return array_merge([
      'action' => 'create_public_instance',
      'template_id' => (string)$templateId,
      'link_token' => $token,
      'locale' => 'ro',
      'expires_at' => self::isoIn(7200),
    ], $overrides);
  }

  /** ISO-8601 UTC instant $seconds from now, in Date#toISOString() form. */
  private static function isoIn(int $seconds): string {
    return gmdate('Y-m-d\TH:i:s', time() + $seconds).'.000Z';
  }

  /** A random RFC 4122 v4 UUID -- what BookingV2's mintWaiverToken() produces. */
  private static function uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
  }

  private function instanceRow(string $token): array {
    $q = $this->pdo->prepare('SELECT * FROM waiver_instances WHERE link_token=?');
    $q->execute([$token]);
    $row = $q->fetch();
    $this->assertIsArray($row, 'instance for token must exist');
    return $row;
  }

  private function countByToken(string $token): int {
    $q = $this->pdo->prepare('SELECT COUNT(*) FROM waiver_instances WHERE link_token=?');
    $q->execute([$token]);
    return (int)$q->fetchColumn();
  }

  private function auditRows(int $instanceId, string $event): array {
    $q = $this->pdo->prepare("SELECT * FROM audit_events WHERE entity_type='instance' AND entity_id=? AND event=?");
    $q->execute([$instanceId, $event]);
    return $q->fetchAll();
  }

  /**
   * [89-M4.3/M4.4] Starts a `php -S` subprocess serving
   * tests/fixtures/mock-bookingv2-capture.php -- a stand-in for BookingV2's
   * evidence relay (POST /api/waiver/evidence, fixed blob-key response) AND
   * its two completion webhooks (POST /api/waiver/complete and
   * POST /api/waiver/public-complete, captured verbatim to disk -- see
   * readCapture()). Registered in $this->relayProcs for teardown. Same
   * technique as WaiverControllerSubmitEvidenceTest::startMockRelay().
   */
  private function startCaptureServer(): int {
    return $this->startRouterServer(__DIR__.'/fixtures/mock-bookingv2-capture.php', 20500);
  }

  /**
   * Start `php -S` on 127.0.0.1:<$basePort + pid % 400> with $router, wait
   * until it accepts connections (FAILS, never skips, if it does not), and
   * register it for teardown. Distinct $basePort ranges keep servers of
   * different kinds from colliding within one run: 20500 capture, 20950 dead
   * port, 21400 erase-race relay.
   */
  private function startRouterServer(string $router, int $basePort): int {
    $port = $basePort + (getmypid() % 400);
    $this->assertFileExists($router);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, $router], $descriptors, $pipes, __DIR__);
    $this->assertIsResource($proc, 'could not spawn the `php -S` subprocess for '.basename($router));
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $this->relayProcs[] = $proc;

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
      $conn = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $errstr, 0.1);
      if ($conn !== false) {
        fclose($conn);
        return $port;
      }
      usleep(20000);
    }
    $this->fail('`php -S` '.basename($router).' did not start listening on port '.$port.' within 5s');
  }

  /**
   * Reads back the JSON body notifyBookingV2Completion() (or uploadEvidence())
   * posted to `$path` on the given capture-server port -- see
   * mock-bookingv2-capture.php's naming convention -- and deletes the file
   * (so a test asserting "no post to the OTHER endpoint" that runs before
   * one asserting "did post to THIS endpoint" can't see a stale capture from
   * an earlier test reusing the same PID-derived port). Returns null when
   * nothing was captured at that path.
   * @return array{path:string,body:string}|null
   */
  private function readCapture(int $port, string $path): ?array {
    $file = sys_get_temp_dir().'/waiver-webhook-capture-'.$port.'-'.sha1($path).'.json';
    if (!is_file($file)) return null;
    $decoded = json_decode((string)file_get_contents($file), true);
    @unlink($file);
    return is_array($decoded) ? $decoded : null;
  }

  /**
   * A port NOTHING is listening on, for the "relay still down" case -- a
   * distinct range from startCaptureServer()'s so the two never collide
   * within one test run (mirrors WaiverControllerSubmitEvidenceTest's
   * reserveDeadPort()).
   */
  private function reserveDeadPort(): int {
    return 20950 + (getmypid() % 400);
  }
}
