<?php
namespace Tests;

use App\TemplateSeed;
use PHPUnit\Framework\TestCase;

/**
 * [GVS-58 follow-up] Micro-harness for the staging waiver-template repair.
 *
 * Four properties, each of which would silently make the repair useless if it
 * regressed — the reason every one of them is pinned rather than eyeballed:
 *
 *  1. THE ID SURVIVES. `waiver_templates.id` is BIGINT AUTO_INCREMENT
 *     (migrations/001_init.sql), so an import into an empty database lands at
 *     id 1 while BookingV2 staging still points at "2". A seed that "worked"
 *     but renumbered would leave staging exactly as broken with a perfectly
 *     good template sitting right there. Pinned by
 *     testSeedPreservesTheExplicitTemplateId + ...ForThatExactIdOnly.
 *
 *  2. THE PUBLISH GATE FLIPS. hasPublishedVersion() answers false with HTTP 200
 *     both for a missing template AND for one whose versions are all drafts, so
 *     "the row is there" proves nothing. Pinned against the EXACT query
 *     WaiverController runs.
 *
 *  3. LOCK 2 — INSERT-ONLY-IF-ABSENT. The production template is the
 *     legally-operative document. testSeedNeverOverwritesAnExistingTemplate
 *     mutates a seeded row and re-seeds, asserting the mutation SURVIVES: that
 *     is the difference between "idempotent" and "silently overwrites the
 *     operator's edits", and it is non-vacuous — an UPDATE-based seed passes
 *     every other test in this file and fails only this one.
 *
 *  4. LOCK 3 — REFUSE A DATABASE HOLDING SIGNATURES. Pinned by
 *     testSeedRefusesADatabaseThatHoldsSignedWaivers.
 *
 * The validate() cases are pure and always run. The DB cases need the
 * `waiver_test` schema (tests/README.md) and skip themselves if it is
 * unreachable, matching this harness's existing convention.
 */
final class TemplateSeedTest extends TestCase {
  private ?\PDO $pdo = null;

  /**
   * DB-gated tests call this; the pure validate() tests never do, so they run
   * even with no database up. (setUp() deliberately does no DB work: skipping
   * there would take the pure cases down with it.)
   */
  private function db(): \PDO {
    if ($this->pdo === null) {
      try {
        $this->pdo = TestDatabase::connect()->pdo();
      } catch (\Throwable $e) {
        $this->markTestSkipped('waiver_test DB unreachable: '.$e->getMessage());
      }
    }
    TestDatabase::reset($this->pdo);   // TRUNCATE also resets AUTO_INCREMENT to 1
    return $this->pdo;
  }

  /**
   * A payload shaped exactly like dev/template_export.php writes. The content
   * carries Romanian diacritics on purpose: the fixture round-trips through
   * json_encode/decode and utf8mb4, and a mojibake regression there would
   * corrupt the operative text while every structural assertion still passed.
   */
  private function payload(int $id = 2, array $versionOverrides = []): array {
    $version = array_replace([
      'version'            => 1,
      'title'              => 'Declarație de asumare a răspunderii',
      'description'        => 'Semnat înainte de activitate',
      'fields_json'        => '[{"key":"full_name","label":"Nume complet","type":"text","required":true}]',
      'requires_signature' => 1,
      'created_by'         => 41,
      'created_at'         => '2026-01-05 10:00:00',
      'content_html'       => '<h1>Declarație</h1><p>Subsemnatul îmi asum răspunderea…</p>',
      'print_css'          => 'body{font-family:serif}',
      'is_published'       => 1,
      'published_at'       => '2026-01-05 10:05:00',
    ], $versionOverrides);

    return [
      'payload_version'    => TemplateSeed::PAYLOAD_VERSION,
      'exported_at'        => '2026-09-04T12:00:00Z',
      'source_template_id' => $id,
      'template' => [
        'id'         => $id,
        'name'       => 'Waiver principal',
        'is_active'  => 1,
        'created_by' => 41,
        'created_at' => '2026-01-05 10:00:00',
        'updated_at' => '2026-02-01 12:30:00',
      ],
      'versions' => [$version],
    ];
  }

  /**
   * MySQL JSON columns NORMALISE the document on storage: object keys come back
   * sorted (by length, then lexicographically), not in the order they were
   * written, and insignificant whitespace is dropped. That is a storage-format
   * property of the column type, not data loss — so fields_json is compared by
   * canonical VALUE, never byte-for-byte. Worth knowing before anyone diffs
   * fields_json across two environments and concludes the copy drifted.
   */
  private static function canonicalJson(string $json): string {
    $decoded = json_decode($json, true);
    self::deepKsort($decoded);
    return (string)json_encode($decoded);
  }

  private static function deepKsort(mixed &$value): void {
    if (!is_array($value)) { return; }
    foreach ($value as &$child) { self::deepKsort($child); }
    unset($child);
    if (array_keys($value) !== range(0, count($value) - 1)) { ksort($value); }   // objects only, never lists
  }

  // ---- 1. the id survives ------------------------------------------------

  public function testSeedPreservesTheExplicitTemplateId(): void {
    $pdo = $this->db();
    $res = TemplateSeed::seed($pdo, $this->payload(2));

    self::assertSame(TemplateSeed::RESULT_SEEDED, $res['result'], $res['detail']);
    self::assertSame(2, $res['template_id']);
    self::assertSame(1, $res['versions_inserted']);
    // The assertion that matters: not "a template exists" but "THE id is 2",
    // and that it is the ONLY row. An AUTO_INCREMENT import into this empty
    // table would have produced 1.
    $ids = array_map('intval', $pdo->query('SELECT id FROM waiver_templates')->fetchAll(\PDO::FETCH_COLUMN));
    self::assertSame(
      [2],
      $ids,
      'the seeded template did not keep id 2 — BookingV2 staging points at "2"'
    );
    self::assertSame('Waiver principal', $pdo->query('SELECT name FROM waiver_templates WHERE id=2')->fetchColumn());
  }

  public function testSeedPreservesContentVerbatimIncludingUnicode(): void {
    $pdo = $this->db();
    $payload = $this->payload(2);
    TemplateSeed::seed($pdo, $payload);

    $row = $pdo->query('SELECT title, description, content_html, print_css, fields_json, requires_signature, version '
      .'FROM waiver_template_versions WHERE template_id=2')->fetch(\PDO::FETCH_ASSOC);
    $src = $payload['versions'][0];

    self::assertSame($src['title'], $row['title']);
    self::assertSame($src['description'], $row['description']);
    self::assertSame($src['content_html'], $row['content_html'], 'content_html was mangled in transit');
    self::assertSame($src['print_css'], $row['print_css']);
    self::assertSame(1, (int)$row['requires_signature']);
    self::assertSame(1, (int)$row['version']);
    self::assertSame(
      self::canonicalJson($src['fields_json']),
      self::canonicalJson((string)$row['fields_json']),
      'fields_json did not survive the round trip'
    );
  }

  public function testAutoIncrementContinuesPastTheSeededId(): void {
    $pdo = $this->db();
    TemplateSeed::seed($pdo, $this->payload(2));

    // A later admin-UI "Create template" (AdminController::createTemplate) must
    // not collide with the explicitly-inserted id. InnoDB advances the counter
    // past an explicit higher id on its own; pin it so nobody has to remember.
    $pdo->prepare('INSERT INTO waiver_templates (name, is_active, created_by, created_at, updated_at) '
      .'VALUES (?,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['Created afterwards']);
    self::assertGreaterThan(2, (int)$pdo->lastInsertId());
  }

  // ---- 2. the publish gate flips -----------------------------------------

  public function testSeedFlipsHasPublishedVersionForThatExactIdOnly(): void {
    $pdo = $this->db();
    $res = TemplateSeed::seed($pdo, $this->payload(2));

    self::assertTrue($res['verified_has_published_version'], $res['detail']);
    self::assertTrue(TemplateSeed::hasPublishedVersion($pdo, 2));
    // Specificity: the gate is per-template-id, so a seed at the WRONG id would
    // still leave 2 false. This is what "preserving the id" actually buys.
    self::assertFalse(TemplateSeed::hasPublishedVersion($pdo, 1));

    $published = $pdo->query('SELECT is_published, published_at FROM waiver_template_versions WHERE template_id=2')
      ->fetch(\PDO::FETCH_ASSOC);
    self::assertSame(1, (int)$published['is_published']);
    self::assertNotNull($published['published_at'], 'published_at must be set alongside is_published=1');
  }

  public function testSeedBackfillsPublishedAtWhenThePayloadOmitsIt(): void {
    $pdo = $this->db();
    // is_published=1 with a NULL published_at reads as "published, but nobody
    // knows when". Every row AdminController::publishVersion writes has both.
    TemplateSeed::seed($pdo, $this->payload(2, ['published_at' => null]));

    $row = $pdo->query('SELECT is_published, published_at FROM waiver_template_versions WHERE template_id=2')
      ->fetch(\PDO::FETCH_ASSOC);
    self::assertSame(1, (int)$row['is_published']);
    self::assertNotNull($row['published_at']);
    self::assertTrue(TemplateSeed::hasPublishedVersion($pdo, 2));
  }

  public function testSeedKeepsPublishedAtNullForADraftVersion(): void {
    $pdo = $this->db();
    $payload = $this->payload(2);
    $payload['versions'][] = array_replace($payload['versions'][0], [
      'version' => 2, 'title' => 'Ciornă', 'is_published' => 0, 'published_at' => null,
    ]);
    $res = TemplateSeed::seed($pdo, $payload);

    self::assertSame(2, $res['versions_inserted']);
    $draft = $pdo->query('SELECT is_published, published_at FROM waiver_template_versions '
      .'WHERE template_id=2 AND version=2')->fetch(\PDO::FETCH_ASSOC);
    self::assertSame(0, (int)$draft['is_published']);
    self::assertNull($draft['published_at'], 'a draft must not acquire a published_at');
  }

  // ---- 3. Lock 2 — insert-only-if-absent ---------------------------------

  public function testSeedNeverOverwritesAnExistingTemplate(): void {
    $pdo = $this->db();
    TemplateSeed::seed($pdo, $this->payload(2));

    // Stand in for the operator's hand edits on the production document.
    $pdo->exec("UPDATE waiver_templates SET name='EDITED BY THE OPERATOR' WHERE id=2");
    $pdo->exec("UPDATE waiver_template_versions SET content_html='<p>OPERATOR TEXT</p>' WHERE template_id=2");

    $again = TemplateSeed::seed($pdo, $this->payload(2));

    self::assertSame(TemplateSeed::RESULT_ALREADY_PRESENT, $again['result'], $again['detail']);
    self::assertSame(0, $again['versions_inserted']);
    // The whole safety argument for shipping this fixture in the production
    // image lives in these three assertions.
    self::assertSame('EDITED BY THE OPERATOR',
      $pdo->query('SELECT name FROM waiver_templates WHERE id=2')->fetchColumn(),
      'the seed OVERWROTE an existing template — it must never update');
    self::assertSame('<p>OPERATOR TEXT</p>',
      $pdo->query('SELECT content_html FROM waiver_template_versions WHERE template_id=2')->fetchColumn(),
      'the seed OVERWROTE an existing version body');
    self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM waiver_template_versions WHERE template_id=2')->fetchColumn(),
      'the seed DUPLICATED the operator document as an extra version row');
  }

  public function testSeedIsIdempotentAcrossRepeatedDeploys(): void {
    $pdo = $this->db();
    for ($i = 0; $i < 3; $i++) {
      TemplateSeed::seed($pdo, $this->payload(2));
    }
    self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM waiver_templates')->fetchColumn());
    self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM waiver_template_versions')->fetchColumn());
  }

  // ---- 4. Lock 3 — refuse a database holding signatures ------------------

  public function testSeedRefusesADatabaseThatHoldsSignedWaivers(): void {
    $pdo = $this->db();
    $versionId  = TestDatabase::seedPublishedTemplateVersion($pdo);   // lands at template id 1
    $instanceId = TestDatabase::seedInstance($pdo, $versionId);
    TestDatabase::seedResponse($pdo, $instanceId);

    $res = TemplateSeed::seed($pdo, $this->payload(2));

    self::assertSame(TemplateSeed::RESULT_REFUSED_SIGNED_DATA, $res['result'], $res['detail']);
    self::assertSame(0, $res['versions_inserted']);
    self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM waiver_templates WHERE id=2')->fetchColumn(),
      'the seed wrote into a database holding real signatures');
  }

  // ---- created_by attribution --------------------------------------------

  public function testSeedAttributesRowsToTheLocalAdminWhenOneIsGiven(): void {
    $pdo = $this->db();
    $pdo->prepare('INSERT INTO users (email, password_hash, role, created_at, updated_at) '
      .'VALUES (?,?,"admin",UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['admin@staging.test', 'x']);
    $adminId = (int)$pdo->lastInsertId();

    self::assertSame($adminId, TemplateSeed::localAdminId($pdo));
    TemplateSeed::seed($pdo, $this->payload(2), $adminId);

    // 41 is the SOURCE database's user id, which need not exist here.
    self::assertSame($adminId, (int)$pdo->query('SELECT created_by FROM waiver_templates WHERE id=2')->fetchColumn());
    self::assertSame($adminId, (int)$pdo->query('SELECT created_by FROM waiver_template_versions WHERE template_id=2')->fetchColumn());
  }

  public function testSeedKeepsTheSourceCreatedByWhenNoOverrideIsGiven(): void {
    $pdo = $this->db();
    self::assertNull(TemplateSeed::localAdminId($pdo), 'no admin seeded, so there is nothing to attribute to');
    TemplateSeed::seed($pdo, $this->payload(2), null);
    // No FK is declared anywhere in migrations/*.sql, so a dangling created_by
    // is inert rather than a hard failure — pinned so nobody "fixes" it later.
    self::assertSame(41, (int)$pdo->query('SELECT created_by FROM waiver_templates WHERE id=2')->fetchColumn());
  }

  // ---- export → seed round trip ------------------------------------------

  public function testExportRoundTripsThroughSeedPreservingTheId(): void {
    $pdo = $this->db();
    $pdo->prepare('INSERT INTO waiver_templates (id, name, is_active, created_by, created_at, updated_at) '
      .'VALUES (7,?,1,41,?,?)')->execute(['Sursa', '2026-01-05 10:00:00', '2026-02-01 12:30:00']);
    $insert = $pdo->prepare('INSERT INTO waiver_template_versions (template_id, version, title, description, '
      .'fields_json, requires_signature, created_by, created_at, content_html, print_css, is_published, published_at) '
      .'VALUES (7,?,?,?,?,1,41,?,?,?,?,?)');
    $insert->execute([1, 'v1 ciornă', null, '[]', '2026-01-05 10:00:00', '<p>vechi</p>', null, 0, null]);
    $insert->execute([2, 'v2 publicat', 'd', '[{"key":"a","type":"text"}]', '2026-01-06 10:00:00',
      '<p>Text operativ — ăâîșț</p>', 'body{}', 1, '2026-01-06 10:05:00']);

    $payload = TemplateSeed::export($pdo, 7);
    self::assertSame(7, $payload['source_template_id']);
    self::assertCount(2, $payload['versions']);

    // Round-trip through the file format the fixture actually uses.
    $payload = json_decode(json_encode($payload), true);

    TestDatabase::reset($pdo);                       // the re-provisioned staging DB
    $res = TemplateSeed::seed($pdo, $payload);

    self::assertSame(TemplateSeed::RESULT_SEEDED, $res['result'], $res['detail']);
    self::assertSame(7, $res['template_id'], 'the export→seed round trip renumbered the template');
    self::assertSame(2, $res['versions_inserted']);
    self::assertTrue(TemplateSeed::hasPublishedVersion($pdo, 7));
    self::assertSame('<p>Text operativ — ăâîșț</p>',
      $pdo->query('SELECT content_html FROM waiver_template_versions WHERE template_id=7 AND version=2')->fetchColumn());
    self::assertSame(0, (int)$pdo->query('SELECT is_published FROM waiver_template_versions WHERE template_id=7 AND version=1')->fetchColumn(),
      'the draft version must stay a draft');
  }

  public function testExportRefusesATemplateWhoseVersionsAreAllDrafts(): void {
    $pdo = $this->db();
    $pdo->exec("INSERT INTO waiver_templates (id, name, is_active, created_by, created_at, updated_at) "
      ."VALUES (9,'Doar ciorne',1,41,'2026-01-05 10:00:00','2026-01-05 10:00:00')");
    $pdo->exec("INSERT INTO waiver_template_versions (template_id, version, title, fields_json, requires_signature, "
      ."created_by, created_at, is_published) VALUES (9,1,'ciornă','[]',1,41,'2026-01-05 10:00:00',0)");

    // Exporting this would produce a fixture that cannot fix anything: it would
    // seed cleanly and leave has_published_version false.
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/NONE with is_published=1/');
    TemplateSeed::export($pdo, 9);
  }

  public function testExportRefusesAMissingTemplate(): void {
    $pdo = $this->db();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/no waiver_templates row with id 2/');
    TemplateSeed::export($pdo, 2);
  }

  // ---- validate() — pure, always runs ------------------------------------

  public function testValidateRejectsAPayloadWithNoPublishedVersion(): void {
    $payload = $this->payload(2, ['is_published' => 0, 'published_at' => null]);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/no version with is_published=1/');
    TemplateSeed::validate($payload);
  }

  public function testValidateRejectsDuplicateVersionNumbers(): void {
    $payload = $this->payload(2);
    $payload['versions'][] = $payload['versions'][0];       // UNIQUE (template_id, version)
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/version 1 twice/');
    TemplateSeed::validate($payload);
  }

  public function testValidateRejectsInvalidFieldsJson(): void {
    $payload = $this->payload(2, ['fields_json' => 'not json at all']);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/fields_json is not valid JSON/');
    TemplateSeed::validate($payload);
  }

  public function testValidateRejectsAMissingTemplateColumn(): void {
    $payload = $this->payload(2);
    unset($payload['template']['is_active']);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/missing column "is_active"/');
    TemplateSeed::validate($payload);
  }

  public function testValidateRejectsAMissingVersionColumn(): void {
    $payload = $this->payload(2);
    unset($payload['versions'][0]['content_html']);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/versions\[0\] is missing column "content_html"/');
    TemplateSeed::validate($payload);
  }

  public function testValidateRejectsANonPositiveTemplateId(): void {
    $payload = $this->payload(2);
    $payload['template']['id'] = 0;
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/must be >= 1/');
    TemplateSeed::validate($payload);
  }

  public function testValidateRejectsAnUnknownPayloadVersion(): void {
    $payload = $this->payload(2);
    $payload['payload_version'] = TemplateSeed::PAYLOAD_VERSION + 1;
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/this build understands/');
    TemplateSeed::validate($payload);
  }

  public function testValidateAcceptsTheExportedShape(): void {
    TemplateSeed::validate($this->payload(2));
    $this->addToAssertionCount(1);   // no exception is the assertion
  }
}
