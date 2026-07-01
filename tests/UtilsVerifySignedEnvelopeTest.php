<?php
namespace Tests;

use App\Utils;
use PHPUnit\Framework\TestCase;

/**
 * [F1-fork-erasure] Micro-harness: verify-before-consume-nonce ordering.
 * Utils::verifySignedEnvelope() MUST check the HMAC signature (rule 4)
 * BEFORE it atomically consumes/inserts the nonce (rule 3) -- otherwise a
 * bad-signature request (e.g. an attacker replaying a sniffed envelope with
 * a tampered signature) would burn the legitimate nonce, letting the
 * attacker deny-of-service the real caller's subsequent legitimate retry
 * with that same nonce.
 *
 * REQUIRES the `waiver_test` MySQL schema (webhook_nonces table) -- the
 * function takes a live PDO to perform the atomic nonce-consume INSERT.
 * Skips itself if the DB is unreachable.
 */
final class UtilsVerifySignedEnvelopeTest extends TestCase {
  private \PDO $pdo;

  protected function setUp(): void {
    try {
      $db = TestDatabase::connect();
    } catch (\Throwable $e) {
      $this->markTestSkipped('waiver_test DB unreachable: '.$e->getMessage());
    }
    $this->pdo = $db->pdo();
    TestDatabase::reset($this->pdo);
  }

  private function countNonces(string $nonce): int {
    $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM webhook_nonces WHERE nonce=?');
    $stmt->execute([$nonce]);
    return (int)$stmt->fetch()['c'];
  }

  public function testValidSignatureConsumesNonceAndSucceeds(): void {
    $secret = 'a-real-secret';
    $body = '{"hello":"world"}';
    $timestamp = (string)time();
    $nonce = bin2hex(random_bytes(6));
    $canonical = "k1\n$timestamp\n$nonce\n$body";
    $sig = hash_hmac('sha256', $canonical, $secret);

    $result = Utils::verifySignedEnvelope($body, [
      'timestamp' => $timestamp, 'nonce' => $nonce, 'keyId' => 'k1', 'signature' => $sig,
    ], ['k1' => $secret], $this->pdo);

    $this->assertTrue($result['ok']);
    $this->assertSame(1, $this->countNonces($nonce), 'a valid request must consume (persist) its nonce');
  }

  /**
   * The core correctness property: a request with a BAD signature must be
   * rejected WITHOUT consuming the nonce. Verified two ways:
   *   1. the nonce row is NOT present afterward (rule 4 ran before rule 3's
   *      INSERT, and rejected before reaching it), and
   *   2. a SUBSEQUENT request reusing that same nonce with the CORRECT
   *      signature still succeeds -- proving the nonce was never burned by
   *      the earlier bad-signature attempt.
   */
  public function testInvalidSignatureDoesNotConsumeNonce(): void {
    $secret = 'a-real-secret';
    $body = '{"hello":"world"}';
    $timestamp = (string)time();
    $nonce = bin2hex(random_bytes(6));
    $badSig = str_repeat('0', 64); // well-formed hex, but not a valid HMAC for this body/secret

    $rejected = Utils::verifySignedEnvelope($body, [
      'timestamp' => $timestamp, 'nonce' => $nonce, 'keyId' => 'k1', 'signature' => $badSig,
    ], ['k1' => $secret], $this->pdo);

    $this->assertFalse($rejected['ok']);
    $this->assertSame('invalid signature', $rejected['error']);
    $this->assertSame(0, $this->countNonces($nonce), 'a bad-signature request must NEVER consume the nonce');

    // The SAME nonce, now with the CORRECT signature, must still succeed --
    // proving the earlier rejected attempt left it fully unconsumed.
    $canonical = "k1\n$timestamp\n$nonce\n$body";
    $goodSig = hash_hmac('sha256', $canonical, $secret);
    $accepted = Utils::verifySignedEnvelope($body, [
      'timestamp' => $timestamp, 'nonce' => $nonce, 'keyId' => 'k1', 'signature' => $goodSig,
    ], ['k1' => $secret], $this->pdo);

    $this->assertTrue($accepted['ok'], 'a legitimate retry with the SAME nonce must succeed after an earlier bad-signature attempt using that nonce');
  }

  public function testReplayOfAnAlreadyConsumedValidNonceIsRejected(): void {
    $secret = 'a-real-secret';
    $body = '{"hello":"world"}';
    $timestamp = (string)time();
    $nonce = bin2hex(random_bytes(6));
    $canonical = "k1\n$timestamp\n$nonce\n$body";
    $sig = hash_hmac('sha256', $canonical, $secret);

    $first = Utils::verifySignedEnvelope($body, [
      'timestamp' => $timestamp, 'nonce' => $nonce, 'keyId' => 'k1', 'signature' => $sig,
    ], ['k1' => $secret], $this->pdo);
    $this->assertTrue($first['ok']);

    // Exact same (valid) envelope replayed -- must be rejected as a replay,
    // not silently re-accepted.
    $second = Utils::verifySignedEnvelope($body, [
      'timestamp' => $timestamp, 'nonce' => $nonce, 'keyId' => 'k1', 'signature' => $sig,
    ], ['k1' => $secret], $this->pdo);
    $this->assertFalse($second['ok']);
    $this->assertSame('nonce already used (replay)', $second['error']);
  }
}
