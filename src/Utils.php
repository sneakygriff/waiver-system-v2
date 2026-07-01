<?php
namespace App;
use PDO;
class Utils {
  // [FK-T7] Replay window for the signed envelope: clock-skew tolerance AND
  // the nonce single-use retention horizon (spec G1: |now-ts|<=300s).
  const ENVELOPE_MAX_SKEW_SECONDS = 300;

  // [W7 / boot sentinel] The distributed config/config.php.docker TEMPLATE
  // ships every secret as this literal placeholder (README: `cp
  // config/config.php.docker config/config.php`). config/config.php itself is
  // gitignored (local/deploy secret material, never committed) -- but nothing
  // stops a fresh deploy from copying the template and forgetting to actually
  // replace the placeholders, which would otherwise boot a fork instance that:
  //   - accepts ANY caller as authentic on the inbound signed-envelope check
  //     (api_hmac_secret / inbound_hmac_keys -- a publicly-known secret is no
  //     secret at all), and
  //   - signs its OWN outbound completion webhook / evidence uploads to
  //     BookingV2 with a publicly-known secret (callback.outbound_secret),
  //     which BookingV2 would then also have to accept as authentic.
  // Fail CLOSED: refuse to boot at all (never silently run insecure) rather
  // than log-and-continue.
  const PLACEHOLDER_SECRET = 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET';

  /**
   * [W7] Boot-time guard: throws if any of the security-critical secrets in
   * $cfg still equal the distributed placeholder value. Every public
   * entrypoint (api.php / admin.php / w.php) calls this immediately after
   * loading config.php and BEFORE constructing Database/WaiverController/etc,
   * so a misconfigured deploy fails closed with a clear error instead of
   * quietly accepting forged requests or signing outbound calls with a
   * secret anyone can read from the public fork repo.
   *
   * Checks (all must be replaced): security.api_hmac_secret,
   * every value in security.inbound_hmac_keys, and callback.outbound_secret
   * (only when callback.base_url is actually configured -- an unconfigured
   * callback block, e.g. a legacy/self-mint-only deployment with no
   * BookingV2 integration at all, has nothing to sign and is not a risk).
   *
   * @throws \RuntimeException listing every offending config key, so an
   *   operator sees exactly what to fix rather than a generic failure.
   */
  public static function assertNoPlaceholderSecrets(array $cfg): void {
    $offenders = [];

    $apiSecret = $cfg['security']['api_hmac_secret'] ?? null;
    if ($apiSecret === self::PLACEHOLDER_SECRET) {
      $offenders[] = 'security.api_hmac_secret';
    }

    $inboundKeys = $cfg['security']['inbound_hmac_keys'] ?? [];
    if (is_array($inboundKeys)) {
      foreach ($inboundKeys as $keyId => $secret) {
        if ($secret === self::PLACEHOLDER_SECRET) {
          $offenders[] = 'security.inbound_hmac_keys['.$keyId.']';
        }
      }
    }

    $callback = $cfg['callback'] ?? null;
    if (is_array($callback) && !empty($callback['base_url'])) {
      $outboundSecret = $callback['outbound_secret'] ?? null;
      if ($outboundSecret === self::PLACEHOLDER_SECRET) {
        $offenders[] = 'callback.outbound_secret';
      }
    }

    if (!empty($offenders)) {
      throw new \RuntimeException(
        'Refusing to boot: placeholder secret(s) still configured ('
        .implode(', ', $offenders)
        .'). Replace every CHANGE_ME_TO_A_LONG_RANDOM_SECRET value in config/config.php'
        .' with a real, unique random secret before starting this app.'
      );
    }
  }

  public static function nowUtc(): string { return gmdate('Y-m-d H:i:s'); }
  public static function jsonResponse(int $code, $data) {
    http_response_code($code); header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
  }
  // Legacy raw-body-only verify. Superseded by verifySignedEnvelope() for the
  // inbound BookingV2 -> fork direction (FK-T7); kept only in case anything
  // else still calls it directly.
  public static function hmacValid(string $body, string $secret, ?string $header): bool {
    if (!$header) return false;
    $calc = hash_hmac('sha256', $body, $secret);
    return hash_equals($calc, $header);
  }

  // [FK-T7] Outbound signer: HMAC-SHA256 over the canonical envelope string.
  // Mirrors the inbound canonical construction exactly (see
  // verifySignedEnvelope below) so both directions share one wire format.
  public static function hmacSign(string $canonical, string $secret): string {
    return hash_hmac('sha256', $canonical, $secret);
  }

  private static function canonicalEnvelope(string $keyId, string $timestamp, string $nonce, string $rawBody): string {
    return $keyId."\n".$timestamp."\n".$nonce."\n".$rawBody;
  }

  /**
   * [FK-T7] Verify an inbound signed envelope (BookingV2 -> fork) per spec G1:
   *   X-Waiver-Timestamp / X-Waiver-Nonce / X-Waiver-Key-Id / X-Waiver-Signature
   * Canonical signed string: "{keyId}\n{timestamp}\n{nonce}\n{rawJsonBody}".
   *
   * Rules, in order (fail-closed, first failure wins):
   *   1. key_id must resolve to a configured secret                -> 401
   *   2. |now - timestamp| <= ENVELOPE_MAX_SKEW_SECONDS             -> 401
   *   3. nonce not previously consumed within the replay window     -> 401
   *   4. constant-time signature compare                            -> 401
   * Only after all four pass does the caller proceed to JSON-decode the body.
   *
   * @param string $rawBody Exact raw request bytes (never a re-encoded copy).
   * @param array<string,?string> $headers ['timestamp'=>, 'nonce'=>, 'keyId'=>, 'signature'=>]
   * @param array<string,string> $secrets key_id => secret map (supports rotation).
   * @param PDO $pdo Used to atomically consume the nonce (single-use, DB-backed).
   * @return array{ok:true}|array{ok:false,error:string,status:int}
   */
  public static function verifySignedEnvelope(string $rawBody, array $headers, array $secrets, PDO $pdo): array {
    $keyId = $headers['keyId'] ?? null;
    $timestamp = $headers['timestamp'] ?? null;
    $nonce = $headers['nonce'] ?? null;
    $signature = $headers['signature'] ?? null;

    // Presence + shape checks up front (malformed/missing headers are just
    // another way to fail verification -> 401, not a 400; we don't want to
    // hand an attacker a signal distinguishing "missing header" from "bad sig").
    if (!is_string($keyId) || $keyId === '' || !is_string($timestamp) || $timestamp === ''
        || !is_string($nonce) || $nonce === '' || !is_string($signature) || $signature === '') {
      return ['ok'=>false, 'error'=>'missing signed-envelope headers', 'status'=>401];
    }
    if (!preg_match('/^[0-9]{1,10}$/', $timestamp)) {
      return ['ok'=>false, 'error'=>'invalid timestamp', 'status'=>401];
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $nonce)) {
      return ['ok'=>false, 'error'=>'invalid nonce', 'status'=>401];
    }

    // Rule 1: key_id -> secret.
    if (!isset($secrets[$keyId]) || !is_string($secrets[$keyId]) || $secrets[$keyId] === '') {
      return ['ok'=>false, 'error'=>'unknown key_id', 'status'=>401];
    }
    $secret = $secrets[$keyId];

    // Rule 2: clock skew / replay window.
    $ts = (int)$timestamp;
    $now = time();
    if (abs($now - $ts) > self::ENVELOPE_MAX_SKEW_SECONDS) {
      return ['ok'=>false, 'error'=>'timestamp outside allowed skew', 'status'=>401];
    }

    // Rule 4 computed before rule 3's write so a bad signature never consumes
    // a nonce slot (an attacker replaying a sniffed-but-not-yet-used envelope
    // with a tampered signature must not burn the legitimate nonce).
    $canonical = self::canonicalEnvelope($keyId, $timestamp, $nonce, $rawBody);
    $calc = hash_hmac('sha256', $canonical, $secret);
    if (!hash_equals($calc, (string)$signature)) {
      return ['ok'=>false, 'error'=>'invalid signature', 'status'=>401];
    }

    // Rule 3: single-use nonce, atomically consumed via INSERT (PK collision
    // = replay). expiresAt is timestamp+window so a sweep can safely purge
    // rows once they've fallen outside anyone's possible skew window.
    $expiresAt = gmdate('Y-m-d H:i:s', $ts + self::ENVELOPE_MAX_SKEW_SECONDS);
    try {
      $pdo->prepare('INSERT INTO webhook_nonces (nonce, expires_at) VALUES (?, ?)')->execute([$nonce, $expiresAt]);
    } catch (\PDOException $e) {
      if ($e->getCode() === '23000') {
        return ['ok'=>false, 'error'=>'nonce already used (replay)', 'status'=>401];
      }
      throw $e;
    }

    // Opportunistic sweep of expired nonces so the table doesn't grow
    // unboundedly. Best-effort: failure here must never fail the request that
    // already verified successfully.
    try {
      $pdo->prepare('DELETE FROM webhook_nonces WHERE expires_at < UTC_TIMESTAMP()')->execute();
    } catch (\Throwable $e) { /* non-fatal */ }

    return ['ok'=>true];
  }

  public static function randomToken(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
}
