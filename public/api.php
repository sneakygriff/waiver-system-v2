<?php
require __DIR__.'/../vendor/autoload.php';
$cfg = require __DIR__.'/../config/config.php';
use App\{Database, WaiverController, Utils};

// API responses are JSON only: never let a PHP warning/notice leak into the body.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// [W7] Fail-closed boot sentinel: refuse to serve ANY request if the loaded
// config still carries a placeholder secret (see Utils::assertNoPlaceholderSecrets
// doc). Runs before Database/WaiverController construction and before the
// signed-envelope check below, so a misconfigured deploy never even reaches
// the point of accepting/signing a request with a known-public secret.
try {
  Utils::assertNoPlaceholderSecrets($cfg);
} catch (\Throwable $e) {
  Utils::jsonResponse(500, ['error'=>'internal server error']);
}

// [FK-T7] Verify the signed envelope (timestamp + nonce + key_id + signature)
// over the EXACT raw bytes received, before any defaulting or JSON-decoding —
// so a caller that honestly signs an empty body isn't rejected, and signing
// '{}' while sending nothing isn't silently accepted. Replaces the old
// raw-body-only single-header X-Hmac check with replay protection (rules 1-4
// of spec G1); only after verification passes do we decode the body (rule 5).
$raw = file_get_contents('php://input'); if ($raw === false) $raw = '';

try {
  $db = new Database($cfg['db']);
  $verify = Utils::verifySignedEnvelope($raw, [
    'timestamp' => $_SERVER['HTTP_X_WAIVER_TIMESTAMP'] ?? null,
    'nonce'     => $_SERVER['HTTP_X_WAIVER_NONCE'] ?? null,
    'keyId'     => $_SERVER['HTTP_X_WAIVER_KEY_ID'] ?? null,
    'signature' => $_SERVER['HTTP_X_WAIVER_SIGNATURE'] ?? null,
  ], $cfg['security']['inbound_hmac_keys'], $db->pdo());
  if (!$verify['ok']) {
    Utils::jsonResponse($verify['status'], ['error'=>$verify['error']]);
  }
} catch (\Throwable $e) {
  // Never leak a stack trace / server paths to the caller.
  Utils::jsonResponse(500, ['error'=>'internal server error']);
}

$payload = json_decode($raw !== '' ? $raw : '{}', true);
if (!is_array($payload)) $payload = [];
$action = is_string($payload['action'] ?? null) ? $payload['action'] : '';

try {
  $ctl = new WaiverController($cfg, $db);

  if ($action === 'create_waiver') {
    $res = $ctl->createInstance($payload);
    Utils::jsonResponse(empty($res['error']) ? 200 : 400, $res);
  }

  if ($action === 'has_published_version') {
    $res = $ctl->hasPublishedVersion($payload['template_id'] ?? null);
    Utils::jsonResponse(empty($res['error']) ? 200 : 400, $res);
  }

  if ($action === 'void_waiver') {
    $res = $ctl->voidWaiver($payload);
    if (!empty($res['error'])) {
      // token_unknown is the one 404-shaped case (matches get_status);
      // already_completed / missing-link_token are plain validation 400s.
      $status = $res['error'] === 'token_unknown' ? 404 : 400;
      Utils::jsonResponse($status, $res);
    }
    Utils::jsonResponse(200, $res);
  }

  if ($action === 'get_status') {
    $res = $ctl->getStatus($payload);
    if (!empty($res['error'])) {
      // token_unknown is the one 404-shaped case (spec G1c); everything else
      // (bad/missing identifiers) is a plain validation 400.
      $status = $res['error'] === 'token_unknown' ? 404 : 400;
      Utils::jsonResponse($status, $res);
    }
    Utils::jsonResponse(200, $res);
  }

  if ($action === 'create_walkin_group') {
    Utils::jsonResponse(200, ['group_token'=>Utils::randomToken(8)]);
  }

  if ($action === 'erase_waiver') {
    $res = $ctl->eraseWaiver($payload);
    Utils::jsonResponse(empty($res['error']) ? 200 : 400, $res);
  }

  if ($action === 'link_waivers') {
    $reservationId = $payload['reservation_id'] ?? '';
    $waiverIds = $payload['waiver_ids'] ?? [];
    $groupToken = $payload['group_token'] ?? null;
    if (!is_string($reservationId)) Utils::jsonResponse(400, ['error'=>'reservation_id must be a string']);
    if (!is_array($waiverIds)) Utils::jsonResponse(400, ['error'=>'waiver_ids must be an array']);
    if ($groupToken !== null && !is_scalar($groupToken)) Utils::jsonResponse(400, ['error'=>'group_token must be a string']);
    $includePending = (bool)($payload['include_pending'] ?? false);
    $res = $ctl->linkWaiversToReservation($reservationId, $waiverIds, $groupToken !== null ? (string)$groupToken : null, $includePending);
    Utils::jsonResponse(empty($res['error']) ? 200 : 400, $res);
  }

  Utils::jsonResponse(404, ['error'=>'unknown action']);
} catch (\Throwable $e) {
  // Never leak a stack trace / server paths to the caller.
  Utils::jsonResponse(500, ['error'=>'internal server error']);
}
