<?php
namespace Tests;

use App\Utils;
use PHPUnit\Framework\TestCase;

/**
 * [FK-T7 / D11] HMAC wire-drift known-answer vector.
 *
 * BookingV2 (Utils::hmacSign here; `signCanonical` in
 * src/lib/waiver/hmac.ts on the BookingV2 side) and this fork sign the SAME
 * canonical envelope string with the SAME algorithm
 * (`hash_hmac('sha256', $canonical, $secret)` / Node's
 * `createHmac("sha256", secret).update(canonical, "utf8").digest("hex")`).
 * If either side's canonical construction, hash algorithm, or encoding ever
 * silently drifts (e.g. someone "simplifies" the join order, switches to
 * sha1, or changes utf8 handling), a real fork <-> BookingV2 envelope would
 * stop verifying — but neither side's own unit tests would catch it, because
 * each only exercises its own sign/verify round trip.
 *
 * This is the fork half of the CROSS-REPO contract-vector gate (D11): a
 * FIXED canonical string + FIXED secret must produce this EXACT signature on
 * BOTH sides. The companion vector lives in BookingV2 at
 * src/lib/waiver/hmac.test.ts ("D11 wire-drift known-answer vector" describe
 * block) — the two must be edited together; this is intentionally NOT run
 * against a live BookingV2 or over the network (CI does not stand up a fork
 * container — see BookingV2 TODOS.md / the T7 build note: "contract vectors,
 * not a fork-in-CI container"). It requires no DB (`Utils::hmacSign` is a
 * pure function) and no HTTP.
 *
 * Run locally: `docker exec waiver-system-v2-php-1 vendor/bin/phpunit
 * tests/UtilsHmacKnownAnswerTest.php` (or from the host, if a PHP CLI with
 * the same vendor/ is available).
 *
 * DO NOT change $CANONICAL, $SECRET, or $EXPECTED_SIGNATURE without also
 * updating the BookingV2-side vector in the same commit/PR — that is the
 * entire point of this test.
 */
final class UtilsHmacKnownAnswerTest extends TestCase {
  // Exact canonical string: "{keyId}\n{timestamp}\n{nonce}\n{rawJsonBody}"
  // (SPEC G1). rawJsonBody below is a byte-for-byte JSON.stringify()-shaped
  // completion-webhook-like payload, deliberately including int/string
  // fields in the SAME key order Node's JSON.stringify would emit for the
  // object literal used to derive this vector (object literal key
  // insertion order == JSON.stringify() key order in V8/Node).
  private const KEY_ID = 'k1';
  private const TIMESTAMP = '1751500000';
  private const NONCE = 'wiredriftvectornoncefixed012345';
  private const RAW_BODY = '{"event":"waiver.completed","idempotency_key":"wire-drift-vector-fixed","waiver_instance_id":42,"link_token":"wire-drift-vector-token","booking_group_id":"RES-WIREDRIFT-0001","participant_id":"part-wiredrift-01","customer_id":"cust-wiredrift-01","computed_age":30}';
  private const SECRET = 'wire-drift-known-answer-secret-do-not-rotate';

  // Computed once via BOTH Node's createHmac("sha256", ...) AND this fork's
  // Utils::hmacSign over the exact canonical string above -- verified
  // identical (see BookingV2 hmac.test.ts's matching describe block for the
  // derivation). If this fork's implementation drifts from the shared
  // canonical/algorithm contract, THIS test fails first, locally, with no
  // need for a live fork-in-CI container.
  private const EXPECTED_SIGNATURE = '0347fffa2325fedf3aa0a557b83f7cdd1c87409dd787e911bb23f563873f1b81';

  public function testKnownAnswerVectorMatchesBookingV2SharedFixture(): void {
    $canonical = self::KEY_ID."\n".self::TIMESTAMP."\n".self::NONCE."\n".self::RAW_BODY;

    $signature = Utils::hmacSign($canonical, self::SECRET);

    $this->assertSame(
      self::EXPECTED_SIGNATURE,
      $signature,
      'Utils::hmacSign() produced a different signature than the shared '.
      'BookingV2/fork known-answer vector -- the canonical envelope '.
      'construction or hash algorithm has drifted between the two repos. '.
      'Compare against src/lib/waiver/hmac.test.ts on the BookingV2 side.'
    );
    $this->assertSame(64, strlen($signature), 'sha256 hex digest must be exactly 64 chars');
  }

  /**
   * Sanity check that the vector is actually sensitive to its inputs (a
   * canary against a no-op / hardcoded-return implementation slipping past
   * the primary assertion above).
   */
  public function testVectorIsSensitiveToASingleByteChangeInTheCanonicalString(): void {
    $canonical = self::KEY_ID."\n".self::TIMESTAMP."\n".self::NONCE."\n".self::RAW_BODY;
    $tamperedCanonical = self::KEY_ID."\n".self::TIMESTAMP."\n".self::NONCE."\n".
      str_replace('"computed_age":30', '"computed_age":31', self::RAW_BODY);

    $signature = Utils::hmacSign($canonical, self::SECRET);
    $tamperedSignature = Utils::hmacSign($tamperedCanonical, self::SECRET);

    $this->assertNotSame($signature, $tamperedSignature);
    $this->assertSame(self::EXPECTED_SIGNATURE, $signature);
  }
}
