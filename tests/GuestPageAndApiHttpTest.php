<?php
namespace Tests;

use App\Utils;
use PHPUnit\Framework\TestCase;

/**
 * [GVS-89 / gate 89-M4] HTTP-level tests for the two PUBLIC entrypoints,
 * public/w.php (guest signing page) and public/api.php (HMAC API): the parts
 * of the gate fixes that live in the entrypoints themselves, not the
 * controller, and so cannot be pinned by calling WaiverController directly.
 *
 *   w.php  P2-2  inside the 60-min submit grace, a POST the controller
 *                rejects with a validation error re-renders the FORM with that
 *                error (200), instead of falling into the no-grace render gate
 *                and 410ing (which masked the error, incl. the adults-only copy).
 *          P2-3  a POST past the grace gets the SAME localized 410 page as a
 *                GET (lang attribute, warning styling, localized title), not
 *                the generic 5xx banner.
 *   api.php      the status codes of the new/changed error answers:
 *                erase_waiver evidence_busy -> 503, create_public_instance
 *                template_missing_dob -> 400, resend_evidence invalid_request
 *                -> 400.
 *
 * HOW: the REAL, current public/w.php and public/api.php are copied into a
 * throwaway app root whose config/config.php returns config.test.php (so they
 * talk to `waiver_test`) and whose vendor/autoload.php forwards to the real
 * one; `php -S` serves that docroot as a subprocess and the tests speak plain
 * HTTP to it. Nothing is stubbed: a test passes only if the entrypoint as
 * committed behaves that way. Same php -S technique as
 * WaiverControllerSubmitEvidenceTest; FAILS (not skips) if the server does not
 * come up. Ports: 21800 + pid % 400 (distinct from the other suites' ranges).
 */
final class GuestPageAndApiHttpTest extends TestCase {
  private \PDO $pdo;
  private array $cfg;
  private string $appRoot = '';
  private int $port = 0;
  /** @var resource|null */
  private $server = null;

  protected function setUp(): void {
    $this->cfg = TestDatabase::config();
    try {
      $db = TestDatabase::connect();
    } catch (\Throwable $e) {
      $this->markTestSkipped('waiver_test DB unreachable: '.$e->getMessage());
    }
    $this->pdo = $db->pdo();
    TestDatabase::reset($this->pdo);
    $this->startEntrypointServer();
  }

  protected function tearDown(): void {
    if (is_resource($this->server)) {
      proc_terminate($this->server);
      proc_close($this->server);
    }
    $this->server = null;
    if ($this->appRoot !== '' && is_dir($this->appRoot)) {
      foreach (['public/w.php', 'public/api.php', 'config/config.php', 'vendor/autoload.php'] as $f) {
        @unlink($this->appRoot.'/'.$f);
      }
      foreach (['public', 'config', 'vendor', ''] as $d) {
        @rmdir($this->appRoot.'/'.$d);
      }
    }
  }

  // =========================================================================
  // w.php -- gate 89-M4 P2-2: validation errors inside the submit grace
  // =========================================================================

  public function testAValidationErrorInsideTheSubmitGraceReRendersTheFormWithTheError(): void {
    $token = $this->seedPublicDobInstance('en', -30 * 60); // 30 min past expires_at: inside the 60-min grace

    // Sanity: a fresh GET in the same state IS refused (render gate: no grace).
    $this->assertSame(410, $this->http('GET', '/w.php?token='.$token)['status']);

    $r = $this->http('POST', '/w.php?token='.$token, ['full_name' => 'Forgot Dob', 'signature_data' => self::png()]);

    $this->assertSame(200, $r['status'], $r['body']);
    $this->assertStringContainsString('<form method="post">', $r['body'], 'the guest keeps the open form');
    $this->assertStringContainsString('<div class="alert alert-danger">Missing field: dob</div>', $r['body'], 'and sees the actual validation error');
    $this->assertSame('pending', $this->instanceStatus($token));
  }

  public function testAMinorInsideTheSubmitGraceSeesTheAdultsOnlyCopyNotA410(): void {
    $token = $this->seedPublicDobInstance('en', -30 * 60);

    $r = $this->http('POST', '/w.php?token='.$token, ['full_name' => 'Minor', 'dob' => self::dobForAge(16), 'signature_data' => self::png()]);

    $this->assertSame(200, $r['status'], $r['body']);
    $this->assertStringContainsString('<form method="post">', $r['body']);
    $this->assertStringContainsString(htmlspecialchars('This form is only available to signers 18 or older. Please ask a staff member for help.'), $r['body']);
    $this->assertSame('pending', $this->instanceStatus($token));
  }

  public function testAValidSubmitInsideTheGraceStillCompletes(): void {
    // Non-vacuity partner of the two above.
    $token = $this->seedPublicDobInstance('en', -30 * 60);

    $r = $this->http('POST', '/w.php?token='.$token, ['full_name' => 'Adult', 'dob' => self::dobForAge(30), 'signature_data' => self::png()]);

    $this->assertSame(200, $r['status'], $r['body']);
    $this->assertStringContainsString('Thank you! Your waiver is complete.', $r['body']);
    $this->assertSame('completed', $this->instanceStatus($token));
  }

  // =========================================================================
  // w.php -- gate 89-M4 P2-3: ONE localized 410 page for GET and POST
  // =========================================================================

  public function testAPostPastTheGraceGetsTheSameLocalized410PageAsAGet(): void {
    foreach (['en' => ['Link expired', 'This link has expired — please scan the QR again.'], 'ro' => ['Link expirat', 'Acest link a expirat — te rugăm să scanezi din nou codul QR.']] as $locale => [$title, $message]) {
      $token = $this->seedPublicDobInstance($locale, -62 * 60); // 2 min past the grace

      $get = $this->http('GET', '/w.php?token='.$token);
      $post = $this->http('POST', '/w.php?token='.$token, ['full_name' => 'Too Late', 'dob' => self::dobForAge(30), 'signature_data' => self::png()]);

      $this->assertSame(410, $post['status'], $post['body']);
      $this->assertSame($get['body'], $post['body'], "[$locale] a late POST must render byte-for-byte the GET's 410 page");
      $this->assertStringContainsString('<html lang="'.$locale.'">', $post['body']);
      $this->assertStringContainsString('<title>'.htmlspecialchars($title).'</title>', $post['body']);
      $this->assertStringContainsString('<div class="alert alert-warning">'.htmlspecialchars($message).'</div>', $post['body']);
      $this->assertStringNotContainsString('alert-danger', $post['body'], 'not the generic 5xx banner');
      $this->assertStringNotContainsString('<form', $post['body']);
      $this->assertSame('pending', $this->instanceStatus($token));
    }
  }

  // =========================================================================
  // api.php -- status codes of the new/changed answers
  // =========================================================================

  public function testEraseWaiverAnswers503EvidenceBusyWhileAResendHoldsTheEvidenceLock(): void {
    $versionId = TestDatabase::seedPublishedTemplateVersion($this->pdo);
    $token = bin2hex(random_bytes(16));
    $id = TestDatabase::seedInstance($this->pdo, $versionId, ['link_token' => $token, 'status' => 'completed']);
    $holder = TestDatabase::connect()->pdo();
    $lock = $holder->prepare("SELECT GET_LOCK(CONCAT('wvr_evidence:', LEFT(SHA1(DATABASE()), 16), ':', ?), 0)");
    $lock->execute([(string)$id]);
    $this->assertSame(1, (int)$lock->fetchColumn(), 'test setup: could not take the evidence lock');

    $busy = $this->signedApi(['action' => 'erase_waiver', 'link_tokens' => [$token]]);
    $this->assertSame(503, $busy['status'], $busy['body']);
    $this->assertSame(['error' => 'evidence_busy'], json_decode($busy['body'], true));
    $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM waiver_instances')->fetchColumn(), 'nothing erased');

    $holder->prepare("SELECT RELEASE_LOCK(CONCAT('wvr_evidence:', LEFT(SHA1(DATABASE()), 16), ':', ?))")->execute([(string)$id]);
    $ok = $this->signedApi(['action' => 'erase_waiver', 'link_tokens' => [$token]]);
    $this->assertSame(200, $ok['status'], $ok['body']);
    $this->assertSame(1, json_decode($ok['body'], true)['instances_deleted'] ?? null);
  }

  public function testCreatePublicInstanceAnswers400TemplateMissingDob(): void {
    $versionId = TestDatabase::seedPublishedTemplateVersion($this->pdo); // full_name only, no DOB
    $templateId = (int)$this->pdo->query('SELECT template_id FROM waiver_template_versions WHERE id='.$versionId)->fetchColumn();

    $r = $this->signedApi([
      'action' => 'create_public_instance', 'template_id' => (string)$templateId,
      'link_token' => bin2hex(random_bytes(16)), 'locale' => 'ro', 'expires_at' => gmdate('Y-m-d\TH:i:s', time() + 7200).'.000Z',
    ]);

    $this->assertSame(400, $r['status'], $r['body']);
    $this->assertSame('template_missing_dob', json_decode($r['body'], true)['error'] ?? null);
  }

  public function testResendEvidenceAnswers400InvalidRequestForAMalformedToken(): void {
    $r = $this->signedApi(['action' => 'resend_evidence', 'link_token' => '']);

    $this->assertSame(400, $r['status'], $r['body']);
    $this->assertSame(['error' => 'invalid_request', 'detail' => 'link_token must be a non-empty string (max 128)'], json_decode($r['body'], true));
  }

  // =========================================================================
  // helpers
  // =========================================================================

  private function startEntrypointServer(): void {
    $repo = \dirname(__DIR__);
    $this->appRoot = sys_get_temp_dir().'/wv-http-'.getmypid().'-'.bin2hex(random_bytes(4));
    foreach (['public', 'config', 'vendor'] as $d) {
      if (!mkdir($this->appRoot.'/'.$d, 0o777, true) && !is_dir($this->appRoot.'/'.$d)) $this->fail('mkdir '.$d);
    }
    foreach (['w.php', 'api.php'] as $entry) {
      copy($repo.'/public/'.$entry, $this->appRoot.'/public/'.$entry);
      $this->assertSame(hash_file('sha256', $repo.'/public/'.$entry), hash_file('sha256', $this->appRoot.'/public/'.$entry), "$entry must be the real, current file");
    }
    file_put_contents($this->appRoot.'/vendor/autoload.php', "<?php\nreturn require ".var_export($repo.'/vendor/autoload.php', true).";\n");
    // Short evidence-lock wait so the evidence_busy case answers in ~1 s.
    file_put_contents($this->appRoot.'/config/config.php', "<?php\n\$cfg = require ".var_export($repo.'/config/config.test.php', true).";\n\$cfg['evidence_lock'] = ['erase_wait_seconds' => 1];\nreturn \$cfg;\n");

    $this->port = 21800 + (getmypid() % 400);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$this->port, '-t', $this->appRoot.'/public'], $descriptors, $pipes, $this->appRoot);
    $this->assertIsResource($proc, 'could not spawn the entrypoint `php -S` subprocess');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $this->server = $proc;

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
      $conn = @stream_socket_client('tcp://127.0.0.1:'.$this->port, $errno, $errstr, 0.1);
      if ($conn !== false) {
        fclose($conn);
        return;
      }
      usleep(20000);
    }
    $this->fail('entrypoint `php -S` did not start listening on port '.$this->port.' within 5s');
  }

  /** @return array{status:int,body:string} */
  private function http(string $method, string $path, ?array $form = null, ?string $rawBody = null, array $headers = []): array {
    $content = $rawBody ?? ($form !== null ? http_build_query($form) : '');
    if ($form !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $ctx = stream_context_create(['http' => [
      'method' => $method,
      'header' => implode("\r\n", $headers),
      'content' => $content,
      'ignore_errors' => true,
      'timeout' => 15,
    ]]);
    $body = @file_get_contents('http://127.0.0.1:'.$this->port.$path, false, $ctx);
    $this->assertIsString($body, "$method $path: no response");
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
      if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    }
    return ['status' => $status, 'body' => $body];
  }

  /** @return array{status:int,body:string} */
  private function signedApi(array $payload): array {
    $raw = json_encode($payload);
    $keyId = 'k1';
    $secret = $this->cfg['security']['inbound_hmac_keys'][$keyId];
    $ts = (string)time();
    $nonce = bin2hex(random_bytes(8));
    $sig = Utils::hmacSign($keyId."\n".$ts."\n".$nonce."\n".$raw, $secret);
    return $this->http('POST', '/api.php', null, $raw, [
      'Content-Type: application/json',
      'X-Waiver-Timestamp: '.$ts,
      'X-Waiver-Nonce: '.$nonce,
      'X-Waiver-Key-Id: '.$keyId,
      'X-Waiver-Signature: '.$sig,
    ]);
  }

  /** A PUBLIC instance on a template WITH a DOB field; expiry relative to the DB clock. */
  private function seedPublicDobInstance(string $locale, int $expiresInSeconds): string {
    $this->pdo->prepare('INSERT INTO waiver_templates (name, is_active, created_by, created_at, updated_at) VALUES (?,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['Http DOB template']);
    $templateId = (int)$this->pdo->lastInsertId();
    $fields = json_encode([
      ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
      ['key' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'required' => true],
    ]);
    $this->pdo->prepare('INSERT INTO waiver_template_versions (template_id, version, title, fields_json, requires_signature, created_by, created_at, is_published, published_at) VALUES (?,1,?,?,1,1,UTC_TIMESTAMP(),1,UTC_TIMESTAMP())')
      ->execute([$templateId, 'Http Waiver', $fields]);
    $versionId = (int)$this->pdo->lastInsertId();
    $token = bin2hex(random_bytes(16));
    TestDatabase::seedPublicInstance($this->pdo, $versionId, ['link_token' => $token, 'locale' => $locale], $expiresInSeconds);
    return $token;
  }

  private function instanceStatus(string $token): string {
    $q = $this->pdo->prepare('SELECT status FROM waiver_instances WHERE link_token=?');
    $q->execute([$token]);
    return (string)$q->fetchColumn();
  }

  private static function dobForAge(int $age): string {
    return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.$age.' years')->modify('-1 day')->format('Y-m-d');
  }

  private static function png(): string {
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
  }
}
